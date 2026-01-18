<?php
/**
 * API Actualizar Datos Usuario - Préstamo Líder
 * 
 * Ubicación: /system/api/app/actualizar_datos_usuario.php
 * Actualiza datos del usuario en clientes_detalles
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
    
    // Verificar que el cliente existe
    $checkStmt = $pdo->prepare("SELECT id FROM clientes_detalles WHERE dni = ? LIMIT 1");
    $checkStmt->execute([$dni]);
    
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'No se encontró el cliente'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Campos editables
    $camposPermitidos = [
        'nombre_completo',
        'telefono',
        'fecha_nacimiento',
        'tipo_ingreso',
        'monto_ingresos',
        'banco',
        'cbu',
        'calle',
        'numero',
        'localidad',
        'provincia',
        'codigo_postal',
        'contacto1_telefono',
        'contacto2_telefono'
    ];
    
    $updates = [];
    $values = [];
    
    foreach ($camposPermitidos as $campo) {
        if (isset($data[$campo])) {
            $valor = trim($data[$campo]);
            
            // Validaciones específicas
            if ($campo === 'fecha_nacimiento' && !empty($valor)) {
                // Convertir formato DD/MM/YYYY a YYYY-MM-DD
                $partes = explode('/', $valor);
                if (count($partes) === 3) {
                    $valor = $partes[2] . '-' . $partes[1] . '-' . $partes[0];
                }
            }
            
            if ($campo === 'cbu' && !empty($valor) && strlen($valor) !== 22) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'El CBU debe tener 22 dígitos'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $updates[] = "$campo = ?";
            $values[] = $valor;
        }
    }
    
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'No se proporcionaron campos para actualizar'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Agregar DNI al final para el WHERE
    $values[] = $dni;
    
    $sql = "UPDATE clientes_detalles SET " . implode(', ', $updates) . " WHERE dni = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Datos actualizados correctamente'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error en la base de datos'
    ], JSON_UNESCAPED_UNICODE);
    error_log('Error DB Actualizar Datos: ' . $e->getMessage());
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}