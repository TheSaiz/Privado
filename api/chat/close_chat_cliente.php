<?php
/**
 * close_chat_cliente.php - Cerrar conversación desde el lado del CLIENTE
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

$data = json_decode(file_get_contents("php://input"), true);
$chat_id = intval($data["chat_id"] ?? 0);
$cliente_id = intval($data["cliente_id"] ?? 0);

if (!$chat_id) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "chat_id es obligatorio"
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // =====================================================
    // OBTENER INFO DEL CHAT Y VERIFICAR PERTENENCIA
    // =====================================================
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.estado,
            c.cliente_id,
            u.nombre AS cliente_nombre
        FROM chats c
        INNER JOIN usuarios u ON c.cliente_id = u.id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$chat_id]);
    $chat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chat) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Chat no encontrado"
        ]);
        exit;
    }

    // Verificar que el chat pertenece al cliente (si se proporcionó cliente_id)
    if ($cliente_id > 0 && $chat['cliente_id'] != $cliente_id) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "No autorizado para cerrar este chat"
        ]);
        exit;
    }

    // =====================================================
    // CERRAR CHAT
    // =====================================================
    $stmt = $pdo->prepare("
        UPDATE chats 
        SET estado = 'cerrado_cliente',
            fecha_fin = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$chat_id]);

    // =====================================================
    // 🔥 MENSAJE VISIBLE EN EL CHAT
    // =====================================================
    $mensaje_cierre = "❌ Conversación cerrada por {$chat['cliente_nombre']}";
    
    $stmt = $pdo->prepare("
        INSERT INTO mensajes (chat_id, emisor, mensaje, fecha)
        VALUES (?, 'sistema', ?, NOW())
    ");
    $stmt->execute([$chat_id, $mensaje_cierre]);

    $pdo->commit();

    // =====================================================
    // RESPUESTA EXITOSA
    // =====================================================
    echo json_encode([
        "success" => true,
        "message" => "Conversación cerrada correctamente"
    ]);

    error_log("✅ Chat cerrado por cliente: chat_id=$chat_id, cliente={$chat['cliente_nombre']}");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("❌ close_chat_cliente.php ERROR: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al cerrar la conversación"
    ]);
}