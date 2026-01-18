<?php
session_start();
require_once 'backend/connection.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($usuario && $password) {
        try {
            $stmt = $pdo->prepare("
                SELECT id, nombre, apellido, email, password, estado, rol
                FROM usuarios
                WHERE email = ?
                LIMIT 1
            ");
            $stmt->execute([$usuario]);
            $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cliente) {
                $error = 'Usuario no encontrado';
            } elseif ($cliente['rol'] !== 'cliente') {
                $error = 'Acceso no permitido';
            } elseif ($cliente['estado'] !== 'activo') {
                $error = 'Tu cuenta no está activa';
            } elseif (!password_verify($password, $cliente['password'])) {
                $error = 'Credenciales incorrectas';
            } else {
                $_SESSION['cliente_id']     = $cliente['id'];
                $_SESSION['cliente_nombre'] = $cliente['nombre'].' '.$cliente['apellido'];
                $_SESSION['cliente_email']  = $cliente['email'];

                header('Location: dashboard_clientes.php');
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Error en el sistema';
        }
    } else {
        $error = 'Completá todos los campos';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Login Clientes - Préstamo Líder</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
</head>

<body class="bg-gradient-to-br from-blue-600 to-blue-800 min-h-screen flex items-center justify-center p-4">

<div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8">

    <!-- Logo -->
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-600 rounded-full mb-4">
            <span class="material-icons-outlined text-white text-4xl">account_circle</span>
        </div>
        <h1 class="text-3xl font-bold text-gray-800">Panel de Clientes</h1>
        <p class="text-gray-600 mt-2">Préstamo Líder</p>
    </div>

    <!-- Error -->
    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center gap-2">
            <span class="material-icons-outlined">error</span>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" class="space-y-6">

        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Email</label>
            <div class="relative">
                <span class="material-icons-outlined absolute left-3 top-3 text-gray-400">email</span>
                <input type="email" name="usuario" required
                       class="w-full pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                       placeholder="cliente@email.com">
            </div>
        </div>

        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Contraseña</label>
            <div class="relative">
                <span class="material-icons-outlined absolute left-3 top-3 text-gray-400">lock</span>
                <input type="password" name="password" required
                       class="w-full pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                       placeholder="••••••••">
            </div>
        </div>

        <button type="submit"
                class="w-full py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition flex items-center justify-center gap-2">
            <span class="material-icons-outlined">login</span>
            <span>Ingresar</span>
        </button>

    </form>

    <!-- Links -->
    <div class="mt-6 flex justify-between text-sm text-blue-600">
        <a href="recuperar_password.php" class="hover:underline">¿Olvidaste tu contraseña?</a>
        <a href="registro_clientes.php" class="hover:underline font-semibold">Registrarse</a>
    </div>

    <!-- Footer -->
    <div class="mt-8 pt-6 border-t border-gray-200 text-center text-sm text-gray-600">
        Acceso seguro al sistema de clientes
    </div>

</div>
</body>
</html>
