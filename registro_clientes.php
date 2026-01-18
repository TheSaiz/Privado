<?php
session_start();
require_once __DIR__ . '/backend/connection.php';

$error = '';
$success = '';

$nombreValue   = htmlspecialchars($_POST['nombre'] ?? '', ENT_QUOTES, 'UTF-8');
$apellidoValue = htmlspecialchars($_POST['apellido'] ?? '', ENT_QUOTES, 'UTF-8');
$dniValue      = htmlspecialchars($_POST['dni'] ?? '', ENT_QUOTES, 'UTF-8');
$emailValue    = htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8');
$codAreaValue  = htmlspecialchars($_POST['cod_area'] ?? '', ENT_QUOTES, 'UTF-8');
$telefonoValue = htmlspecialchars($_POST['telefono'] ?? '', ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nombre   = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $dni      = preg_replace('/\D/', '', $_POST['dni'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $cod_area = preg_replace('/\D/', '', $_POST['cod_area'] ?? '');
    $telefono = preg_replace('/\D/', '', $_POST['telefono'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    // =========================
    // VALIDACIONES
    // =========================
    if ($nombre === '' || $apellido === '' || $dni === '' || $email === '' || $password === '' || $cod_area === '' || $telefono === '') {
        $error = 'Completá todos los campos.';
    } elseif (strlen($dni) < 7 || strlen($dni) > 8) {
        $error = 'DNI inválido.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email inválido.';
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif (strlen($cod_area) < 2 || strlen($cod_area) > 4) {
        $error = 'Código de área inválido.';
    } elseif (strlen($telefono) < 6 || strlen($telefono) > 8) {
        $error = 'Teléfono inválido.';
    } else {

        try {
            // =========================
            // INICIO TRANSACCIÓN
            // =========================
            $pdo->beginTransaction();

            // Verificar si el email existe y si tiene contraseña
            $chk = $pdo->prepare("SELECT id, password FROM usuarios WHERE email = ? LIMIT 1");
            $chk->execute([$email]);
            $usuarioExistente = $chk->fetch();

            // Solo bloquear si el usuario existe Y tiene contraseña guardada
            if ($usuarioExistente && !empty($usuarioExistente['password'])) {
                throw new Exception('Ya existe una cuenta con ese email y contraseña. Por favor, iniciá sesión.');
            }

            // Hash seguro
            $hash = password_hash($password, PASSWORD_BCRYPT);
            
            // Generar token de validación de email
            $token = bin2hex(random_bytes(32));

            // Si el usuario existe pero sin contraseña, actualizar
            if ($usuarioExistente && empty($usuarioExistente['password'])) {
                $usuario_id = (int)$usuarioExistente['id'];
                
                // Actualizar usuario existente con la nueva información
                $upd = $pdo->prepare("
                    UPDATE usuarios
                    SET nombre = ?, 
                        apellido = ?, 
                        password = ?, 
                        fecha_registro = NOW(),
                        email_verification_token = ?,
                        email_verification_expires = DATE_ADD(NOW(), INTERVAL 24 HOUR),
                        auto_validado = 0,
                        requiere_validacion_prestamo = 1
                    WHERE id = ?
                ");
                $upd->execute([$nombre, $apellido, $hash, $token, $usuario_id]);

                // Verificar si ya existe en clientes_detalles
                $chkDet = $pdo->prepare("SELECT id FROM clientes_detalles WHERE usuario_id = ? LIMIT 1");
                $chkDet->execute([$usuario_id]);
                
                if (!$chkDet->fetch()) {
                    // Crear detalles cliente si no existe
                    $insDet = $pdo->prepare("
                        INSERT INTO clientes_detalles 
                        (usuario_id, dni, cod_area, telefono, email, docs_completos, estado_validacion, email_verificado)
                        VALUES (?, ?, ?, ?, ?, 0, 'pendiente', 0)
                    ");
                    $insDet->execute([$usuario_id, $dni, $cod_area, $telefono, $email]);
                } else {
                    // Actualizar detalles existentes
                    $updDet = $pdo->prepare("
                        UPDATE clientes_detalles
                        SET dni = ?, cod_area = ?, telefono = ?, email = ?, docs_completos = 0, estado_validacion = 'pendiente'
                        WHERE usuario_id = ?
                    ");
                    $updDet->execute([$dni, $cod_area, $telefono, $email, $usuario_id]);
                }
            } else {
                // Crear nuevo usuario
                $ins = $pdo->prepare("
                    INSERT INTO usuarios
                    (nombre, apellido, email, password, rol, estado, fecha_registro, email_verification_token, email_verification_expires, auto_validado, requiere_validacion_prestamo)
                    VALUES (?, ?, ?, ?, 'cliente', 'activo', NOW(), ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), 0, 1)
                ");
                $ins->execute([$nombre, $apellido, $email, $hash, $token]);

                $usuario_id = (int)$pdo->lastInsertId();
                if ($usuario_id <= 0) {
                    throw new Exception('No se pudo crear el usuario.');
                }

                // Crear detalles cliente con docs_completos = 0 (pendiente)
                $insDet = $pdo->prepare("
                    INSERT INTO clientes_detalles 
                    (usuario_id, dni, cod_area, telefono, email, docs_completos, estado_validacion, email_verificado)
                    VALUES (?, ?, ?, ?, ?, 0, 'pendiente', 0)
                ");
                $insDet->execute([$usuario_id, $dni, $cod_area, $telefono, $email]);
            }

            // =========================
            // COMMIT DB
            // =========================
            $pdo->commit();

            // =========================
            // ENVÍO DE MAIL (NO BLOQUEANTE)
            // =========================
            try {
                require_once __DIR__ . '/correos/EmailDispatcher.php';

                (new EmailDispatcher())->send(
                    'registro_validacion',
                    $email,
                    [
                        'nombre'      => trim($nombre . ' ' . $apellido),
                        'email'       => $email,
                        'token'       => $token,
                        'link_validacion' => 'https://prestamolider.com/system/validar_email.php?token=' . $token,
                        'link_login'  => 'https://prestamolider.com/system/login_clientes.php',
                    ]
                );
            } catch (Throwable $mailError) {
                // Log interno si querés, pero NO rompemos el registro
                error_log("Error enviando email: " . $mailError->getMessage());
            }

            $success = '✅ Cuenta creada correctamente. Te enviamos un email para validar tu cuenta. Podés iniciar sesión ahora.';
            $nombreValue = $apellidoValue = $dniValue = $emailValue = $codAreaValue = $telefonoValue = '';

        } catch (Throwable $e) {
            // Rollback seguro
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registro de Clientes - Préstamo Líder</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
</head>

<body class="bg-gradient-to-br from-blue-600 to-blue-800 min-h-screen flex items-center justify-center p-4">

<div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8">

  <div class="text-center mb-8">
    <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-600 rounded-full mb-4">
      <span class="material-icons-outlined text-white text-4xl">person_add</span>
    </div>
    <h1 class="text-3xl font-bold text-gray-800">Crear cuenta</h1>
    <p class="text-gray-600 mt-2">Registro de clientes · Préstamo Líder</p>
  </div>

  <?php if ($error): ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
      <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
      <?php echo $success; ?>
    </div>
  <?php endif; ?>

  <form method="POST" class="space-y-4" novalidate>

    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
        <input type="text" name="nombre" required value="<?php echo $nombreValue; ?>"
               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Juan">
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Apellido</label>
        <input type="text" name="apellido" required value="<?php echo $apellidoValue; ?>"
               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Pérez">
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">DNI</label>
      <input type="text" name="dni" required value="<?php echo $dniValue; ?>" maxlength="8"
             class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="12345678">
    </div>

    <div class="grid grid-cols-3 gap-3">
      <div class="col-span-1">
        <label class="block text-sm font-medium text-gray-700 mb-1">Cód. Área</label>
        <input type="text" name="cod_area" required value="<?php echo $codAreaValue; ?>" maxlength="4"
               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="11">
      </div>
      <div class="col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
        <input type="text" name="telefono" required value="<?php echo $telefonoValue; ?>" maxlength="8"
               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="12345678">
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
      <input type="email" name="email" required value="<?php echo $emailValue; ?>"
             class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="tu@email.com">
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label>
      <input type="password" name="password" required minlength="6"
             class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Mínimo 6 caracteres">
    </div>

    <div class="text-xs text-gray-500">
      Al registrarte, aceptás los términos y condiciones del servicio.
    </div>

    <button type="submit"
            class="w-full py-3 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition">
      Crear cuenta
    </button>

  </form>

  <div class="mt-8 pt-6 border-t text-center text-sm">
    <a href="login_clientes.php" class="text-blue-600 hover:underline">← Ya tengo cuenta, iniciar sesión</a>
  </div>

</div>
</body>
</html>