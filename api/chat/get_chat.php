<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../../backend/connection.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método no permitido"]);
    exit;
}

$chat_id = intval($_GET['chat_id'] ?? 0);

if (!$chat_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "chat_id es obligatorio"]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT c.*, u.nombre as cliente_nombre, u.telefono as cliente_telefono, 
               d.nombre as departamento_nombre
        FROM chats c
        LEFT JOIN usuarios u ON c.cliente_id = u.id
        LEFT JOIN departamentos d ON c.departamento_id = d.id
        WHERE c.id = ?
    ");
    $stmt->execute([$chat_id]);
    $chat = $stmt->fetch();

    if (!$chat) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Chat no encontrado"]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "data" => $chat
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al obtener chat"
    ]);
}
?>