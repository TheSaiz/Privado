<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');
session_start();

require_once 'backend/connection.php';

// ====================================================================
// RECUPERAR INFORMACIÓN DE REFERIDO DE LA SESIÓN
// ====================================================================
$asesor_referidor_id = $_SESSION['asesor_referidor_id'] ?? null;
$asesor_referidor_nombre = $_SESSION['asesor_referidor_nombre'] ?? null;
$codigo_referido = $_SESSION['codigo_referido'] ?? null;

// ====================================================================
// PROCESAR REGISTRO
// ====================================================================
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    
    // Validaciones
    if (empty($nombre) || empty($email) || empty($password)) {
        $error = "Todos los campos son obligatorios";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Email inválido";
    } elseif (strlen($password) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres";
    } elseif ($password !== $password_confirm) {
        $error = "Las contraseñas no coinciden";
    } else {
        // Verificar si el email ya existe
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->fetch()) {
            $error = "Este email ya está registrado";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Hash de la contraseña
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                // Insertar usuario
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios (nombre, email, password, rol, estado, referido_por)
                    VALUES (?, ?, ?, 'asesor', 'activo', ?)
                ");
                $stmt->execute([
                    $nombre,
                    $email,
                    $password_hash,
                    $asesor_referidor_id
                ]);
                
                $nuevo_asesor_id = $pdo->lastInsertId();
                
                // Generar código de referido para el nuevo asesor
                $nuevo_codigo = 'REF-' . strtoupper(substr(md5($nuevo_asesor_id . time()), 0, 8));
                $nuevo_link = 'https://prestamolider.com/system/?ref=' . $nuevo_codigo;
                
                $stmt = $pdo->prepare("
                    UPDATE usuarios 
                    SET codigo_referido = ?, link_referido = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$nuevo_codigo, $nuevo_link, $nuevo_asesor_id]);
                
                // Registrar conversión si viene de referido
                if ($asesor_referidor_id) {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                    
                    // Marcar conversión en el click más reciente de este usuario
                    $stmt = $pdo->prepare("
                        UPDATE referidos_clicks 
                        SET convertido = 1, 
                            usuario_id = ?, 
                            fecha_conversion = NOW()
                        WHERE asesor_id = ? 
                          AND ip_address = ?
                          AND convertido = 0
                        ORDER BY fecha_click DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$nuevo_asesor_id, $asesor_referidor_id, $ip]);
                    
                    // Si no había click registrado, crear uno ahora
                    if ($stmt->rowCount() === 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO referidos_clicks 
                            (asesor_id, ip_address, user_agent, convertido, usuario_id, fecha_conversion)
                            VALUES (?, ?, ?, 1, ?, NOW())
                        ");
                        $stmt->execute([$asesor_referidor_id, $ip, $user_agent, $nuevo_asesor_id]);
                    }
                    
                    // Actualizar estadísticas de conversión
                    $pdo->prepare("
                        UPDATE estadisticas_referidos 
                        SET conversiones = conversiones + 1,
                            tasa_conversion = ROUND((conversiones + 1) * 100.0 / NULLIF(clicks_link, 0), 2)
                        WHERE asesor_id = ?
                    ")->execute([$asesor_referidor_id]);
                }
                
                $pdo->commit();
                
                $success = "¡Registro exitoso! Redirigiendo al login...";
                
                // Limpiar sesión de referido
                unset($_SESSION['asesor_referidor_id']);
                unset($_SESSION['asesor_referidor_nombre']);
                unset($_SESSION['codigo_referido']);
                unset($_SESSION['referral_tracked']);
                
                // Redirigir después de 2 segundos
                header("refresh:2;url=login.php");
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error al crear la cuenta. Intenta nuevamente.";
                error_log("Error en registro: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro - Préstamo Líder</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .referral-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            animation: slideDown 0.5s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .input-group {
            position: relative;
        }
        
        .input-group .material-icons-outlined {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            pointer-events: none;
        }
        
        .input-group input {
            padding-left: 3rem;
        }
    </style>
</head>
<body class="flex items-center justify-center p-4">

<div class="w-full max-w-md">
    <!-- Logo -->
    <div class="text-center mb-8">
        <h1 class="text-4xl font-bold text-white mb-2">Préstamo Líder</h1>
        <p class="text-white opacity-90">Registro de Nuevo Asesor</p>
    </div>
    
    <div class="card p-8">
        
        <!-- Badge de Referido -->
        <?php if ($asesor_referidor_id): ?>
        <div class="referral-badge text-center">
            <div class="flex items-center justify-center gap-2 mb-1">
                <span class="material-icons-outlined">person_add</span>
                <p class="text-sm opacity-90">Fuiste invitado por:</p>
            </div>
            <p class="text-xl font-bold">👤 <?php echo htmlspecialchars($asesor_referidor_nombre); ?></p>
            <p class="text-xs opacity-75 mt-1">Código: <?php echo htmlspecialchars($codigo_referido); ?></p>
        </div>
        <?php endif; ?>
        
        <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">Crear Cuenta</h2>
        
        <!-- Mensajes -->
        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 flex items-center gap-2">
            <span class="material-icons-outlined text-red-700">error</span>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4 flex items-center gap-2">
            <span class="material-icons-outlined text-green-700">check_circle</span>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Formulario -->
        <form method="POST" action="">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-semibold mb-2">
                    Nombre Completo
                </label>
                <div class="input-group">
                    <span class="material-icons-outlined">person</span>
                    <input type="text" 
                           name="nombre" 
                           required
                           value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="Juan Pérez">
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-semibold mb-2">
                    Email
                </label>
                <div class="input-group">
                    <span class="material-icons-outlined">email</span>
                    <input type="email" 
                           name="email" 
                           required
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="tu@email.com">
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-semibold mb-2">
                    Contraseña
                </label>
                <div class="input-group">
                    <span class="material-icons-outlined">lock</span>
                    <input type="password" 
                           name="password" 
                           required
                           minlength="6"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="Mínimo 6 caracteres">
                </div>
            </div>
            
            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-semibold mb-2">
                    Confirmar Contraseña
                </label>
                <div class="input-group">
                    <span class="material-icons-outlined">lock</span>
                    <input type="password" 
                           name="password_confirm" 
                           required
                           minlength="6"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="Repite tu contraseña">
                </div>
            </div>
            
            <button type="submit" 
                    class="w-full bg-gradient-to-r from-blue-600 to-purple-600 text-white py-3 rounded-lg font-semibold hover:from-blue-700 hover:to-purple-700 transition shadow-lg flex items-center justify-center gap-2">
                <span class="material-icons-outlined">person_add</span>
                Crear Cuenta
            </button>
        </form>
        
        <div class="mt-6 text-center text-sm text-gray-600">
            ¿Ya tienes cuenta? 
            <a href="login.php" class="text-blue-600 hover:text-blue-800 font-semibold">Iniciar Sesión</a>
        </div>
    </div>
</div>

</body>
</html>