<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once "../../backend/connection.php";
session_start();

/**
 * =====================================================
 * SOLICITAR TRANSFERENCIA DE CHAT (PROD)
 * =====================================================
 */

// ID asesor logueado (compat: sistemas con asesor_id o usuario_id)
$asesor_origen = (int)($_SESSION['asesor_id'] ?? ($_SESSION['usuario_id'] ?? 0));

$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

$chat_id        = (int)($data["chat_id"] ?? 0);
$asesor_destino = (int)($data["asesor_destino"] ?? 0);

if ($asesor_origen <= 0) {
    echo json_encode(["success" => false, "message" => "No autenticado"]);
    exit;
}
if ($chat_id <= 0 || $asesor_destino <= 0) {
    echo json_encode(["success" => false, "message" => "Datos incompletos"]);
    exit;
}
if ($asesor_destino === $asesor_origen) {
    echo json_encode(["success" => false, "message" => "No podés transferirte el chat a vos mismo"]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Lock del chat para evitar carreras
    $stmt = $pdo->prepare("
        SELECT id, asesor_id, estado
        FROM chats
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$chat_id]);
    $chat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chat) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Chat inexistente"]);
        exit;
    }

    // Validar que el chat pertenece al asesor origen (si tu modelo asigna dueño)
    // Si en tu sistema hay chats sin asesor_id asignado, podés adaptar esto.
    $chat_asesor = (int)($chat['asesor_id'] ?? 0);
    if ($chat_asesor > 0 && $chat_asesor !== $asesor_origen) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "No tenés permiso para transferir este chat"]);
        exit;
    }

    // Evitar duplicados pendientes
    // (Asumo tabla chat_transferencias con columnas: id, chat_id, asesor_origen, asesor_destino, estado, fecha_solicitud, fecha_aceptacion)
    $stmt = $pdo->prepare("
        SELECT id
        FROM chat_transferencias
        WHERE chat_id = ?
          AND estado = 'pendiente'
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$chat_id]);
    $pendiente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pendiente) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Ya existe una transferencia pendiente para este chat"]);
        exit;
    }

    // Crear solicitud de transferencia
    $stmt = $pdo->prepare("
        INSERT INTO chat_transferencias
            (chat_id, asesor_origen, asesor_destino, estado, fecha_solicitud)
        VALUES
            (?, ?, ?, 'pendiente', NOW())
    ");
    $stmt->execute([$chat_id, $asesor_origen, $asesor_destino]);

    // Marcar chat como transferencia para ocultarlo del asesor A en el panel (si lista solo activos)
    // NO cambiamos asesor_id acá (pasa recién cuando acepta B)
    $stmt = $pdo->prepare("
        UPDATE chats
        SET estado = 'transferencia'
        WHERE id = ?
    ");
    $stmt->execute([$chat_id]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Transferencia solicitada"
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("❌ transfer_chat ERROR: " . $e->getMessage());

    echo json_encode([
        "success" => false,
        "message" => "Error interno"
    ]);
}
