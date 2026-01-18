<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once "../../backend/connection.php";

// Validación del asesor_id
$asesor_id = intval($_GET['asesor_id'] ?? 0);

if ($asesor_id <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "asesor_id requerido"
    ]);
    exit;
}

try {
    // Obtener todos los chats asignados a este asesor + mensajes nuevos
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            (
                SELECT COUNT(*) 
                FROM mensajes 
                WHERE chat_id = c.id
                  AND emisor = 'cliente'
                  AND fecha > COALESCE(c.ultima_lectura_asesor, '1970-01-01')
            ) AS mensajes_nuevos
        FROM chats c
        WHERE c.asesor_id = ?
        ORDER BY c.fecha_inicio DESC
    ");

    $stmt->execute([$asesor_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data" => $rows
    ]);

} catch (PDOException $e) {
    error_log("Error en get_chats_status: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al obtener estado de chats"
    ]);
}