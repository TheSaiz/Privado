<?php
/**
 * API Obtener Datos Usuario - Préstamo Líder
 * 
 * Ubicación: /system/api/app/obtener_datos_usuario.php
 * Devuelve todos los datos del usuario desde clientes_detalles
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../backend/connection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método no permitido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido');
    }
    
    $dni = trim($data['dni'] ?? '');
    
    if (empty($dni)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'DNI es requerido'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Obtener datos de clientes_detalles
    $stmt = $pdo->prepare("
        SELECT 
            dni,
            cuit,
            nombre_completo,
            telefono,
            fecha_nacimiento,
            tipo_ingreso,
            monto_ingresos,
            banco,
            cbu,
            calle,
            numero,
            localidad,
            provincia,
            codigo_postal,
            contacto1_telefono,
            contacto2_telefono,
            estado_validacion
        FROM clientes_detalles
        WHERE dni = ?
        LIMIT 1
    ");
    
    $stmt->execute([$dni]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cliente) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'No se encontraron datos para este DNI'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Formatear fecha de nacimiento
    if ($cliente['fecha_nacimiento']) {
        $fecha = new DateTime($cliente['fecha_nacimiento']);
        $cliente['fecha_nacimiento'] = $fecha->format('d/m/Y');
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Datos obtenidos correctamente',
        'data' => $cliente
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error en la base de datos'
    ], JSON_UNESCAPED_UNICODE);
    error_log('Error DB Obtener Datos: ' . $e->getMessage());
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}