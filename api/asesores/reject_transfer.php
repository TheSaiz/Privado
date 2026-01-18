<?php
header("Content-Type: application/json");
require_once "../../backend/connection.php";
session_start();

$asesor_id = $_SESSION['asesor_id'] ?? 0;
$data = json_decode(file_get_contents("php://input"), true);

$transfer_id = intval($data['transfer_id'] ?? 0);

if (!$transfer_id || !$asesor_id) {
    echo json_encode(["success" => false]);
    exit;
}

$stmt = $pdo->prepare("
    UPDATE chat_transferencias
    SET estado = 'rechazada', fecha_respuesta = NOW()
    WHERE id = ? AND asesor_destino = ?
");

$stmt->execute([$transfer_id, $asesor_id]);

echo json_encode(["success" => true]);
