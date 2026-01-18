<?php
/**
 * GENERAR CUOTAS AUTOMÁTICAMENTE
 * 
 * Este archivo contiene las funciones necesarias para generar automáticamente
 * las cuotas de un préstamo cuando es aprobado.
 * 
 * Uso:
 * require_once 'generar_cuotas.php';
 * generarCuotasPrestamo($pdo, $prestamo_id);
 * 
 * IMPORTANTE: Este archivo debe estar en el mismo directorio que connection.php
 * o ajustar la ruta según la estructura del proyecto.
 */

/**
 * Genera las cuotas de pago para un préstamo aprobado
 * 
 * @param PDO $pdo Conexión a la base de datos
 * @param int $prestamo_id ID del préstamo aprobado
 * @return array ['success' => bool, 'message' => string, 'cuotas_generadas' => int]
 */
function generarCuotasPrestamo($pdo, $prestamo_id) {
    try {
        // Obtener información del préstamo
        $stmt = $pdo->prepare("
            SELECT 
                id,
                COALESCE(monto_ofrecido, monto_solicitado, monto) as monto_prestamo,
                COALESCE(cuotas_ofrecidas, cuotas_solicitadas, cuotas) as cantidad_cuotas,
                COALESCE(frecuencia_ofrecida, frecuencia_solicitada, frecuencia_pago) as frecuencia,
                COALESCE(tasa_interes_ofrecida, tasa_interes) as tasa_interes,
                fecha_aprobacion,
                estado
            FROM prestamos 
            WHERE id = ?
        ");
        $stmt->execute([$prestamo_id]);
        $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$prestamo) {
            return [
                'success' => false,
                'message' => 'Préstamo no encontrado',
                'cuotas_generadas' => 0
            ];
        }
        
        // Validar que el préstamo esté aprobado
        if ($prestamo['estado'] !== 'aprobado') {
            return [
                'success' => false,
                'message' => 'El préstamo debe estar en estado aprobado',
                'cuotas_generadas' => 0
            ];
        }
        
        // Verificar si ya existen cuotas generadas
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM prestamos_pagos WHERE prestamo_id = ?");
        $stmt->execute([$prestamo_id]);
        $cuotas_existentes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($cuotas_existentes > 0) {
            return [
                'success' => false,
                'message' => 'Ya existen cuotas generadas para este préstamo',
                'cuotas_generadas' => $cuotas_existentes
            ];
        }
        
        // Calcular valores
        $monto_prestamo = (float) $prestamo['monto_prestamo'];
        $cantidad_cuotas = (int) $prestamo['cantidad_cuotas'];
        $tasa_interes = (float) ($prestamo['tasa_interes'] ?? 0);
        $frecuencia = $prestamo['frecuencia'] ?? 'mensual';
        
        if ($cantidad_cuotas <= 0) {
            return [
                'success' => false,
                'message' => 'Cantidad de cuotas inválida',
                'cuotas_generadas' => 0
            ];
        }
        
        // Calcular interés total
        $interes_total = $monto_prestamo * ($tasa_interes / 100);
        $monto_total = $monto_prestamo + $interes_total;
        
        // Monto por cuota (se distribuye de forma uniforme)
        $monto_cuota = $monto_total / $cantidad_cuotas;
        $interes_por_cuota = $interes_total / $cantidad_cuotas;
        $capital_por_cuota = $monto_prestamo / $cantidad_cuotas;
        
        // Determinar días entre cuotas según frecuencia
        $dias_entre_cuotas = match($frecuencia) {
            'diario' => 1,
            'semanal' => 7,
            'quincenal' => 15,
            'mensual' => 30,
            default => 30
        };
        
        // Fecha de inicio (fecha de aprobación o hoy)
        $fecha_inicio = new DateTime($prestamo['fecha_aprobacion'] ?? 'now', new DateTimeZone('America/Argentina/Buenos_Aires'));
        
        // Generar cuotas
        $cuotas_generadas = 0;
        $pdo->beginTransaction();
        
        $stmt_insert = $pdo->prepare("
            INSERT INTO prestamos_pagos (
                prestamo_id,
                cuota_num,
                fecha_vencimiento,
                monto,
                monto_interes,
                monto_capital,
                estado,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'pendiente', NOW())
        ");
        
        for ($i = 1; $i <= $cantidad_cuotas; $i++) {
            // Calcular fecha de vencimiento
            if ($i === 1) {
                // Primera cuota vence después del período correspondiente
                $fecha_vencimiento = clone $fecha_inicio;
                $fecha_vencimiento->modify("+{$dias_entre_cuotas} days");
            } else {
                // Las siguientes cuotas van sumando el período
                $fecha_vencimiento->modify("+{$dias_entre_cuotas} days");
            }
            
            // Ajustar montos para la última cuota (por redondeos)
            if ($i === $cantidad_cuotas) {
                // Calcular el total de las cuotas anteriores
                $total_cuotas_anteriores = ($cantidad_cuotas - 1) * $monto_cuota;
                $monto_cuota_actual = $monto_total - $total_cuotas_anteriores;
                
                $total_interes_anterior = ($cantidad_cuotas - 1) * $interes_por_cuota;
                $interes_cuota_actual = $interes_total - $total_interes_anterior;
                
                $total_capital_anterior = ($cantidad_cuotas - 1) * $capital_por_cuota;
                $capital_cuota_actual = $monto_prestamo - $total_capital_anterior;
            } else {
                $monto_cuota_actual = $monto_cuota;
                $interes_cuota_actual = $interes_por_cuota;
                $capital_cuota_actual = $capital_por_cuota;
            }
            
            // Insertar cuota
            $stmt_insert->execute([
                $prestamo_id,
                $i,
                $fecha_vencimiento->format('Y-m-d'),
                round($monto_cuota_actual, 2),
                round($interes_cuota_actual, 2),
                round($capital_cuota_actual, 2)
            ]);
            
            $cuotas_generadas++;
        }
        
        // Actualizar fecha de inicio y fin estimada del préstamo
        $fecha_fin = clone $fecha_vencimiento; // Ya tiene la fecha de la última cuota
        
        $stmt_update = $pdo->prepare("
            UPDATE prestamos 
            SET 
                fecha_inicio_prestamo = ?,
                fecha_fin_estimada = ?,
                estado = 'activo'
            WHERE id = ?
        ");
        $stmt_update->execute([
            $fecha_inicio->format('Y-m-d H:i:s'),
            $fecha_fin->format('Y-m-d H:i:s'),
            $prestamo_id
        ]);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => "Se generaron {$cuotas_generadas} cuotas correctamente",
            'cuotas_generadas' => $cuotas_generadas,
            'primera_cuota' => $fecha_inicio->modify("+{$dias_entre_cuotas} days")->format('Y-m-d'),
            'ultima_cuota' => $fecha_fin->format('Y-m-d')
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        error_log("Error al generar cuotas: " . $e->getMessage());
        
        return [
            'success' => false,
            'message' => 'Error al generar cuotas: ' . $e->getMessage(),
            'cuotas_generadas' => 0
        ];
    }
}

/**
 * Elimina las cuotas de un préstamo (útil para regenerar o corregir)
 * PRECAUCIÓN: Solo usar si las cuotas están en estado 'pendiente' y sin pagos
 * 
 * @param PDO $pdo Conexión a la base de datos
 * @param int $prestamo_id ID del préstamo
 * @return array ['success' => bool, 'message' => string, 'cuotas_eliminadas' => int]
 */
function eliminarCuotasPrestamo($pdo, $prestamo_id) {
    try {
        // Verificar que no haya cuotas pagadas
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM prestamos_pagos 
            WHERE prestamo_id = ? 
            AND estado != 'pendiente'
        ");
        $stmt->execute([$prestamo_id]);
        $cuotas_no_pendientes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($cuotas_no_pendientes > 0) {
            return [
                'success' => false,
                'message' => 'No se pueden eliminar cuotas porque ya hay pagos registrados',
                'cuotas_eliminadas' => 0
            ];
        }
        
        // Eliminar cuotas pendientes
        $stmt = $pdo->prepare("DELETE FROM prestamos_pagos WHERE prestamo_id = ? AND estado = 'pendiente'");
        $stmt->execute([$prestamo_id]);
        $cuotas_eliminadas = $stmt->rowCount();
        
        return [
            'success' => true,
            'message' => "Se eliminaron {$cuotas_eliminadas} cuotas pendientes",
            'cuotas_eliminadas' => $cuotas_eliminadas
        ];
        
    } catch (Exception $e) {
        error_log("Error al eliminar cuotas: " . $e->getMessage());
        
        return [
            'success' => false,
            'message' => 'Error al eliminar cuotas: ' . $e->getMessage(),
            'cuotas_eliminadas' => 0
        ];
    }
}

/**
 * Regenera las cuotas de un préstamo
 * Útil si se modificaron los términos del préstamo
 * 
 * @param PDO $pdo Conexión a la base de datos
 * @param int $prestamo_id ID del préstamo
 * @return array Resultado de la regeneración
 */
function regenerarCuotasPrestamo($pdo, $prestamo_id) {
    // Primero eliminar cuotas existentes
    $resultado_eliminar = eliminarCuotasPrestamo($pdo, $prestamo_id);
    
    if (!$resultado_eliminar['success']) {
        return $resultado_eliminar;
    }
    
    // Luego generar nuevas cuotas
    return generarCuotasPrestamo($pdo, $prestamo_id);
}