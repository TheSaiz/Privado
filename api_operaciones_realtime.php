<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/backend/connection.php';

$usuario_id = (int)$_SESSION['usuario_id'];
$es_asesor = ($_SESSION['usuario_rol'] ?? '') === 'asesor';

try {
    // Filtros
    $filtro_estado = $_GET['estado'] ?? 'todos';
    $filtro_tipo = $_GET['tipo'] ?? 'todos';
    $busqueda = trim($_GET['buscar'] ?? '');

    // ============================================================
    // FUNCIÓN AUXILIAR: Obtener solicitudes respondidas recientes
    // ============================================================
    function obtenerSolicitudesRespondidasRecientes($pdo, $operacion_id, $tipo_operacion) {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM solicitudes_info 
                WHERE operacion_id = ? 
                AND tipo_operacion = ?
                AND respuesta IS NOT NULL 
                AND respuesta != ''
                AND fecha_respuesta >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute([$operacion_id, $tipo_operacion]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['total'] ?? 0);
        } catch (Exception $e) {
            error_log("Error obteniendo solicitudes respondidas: " . $e->getMessage());
            return 0;
        }
    }

    // ============================================================
    // FUNCIÓN AUXILIAR: Obtener valores display correctos
    // ============================================================
    function obtenerValoresDisplay($op) {
        $aceptada = !empty($op['fecha_aceptacion_contraoferta']);
        
        $monto_solicitado = (float)($op['monto_solicitado'] ?? 0);
        $cuotas_solicitadas = (int)($op['cuotas_solicitadas'] ?? 0);
        $frecuencia_solicitada = (string)($op['frecuencia_solicitada'] ?? 'mensual');
        
        $monto_ofrecido = (float)($op['monto_ofrecido'] ?? 0);
        $cuotas_ofrecidas = (int)($op['cuotas_ofrecidas'] ?? 0);
        $frecuencia_ofrecida = (string)($op['frecuencia_ofrecida'] ?? '');
        
        $monto_final = (float)($op['monto_final'] ?? 0);
        $cuotas_final = (int)($op['cuotas_final'] ?? 0);
        $frecuencia_final = (string)($op['frecuencia_final'] ?? '');
        
        $estado = (string)($op['estado'] ?? '');
        
        if ($aceptada) {
            $m = $monto_final > 0 ? $monto_final : ($monto_ofrecido > 0 ? $monto_ofrecido : $monto_solicitado);
            $c = $cuotas_final > 0 ? $cuotas_final : ($cuotas_ofrecidas > 0 ? $cuotas_ofrecidas : $cuotas_solicitadas);
            $f = $frecuencia_final !== '' ? $frecuencia_final : ($frecuencia_ofrecida !== '' ? $frecuencia_ofrecida : $frecuencia_solicitada);
            return [$m, $c, $f];
        }
        
        if ($estado === 'contraoferta' && $monto_ofrecido > 0) {
            $m = $monto_ofrecido;
            $c = $cuotas_ofrecidas > 0 ? $cuotas_ofrecidas : $cuotas_solicitadas;
            $f = $frecuencia_ofrecida !== '' ? $frecuencia_ofrecida : $frecuencia_solicitada;
            return [$m, $c, $f];
        }
        
        if (in_array($estado, ['aprobado', 'activo'], true) && $monto_final > 0) {
            $m = $monto_final;
            $c = $cuotas_final > 0 ? $cuotas_final : $cuotas_solicitadas;
            $f = $frecuencia_final !== '' ? $frecuencia_final : $frecuencia_solicitada;
            return [$m, $c, $f];
        }
        
        return [$monto_solicitado, $cuotas_solicitadas, $frecuencia_solicitada];
    }

    // ============================================================
    // OBTENER PRÉSTAMOS
    // ============================================================
    $operaciones = [];
    
    if ($filtro_tipo === 'todos' || $filtro_tipo === 'prestamo') {
        $where = ["1=1"];
        $params = [];
        
        if ($filtro_estado !== 'todos') {
            if ($filtro_estado === 'contraoferta') {
                $where[] = "(p.estado = 'contraoferta' AND (p.fecha_aceptacion_contraoferta IS NULL OR p.fecha_aceptacion_contraoferta = '0000-00-00 00:00:00'))";
            } else {
                $where[] = "p.estado = ?";
                $params[] = $filtro_estado;
            }
        }
        
        if ($busqueda !== '') {
            $where[] = "(u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR cd.dni LIKE ?)";
            $s = "%{$busqueda}%";
            $params = array_merge($params, [$s, $s, $s, $s]);
        }
        
        if ($es_asesor) {
            $where[] = "(p.asesor_id = ? OR p.asesor_id IS NULL)";
            $params[] = $usuario_id;
        }
        
        $w = implode(' AND ', $where);
        
        $stmt = $pdo->prepare("
            SELECT
                'prestamo' as tipo,
                p.id,
                COALESCE(p.monto_solicitado, p.monto, 0) as monto,
                COALESCE(p.monto_solicitado, 0) as monto_solicitado,
                COALESCE(p.cuotas_solicitadas, p.cuotas, 0) as cuotas,
                COALESCE(p.cuotas_solicitadas, 0) as cuotas_solicitadas,
                COALESCE(p.frecuencia_solicitada, p.frecuencia_pago, 'mensual') as frecuencia,
                COALESCE(p.frecuencia_solicitada, 'mensual') as frecuencia_solicitada,
                COALESCE(p.monto_ofrecido, 0) as monto_ofrecido,
                COALESCE(p.cuotas_ofrecidas, 0) as cuotas_ofrecidas,
                COALESCE(p.frecuencia_ofrecida, '') as frecuencia_ofrecida,
                COALESCE(p.tasa_interes_ofrecida, 0) as tasa_interes_ofrecida,
                COALESCE(p.monto_total_ofrecido, 0) as monto_total_ofrecido,
                p.fecha_contraoferta,
                p.fecha_aceptacion_contraoferta,
                COALESCE(p.monto_final, 0) as monto_final,
                COALESCE(p.cuotas_final, 0) as cuotas_final,
                COALESCE(p.frecuencia_final, '') as frecuencia_final,
                COALESCE(p.tasa_interes_final, 0) as tasa_interes_final,
                COALESCE(p.monto_total_final, 0) as monto_total_final,
                COALESCE(p.destino_credito, p.comentarios_cliente, '') as descripcion,
                p.estado,
                p.estado_contrato,
                p.desembolso_estado,
                p.solicitud_desembolso_fecha,
                p.banco,
                p.cbu,
                p.tipo_cuenta,
                p.alias_cbu,
                p.titular_cuenta,
                p.fecha_solicitud,
                COALESCE(p.tasa_interes, 0) as tasa_interes,
                p.comentarios_admin,
                p.usa_documentacion_existente,
                CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, '')) as cliente_nombre,
                COALESCE(u.email, '') as cliente_email,
                COALESCE(cd.dni, '') as cliente_dni,
                COALESCE(cd.telefono, '') as cliente_telefono
            FROM prestamos p
            INNER JOIN usuarios u ON p.cliente_id = u.id
            LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
            WHERE {$w}
            ORDER BY p.fecha_solicitud DESC
        ");
        $stmt->execute($params);
        $prestamos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($prestamos as &$prestamo) {
            $prestamo['solicitudes_respondidas'] = obtenerSolicitudesRespondidasRecientes(
                $pdo, 
                $prestamo['id'], 
                'prestamo'
            );
        }
        unset($prestamo);
        
        $operaciones = array_merge($operaciones, $prestamos);
    }

    // ============================================================
    // OBTENER EMPEÑOS
    // ============================================================
    if ($filtro_tipo === 'todos' || $filtro_tipo === 'empeno') {
        $where = ["1=1"];
        $params = [];
        
        if ($filtro_estado !== 'todos') {
            if ($filtro_estado === 'contraoferta') {
                $where[] = "(e.estado = 'contraoferta' AND (e.fecha_aceptacion_contraoferta IS NULL OR e.fecha_aceptacion_contraoferta = '0000-00-00 00:00:00'))";
            } else {
                $where[] = "e.estado = ?";
                $params[] = $filtro_estado;
            }
        }
        
        if ($busqueda !== '') {
            $where[] = "(u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR cd.dni LIKE ?)";
            $s = "%{$busqueda}%";
            $params = array_merge($params, [$s, $s, $s, $s]);
        }
        
        if ($es_asesor) {
            $where[] = "(e.asesor_id = ? OR e.asesor_id IS NULL)";
            $params[] = $usuario_id;
        }
        
        $w = implode(' AND ', $where);
        
        $stmt = $pdo->prepare("
            SELECT
                'empeno' as tipo,
                e.id,
                COALESCE(e.monto_solicitado, 0) as monto,
                COALESCE(e.monto_solicitado, 0) as monto_solicitado,
                1 as cuotas,
                1 as cuotas_solicitadas,
                'mensual' as frecuencia,
                'mensual' as frecuencia_solicitada,
                COALESCE(e.monto_ofrecido, 0) as monto_ofrecido,
                COALESCE(e.cuotas_ofrecidas, 0) as cuotas_ofrecidas,
                COALESCE(e.frecuencia_ofrecida, '') as frecuencia_ofrecida,
                COALESCE(e.tasa_interes_ofrecida, 0) as tasa_interes_ofrecida,
                COALESCE(e.monto_total_ofrecido, 0) as monto_total_ofrecido,
                e.fecha_contraoferta,
                e.fecha_aceptacion_contraoferta,
                COALESCE(e.monto_final, 0) as monto_final,
                COALESCE(e.cuotas_final, 0) as cuotas_final,
                COALESCE(e.frecuencia_final, '') as frecuencia_final,
                COALESCE(e.tasa_interes_final, 0) as tasa_interes_final,
                COALESCE(e.monto_total_final, 0) as monto_total_final,
                COALESCE(e.descripcion_producto, '') as descripcion,
                e.estado,
                e.estado_contrato,
                e.desembolso_estado,
                e.solicitud_desembolso_fecha,
                e.banco,
                e.cbu,
                e.tipo_cuenta,
                e.alias_cbu,
                e.titular_cuenta,
                e.fecha_solicitud,
                COALESCE(e.comentarios_admin, '') as comentarios_admin,
                1 as usa_documentacion_existente,
                CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, '')) as cliente_nombre,
                COALESCE(u.email, '') as cliente_email,
                COALESCE(cd.dni, '') as cliente_dni,
                COALESCE(cd.telefono, '') as cliente_telefono
            FROM empenos e
            INNER JOIN usuarios u ON e.cliente_id = u.id
            LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
            WHERE {$w}
            ORDER BY e.fecha_solicitud DESC
        ");
        $stmt->execute($params);
        $empenos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($empenos as &$empeno) {
            $empeno['solicitudes_respondidas'] = obtenerSolicitudesRespondidasRecientes(
                $pdo, 
                $empeno['id'], 
                'empeno'
            );
        }
        unset($empeno);
        
        $operaciones = array_merge($operaciones, $empenos);
    }

    // ============================================================
    // OBTENER PRENDARIOS
    // ============================================================
    if ($filtro_tipo === 'todos' || $filtro_tipo === 'prendario') {
        $where = ["1=1"];
        $params = [];
        
        if ($filtro_estado !== 'todos') {
            if ($filtro_estado === 'contraoferta') {
                $where[] = "(cp.estado = 'contraoferta' AND (cp.fecha_aceptacion_contraoferta IS NULL OR cp.fecha_aceptacion_contraoferta = '0000-00-00 00:00:00'))";
            } else {
                $where[] = "cp.estado = ?";
                $params[] = $filtro_estado;
            }
        }
        
        if ($busqueda !== '') {
            $where[] = "(u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR cd.dni LIKE ? OR cp.dominio LIKE ?)";
            $s = "%{$busqueda}%";
            $params = array_merge($params, [$s, $s, $s, $s, $s]);
        }
        
        if ($es_asesor) {
            $where[] = "(cp.asesor_id = ? OR cp.asesor_id IS NULL)";
            $params[] = $usuario_id;
        }
        
        $w = implode(' AND ', $where);
        
        $stmt = $pdo->prepare("
            SELECT
                'prendario' as tipo,
                cp.id,
                COALESCE(cp.monto_solicitado, 0) as monto,
                COALESCE(cp.monto_solicitado, 0) as monto_solicitado,
                1 as cuotas,
                1 as cuotas_solicitadas,
                'mensual' as frecuencia,
                'mensual' as frecuencia_solicitada,
                COALESCE(cp.monto_ofrecido, 0) as monto_ofrecido,
                COALESCE(cp.cuotas_ofrecidas, 0) as cuotas_ofrecidas,
                COALESCE(cp.frecuencia_ofrecida, '') as frecuencia_ofrecida,
                COALESCE(cp.tasa_interes_ofrecida, 0) as tasa_interes_ofrecida,
                COALESCE(cp.monto_total_ofrecido, 0) as monto_total_ofrecido,
                cp.fecha_contraoferta,
                cp.fecha_aceptacion_contraoferta,
                COALESCE(cp.monto_final, 0) as monto_final,
                COALESCE(cp.cuotas_final, 0) as cuotas_final,
                COALESCE(cp.frecuencia_final, '') as frecuencia_final,
                COALESCE(cp.tasa_interes_final, 0) as tasa_interes_final,
                COALESCE(cp.monto_total_final, 0) as monto_total_final,
                CONCAT('Dominio: ', COALESCE(cp.dominio, '')) as descripcion,
                cp.estado,
                cp.estado_contrato,
                cp.desembolso_estado,
                cp.solicitud_desembolso_fecha,
                cp.banco,
                cp.cbu,
                cp.tipo_cuenta,
                cp.alias_cbu,
                cp.titular_cuenta,
                cp.fecha_solicitud,
                COALESCE(cp.comentarios_admin, '') as comentarios_admin,
                1 as usa_documentacion_existente,
                CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, '')) as cliente_nombre,
                COALESCE(u.email, '') as cliente_email,
                COALESCE(cd.dni, '') as cliente_dni,
                COALESCE(cd.telefono, '') as cliente_telefono
            FROM creditos_prendarios cp
            INNER JOIN usuarios u ON cp.cliente_id = u.id
            LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
            WHERE {$w}
            ORDER BY cp.fecha_solicitud DESC
        ");
        $stmt->execute($params);
        $prendarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($prendarios as &$prendario) {
            $prendario['solicitudes_respondidas'] = obtenerSolicitudesRespondidasRecientes(
                $pdo, 
                $prendario['id'], 
                'prendario'
            );
        }
        unset($prendario);
        
        $operaciones = array_merge($operaciones, $prendarios);
    }

    // Ordenar por prioridad
    usort($operaciones, function($a, $b) {
        $prioridades = [
            'pendiente' => 1,
            'en_revision' => 2,
            'en_evaluacion' => 2,
            'solicitud_enviada' => 2,
            'info_solicitada' => 2,
            'contraoferta' => 3,
            'aprobado' => 4,
            'activo' => 5,
            'rechazado' => 6,
            'finalizado' => 7
        ];
        
        $ea = $a['estado'] ?? '';
        $eb = $b['estado'] ?? '';
        
        $pa = $prioridades[$ea] ?? 99;
        $pb = $prioridades[$eb] ?? 99;
        
        if ($pa === $pb) {
            return strtotime($b['fecha_solicitud']) - strtotime($a['fecha_solicitud']);
        }
        return $pa - $pb;
    });

    // ============================================================
    // CONTADORES
    // ============================================================
    $contadores = [
        'todos' => count($operaciones),
        'pendientes' => 0,
        'rechazados' => 0,
        'prestamo' => 0,
        'empeno' => 0,
        'prendario' => 0,
        'pendiente_desembolso' => 0,
        'firmados' => 0
    ];

    foreach ($operaciones as $op) {
        $estado = $op['estado'] ?? '';
        
        if (in_array($estado, ['pendiente', 'en_revision', 'en_evaluacion', 'solicitud_enviada', 'info_solicitada'], true)) {
            $contadores['pendientes']++;
        }
        if ($estado === 'rechazado') {
            $contadores['rechazados']++;
        }
        if (($op['estado_contrato'] ?? '') === 'firmado' && ($op['desembolso_estado'] ?? 'pendiente') === 'pendiente') {
            $contadores['pendiente_desembolso']++;
        }
        if (($op['estado_contrato'] ?? '') === 'firmado') {
            $contadores['firmados']++;
        }
        
        $contadores[$op['tipo']] = ($contadores[$op['tipo']] ?? 0) + 1;
        $contadores[$estado] = ($contadores[$estado] ?? 0) + 1;
    }

    echo json_encode([
        'success' => true,
        'operaciones' => $operaciones,
        'contadores' => $contadores
    ]);

} catch (PDOException $e) {
    error_log("Error SQL en api_operaciones_realtime.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al cargar las operaciones'
    ]);
}