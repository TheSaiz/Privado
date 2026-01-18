<?php
// =====================================================
// mark_waiting_asesor.php (PROD - ULTRA BLINDADO)

// =====================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../../backend/connection.php";

// =====================================================
// VALIDAR MÉTODO
// =====================================================
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Método no permitido"
    ]);
    exit;
}

// =====================================================
// INPUT
// =====================================================
$input = json_decode(file_get_contents("php://input"), true);
$chat_id = intval($input["chat_id"] ?? 0);

if ($chat_id <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "chat_id inválido"
    ]);
    exit;
}

try {

    // =====================================================
    // 1) OBTENER CHAT
    // =====================================================
    $stmt = $pdo->prepare("
        SELECT id, estado
        FROM chats
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$chat_id]);
    $chat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chat) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Chat no encontrado"
        ]);
        exit;
    }

    // =====================================================
    // 2) SI YA ESTÁ FUERA DEL CHATBOT → OK (IDEMPOTENTE)
    // =====================================================
    if (in_array($chat["estado"], [
        "esperando_asesor",
        "en_conversacion",
        "cerrado"
    ], true)) {

        echo json_encode([
            "success" => true,
            "message" => "El chat ya fue finalizado",
            "data" => [
                "chat_id" => $chat_id,
                "estado"  => $chat["estado"]
            ]
        ]);
        exit;
    }

    // =====================================================
    // 3) VALIDAR ESTADO PERMITIDO DE ORIGEN
    // =====================================================
    if (!in_array($chat["estado"], ["pendiente", "en_flujo"], true)) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Estado inválido para finalizar chatbot",
            "data" => [
                "chat_id" => $chat_id,
                "estado"  => $chat["estado"]
            ]
        ]);
        exit;
    }

    $pdo->beginTransaction();

    // =====================================================
    // 4) CAMBIO DE ESTADO ATÓMICO (BLINDADO)
    // =====================================================
    $stmt = $pdo->prepare("
        UPDATE chats
        SET estado = 'esperando_asesor'
        WHERE id = ?
          AND estado IN ('pendiente', 'en_flujo')
    ");
    $stmt->execute([$chat_id]);

    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "No se pudo actualizar el estado del chat"
        ]);
        exit;
    }

    // =====================================================
    // 5) MENSAJE FINAL DEL BOT (UNA SOLA VEZ)
    // =====================================================
    $stmt = $pdo->prepare("
        INSERT INTO mensajes (chat_id, emisor, mensaje)
        VALUES (?, 'bot', '👨‍💼 Un asesor se comunicará contigo en breve')
    ");
    $stmt->execute([$chat_id]);

    // =====================================================
    // 6) FORZAR ACTUALIZACIÓN DE TIMESTAMP
    // =====================================================
    $stmt = $pdo->prepare("
        UPDATE chats
        SET fecha_inicio = fecha_inicio
        WHERE id = ?
    ");
    $stmt->execute([$chat_id]);

    $pdo->commit();

    // =====================================================
    // 7) OK
    // =====================================================
    echo json_encode([
        "success" => true,
        "message" => "Chat enviado a asesores",
        "data" => [
            "chat_id"        => $chat_id,
            "estado"         => "esperando_asesor",
            "chatbot_activo" => false
        ]
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("❌ mark_waiting_asesor.php ERROR: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error interno al finalizar el chat"
    ]);
}