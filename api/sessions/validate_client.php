<?php
// /api/sessions/validate_client.php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

$dni = $_GET['dni'] ?? '';
if (!$dni) response(false, "dni requerido", null, 400);

// buscar usuario por email fake o telefono
$fakeEmail = $dni . "@temp.chat";
$stmt = $conn->prepare("SELECT id, nombre, email FROM usuarios WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $fakeEmail);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) response(false, "Cliente no encontrado", null, 404);

$user = $res->fetch_assoc();
response(true, "Cliente válido", $user);
?>
