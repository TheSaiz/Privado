<?php
/*************************************************
 * api/detalle_pago.php
 * Obtener detalle completo de un pago
 *************************************************/

header('Content-Type: application/json');
session_start();

// Validar sesión de administrador
if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'administrador') {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/../backend/connection.php';

$pago_id = (int)($_GET['id'] ?? 0);

if ($pago_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

try {
    // Obtener datos del pago
    $stmt = $pdo->prepare("
        SELECT 
            pp.*,
            p.id as prestamo_id,
            p.monto_ofrecido,
            p.cuotas_ofrecidas,
            c.id as cliente_id,
            c.nombre as cliente_nombre,
            c.apellido as cliente_apellido,
            c.email as cliente_email,
            c.telefono as cliente_telefono,
            u_aprobado.nombre as aprobado_por_nombre,
            u_rechazado.nombre as rechazado_por_nombre
        FROM prestamos_pagos pp
        INNER JOIN prestamos p ON p.id = pp.prestamo_id
        INNER JOIN clientes c ON c.id = p.cliente_id
        LEFT JOIN usuarios u_aprobado ON u_aprobado.id = pp.aprobado_por
        LEFT JOIN usuarios u_rechazado ON u_rechazado.id = pp.rechazado_por
        WHERE pp.id = ?
    ");
    $stmt->execute([$pago_id]);
    $pago = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pago) {
        echo json_encode(['success' => false, 'error' => 'Pago no encontrado']);
        exit;
    }
    
    // Formatear datos
    $pago['cliente_nombre'] = $pago['cliente_nombre'] . ' ' . $pago['cliente_apellido'];
    $pago['monto'] = number_format((float)$pago['monto'], 0, ',', '.');
    $pago['fecha_vencimiento'] = date('d/m/Y', strtotime($pago['fecha_vencimiento']));
    
    // Determinar estado
    if ($pago['estado'] === 'pagado') {
        $pago['estado'] = 'Aprobado';
    } elseif ($pago['rechazado_fecha']) {
        $pago['estado'] = 'Rechazado';
    } elseif ($pago['metodo_pago'] === 'En revisión') {
        $pago['estado'] = 'En revisión';
    } else {
        $pago['estado'] = 'Pendiente de revisión';
    }
    
    // Obtener historial
    $stmt = $pdo->prepare("
        SELECT 
            hp.*,
            u.nombre as usuario_nombre
        FROM historial_pagos hp
        LEFT JOIN usuarios u ON u.id = hp.usuario_id
        WHERE hp.pago_id = ?
        ORDER BY hp.fecha DESC
    ");
    $stmt->execute([$pago_id]);
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear historial
    foreach ($historial as &$h) {
        $h['fecha'] = date('d/m/Y H:i', strtotime($h['fecha']));
        $h['accion'] = ucfirst($h['accion']);
    }
    
    echo json_encode([
        'success' => true,
        'pago' => $pago,
        'historial' => $historial
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error al obtener detalle: ' . $e->getMessage()
    ]);
}