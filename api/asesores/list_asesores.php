<?php
header("Content-Type: application/json; charset=UTF-8");
require_once "../../backend/connection.php";

$asesor_actual = intval($_GET["asesor_id"] ?? 0);

if ($asesor_actual <= 0) {
    echo json_encode([
        "success" => true,
        "data" => []
    ]);
    exit;
}

try {

    $stmt = $pdo->prepare("
        SELECT 
            id,
            CONCAT(nombre, 
                   IF(apellido IS NOT NULL AND apellido != '', CONCAT(' ', apellido), '')
            ) AS nombre
        FROM usuarios
        WHERE rol = 'asesor'
          AND estado = 'activo'
          AND id != ?
        ORDER BY nombre ASC
    ");

    $stmt->execute([$asesor_actual]);

    echo json_encode([
        "success" => true,
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Throwable $e) {

    error_log("list_asesores ERROR: " . $e->getMessage());

    echo json_encode([
        "success" => false,
        "message" => "Error interno"
    ]);
}
