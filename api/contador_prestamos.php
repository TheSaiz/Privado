<?php
session_start();
require_once __DIR__ . '/../backend/connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    die(json_encode(['success' => false, 'message' => 'No autorizado']));
}

try {
    // Contar préstamos que requieren atención
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM prestamos 
        WHERE estado IN ('pendiente', 'en_revision', 'documentacion_completa')
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total' => (int)$result['total']
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener contadores'
    ]);
}