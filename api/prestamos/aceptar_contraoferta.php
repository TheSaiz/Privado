<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

function logError($msg) {
    $log = __DIR__ . '/../../logs/api_errors.log';
    @mkdir(dirname($log), 0755, true);
    @file_put_contents($log, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

function jsonResponse($success, $message, $data = null) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Validar sesión cliente
    if (!isset($_SESSION['cliente_id'])) {
        jsonResponse(false, 'Sesión no válida');
    }
    
    $cliente_id = (int)$_SESSION['cliente_id'];
    
    // Conectar BD
    require_once __DIR__ . '/../../backend/connection.php';
    
    // Validar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Método no permitido');
    }
    
    // Obtener datos
    $input = json_decode(file_get_contents('php://input'), true);
    $tipo = $input['tipo'] ?? 'prestamo';
    $operacion_id = (int)($input[$tipo . '_id'] ?? 0);
    
    if ($operacion_id <= 0) {
        jsonResponse(false, 'ID de operación inválido');
    }
    
    // Determinar tabla según tipo
    $tabla = match($tipo) {
        'prestamo' => 'prestamos',
        'empeno' => 'empenos',
        'prendario' => 'creditos_prendarios',
        default => null
    };
    
    if (!$tabla) {
        jsonResponse(false, 'Tipo de operación no válido');
    }
    
    // Verificar que la operación existe y pertenece al cliente
    $stmt = $pdo->prepare("
        SELECT op.*, u.email as cliente_email, CONCAT(u.nombre, ' ', u.apellido) as cliente_nombre
        FROM $tabla op
        INNER JOIN usuarios u ON op.cliente_id = u.id
        WHERE op.id = ? AND op.cliente_id = ?
    ");
    $stmt->execute([$operacion_id, $cliente_id]);
    $operacion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$operacion) {
        jsonResponse(false, ucfirst($tipo) . ' no encontrado');
    }
    
    if ($operacion['estado'] !== 'contraoferta') {
        jsonResponse(false, 'Esta operación no tiene una contraoferta pendiente');
    }
    
    // Aceptar contraoferta
    $pdo->beginTransaction();
    
    try {
        
        // PRÉSTAMO - Genera plan de pagos
        if ($tipo === 'prestamo') {
            
            // Calcular fecha fin estimada
            $fecha_inicio = date('Y-m-d');
            $cuotas = $operacion['cuotas_ofrecidas'];
            $frecuencia = $operacion['frecuencia_ofrecida'];
            
            $meses = match($frecuencia) {
                'diario' => ceil($cuotas / 30),
                'semanal' => ceil($cuotas / 4),
                'quincenal' => ceil($cuotas / 2),
                default => $cuotas
            };
            
            $fecha_fin = date('Y-m-d', strtotime("+$meses months"));
            
            // Actualizar préstamo a activo con valores de contraoferta
            $stmt = $pdo->prepare("
                UPDATE prestamos SET
                    monto = monto_ofrecido,
                    cuotas = cuotas_ofrecidas,
                    frecuencia_pago = frecuencia_ofrecida,
                    tasa_interes = tasa_interes_ofrecida,
                    monto_cuota = monto_cuota_ofrecido,
                    monto_total = monto_total_ofrecido,
                    estado = 'activo',
                    fecha_aceptacion = NOW(),
                    fecha_inicio_prestamo = ?,
                    fecha_fin_estimada = ?
                WHERE id = ?
            ");
            
            $stmt->execute([$fecha_inicio, $fecha_fin, $operacion_id]);
            
            // Crear plan de pagos
            $monto_cuota = $operacion['monto_cuota_ofrecido'] ?? ($operacion['monto_total_ofrecido'] / $cuotas);
            $monto_capital = $operacion['monto_ofrecido'] / $cuotas;
            $monto_interes_cuota = ($operacion['monto_total_ofrecido'] - $operacion['monto_ofrecido']) / $cuotas;
            
            $fecha_vencimiento = $fecha_inicio;
            
            for ($i = 1; $i <= $cuotas; $i++) {
                // Calcular siguiente fecha de vencimiento
                $fecha_vencimiento = match($frecuencia) {
                    'diario' => date('Y-m-d', strtotime("+1 day", strtotime($fecha_vencimiento))),
                    'semanal' => date('Y-m-d', strtotime("+7 days", strtotime($fecha_vencimiento))),
                    'quincenal' => date('Y-m-d', strtotime("+15 days", strtotime($fecha_vencimiento))),
                    default => date('Y-m-d', strtotime("+1 month", strtotime($fecha_vencimiento)))
                };
                
                $stmt = $pdo->prepare("
                    INSERT INTO prestamos_pagos (
                        prestamo_id, cuota_num, fecha_vencimiento, monto, monto_capital, monto_interes, estado
                    ) VALUES (?, ?, ?, ?, ?, ?, 'pendiente')
                ");
                $stmt->execute([
                    $operacion_id,
                    $i,
                    $fecha_vencimiento,
                    round($monto_cuota, 2),
                    round($monto_capital, 2),
                    round($monto_interes_cuota, 2)
                ]);
            }
            
        }
        
        // EMPEÑO - Solo actualiza estado y monto
        elseif ($tipo === 'empeno') {
            
            $stmt = $pdo->prepare("
                UPDATE empenos SET
                    monto_prestado = monto_ofrecido,
                    estado = 'activo',
                    fecha_aceptacion = NOW(),
                    fecha_inicio = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$operacion_id]);
        }
        
        // PRENDARIO - Solo actualiza estado y monto
        elseif ($tipo === 'prendario') {
            
            $stmt = $pdo->prepare("
                UPDATE creditos_prendarios SET
                    monto_prestado = monto_ofrecido,
                    estado = 'activo',
                    fecha_aceptacion = NOW(),
                    fecha_inicio = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$operacion_id]);
        }
        
        $pdo->commit();
        
        logError("Contraoferta aceptada - Tipo: $tipo, ID: $operacion_id, Cliente: {$operacion['cliente_nombre']}");
        
        jsonResponse(true, 'Contraoferta aceptada. Tu operación está ahora activa.');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        logError("Error aceptando contraoferta: " . $e->getMessage());
        jsonResponse(false, 'Error al aceptar la contraoferta: ' . $e->getMessage());
    }
    
} catch (Exception $e) {
    logError("Error general: " . $e->getMessage());
    jsonResponse(false, 'Error del servidor');
}