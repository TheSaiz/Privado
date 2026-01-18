<?php
session_start();
header('Content-Type: application/json');

// Validar sesión
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/backend/connection.php';

try {
    $operacion_id = isset($_GET['operacion_id']) ? (int)$_GET['operacion_id'] : 0;
    $tipo_operacion = isset($_GET['tipo']) ? strtolower($_GET['tipo']) : '';
    
    if ($operacion_id <= 0 || !in_array($tipo_operacion, ['prestamo', 'empeno', 'prendario'])) {
        throw new Exception('Parámetros inválidos');
    }
    
    // Obtener todas las solicitudes de info para esta operación
    // CORRECCIÓN: Usar la tabla usuarios en lugar de clientes
    $stmt = $pdo->prepare("
        SELECT 
            si.id,
            si.mensaje,
            si.fecha,
            si.respuesta,
            si.fecha_respuesta,
            u.nombre as cliente_nombre,
            u.apellido as cliente_apellido,
            u.email as cliente_email,
            cd.dni as cliente_dni,
            cd.telefono as cliente_telefono
        FROM solicitudes_info si
        INNER JOIN usuarios u ON u.id = si.cliente_id
        LEFT JOIN clientes_detalles cd ON cd.usuario_id = u.id
        WHERE si.operacion_id = ? 
        AND si.tipo_operacion = ?
        ORDER BY si.fecha DESC
    ");
    $stmt->execute([$operacion_id, $tipo_operacion]);
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Para cada solicitud, obtener archivos adjuntos
    foreach ($solicitudes as &$solicitud) {
        // Archivos adjuntos en la solicitud original (del admin/asesor)
        try {
            $stmt = $pdo->prepare("
                SELECT archivo 
                FROM solicitudes_info_archivos 
                WHERE solicitud_id = ?
                ORDER BY fecha_subida ASC
            ");
            $stmt->execute([$solicitud['id']]);
            $solicitud['archivos_solicitud'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            // Si la tabla no existe, array vacío
            $solicitud['archivos_solicitud'] = [];
        }
        
        // Archivos adjuntos en la respuesta (del cliente)
        try {
            $stmt = $pdo->prepare("
                SELECT archivo, nombre_original 
                FROM solicitudes_info_respuestas 
                WHERE solicitud_id = ?
                ORDER BY fecha_subida ASC
            ");
            $stmt->execute([$solicitud['id']]);
            $solicitud['archivos_respuesta'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Si la tabla no existe, array vacío
            $solicitud['archivos_respuesta'] = [];
        }
    }
    unset($solicitud); // Liberar referencia
    
    echo json_encode([
        'success' => true,
        'solicitudes' => $solicitudes,
        'total' => count($solicitudes)
    ]);
    
} catch (Exception $e) {
    error_log("Error en obtener_respuestas_solicitudes.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}