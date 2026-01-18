<?php
/**
 * Health Check Endpoint
 * Verifica el estado del servidor y mantiene la sesión activa
 */

session_start();

// Headers para prevenir caching
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

// Validar que sea una petición AJAX
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Forbidden']));
}

try {
    // Verificar conexión a base de datos
    require_once __DIR__ . '/backend/connection.php';
    
    // Simple query para verificar DB
    $stmt = $pdo->query('SELECT 1');
    $stmt->fetch();
    
    // Verificar sesión
    $sessionActive = isset($_SESSION['cliente_id']) || isset($_SESSION['asesor_id']);
    
    // Responder con estado OK
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'status' => 'ok',
        'timestamp' => time(),
        'session_active' => $sessionActive
    ]);
    
} catch (Exception $e) {
    // Error en el servidor
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => 'Service temporarily unavailable'
    ]);
}