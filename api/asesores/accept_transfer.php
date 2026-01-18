<?php
header("Content-Type: application/json; charset=UTF-8");
require_once "../../backend/connection.php";
session_start();

/**
 * =====================================================
 * ACEPTAR TRANSFERENCIA (PROD - DEFINITIVO)
 * =====================================================
 */

$asesor_id = intval($_SESSION['usuario_id'] ?? 0);
$data = json_decode(file_get_contents("php://input"), true);

$transfer_id = intval($data['transfer_id'] ?? 0);

if (!$asesor_id || !$transfer_id) {
    echo json_encode(["success" => false, "message" => "Datos inválidos"]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Obtener transferencia pendiente
    $stmt = $pdo->prepare("
        SELECT id, chat_id, asesor_destino
        FROM chat_transferencias
        WHERE id = ? AND estado = 'pendiente'
        FOR UPDATE
    ");
    $stmt->execute([$transfer_id]);
    $tr = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tr || intval($tr['asesor_destino']) !== $asesor_id) {
        throw new Exception("No autorizado para aceptar esta transferencia");
    }

    // Asignar chat al nuevo asesor
    $stmt = $pdo->prepare("
        UPDATE chats
        SET asesor_id = ?, ultima_lectura_asesor = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$asesor_id, $tr['chat_id']]);

    // Marcar transferencia como aceptada
    $stmt = $pdo->prepare("
        UPDATE chat_transferencias
        SET estado = 'aceptada', fecha_respuesta = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$transfer_id]);

    // Cancelar cualquier otra pendiente del mismo chat
    $stmt = $pdo->prepare("
        UPDATE chat_transferencias
        SET estado = 'cancelada'
        WHERE chat_id = ? AND estado = 'pendiente'
    ");
    $stmt->execute([$tr['chat_id']]);

    $pdo->commit();

    echo json_encode(["success" => true]);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log("❌ accept_transfer ERROR: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Error al aceptar transferencia"]);
}
