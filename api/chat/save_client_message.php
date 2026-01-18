<?php
/**
 * save_client_message.php
 * Guarda mensajes del cliente durante el flujo del chatbot
 */

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../../backend/connection.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Método no permitido"
    ]);
    exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "JSON inválido"
    ]);
    exit;
}

$chat_id = intval($data['chat_id'] ?? 0);
$mensaje = trim($data['mensaje'] ?? '');

if (!$chat_id || empty($mensaje)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "chat_id y mensaje son requeridos"
    ]);
    exit;
}

try {
    // Insertar mensaje del cliente
    $stmt = $pdo->prepare("
        INSERT INTO mensajes (chat_id, emisor, mensaje, fecha)
        VALUES (?, 'cliente', ?, NOW())
    ");
    
    $stmt->execute([$chat_id, $mensaje]);
    
    echo json_encode([
        "success" => true,
        "message" => "Mensaje guardado",
        "data" => [
            "mensaje_id" => $pdo->lastInsertId()
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("Error guardando mensaje cliente: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al guardar mensaje"
    ]);
}