<?php
session_start();
require_once 'backend/connection.php';

// Proteger acceso (solo usuarios logueados)
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$mensaje = "";

// ==========================
// CARGAR DATOS DEL USUARIO
// ==========================
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch();

// Cargar detalles del cliente (si existe)
$stmt_det = $pdo->prepare("SELECT * FROM clientes_detalles WHERE usuario_id = ?");
$stmt_det->execute([$usuario_id]);
$detalles = $stmt_det->fetch();

// Si no existe detalles, crear array vacío
if (!$detalles) {
    $detalles = [
        'dni' => '',
        'direccion' => '',
        'ciudad' => '',
        'provincia' => '',
        'fecha_nacimiento' => ''
    ];
}

// ==========================
// PROCESAR FORMULARIO
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Datos generales del usuario
    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $email = trim($_POST['email']);
    $telefono = trim($_POST['telefono']);

    // Datos adicionales (solo clientes)
    $dni = trim($_POST['dni']);
    $direccion = trim($_POST['direccion']);
    $ciudad = trim($_POST['ciudad']);
    $provincia = trim($_POST['provincia']);
    $fecha_nac = $_POST['fecha_nacimiento'] ?: null;

    try {
        // Actualizar tabla usuarios
        $stmtUpdate = $pdo->prepare("
            UPDATE usuarios
            SET nombre = ?, apellido = ?, email = ?, telefono = ?
            WHERE id = ?
        ");
        $stmtUpdate->execute([$nombre, $apellido, $email, $telefono, $usuario_id]);

        // Actualizar o crear registro en clientes_detalles
        if ($usuario['rol'] === "cliente") {
            
            // Verificar si existe detalle
            $check = $pdo->prepare("SELECT id FROM clientes_detalles WHERE usuario_id = ?");
            $check->execute([$usuario_id]);

            if ($check->fetch()) {
                // Update
                $stmtDetUpdate = $pdo->prepare("
                    UPDATE clientes_detalles
                    SET dni = ?, direccion = ?, ciudad = ?, provincia = ?, fecha_nacimiento = ?
                    WHERE usuario_id = ?
                ");
                $stmtDetUpdate->execute([$dni, $direccion, $ciudad, $provincia, $fecha_nac, $usuario_id]);

            } else {
                // Insert
                $stmtDetInsert = $pdo->prepare("
                    INSERT INTO clientes_detalles (usuario_id, dni, direccion, ciudad, provincia, fecha_nacimiento)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmtDetInsert->execute([$usuario_id, $dni, $direccion, $ciudad, $provincia, $fecha_nac]);
            }
        }

        $mensaje = "Datos actualizados correctamente";

    } catch (PDOException $e) {
        $mensaje = "Error al guardar los cambios";
    }

    // Recargar datos
    header("Refresh: 1");
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mi Perfil</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
</head>
<body 
<?php include 'sidebar.php'; ?>

class="bg-gray-50">
    <!-- Navegación -->
    <nav class="bg-white shadow-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="text-xl font-bold text-gray-800">Préstamo Líder</span>
            </div>
        </div>
    </nav>

<div class="max-w-2xl mx-auto mt-10 bg-white p-8 rounded-xl shadow-lg">

    <h1 class="text-3xl font-bold mb-6 text-gray-800">Mi Perfil</h1>

    <?php if ($mensaje): ?>
    <div class="bg-green-100 text-green-700 p-3 rounded mb-4">
        <?= htmlspecialchars($mensaje) ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">

        <div>
            <label class="font-semibold text-gray-700">Nombre</label>
            <input type="text" name="nombre" value="<?= htmlspecialchars($usuario['nombre']) ?>"
                   class="w-full p-3 border rounded-lg">
        </div>

        <div>
            <label class="font-semibold text-gray-700">Apellido</label>
            <input type="text" name="apellido" value="<?= htmlspecialchars($usuario['apellido']) ?>"
                   class="w-full p-3 border rounded-lg">
        </div>

        <div>
            <label class="font-semibold text-gray-700">Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($usuario['email']) ?>"
                   class="w-full p-3 border rounded-lg">
        </div>

        <div>
            <label class="font-semibold text-gray-700">Teléfono</label>
            <input type="text" name="telefono" value="<?= htmlspecialchars($usuario['telefono']) ?>"
                   class="w-full p-3 border rounded-lg">
        </div>

        <?php if ($usuario['rol'] === "cliente"): ?>
        <hr class="my-4">

        <h2 class="text-xl font-bold text-gray-800">Datos del Cliente</h2>

        <div>
            <label class="font-semibold text-gray-700">DNI</label>
            <input type="text" name="dni" value="<?= htmlspecialchars($detalles['dni']) ?>"
                   class="w-full p-3 border rounded-lg">
        </div>

        <div>
            <label class="font-semibold text-gray-700">Dirección</label>
            <input type="text" name="direccion" value="<?= htmlspecialchars($detalles['direccion']) ?>"
                   class="w-full p-3 border rounded-lg">
        </div>

        <div>
            <label class="font-semibold text-gray-700">Ciudad</label>
            <input type="text" name="ciudad" value="<?= htmlspecialchars($detalles['ciudad']) ?>"
                   class="w-full p-3 border rounded-lg">
        </div>

        <div>
            <label class="font-semibold text-gray-700">Provincia</label>
            <input type="text" name="provincia" value="<?= htmlspecialchars($detalles['provincia']) ?>"
                   class="w-full p-3 border rounded-lg">
        </div>

        <div>
            <label class="font-semibold text-gray-700">Fecha de nacimiento</label>
            <input type="date" name="fecha_nacimiento" value="<?= htmlspecialchars($detalles['fecha_nacimiento']) ?>"
                   class="w-full p-3 border rounded-lg">
        </div>
        <?php endif; ?>

        <button class="w-full bg-blue-600 text-white py-3 rounded-lg font-bold hover:bg-blue-700">
            Guardar Cambios
        </button>

    </form>
</div>

</body>
</html>
