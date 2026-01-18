<?php
/*************************************************
 * api/contador_pagos_pendientes.php
 * Contador de comprobantes pendientes de revisión
 *************************************************/

header('Content-Type: application/json');
session_start();

// Validar sesión de administrador
if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'administrador') {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/../backend/connection.php';

try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as total
        FROM prestamos_pagos pp
        INNER JOIN prestamos p ON p.id = pp.prestamo_id
        WHERE pp.comprobante IS NOT NULL 
        AND pp.estado = 'pendiente'
        AND (pp.metodo_pago = 'Pendiente de revisión' OR pp.metodo_pago = 'En revisión')
    ");
    
    $result = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'total' => (int)$result['total']
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error al obtener contador'
    ]);
}