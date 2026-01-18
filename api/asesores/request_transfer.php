<?php
header("Content-Type: application/json; charset=UTF-8");
require_once "../../backend/connection.php";
session_start();

/**
 * =====================================================
 * SOLICITAR TRANSFERENCIA (PROD - COMPATIBLE)
 * =====================================================
 */

$asesor_origen = intval($_SESSION['usuario_id'] ?? 0);
$data = json_decode(file_get_contents("php://input"), true);

$chat_id         = intval($data['chat_id'] ?? 0);
$asesor_destino  = intval($data['asesor_destino'] ?? 0);

if (!$asesor_origen || !$chat_id || !$asesor_destino) {
    echo json_encode(["success" => false, "message" => "Datos incompletos"]);
    exit;
}

if ($asesor_origen === $asesor_destino) {
    echo json_encode(["success" => false, "message" => "No podés transferirte el chat a vos mismo"]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Verificar que el chat pertenece al asesor origen
    $stmt = $pdo->prepare("
        SELECT id 
        FROM chats 
        WHERE id = ? AND asesor_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$chat_id, $asesor_origen]);

    if (!$stmt->fetch()) {
        throw new Exception("No autorizado para transferir este chat");
    }

    // Evitar duplicados
    $stmt = $pdo->prepare("
        SELECT id 
        FROM chat_transferencias
        WHERE chat_id = ? AND estado = 'pendiente'
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$chat_id]);

    if ($stmt->fetch()) {
        throw new Exception("Ya existe una transferencia pendiente");
    }

    // Crear solicitud
    $stmt = $pdo->prepare("
        INSERT INTO chat_transferencias
        (chat_id, asesor_origen, asesor_destino, estado, fecha)
        VALUES (?, ?, ?, 'pendiente', NOW())
    ");
    $stmt->execute([$chat_id, $asesor_origen, $asesor_destino]);

    $pdo->commit();

    echo json_encode(["success" => true]);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log("❌ request_transfer ERROR: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
