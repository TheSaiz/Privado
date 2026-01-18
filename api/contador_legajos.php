<?php
session_start();
header('Content-Type: application/json');

// Verificar sesión
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor'])) {
    echo json_encode(['success' => false, 'total' => 0]);
    exit;
}

require_once __DIR__ . '/../backend/connection.php';

try {
    $rol = $_SESSION['usuario_rol'];
    $usuario_id = $_SESSION['usuario_id'];
    
    // Contar legajos que necesitan revisión
    // Prioridad 1: Legajos completos sin validar
    // Prioridad 2: Legajos con documentos pendientes de revisión
    
    $where = ["p.requiere_legajo = 1"];
    $params = [];
    
    // Solo contar los que necesitan acción
    $where[] = "(p.legajo_completo = 1 AND p.legajo_validado = 0) OR EXISTS (
        SELECT 1 FROM cliente_documentos_legajo cdl 
        WHERE cdl.prestamo_id = p.id 
        AND cdl.estado_validacion = 'pendiente'
    )";
    
    // Si es asesor, solo sus préstamos
    if ($rol === 'asesor') {
        $where[] = "p.asesor_id = ?";
        $params[] = $usuario_id;
    }
    
    $where_clause = implode(' AND ', $where);
    
    $sql = "
        SELECT COUNT(DISTINCT p.id) as total
        FROM prestamos p
        WHERE $where_clause
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $total = (int)($result['total'] ?? 0);
    
    echo json_encode([
        'success' => true,
        'total' => $total
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'total' => 0,
        'error' => $e->getMessage()
    ]);
}