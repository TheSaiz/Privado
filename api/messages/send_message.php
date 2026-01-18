<?php
// =====================================================
// send_message.php (PROD FINAL - SEGURO)
// =====================================================

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../../backend/connection.php";

// =====================================================
// VALIDACIÓN MÉTODO
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
$chat_id = (int)($_POST["chat_id"] ?? 0);
$sender  = trim($_POST["sender"] ?? "");
$message = trim($_POST["message"] ?? "");

// =====================================================
// VALIDACIONES BÁSICAS
// =====================================================
if ($chat_id <= 0 || $sender === "" || $message === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Datos inválidos"
    ]);
    exit;
}

if (!in_array($sender, ["cliente", "asesor", "bot"], true)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Sender inválido"
    ]);
    exit;
}

try {
    // =====================================================
    // OBTENER ESTADO REAL DEL CHAT
    // =====================================================
    $stmt = $pdo->prepare("
        SELECT estado, asesor_id
        FROM chats
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$chat_id]);
    $chat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chat) {
        throw new Exception("Chat no encontrado");
    }

    // =====================================================
    // BLOQUEO SOLO DURANTE CHATBOT SIN ASESOR
    // =====================================================
    if ($chat["estado"] === "en_flujo" && empty($chat["asesor_id"])) {
        echo json_encode([
            "success" => true,
            "ignored" => true,
            "message" => "Mensaje ignorado durante flujo del bot"
        ]);
        exit;
    }

    // =====================================================
    // INSERTAR MENSAJE (CHAT HUMANO)
    // =====================================================
    $stmt = $pdo->prepare("
        INSERT INTO mensajes (chat_id, emisor, usuario_id, mensaje)
        VALUES (?, ?, NULL, ?)
    ");
    $stmt->execute([
        $chat_id,
        $sender,
        $message
    ]);

    $mensaje_id = (int)$pdo->lastInsertId();

    // =====================================================
    // MARCAR COMO NO LEÍDO PARA ASESOR
    // =====================================================
    if ($sender === "cliente") {
        $pdo->prepare("
            UPDATE chats
            SET ultima_lectura_asesor = NULL
            WHERE id = ?
        ")->execute([$chat_id]);
    }

    // =====================================================
    // RESPUESTA OK
    // =====================================================
    echo json_encode([
        "success"    => true,
        "mensaje_id"=> $mensaje_id
    ]);

} catch (Throwable $e) {

    error_log("❌ send_message.php ERROR: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error"   => "Error interno al enviar mensaje"
    ]);
}
