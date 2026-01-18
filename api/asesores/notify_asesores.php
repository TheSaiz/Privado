<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../../backend/connection.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método no permitido"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$chat_id = intval($data["chat_id"] ?? 0);

if (!$chat_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "chat_id es obligatorio"]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT departamento_id FROM chats WHERE id = ?");
    $stmt->execute([$chat_id]);
    $chat = $stmt->fetch();

    if (!$chat) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Chat no encontrado"]);
        exit;
    }

    $departamento_id = $chat["departamento_id"];

    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre 
        FROM asesores_departamentos ad
        INNER JOIN usuarios u ON ad.asesor_id = u.id
        WHERE ad.departamento_id = ? AND ad.disponible = 1
    ");
    $stmt->execute([$departamento_id]);
    $asesores = $stmt->fetchAll();

    echo json_encode([
        "success" => true,
        "message" => "Asesores notificados",
        "data" => [
            "departamento_id" => $departamento_id,
            "notificados" => $asesores
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al notificar asesores"
    ]);
}
?>