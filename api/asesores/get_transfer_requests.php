<?php
header("Content-Type: application/json");
require_once "../../backend/connection.php";

$asesor_id = intval($_GET['asesor_id'] ?? 0);

if ($asesor_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Asesor inválido"
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            t.id,
            t.chat_id,
            t.asesor_origen,
            u.nombre AS asesor_origen_nombre,
            c.cliente_id,
            uc.nombre AS cliente_nombre
        FROM chat_transferencias t
        INNER JOIN usuarios u ON u.id = t.asesor_origen
        INNER JOIN chats c ON c.id = t.chat_id
        INNER JOIN usuarios uc ON uc.id = c.cliente_id
        WHERE 
            t.asesor_destino = ?
            AND t.estado = 'pendiente'
        ORDER BY t.fecha ASC
    ");
    $stmt->execute([$asesor_id]);

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data" => $data
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => "Error interno"
    ]);
}
