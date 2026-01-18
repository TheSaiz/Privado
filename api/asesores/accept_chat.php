<?php
// =====================================================
// accept_chat.php - PROD FINAL

// =====================================================

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../../backend/connection.php";

try {

    // ==================================================
    // VALIDAR MÉTODO
    // ==================================================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            "success" => false,
            "message" => "Método no permitido"
        ]);
        exit;
    }

    // ==================================================
    // LEER JSON
    // ==================================================
    $raw  = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "JSON inválido"
        ]);
        exit;
    }

    $chatId   = isset($data['chat_id'])   ? (int)$data['chat_id']   : 0;
    $asesorId = isset($data['asesor_id']) ? (int)$data['asesor_id'] : 0;

    if ($chatId <= 0 || $asesorId <= 0) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Datos incompletos"
        ]);
        exit;
    }

    // ==================================================
    // TRANSACCIÓN + LOCK DE FILA
    // ==================================================
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT 
            id,
            estado,
            asesor_id,
            es_referido
        FROM chats
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$chatId]);
    $chat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chat) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Chat inexistente"
        ]);
        exit;
    }

    $estadoActual     = $chat['estado'];
    $esReferido       = (int)($chat['es_referido'] ?? 0) === 1;
    $asesorAsignado   = $chat['asesor_id'];

    // ==================================================
    // VALIDAR ESTADO ÚNICO VÁLIDO
    // ==================================================
    if ($estadoActual !== 'esperando_asesor') {
        $pdo->rollBack();
        echo json_encode([
            "success" => false,
            "message" => "El chat ya no está disponible"
        ]);
        exit;
    }

    // ==================================================
    // 🔥 REFERIDOS: BLINDAJE TOTAL
    // ==================================================
    if ($esReferido) {

        // ❌ Referido sin asesor → error de datos
        if (empty($asesorAsignado)) {
            $pdo->rollBack();
            echo json_encode([
                "success" => false,
                "message" => "Referido sin asesor asignado"
            ]);
            exit;
        }

        // ❌ Referido de otro asesor
        if ((int)$asesorAsignado !== $asesorId) {
            $pdo->rollBack();
            echo json_encode([
                "success" => false,
                "message" => "Este chat pertenece a otro asesor"
            ]);
            exit;
        }

        // ✅ Es MI referido → puede aceptar
        error_log("⭐ Asesor $asesorId aceptando SU REFERIDO (chat $chatId)");
    }

    // ==================================================
    // ✅ ACEPTAR CHAT (UPDATE BLINDADO)
    // ==================================================
    $stmt = $pdo->prepare("
        UPDATE chats
        SET
            estado = 'en_conversacion',
            asesor_id = ?,
            fecha_asignacion = NOW()
        WHERE id = ?
          AND estado = 'esperando_asesor'
    ");
    $stmt->execute([$asesorId, $chatId]);

    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "El chat ya fue tomado por otro asesor"
        ]);
        exit;
    }

    $pdo->commit();

    // ==================================================
    // RESPUESTA OK
    // ==================================================
    echo json_encode([
        "success" => true,
        "message" => "Chat aceptado correctamente",
        "data" => [
            "chat_id"     => $chatId,
            "asesor_id"   => $asesorId,
            "es_referido" => $esReferido ? 1 : 0,
            "tipo"        => $esReferido ? "referido" : "normal"
        ]
    ]);

    error_log("✅ Chat $chatId aceptado por asesor $asesorId (" . ($esReferido ? "REFERIDO" : "NORMAL") . ")");
    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("❌ accept_chat.php ERROR: " . $e->getMessage());
    error_log($e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error interno del servidor"
    ]);
    exit;
}
