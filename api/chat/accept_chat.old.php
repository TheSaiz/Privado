<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/utils.php';

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, "Método no permitido", null, 405);
}

// Leer JSON correctamente
$input = json_decode(file_get_contents("php://input"), true);

$chat_id   = intval($input['chat_id'] ?? 0);
$asesor_id = intval($input['asesor_id'] ?? 0);

if (!$chat_id || !$asesor_id) {
    response(false, "Faltan parámetros", null, 400);
}

// Iniciar transacción para evitar race condition
$conn->begin_transaction();

// Verificar que el chat siga esperando asesor
$chk = $conn->prepare("
    SELECT estado 
    FROM chats 
    WHERE id = ? 
    FOR UPDATE
");
$chk->bind_param("i", $chat_id);
$chk->execute();
$r = $chk->get_result();

if ($r->num_rows === 0) {
    $conn->rollback();
    response(false, "Chat no encontrado", null, 404);
}

$row = $r->fetch_assoc();

// ===============================
// VALIDACIÓN CORRECTA DE ESTADO
// ===============================
// Aceptar tanto chats creados por chatbot como chats pendientes del panel
$estados_permitidos = ['esperando_asesor', 'pendiente'];

if (!in_array($row['estado'], $estados_permitidos)) {
    $conn->rollback();
    response(false, "El chat ya fue tomado por otro asesor", null, 409);
}


// Asignar asesor y cambiar estado
$u = $conn->prepare("
    UPDATE chats 
    SET asesor_id = ?, estado = 'en_conversacion' 
    WHERE id = ?
");
$u->bind_param("ii", $asesor_id, $chat_id);

if (!$u->execute()) {
    $conn->rollback();
    response(false, "Error asignando asesor", null, 500);
}

// Marcar asesor como NO disponible
$upd2 = $conn->prepare("
    UPDATE asesores_departamentos 
    SET disponible = 0 
    WHERE asesor_id = ?
");
$upd2->bind_param("i", $asesor_id);
$upd2->execute();

$conn->commit();

response(true, "Chat asignado al asesor", [
    "chat_id" => $chat_id,
    "asesor_id" => $asesor_id
]);
?>