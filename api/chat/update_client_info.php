<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../../backend/connection.php";

session_start();

// Verificar que sea asesor
if (!isset($_SESSION['asesor_id'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "No autorizado"]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método no permitido"]);
    exit;
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "JSON inválido"]);
    exit;
}

$chat_id = intval($data["chat_id"] ?? 0);
$nombre = trim($data["nombre"] ?? "");
$telefono = trim($data["telefono"] ?? "");
$email = trim($data["email"] ?? "");

if (!$chat_id || !$nombre || !$telefono || !$email) {
    http_response_code(400);
    echo json_encode([
        "success" => false, 
        "message" => "Datos incompletos",
        "debug" => [
            "chat_id" => $chat_id,
            "nombre" => $nombre,
            "telefono" => $telefono,
            "email" => $email
        ]
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Obtener cliente_id del chat
    $stmt = $pdo->prepare("SELECT cliente_id FROM chats WHERE id = ?");
    $stmt->execute([$chat_id]);
    $chat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chat) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Chat no encontrado"]);
        exit;
    }

    $cliente_id = $chat['cliente_id'];

    // Actualizar datos en tabla usuarios
    $stmt = $pdo->prepare("
        UPDATE usuarios 
        SET nombre = ?, telefono = ?, email = ?
        WHERE id = ?
    ");
    $stmt->execute([$nombre, $telefono, $email, $cliente_id]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Datos actualizados correctamente",
        "data" => [
            "nombre" => $nombre,
            "email" => $email,
            "telefono" => $telefono
        ]
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error actualizando datos del cliente: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al actualizar los datos: " . $e->getMessage()
    ]);
}
?>