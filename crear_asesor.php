<?php
// =====================================================
// ALTA DE ASESORES - PDO
// CLAVE POR DEFECTO: password
// =====================================================

// SOLO PARA DEBUG (luego podés quitar esto)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// -----------------------------------------------------
// CONEXIÓN (PDO)
// -----------------------------------------------------
require_once __DIR__ . "/backend/connection.php";

// Verificar que exista $pdo
if (!isset($pdo)) {
    die("ERROR: Conexión PDO no disponible");
}

// -----------------------------------------------------
// VARIABLES
// -----------------------------------------------------
$mensaje = "";
$success = false;

// -----------------------------------------------------
// PROCESAR FORMULARIO
// -----------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nombre   = trim($_POST["nombre"] ?? "");
    $apellido = trim($_POST["apellido"] ?? "");
    $email    = trim($_POST["email"] ?? "");

    // ------------------------------
    // VALIDACIONES
    // ------------------------------
    if ($nombre === "" || $email === "") {
        $mensaje = "Nombre y email son obligatorios";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mensaje = "Email inválido";
    } else {

        try {
            // ------------------------------
            // VERIFICAR EMAIL EXISTENTE
            // ------------------------------
            $check = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $check->execute([$email]);

            if ($check->fetch()) {
                $mensaje = "El email ya está registrado";
            } else {

                // ------------------------------
                // CLAVE POR DEFECTO
                // ------------------------------
                $passwordHash = password_hash("password", PASSWORD_BCRYPT);

                // ------------------------------
                // INSERTAR ASESOR
                // ------------------------------
                $insert = $pdo->prepare("
                    INSERT INTO usuarios 
                    (nombre, apellido, email, password, rol, estado)
                    VALUES (?, ?, ?, ?, 'asesor', 'activo')
                ");

                $insert->execute([
                    $nombre,
                    $apellido,
                    $email,
                    $passwordHash
                ]);

                $success = true;
                $mensaje = "Asesor creado correctamente. Clave inicial: password";
            }

        } catch (PDOException $e) {
            $mensaje = "Error al guardar el asesor";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Alta de Asesores</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
body {
    background: #f3f4f6;
    font-family: Arial, Helvetica, sans-serif;
}

.card {
    max-width: 420px;
    margin: 80px auto;
    background: #ffffff;
    padding: 30px;
    border-radius: 14px;
    box-shadow: 0 15px 40px rgba(0,0,0,.1);
}

h2 {
    text-align: center;
    margin-bottom: 25px;
    color: #111827;
}

label {
    font-weight: bold;
    display: block;
    margin-bottom: 6px;
    color: #374151;
}

input {
    width: 100%;
    padding: 12px;
    margin-bottom: 16px;
    border-radius: 8px;
    border: 1px solid #d1d5db;
    font-size: 15px;
}

button {
    width: 100%;
    padding: 14px;
    background: #2563eb;
    color: #ffffff;
    font-size: 16px;
    font-weight: bold;
    border: none;
    border-radius: 10px;
    cursor: pointer;
}

button:hover {
    background: #1e40af;
}

.msg {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 18px;
    text-align: center;
    font-weight: bold;
}

.msg.ok {
    background: #dcfce7;
    color: #166534;
}

.msg.err {
    background: #fee2e2;
    color: #991b1b;
}

.footer {
    text-align: center;
    margin-top: 16px;
    font-size: 13px;
    color: #6b7280;
}
</style>
</head>

<body>

<div class="card">
    <h2>Alta de Asesor</h2>

    <?php if ($mensaje): ?>
        <div class="msg <?= $success ? 'ok' : 'err' ?>">
            <?= htmlspecialchars($mensaje) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <label>Nombre *</label>
        <input type="text" name="nombre" required>

        <label>Apellido</label>
        <input type="text" name="apellido">

        <label>Email *</label>
        <input type="email" name="email" required>

        <button type="submit">Crear asesor</button>
    </form>

    <div class="footer">
        La contraseña inicial será <b>password</b>
    </div>
</div>

</body>
</html>
