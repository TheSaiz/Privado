<?php
header("Content-Type: application/json");
require_once __DIR__ . "/../../backend/connection.php";

session_start();

if (!isset($_SESSION['asesor_id'])) {
    echo json_encode([
        "success" => false,
        "message" => "Asesor no logueado"
    ]);
    exit;
}

$asesor_id = $_SESSION['asesor_id'];

try {
    // Datos del asesor
    $stmt = $pdo->prepare("
        SELECT a.id, a.nombre, a.estado, d.nombre AS departamento
        FROM asesores a
        LEFT JOIN departamentos d ON a.departamento_id = d.id
        WHERE a.id = ?
    ");
    $stmt->execute([$asesor_id]);
    $asesor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asesor) {
        echo json_encode(["success" => false, "message" => "Asesor no encontrado"]);
        exit;
    }

    // Chats activos
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM chats 
        WHERE asesor_id = ? AND estado = 'en_conversacion'
    ");
    $stmt->execute([$asesor_id]);
    $asesor["chats_activos"] = (int)$stmt->fetchColumn();

    echo json_encode([
        "success" => true,
        "data" => $asesor
    ]);

} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Error interno"]);
}
