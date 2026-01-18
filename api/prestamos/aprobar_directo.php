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
    // Validar sesión admin
    if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'admin') {
        jsonResponse(false, 'No autorizado');
    }
    
    $admin_id = (int)$_SESSION['usuario_id'];
    
    // Conectar BD
    require_once __DIR__ . '/../../backend/connection.php';
    
    // Validar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Método no permitido');
    }
    
    // Obtener datos
    $input = json_decode(file_get_contents('php://input'), true);
    $prestamo_id = isset($input['prestamo_id']) ? (int)$input['prestamo_id'] : 0;
    
    if ($prestamo_id <= 0) {
        jsonResponse(false, 'ID de préstamo inválido');
    }
    
    // Verificar que el préstamo existe
    $stmt = $pdo->prepare("
        SELECT p.*, u.email as cliente_email, CONCAT(u.nombre, ' ', u.apellido) as cliente_nombre
        FROM prestamos p
        INNER JOIN usuarios u ON p.cliente_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$prestamo_id]);
    $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$prestamo) {
        jsonResponse(false, 'Préstamo no encontrado');
    }
    
    if (!in_array($prestamo['estado'], ['pendiente', 'en_evaluacion'])) {
        jsonResponse(false, 'El préstamo no está en un estado válido para aprobación');
    }
    
    // Aprobar préstamo con valores solicitados
    $pdo->beginTransaction();
    
    try {
        // Calcular fecha fin estimada según frecuencia
        $fecha_inicio = date('Y-m-d');
        $cuotas = $prestamo['cuotas_solicitadas'];
        $frecuencia = $prestamo['frecuencia_solicitada'];
        
        $meses = 0;
        switch ($frecuencia) {
            case 'diario':
                $meses = ceil($cuotas / 30);
                break;
            case 'semanal':
                $meses = ceil($cuotas / 4);
                break;
            case 'quincenal':
                $meses = ceil($cuotas / 2);
                break;
            case 'mensual':
            default:
                $meses = $cuotas;
                break;
        }
        
        $fecha_fin = date('Y-m-d', strtotime("+$meses months", strtotime($fecha_inicio)));
        
        // Actualizar préstamo a activo
        $stmt = $pdo->prepare("
            UPDATE prestamos SET
                monto = monto_solicitado,
                cuotas = cuotas_solicitadas,
                frecuencia_pago = frecuencia_solicitada,
                estado = 'activo',
                fecha_aprobacion = NOW(),
                fecha_inicio_prestamo = ?,
                fecha_fin_estimada = ?,
                asesor_id = ?
            WHERE id = ?
        ");
        
        $stmt->execute([$fecha_inicio, $fecha_fin, $admin_id, $prestamo_id]);
        
        // Registrar en historial
        $stmt = $pdo->prepare("
            INSERT INTO prestamos_historial (
                prestamo_id, estado_anterior, estado_nuevo, usuario_id, tipo_usuario, comentario
            ) VALUES (?, ?, 'activo', ?, 'admin', 'Préstamo aprobado directamente')
        ");
        $stmt->execute([$prestamo_id, $prestamo['estado'], $admin_id]);
        
        // Crear plan de pagos
        $monto_cuota = $prestamo['monto_total'] / $cuotas;
        $monto_capital = $prestamo['monto_solicitado'] / $cuotas;
        $monto_interes_cuota = ($prestamo['monto_total'] - $prestamo['monto_solicitado']) / $cuotas;
        
        $fecha_vencimiento = $fecha_inicio;
        
        for ($i = 1; $i <= $cuotas; $i++) {
            // Calcular siguiente fecha de vencimiento
            switch ($frecuencia) {
                case 'diario':
                    $fecha_vencimiento = date('Y-m-d', strtotime("+1 day", strtotime($fecha_vencimiento)));
                    break;
                case 'semanal':
                    $fecha_vencimiento = date('Y-m-d', strtotime("+7 days", strtotime($fecha_vencimiento)));
                    break;
                case 'quincenal':
                    $fecha_vencimiento = date('Y-m-d', strtotime("+15 days", strtotime($fecha_vencimiento)));
                    break;
                case 'mensual':
                default:
                    $fecha_vencimiento = date('Y-m-d', strtotime("+1 month", strtotime($fecha_vencimiento)));
                    break;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO prestamos_pagos (
                    prestamo_id, cuota_num, fecha_vencimiento, monto, monto_capital, monto_interes, estado
                ) VALUES (?, ?, ?, ?, ?, ?, 'pendiente')
            ");
            $stmt->execute([
                $prestamo_id,
                $i,
                $fecha_vencimiento,
                round($monto_cuota, 2),
                round($monto_capital, 2),
                round($monto_interes_cuota, 2)
            ]);
        }
        
        $pdo->commit();
        
        // Enviar notificación por email
        try {
            require_once __DIR__ . '/../../backend/emailservice.php';
            $emailService = new EmailService();
            
            $emailService->notificarPrestamoAprobado(
                $prestamo['cliente_id'],
                $prestamo_id,
                $prestamo['monto_solicitado'],
                $cuotas,
                $frecuencia
            );
            
        } catch (Exception $e) {
            logError("Error enviando email de aprobación: " . $e->getMessage());
        }
        
        jsonResponse(true, 'Préstamo aprobado exitosamente');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        logError("Error aprobando préstamo: " . $e->getMessage());
        jsonResponse(false, 'Error al aprobar el préstamo: ' . $e->getMessage());
    }
    
} catch (Exception $e) {
    logError("Error general: " . $e->getMessage());
    jsonResponse(false, 'Error del servidor');
}
