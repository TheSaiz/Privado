<?php
session_start();
require_once 'backend/connection.php';

$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email) {
        $stmt = $pdo->prepare("
            SELECT id, nombre, email
            FROM usuarios
            WHERE email = ?
              AND rol = 'cliente'
              AND estado = 'activo'
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cliente) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);

            $pdo->prepare("
                UPDATE usuarios
                SET reset_token = ?, reset_expires = ?
                WHERE id = ?
            ")->execute([$token, $expires, $cliente['id']]);

            require_once __DIR__ . '/correos/EmailDispatcher.php';

            (new EmailDispatcher())->send(
                'recupero_password',
                $cliente['email'],
                [
                    'nombre'     => $cliente['nombre'],
                    'link_reset' => "https://prestamolider.com/system/reset_password.php?token=$token"
                ]
            );
        }

        $ok = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Recuperar contraseña</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
</head>

<body class="bg-gradient-to-br from-blue-600 to-blue-800 min-h-screen flex items-center justify-center p-4">

<div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8">

    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-600 rounded-full mb-4">
            <span class="material-icons-outlined text-white text-4xl">lock_reset</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-800">Recuperar contraseña</h1>
        <p class="text-gray-600 mt-2">Te enviaremos un enlace seguro</p>
    </div>

    <?php if ($ok): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center gap-2">
            <span class="material-icons-outlined">check_circle</span>
            Si el email existe, te enviamos un enlace para restablecer tu contraseña.
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Email</label>
            <div class="relative">
                <span class="material-icons-outlined absolute left-3 top-3 text-gray-400">email</span>
                <input type="email" name="email" required
                       class="w-full pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                       placeholder="cliente@email.com">
            </div>
        </div>

        <button type="submit"
                class="w-full py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition">
            Enviar enlace
        </button>
    </form>

    <div class="mt-6 text-center text-sm text-blue-600">
        <a href="login_clientes.php" class="hover:underline">← Volver al login</a>
    </div>

</div>
</body>
</html>
