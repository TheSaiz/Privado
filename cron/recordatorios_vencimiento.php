<?php
/**
 * CRON JOB: Recordatorios de Vencimiento de Cuotas
 * Ejecutar diariamente a las 9:00 AM
 * 
 * Configurar en crontab:
 * 0 9 * * * /usr/bin/php /ruta/al/sistema/cron/recordatorios_vencimiento.php
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Log
$logFile = __DIR__ . '/../logs/cron_recordatorios.log';
function logMsg($msg) {
    global $logFile;
    @mkdir(dirname($logFile), 0755, true);
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}

logMsg("===== INICIO CRON RECORDATORIOS =====");

try {
    // Conectar BD
    require_once __DIR__ . '/../backend/connection.php';
    require_once __DIR__ . '/../backend/emailservice.php';
    
    $emailService = new EmailService();
    $hoy = date('Y-m-d');
    $enviados = 0;
    $errores = 0;
    
    // === 1. CUOTAS QUE VENCEN HOY ===
    logMsg("Buscando cuotas que vencen hoy...");
    
    $stmt = $pdo->prepare("
        SELECT 
            pp.id as pago_id,
            pp.prestamo_id,
            pp.cuota_num,
            pp.fecha_vencimiento,
            pp.monto,
            p.cliente_id,
            u.email as cliente_email,
            CONCAT(u.nombre, ' ', u.apellido) as cliente_nombre
        FROM prestamos_pagos pp
        INNER JOIN prestamos p ON pp.prestamo_id = p.id
        INNER JOIN usuarios u ON p.cliente_id = u.id
        WHERE pp.fecha_vencimiento = ?
          AND pp.estado = 'pendiente'
          AND p.estado = 'activo'
    ");
    $stmt->execute([$hoy]);
    $cuotas_hoy = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    logMsg("Encontradas " . count($cuotas_hoy) . " cuotas que vencen hoy");
    
    foreach ($cuotas_hoy as $cuota) {
        try {
            $emailService->recordatorioVenceHoy(
                $cuota['cliente_id'],
                $cuota['prestamo_id'],
                $cuota['cuota_num'],
                $cuota['monto'],
                $cuota['fecha_vencimiento']
            );
            $enviados++;
            logMsg("✅ Email enviado a {$cuota['cliente_email']} (Préstamo #{$cuota['prestamo_id']}, Cuota #{$cuota['cuota_num']})");
        } catch (Exception $e) {
            $errores++;
            logMsg("❌ Error enviando a {$cuota['cliente_email']}: " . $e->getMessage());
        }
    }
    
    // === 2. CUOTAS QUE VENCEN EN 3 DÍAS ===
    logMsg("Buscando cuotas que vencen en 3 días...");
    
    $fecha_3dias = date('Y-m-d', strtotime('+3 days'));
    
    $stmt = $pdo->prepare("
        SELECT 
            pp.id as pago_id,
            pp.prestamo_id,
            pp.cuota_num,
            pp.fecha_vencimiento,
            pp.monto,
            p.cliente_id,
            u.email as cliente_email,
            CONCAT(u.nombre, ' ', u.apellido) as cliente_nombre
        FROM prestamos_pagos pp
        INNER JOIN prestamos p ON pp.prestamo_id = p.id
        INNER JOIN usuarios u ON p.cliente_id = u.id
        WHERE pp.fecha_vencimiento = ?
          AND pp.estado = 'pendiente'
          AND p.estado = 'activo'
    ");
    $stmt->execute([$fecha_3dias]);
    $cuotas_3dias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    logMsg("Encontradas " . count($cuotas_3dias) . " cuotas que vencen en 3 días");
    
    foreach ($cuotas_3dias as $cuota) {
        try {
            $emailService->recordatorioProximoVencimiento(
                $cuota['cliente_id'],
                $cuota['prestamo_id'],
                $cuota['cuota_num'],
                $cuota['monto'],
                $cuota['fecha_vencimiento']
            );
            $enviados++;
            logMsg("✅ Email enviado a {$cuota['cliente_email']} (Préstamo #{$cuota['prestamo_id']}, Cuota #{$cuota['cuota_num']})");
        } catch (Exception $e) {
            $errores++;
            logMsg("❌ Error enviando a {$cuota['cliente_email']}: " . $e->getMessage());
        }
    }
    
    // === 3. CUOTAS VENCIDAS (ACTUALIZAR ESTADO Y CALCULAR MORA) ===
    logMsg("Actualizando cuotas vencidas...");
    
    // Obtener configuración de mora
    $stmt = $pdo->query("SELECT valor FROM prestamos_config WHERE clave = 'mora_diaria'");
    $mora_diaria = (float)$stmt->fetchColumn();
    if (!$mora_diaria) {
        $mora_diaria = 2.0; // Default
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            pp.id as pago_id,
            pp.prestamo_id,
            pp.cuota_num,
            pp.fecha_vencimiento,
            pp.monto,
            pp.mora_dias as mora_dias_actual,
            DATEDIFF(?, pp.fecha_vencimiento) as dias_vencido,
            p.cliente_id,
            u.email as cliente_email,
            CONCAT(u.nombre, ' ', u.apellido) as cliente_nombre
        FROM prestamos_pagos pp
        INNER JOIN prestamos p ON pp.prestamo_id = p.id
        INNER JOIN usuarios u ON p.cliente_id = u.id
        WHERE pp.fecha_vencimiento < ?
          AND pp.estado IN ('pendiente', 'vencido', 'mora')
          AND p.estado = 'activo'
    ");
    $stmt->execute([$hoy, $hoy]);
    $cuotas_vencidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    logMsg("Encontradas " . count($cuotas_vencidas) . " cuotas vencidas");
    
    foreach ($cuotas_vencidas as $cuota) {
        $dias_mora = (int)$cuota['dias_vencido'];
        $monto_mora = round($cuota['monto'] * ($mora_diaria / 100) * $dias_mora, 2);
        
        $nuevo_estado = $dias_mora <= 7 ? 'vencido' : 'mora';
        
        // Actualizar solo si cambió el estado o los días de mora
        if ($cuota['mora_dias_actual'] != $dias_mora) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE prestamos_pagos 
                    SET estado = ?,
                        mora_dias = ?,
                        monto_mora = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nuevo_estado, $dias_mora, $monto_mora, $cuota['pago_id']]);
                
                logMsg("Actualizada cuota #{$cuota['cuota_num']} del préstamo #{$cuota['prestamo_id']}: $dias_mora días de mora, monto mora: $$monto_mora");
                
                // Enviar email de mora solo si es nuevo (primer día de vencimiento)
                if ($dias_mora == 1) {
                    try {
                        $emailService->notificarCuotaVencida(
                            $cuota['cliente_id'],
                            $cuota['prestamo_id'],
                            $cuota['cuota_num'],
                            $cuota['monto'],
                            $dias_mora,
                            $monto_mora
                        );
                        $enviados++;
                        logMsg("✅ Email de mora enviado a {$cuota['cliente_email']}");
                    } catch (Exception $e) {
                        $errores++;
                        logMsg("❌ Error enviando email de mora: " . $e->getMessage());
                    }
                }
                
            } catch (Exception $e) {
                logMsg("❌ Error actualizando cuota: " . $e->getMessage());
            }
        }
    }
    
    logMsg("===== FIN CRON RECORDATORIOS =====");
    logMsg("Emails enviados: $enviados | Errores: $errores");
    
} catch (Exception $e) {
    logMsg("❌ ERROR CRÍTICO: " . $e->getMessage());
    logMsg($e->getTraceAsString());
}
