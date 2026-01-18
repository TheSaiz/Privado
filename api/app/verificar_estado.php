<?php
/**
 * API Verificar Estado - Préstamo Líder
 * 
 * Ubicación: /system/api/app/verificar_estado.php
 * Verifica el estado de validación de un DNI específico
 */

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../backend/connection.php';

function respond($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, "Método no permitido");
    }
    
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    
    if (!isset($data['dni']) || empty($data['dni'])) {
        respond(false, "DNI es obligatorio");
    }
    
    $dni = $data['dni'];
    
    $stmt = $conn->prepare("
        SELECT 
            id,
            dni,
            nombre_completo,
            estado_validacion,
            motivo_rechazo
        FROM clientes_detalles 
        WHERE dni = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    
    $stmt->execute([$dni]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cliente) {
        respond(false, "No se encontró solicitud para este DNI");
    }
    
    respond(true, "Estado obtenido correctamente", $cliente);
    
} catch (Exception $e) {
    error_log("Error verificando estado: " . $e->getMessage());
    respond(false, "Error del servidor: " . $e->getMessage());
}