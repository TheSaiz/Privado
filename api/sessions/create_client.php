<?php
// /api/sessions/create_client.php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/utils.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, "Método no permitido", null, 405);
}

$nombre   = sanitize($_POST['nombre'] ?? '');
$dni      = sanitize($_POST['dni'] ?? '');
$telefono = sanitize($_POST['telefono'] ?? '');

if (!$nombre || !$dni || !$telefono) {
    response(false, "Faltan datos", null, 400);
}

// Email temporal (igual que otros flujos)
$fakeEmail = $dni . "@temp.chat";

// 👉 NO se genera password
$ins = $conn->prepare("
    INSERT INTO usuarios (
        nombre,
        email,
        telefono,
        password,
        rol,
        must_reset_password
    ) VALUES (?, ?, ?, NULL, 'cliente', 1)
");

$ins->bind_param("sss", $nombre, $fakeEmail, $telefono);

if (!$ins->execute()) {
    response(false, "Error creando cliente", null, 500);
}

$response_id = $ins->insert_id;

response(true, "Cliente creado", [
    "cliente_id" => $response_id
]);
