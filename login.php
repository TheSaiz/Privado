<?php
session_start();

require_once 'backend/connection.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if ($email && $password) {
        try {
            // Buscar usuario por email (NO filtramos por rol aquí)
            $stmt = $pdo->prepare("
                SELECT id, nombre, apellido, email, password, rol 
                FROM usuarios 
                WHERE email = ?
            ");
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();
            
            if ($usuario && password_verify($password, $usuario['password'])) {

                // Guardar variables de sesión generales
                $_SESSION['usuario_id'] = $usuario['id'];
                $_SESSION['usuario_nombre'] = $usuario['nombre'] . ' ' . $usuario['apellido'];
                $_SESSION['usuario_email'] = $usuario['email'];
                $_SESSION['usuario_rol'] = $usuario['rol'];

                // SI ES ADMIN → Dashboard general
                if ($usuario['rol'] === 'admin') {
                    header('Location: dashboard.php');
                    exit;
                }

                // SI ES ASESOR → Panel de asesor
                if ($usuario['rol'] === 'asesor') {
                    $_SESSION['asesor_id'] = $usuario['id'];
$_SESSION['asesor_nombre'] = $usuario['nombre'] . ' ' . $usuario['apellido'];


                    // Marcar asesor como disponible
                    $stmt = $pdo->prepare("
                        UPDATE asesores_departamentos 
                        SET disponible = 1 
                        WHERE asesor_id = ?
                    ");
                    $stmt->execute([$usuario['id']]);

                    header('Location: dashboard_asesor.php');
                    exit;
                }

                // Si existe otro tipo de rol no contemplado
                $error = 'Rol de usuario no reconocido';

            } else {
                $error = 'Credenciales incorrectas';
            }
        } catch (PDOException $e) {
            $error = 'Error en el sistema';
        }
    } else {
        $error = 'Complete todos los campos';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema de Asesores</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
</head>
<body class="bg-gradient-to-br from-blue-600 to-blue-800 min-h-screen flex items-center justify-center p-4">
    
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8">
        
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-600 rounded-full mb-4">
                <span class="material-icons-outlined text-white text-4xl">support_agent</span>
            </div>
            <h1 class="text-3xl font-bold text-gray-800">Panel de Asesores</h1>
            <p class="text-gray-600 mt-2">Préstamo Líder</p>
        </div>

        <!-- Error -->
        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center gap-2">
            <span class="material-icons-outlined">error</span>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <!-- Formulario -->
        <form method="POST" class="space-y-6">
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Email</label>
                <div class="relative">
                    <span class="material-icons-outlined absolute left-3 top-3 text-gray-400">email</span>
                    <input type="email" name="email" required
                           class="w-full pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="tu@email.com">
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Contraseña</label>
                <div class="relative">
                    <span class="material-icons-outlined absolute left-3 top-3 text-gray-400">lock</span>
                    <input type="password" name="password" required
                           class="w-full pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="••••••••">
                </div>
            </div>

            <button type="submit" 
                    class="w-full py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition flex items-center justify-center gap-2">
                <span class="material-icons-outlined">login</span>
                <span>Iniciar Sesión</span>
            </button>

        </form>

        <!-- Info -->
        <div class="mt-8 pt-6 border-t border-gray-200 text-center text-sm text-gray-600">
            <p>¿Problemas para ingresar?</p>
            <p class="mt-1">Contacta al administrador</p>
        </div>

    </div>

</body>
</html>