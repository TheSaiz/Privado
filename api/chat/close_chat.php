<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../../backend/connection.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método no permitido"]);
    exit;
}

$chat_id = intval($_POST['chat_id'] ?? 0);

if (!$chat_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "chat_id requerido"]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE chats 
        SET estado = 'cerrado', fecha_cierre = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$chat_id]);

    echo json_encode([
        "success" => true,
        "message" => "Chat cerrado correctamente"
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error al cerrar chat"]);
}
?>