<?php
session_start();

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/backend/connection.php';

$usuario_id = (int)$_SESSION['usuario_id'];
$es_asesor = $_SESSION['usuario_rol'] === 'asesor';

$mensaje = '';
$error = '';

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $tipo = $_POST['tipo'] ?? 'prestamo';
    $operacion_id = (int)($_POST['operacion_id'] ?? 0);
    
    if ($operacion_id > 0) {
        try {
            $pdo->beginTransaction();
            
            $tabla = match($tipo) {
                'prestamo' => 'prestamos',
                'empeno' => 'empenos',
                'prendario' => 'creditos_prendarios',
                default => null
            };
            
            if (!$tabla) {
                throw new Exception('Tipo de operación no válido');
            }
            
            // PRE-APROBAR (Nuevo estado)
            if ($accion === 'pre_aprobar') {
                // Actualizar estado a aprobado y estado_contrato a pendiente_firma
                $stmt = $pdo->prepare("
                    UPDATE {$tabla} SET 
                        estado = 'aprobado',
                        estado_contrato = 'pendiente_firma',
                        fecha_aprobacion = NOW(),
                        asesor_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([$usuario_id, $operacion_id]);
                
                if ($stmt->rowCount() === 0) {
                    throw new Exception('No se pudo actualizar la operación');
                }
                
                // Obtener cliente_id
                $stmt = $pdo->prepare("SELECT cliente_id FROM {$tabla} WHERE id = ?");
                $stmt->execute([$operacion_id]);
                $cliente_id = $stmt->fetchColumn();
                
                if (!$cliente_id) {
                    throw new Exception('Cliente no encontrado');
                }
                
                // Crear notificación para el cliente
                $stmt = $pdo->prepare("
                    INSERT INTO clientes_notificaciones (
                        cliente_id, tipo, titulo, mensaje, url_accion, texto_accion
                    ) VALUES (?, 'success', '¡Felicitaciones! Tu préstamo ha sido pre-aprobado', 
                    'Tu solicitud ha sido aprobada. Revisá los detalles y firmá el contrato para recibir el dinero en 24-48hs hábiles.', 
                    'dashboard_clientes.php', 'Ver Dashboard')
                ");
                $stmt->execute([$cliente_id]);
                
                $pdo->commit();
                $mensaje = '✅ Operación pre-aprobada exitosamente. El cliente debe firmar el contrato.';
            }
            
            // APROBAR (Sin contrato - para créditos simples)
            elseif ($accion === 'aprobar') {
                $stmt = $pdo->prepare("
                    UPDATE {$tabla} SET 
                        estado = 'aprobado', 
                        fecha_aprobacion = NOW(), 
                        asesor_id = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$usuario_id, $operacion_id]);
                
                if ($stmt->rowCount() === 0) {
                    throw new Exception('No se pudo aprobar la operación');
                }
                
                $pdo->commit();
                $mensaje = '✅ Operación aprobada exitosamente';
            }
            
            // CONFIRMAR TRANSFERENCIA
            elseif ($accion === 'confirmar_transferencia') {
                $notas = trim($_POST['notas_transferencia'] ?? '');
                
                $stmt = $pdo->prepare("
                    UPDATE {$tabla} SET 
                        desembolso_estado = 'transferido',
                        desembolso_fecha = NOW(),
                        desembolso_notas = ?,
                        estado = 'activo',
                        fecha_inicio_prestamo = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$notas, $operacion_id]);
                
                if ($stmt->rowCount() === 0) {
                    throw new Exception('No se pudo confirmar la transferencia');
                }
                
                // Notificar al cliente
                $stmt = $pdo->prepare("SELECT cliente_id FROM {$tabla} WHERE id = ?");
                $stmt->execute([$operacion_id]);
                $cliente_id = $stmt->fetchColumn();
                
                if ($cliente_id) {
                    $stmt = $pdo->prepare("
                        INSERT INTO clientes_notificaciones (
                            cliente_id, tipo, titulo, mensaje, url_accion, texto_accion
                        ) VALUES (?, 'success', '💰 ¡Dinero Transferido!', 
                        'El dinero ya fue transferido a tu cuenta. Verificá en tu homebanking.', 
                        'detalle_prestamo.php?id={$operacion_id}&tipo={$tipo}', 'Ver Préstamo')
                    ");
                    $stmt->execute([$cliente_id]);
                }
                
                $pdo->commit();
                $mensaje = '✅ Transferencia confirmada. El préstamo ahora está activo.';
            }
            
            // RECHAZAR
            elseif ($accion === 'rechazar') {
                $motivo = trim($_POST['motivo_rechazo'] ?? '');
                if (empty($motivo)) {
                    throw new Exception('El motivo del rechazo es obligatorio');
                }
                
                $stmt = $pdo->prepare("
                    UPDATE {$tabla} SET 
                        estado = 'rechazado', 
                        comentarios_admin = ?, 
                        asesor_id = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$motivo, $usuario_id, $operacion_id]);
                
                if ($stmt->rowCount() === 0) {
                    throw new Exception('No se pudo rechazar la operación');
                }
                
                // Notificar al cliente
                $stmt = $pdo->prepare("SELECT cliente_id FROM {$tabla} WHERE id = ?");
                $stmt->execute([$operacion_id]);
                $cliente_id = $stmt->fetchColumn();
                
                if ($cliente_id) {
                    $stmt = $pdo->prepare("
                        INSERT INTO clientes_notificaciones (
                            cliente_id, tipo, titulo, mensaje
                        ) VALUES (?, 'error', 'Solicitud Rechazada', ?)
                    ");
                    $stmt->execute([
                        $cliente_id, 
                        "Lamentablemente tu solicitud ha sido rechazada. Motivo: {$motivo}"
                    ]);
                }
                
                $pdo->commit();
                $mensaje = '✅ Operación rechazada exitosamente';
            }
            
            // SOLICITAR MÁS INFORMACIÓN
            elseif ($accion === 'solicitar_info') {
                $mensaje_info = trim($_POST['mensaje_info'] ?? '');
                if (empty($mensaje_info)) {
                    throw new Exception('El mensaje es obligatorio');
                }

                // Obtener cliente_id
                $stmt = $pdo->prepare("SELECT cliente_id FROM {$tabla} WHERE id = ?");
                $stmt->execute([$operacion_id]);
                $cliente_id = $stmt->fetchColumn();

                if (!$cliente_id) {
                    throw new Exception('Cliente no encontrado');
                }

                // Cambiar estado
                $stmt = $pdo->prepare("UPDATE {$tabla} SET estado = 'info_solicitada' WHERE id = ?");
                $stmt->execute([$operacion_id]);

                // Guardar solicitud
                $stmt = $pdo->prepare("
                    INSERT INTO solicitudes_info (
                        cliente_id,
                        operacion_id,
                        tipo_operacion,
                        mensaje,
                        fecha
                    ) VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$cliente_id, $operacion_id, $tipo, $mensaje_info]);

                $solicitud_id = $pdo->lastInsertId();

                // Subida de archivos
                if (!empty($_FILES['archivos']['name'][0])) {
                    $upload_dir = __DIR__ . '/uploads/solicitudes_info/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
                    $max_file_size = 10 * 1024 * 1024; // 10MB

                    foreach ($_FILES['archivos']['tmp_name'] as $i => $tmp) {
                        if ($_FILES['archivos']['error'][$i] === UPLOAD_ERR_OK) {
                            $file_size = $_FILES['archivos']['size'][$i];
                            if ($file_size > $max_file_size) {
                                continue; // Skip files larger than 10MB
                            }

                            $ext = strtolower(pathinfo($_FILES['archivos']['name'][$i], PATHINFO_EXTENSION));
                            if (!in_array($ext, $allowed_extensions)) {
                                continue; // Skip invalid file types
                            }

                            $nombre = uniqid('info_') . '.' . $ext;
                            $ruta = $upload_dir . $nombre;

                            if (move_uploaded_file($tmp, $ruta)) {
                                $stmt = $pdo->prepare("
                                    INSERT INTO solicitudes_info_archivos (
                                        solicitud_id,
                                        archivo
                                    ) VALUES (?, ?)
                                ");
                                $stmt->execute([$solicitud_id, $nombre]);
                            }
                        }
                    }
                }

                // Notificación al cliente
                $stmt = $pdo->prepare("
                    INSERT INTO clientes_notificaciones (
                        cliente_id, tipo, titulo, mensaje, url_accion, texto_accion
                    ) VALUES (
                        ?, 'warning', '📄 Necesitamos más información',
                        ?, 'dashboard_clientes.php', 'Responder solicitud'
                    )
                ");
                $stmt->execute([
                    $cliente_id,
                    "Tu asesor necesita más información para continuar: {$mensaje_info}"
                ]);

                $pdo->commit();
                $mensaje = '✅ Solicitud de información enviada correctamente';
            } else {
                throw new Exception('Acción no válida');
            }
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error en gestión de operaciones: " . $e->getMessage());
            $error = $e->getMessage();
        }
    } else {
        $error = 'ID de operación inválido';
    }
}

// Filtros
$filtro_tipo = $_GET['tipo'] ?? 'todos';
$filtro_estado = $_GET['estado'] ?? 'todos';
$filtro_busqueda = trim($_GET['buscar'] ?? '');

$operaciones = [];

try {
    // PRÉSTAMOS
    if ($filtro_tipo === 'todos' || $filtro_tipo === 'prestamo') {
        $where = ["1=1"];
        $params = [];
        
        if ($filtro_estado !== 'todos') {
            $where[] = "p.estado = ?";
            $params[] = $filtro_estado;
        }
        
        if ($filtro_busqueda !== '') {
            $where[] = "(u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR cd.dni LIKE ?)";
            $s = "%{$filtro_busqueda}%";
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
                COALESCE(p.cuotas_solicitadas, p.cuotas, 0) as cuotas,
                COALESCE(p.frecuencia_solicitada, p.frecuencia_pago, '') as frecuencia,
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
                COALESCE(p.monto_ofrecido, 0) as monto_ofrecido,
                p.comentarios_admin,
                p.usa_documentacion_existente,
                CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, '')) as cliente_nombre,
                COALESCE(u.email, '') as cliente_email,
                COALESCE(cd.dni, '') as cliente_dni,
                COALESCE(cd.telefono, '') as cliente_telefono,
                CONCAT(COALESCE(ua.nombre, ''), ' ', COALESCE(ua.apellido, '')) as gestor_nombre
            FROM prestamos p
            INNER JOIN usuarios u ON p.cliente_id = u.id
            LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
            LEFT JOIN usuarios ua ON p.asesor_id = ua.id
            WHERE {$w}
        ");
        $stmt->execute($params);
        $operaciones = array_merge($operaciones, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    // EMPEÑOS
    if ($filtro_tipo === 'todos' || $filtro_tipo === 'empeno') {
        $where = ["1=1"];
        $params = [];
        
        if ($filtro_estado !== 'todos') {
            $where[] = "e.estado = ?";
            $params[] = $filtro_estado;
        }
        
        if ($filtro_busqueda !== '') {
            $where[] = "(u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR cd.dni LIKE ?)";
            $s = "%{$filtro_busqueda}%";
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
                NULL as cuotas,
                NULL as frecuencia,
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
                COALESCE(e.monto_ofrecido, 0) as monto_ofrecido,
                e.comentarios_admin,
                1 as usa_documentacion_existente,
                CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, '')) as cliente_nombre,
                COALESCE(u.email, '') as cliente_email,
                COALESCE(cd.dni, '') as cliente_dni,
                COALESCE(cd.telefono, '') as cliente_telefono,
                CONCAT(COALESCE(ua.nombre, ''), ' ', COALESCE(ua.apellido, '')) as gestor_nombre
            FROM empenos e
            INNER JOIN usuarios u ON e.cliente_id = u.id
            LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
            LEFT JOIN usuarios ua ON e.asesor_id = ua.id
            WHERE {$w}
        ");
        $stmt->execute($params);
        $operaciones = array_merge($operaciones, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    // PRENDARIOS
    if ($filtro_tipo === 'todos' || $filtro_tipo === 'prendario') {
        $where = ["1=1"];
        $params = [];
        
        if ($filtro_estado !== 'todos') {
            $where[] = "cp.estado = ?";
            $params[] = $filtro_estado;
        }
        
        if ($filtro_busqueda !== '') {
            $where[] = "(u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR cd.dni LIKE ? OR cp.dominio LIKE ?)";
            $s = "%{$filtro_busqueda}%";
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
                NULL as cuotas,
                NULL as frecuencia,
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
                COALESCE(cp.monto_ofrecido, 0) as monto_ofrecido,
                cp.comentarios_admin,
                1 as usa_documentacion_existente,
                CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, '')) as cliente_nombre,
                COALESCE(u.email, '') as cliente_email,
                COALESCE(cd.dni, '') as cliente_dni,
                COALESCE(cd.telefono, '') as cliente_telefono,
                CONCAT(COALESCE(ua.nombre, ''), ' ', COALESCE(ua.apellido, '')) as gestor_nombre
            FROM creditos_prendarios cp
            INNER JOIN usuarios u ON cp.cliente_id = u.id
            LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
            LEFT JOIN usuarios ua ON cp.asesor_id = ua.id
            WHERE {$w}
        ");
        $stmt->execute($params);
        $operaciones = array_merge($operaciones, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    // Ordenar por prioridad de estado y fecha
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
        $pa = $prioridades[$a['estado']] ?? 99;
        $pb = $prioridades[$b['estado']] ?? 99;
        
        if ($pa === $pb) {
            return strtotime($b['fecha_solicitud']) - strtotime($a['fecha_solicitud']);
        }
        return $pa - $pb;
    });
    
} catch (PDOException $e) {
    error_log("Error SQL en gestión de operaciones: " . $e->getMessage());
    $error = 'Error al cargar las operaciones';
}

// CONTADORES
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
    if (in_array($op['estado'], ['pendiente', 'en_revision', 'en_evaluacion', 'solicitud_enviada', 'info_solicitada'])) {
        $contadores['pendientes']++;
    }
    if ($op['estado'] === 'rechazado') {
        $contadores['rechazados']++;
    }
    if (($op['estado_contrato'] ?? '') === 'firmado' && ($op['desembolso_estado'] ?? 'pendiente') === 'pendiente') {
        $contadores['pendiente_desembolso']++;
    }
    if (($op['estado_contrato'] ?? '') === 'firmado') {
        $contadores['firmados']++;
    }
    $contadores[$op['tipo']] = ($contadores[$op['tipo']] ?? 0) + 1;
    $contadores[$op['estado']] = ($contadores[$op['estado']] ?? 0) + 1;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Operaciones - Panel Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <style>
        .tipo-badge-prestamo {
            background: #dbeafe;
            color: #1e40af;
        }
        .tipo-badge-empeno {
            background: #fce7f3;
            color: #9f1239;
        }
        .tipo-badge-prendario {
            background: #dcfce7;
            color: #15803d;
        }
        .contador-card {
            transition: all 0.2s ease;
        }
        .contador-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .badge-reutiliza {
            background: #d1fae5;
            color: #065f46;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin-left: 8px;
        }
        .badge-firmado {
            background: #dcfce7;
            color: #15803d;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin-left: 8px;
        }
        .badge-desembolso {
            background: #fef3c7;
            color: #92400e;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin-left: 8px;
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.7;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include $es_asesor ? 'sidebar_asesores.php' : 'sidebar.php'; ?>

    <main class="ml-64 bg-gray-50 min-h-screen">
        <nav class="bg-white shadow-sm sticky top-0 z-40 border-b">
            <div class="max-w-[1600px] mx-auto px-6 py-3">
                <h1 class="text-2xl font-bold text-gray-800">💼 Gestión de Operaciones</h1>
            </div>
        </nav>

        <div class="px-6 py-6">
            <?php if ($mensaje): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-6 py-4 rounded-lg mb-6 shadow">
                    <?= htmlspecialchars($mensaje) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-6 py-4 rounded-lg mb-6 shadow">
                    ❌ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- CONTADORES -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-6">
                <div class="contador-card bg-white rounded-xl shadow p-4 border-l-4 border-blue-500">
                    <div class="text-gray-600 text-sm font-semibold">Total</div>
                    <div class="text-3xl font-bold text-blue-600"><?= $contadores['todos'] ?></div>
                </div>

                <div class="contador-card bg-white rounded-xl shadow p-4 border-l-4 border-yellow-500">
                    <div class="text-gray-600 text-sm font-semibold">Pendientes</div>
                    <div class="text-3xl font-bold text-yellow-600"><?= $contadores['pendientes'] ?></div>
                </div>

                <div class="contador-card bg-white rounded-xl shadow p-4 border-l-4 border-green-500">
                    <div class="text-gray-600 text-sm font-semibold">Firmados</div>
                    <div class="text-3xl font-bold text-green-600"><?= $contadores['firmados'] ?></div>
                </div>

                <div class="contador-card bg-white rounded-xl shadow p-4 border-l-4 border-orange-500">
                    <div class="text-gray-600 text-sm font-semibold">Desembolso Pend.</div>
                    <div class="text-3xl font-bold text-orange-600"><?= $contadores['pendiente_desembolso'] ?></div>
                </div>

                <div class="contador-card bg-white rounded-xl shadow p-4 border-l-4 border-red-500">
                    <div class="text-gray-600 text-sm font-semibold">Rechazados</div>
                    <div class="text-3xl font-bold text-red-600"><?= $contadores['rechazados'] ?></div>
                </div>

                <div class="contador-card bg-white rounded-xl shadow p-4 border-l-4 border-indigo-500">
                    <div class="text-gray-600 text-sm font-semibold">Préstamos</div>
                    <div class="text-3xl font-bold text-indigo-600"><?= $contadores['prestamo'] ?></div>
                </div>

                <div class="contador-card bg-white rounded-xl shadow p-4 border-l-4 border-pink-500">
                    <div class="text-gray-600 text-sm font-semibold">Empeños</div>
                    <div class="text-3xl font-bold text-pink-600"><?= $contadores['empeno'] ?></div>
                </div>
            </div>

            <!-- FILTROS -->
            <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
                <form method="GET" class="flex flex-wrap gap-4">
                    <input type="text" 
                           name="buscar" 
                           value="<?= htmlspecialchars($filtro_busqueda) ?>" 
                           placeholder="🔍 Buscar por nombre, email, DNI..." 
                           class="flex-1 min-w-[250px] px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                    
                    <select name="tipo" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                        <option value="todos">Todos los tipos</option>
                        <option value="prestamo"<?= $filtro_tipo === 'prestamo' ? ' selected' : '' ?>>
                            Préstamos (<?= $contadores['prestamo'] ?>)
                        </option>
                        <option value="empeno"<?= $filtro_tipo === 'empeno' ? ' selected' : '' ?>>
                            Empeños (<?= $contadores['empeno'] ?>)
                        </option>
                        <option value="prendario"<?= $filtro_tipo === 'prendario' ? ' selected' : '' ?>>
                            Prendarios (<?= $contadores['prendario'] ?>)
                        </option>
                    </select>
                    
                    <select name="estado" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                        <option value="todos">Todos los estados</option>
                        <option value="pendiente"<?= $filtro_estado === 'pendiente' ? ' selected' : '' ?>>
                            Pendientes (<?= $contadores['pendiente'] ?? 0 ?>)
                        </option>
                        <option value="solicitud_enviada"<?= $filtro_estado === 'solicitud_enviada' ? ' selected' : '' ?>>
                            Enviados (<?= $contadores['solicitud_enviada'] ?? 0 ?>)
                        </option>
                        <option value="info_solicitada"<?= $filtro_estado === 'info_solicitada' ? ' selected' : '' ?>>
                            Info Solicitada (<?= $contadores['info_solicitada'] ?? 0 ?>)
                        </option>
                        <option value="contraoferta"<?= $filtro_estado === 'contraoferta' ? ' selected' : '' ?>>
                            Contraoferta (<?= $contadores['contraoferta'] ?? 0 ?>)
                        </option>
                        <option value="aprobado"<?= $filtro_estado === 'aprobado' ? ' selected' : '' ?>>
                            Pre-Aprobados (<?= $contadores['aprobado'] ?? 0 ?>)
                        </option>
                        <option value="activo"<?= $filtro_estado === 'activo' ? ' selected' : '' ?>>
                            Activos (<?= $contadores['activo'] ?? 0 ?>)
                        </option>
                        <option value="rechazado"<?= $filtro_estado === 'rechazado' ? ' selected' : '' ?>>
                            Rechazados (<?= $contadores['rechazados'] ?>)
                        </option>
                    </select>
                    
                    <button type="submit" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-semibold transition">
                        🔍 Buscar
                    </button>
                </form>
            </div>

            <!-- TABLA -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Tipo</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Cliente</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Monto</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Detalles</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Estado</th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Fecha</th>
                                <th class="px-6 py-4 text-center text-sm font-semibold text-gray-700">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($operaciones)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                        No se encontraron operaciones
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($operaciones as $op): 
                                    $usa_docs = (int)($op['usa_documentacion_existente'] ?? 0) === 1;
                                    $contrato_firmado = ($op['estado_contrato'] ?? '') === 'firmado';
                                    $desembolso_pendiente = $contrato_firmado && 
                                                          ($op['desembolso_estado'] ?? 'pendiente') === 'pendiente' && 
                                                          !empty($op['solicitud_desembolso_fecha']);
                                ?>
                                <tr class="border-t hover:bg-gray-50 transition">
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 rounded-full text-xs font-bold tipo-badge-<?= $op['tipo'] ?>">
                                            <?= strtoupper($op['tipo']) ?>
                                        </span>
                                        <?php if ($usa_docs): ?>
                                            <span class="badge-reutiliza" title="Reutiliza documentación">✓ Docs</span>
                                        <?php endif; ?>
                                        <?php if ($contrato_firmado): ?>
                                            <span class="badge-firmado" title="Contrato Firmado">📝 Firmado</span>
                                        <?php endif; ?>
                                        <?php if ($desembolso_pendiente): ?>
                                            <span class="badge-desembolso" title="Esperando desembolso">💰 Desembolso Pend.</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-gray-900"><?= htmlspecialchars($op['cliente_nombre']) ?></div>
                                        <div class="text-sm text-gray-600"><?= htmlspecialchars($op['cliente_email']) ?></div>
                                        <div class="text-xs text-gray-500">DNI: <?= htmlspecialchars($op['cliente_dni'] ?: 'N/A') ?></div>
                                    </td>
                                    <td class="px-6 py-4 font-bold text-green-600">
                                        $<?= number_format($op['monto'], 0, ',', '.') ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <?php if ($op['cuotas']): ?>
                                            <?= $op['cuotas'] ?> cuotas <?= $op['frecuencia'] ?>es
                                        <?php else: ?>
                                            <?= htmlspecialchars(substr($op['descripcion'] ?? '', 0, 40)) ?>...
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php 
                                        // Si está firmado, mostrar "Firmado" en lugar del estado general
                                        if ($contrato_firmado) {
                                            $badge_text = 'Firmado';
                                            $bc = 'bg-green-100 text-green-800';
                                        } else {
                                            $bc = match($op['estado']) {
                                                'pendiente', 'en_evaluacion', 'solicitud_enviada', 'info_solicitada' => 'bg-yellow-100 text-yellow-800',
                                                'contraoferta' => 'bg-pink-100 text-pink-800',
                                                'aprobado' => 'bg-green-100 text-green-800',
                                                'rechazado' => 'bg-red-100 text-red-800',
                                                'activo' => 'bg-purple-100 text-purple-800',
                                                default => 'bg-gray-100 text-gray-800'
                                            };

                                            // Mostrar badge de pre-aprobado si corresponde
                                            if ($op['estado'] === 'aprobado' && ($op['estado_contrato'] ?? '') === 'pendiente_firma') {
                                                $badge_text = 'Pre-Aprobado';
                                            } else {
                                                $badge_text = ucfirst(str_replace('_', ' ', $op['estado']));
                                            }
                                        }
                                        ?>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $bc ?>">
                                            <?= $badge_text ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">
                                        <?= date('d/m/Y', strtotime($op['fecha_solicitud'])) ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex justify-center gap-2 flex-wrap">
                                            <!-- Botón VER -->
                                            <a href="ver_prestamo.php?id=<?= $op['id'] ?>&tipo=<?= $op['tipo'] ?>" 
                                               class="px-3 py-2 bg-blue-600 text-white rounded-lg text-xs font-semibold hover:bg-blue-700 transition">
                                                👁 Ver
                                            </a>

                                            <?php if (in_array($op['estado'], ['pendiente', 'en_revision', 'en_evaluacion', 'solicitud_enviada', 'info_solicitada'])): ?>
                                                <!-- Botón PRE-APROBAR -->
                                                <form method="POST" style="display:inline" onsubmit="return confirm('¿Pre-aprobar esta solicitud? El cliente deberá firmar el contrato.')">
                                                    <input type="hidden" name="operacion_id" value="<?= $op['id'] ?>">
                                                    <input type="hidden" name="tipo" value="<?= $op['tipo'] ?>">
                                                    <input type="hidden" name="accion" value="pre_aprobar">
                                                    <button type="submit" class="px-3 py-2 bg-green-600 text-white rounded-lg text-xs font-semibold hover:bg-green-700 transition">
                                                        ✓ Pre-Aprobar
                                                    </button>
                                                </form>

                                                <button onclick="mostrarContra(<?= $op['id'] ?>,'<?= $op['tipo'] ?>')" 
                                                        class="px-3 py-2 bg-blue-600 text-white rounded-lg text-xs font-semibold hover:bg-blue-700 transition">
                                                    📄 Solicitar Info
                                                </button>

                                                <button onclick="mostrarRechazo(<?= $op['id'] ?>,'<?= $op['tipo'] ?>')" 
                                                        class="px-3 py-2 bg-red-600 text-white rounded-lg text-xs font-semibold hover:bg-red-700 transition">
                                                    ✗ Rechazar
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($desembolso_pendiente): ?>
                                                <!-- Botón CONFIRMAR TRANSFERENCIA -->
                                                <button onclick="mostrarTransferencia(<?= $op['id'] ?>,'<?= $op['tipo'] ?>', '<?= htmlspecialchars($op['banco'] ?? '') ?>', '<?= htmlspecialchars($op['cbu'] ?? '') ?>', '<?= htmlspecialchars($op['tipo_cuenta'] ?? '') ?>', '<?= htmlspecialchars($op['titular_cuenta'] ?? '') ?>')" 
                                                        class="px-3 py-2 bg-green-600 text-white rounded-lg text-xs font-semibold hover:bg-green-700 transition animate-pulse">
                                                    💰 Confirmar Transferencia
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- MODAL RECHAZO -->
    <div id="modalRechazo" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-8 max-w-md w-full mx-4 shadow-2xl">
            <h3 class="text-2xl font-bold mb-4 text-gray-800">❌ Rechazar Operación</h3>
            <form method="POST">
                <input type="hidden" name="operacion_id" id="rechazo_id">
                <input type="hidden" name="tipo" id="rechazo_tipo">
                <input type="hidden" name="accion" value="rechazar">
                <div class="mb-6">
                    <label class="block text-sm font-semibold mb-2 text-gray-700">Motivo del rechazo *</label>
                    <textarea name="motivo_rechazo" 
                              required 
                              rows="4" 
                              class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500" 
                              placeholder="Ingresá el motivo del rechazo..."></textarea>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="flex-1 px-6 py-3 bg-red-600 text-white rounded-lg font-semibold hover:bg-red-700 transition">
                        Rechazar
                    </button>
                    <button type="button" 
                            onclick="cerrarRechazo()" 
                            class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold hover:bg-gray-300 transition">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL SOLICITAR INFORMACIÓN -->
    <div id="modalContra" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-8 max-w-md w-full mx-4 shadow-2xl">
            <h3 class="text-2xl font-bold mb-4 text-gray-800">📄 Solicitar más información</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="operacion_id" id="contra_id">
                <input type="hidden" name="tipo" id="contra_tipo">
                <input type="hidden" name="accion" value="solicitar_info">

                <div class="mb-4">
                    <label class="block text-sm font-semibold mb-2 text-gray-700">Mensaje para el cliente *</label>
                    <textarea name="mensaje_info" 
                              required 
                              rows="4" 
                              class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" 
                              placeholder="Indicá qué información necesitás del cliente..."></textarea>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-semibold mb-2 text-gray-700">Adjuntar archivos (opcional)</label>
                    <input type="file" 
                           name="archivos[]" 
                           multiple 
                           accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx"
                           class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Podés subir fotos, PDFs, documentos, etc. (Máx. 10MB por archivo)</p>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="flex-1 px-6 py-3 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition">
                        Enviar solicitud
                    </button>
                    <button type="button" 
                            onclick="cerrarContra()" 
                            class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold hover:bg-gray-300 transition">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL CONFIRMAR TRANSFERENCIA -->
    <div id="modalTransferencia" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-8 max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto shadow-2xl">
            <h3 class="text-2xl font-bold mb-4 text-gray-800">💰 Confirmar Transferencia</h3>
            <div class="bg-blue-50 border-2 border-blue-200 rounded-lg p-4 mb-6">
                <h4 class="font-bold text-blue-900 mb-2">Datos Bancarios del Cliente:</h4>
                <div class="space-y-2 text-sm">
                    <div><strong>Banco:</strong> <span id="transfer_banco"></span></div>
                    <div><strong>Tipo de Cuenta:</strong> <span id="transfer_tipo_cuenta"></span></div>
                    <div><strong>CBU:</strong> <code class="bg-white px-2 py-1 rounded font-mono" id="transfer_cbu"></code></div>
                    <div><strong>Titular:</strong> <span id="transfer_titular"></span></div>
                </div>
            </div>
            <form method="POST">
                <input type="hidden" name="operacion_id" id="transfer_id">
                <input type="hidden" name="tipo" id="transfer_tipo">
                <input type="hidden" name="accion" value="confirmar_transferencia">
                <div class="mb-6">
                    <label class="block text-sm font-semibold mb-2 text-gray-700">Notas de la Transferencia (Opcional)</label>
                    <textarea name="notas_transferencia" 
                              rows="3" 
                              class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" 
                              placeholder="Número de operación, banco emisor, etc..."></textarea>
                </div>
                <div class="bg-yellow-50 border-2 border-yellow-300 rounded-lg p-4 mb-6">
                    <p class="text-sm text-yellow-900">
                        ⚠️ <strong>Importante:</strong> Solo confirmá la transferencia después de haberla realizado. El préstamo pasará a estado "Activo".
                    </p>
                </div>
                <div class="flex gap-3">
                    <button type="submit" 
                            class="flex-1 px-6 py-3 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 transition" 
                            onclick="return confirm('¿Confirmar que la transferencia fue realizada?')">
                        ✓ Confirmar Transferencia
                    </button>
                    <button type="button" 
                            onclick="cerrarTransferencia()" 
                            class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold hover:bg-gray-300 transition">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function mostrarRechazo(id, tipo) {
            document.getElementById('rechazo_id').value = id;
            document.getElementById('rechazo_tipo').value = tipo;
            document.getElementById('modalRechazo').classList.remove('hidden');
            document.getElementById('modalRechazo').classList.add('flex');
        }

        function cerrarRechazo() {
            document.getElementById('modalRechazo').classList.add('hidden');
            document.getElementById('modalRechazo').classList.remove('flex');
        }

        function mostrarContra(id, tipo) {
            document.getElementById('contra_id').value = id;
            document.getElementById('contra_tipo').value = tipo;
            document.getElementById('modalContra').classList.remove('hidden');
            document.getElementById('modalContra').classList.add('flex');
        }

        function cerrarContra() {
            document.getElementById('modalContra').classList.add('hidden');
            document.getElementById('modalContra').classList.remove('flex');
        }

        function mostrarTransferencia(id, tipo, banco, cbu, tipo_cuenta, titular) {
            document.getElementById('transfer_id').value = id;
            document.getElementById('transfer_tipo').value = tipo;
            document.getElementById('transfer_banco').textContent = banco || 'N/A';
            document.getElementById('transfer_cbu').textContent = cbu || 'N/A';
            
            let tipoCuentaTexto = 'N/A';
            if (tipo_cuenta === 'caja_ahorro') {
                tipoCuentaTexto = 'Caja de Ahorro';
            } else if (tipo_cuenta === 'cuenta_corriente') {
                tipoCuentaTexto = 'Cuenta Corriente';
            }
            
            document.getElementById('transfer_tipo_cuenta').textContent = tipoCuentaTexto;
            document.getElementById('transfer_titular').textContent = titular || 'N/A';
            document.getElementById('modalTransferencia').classList.remove('hidden');
            document.getElementById('modalTransferencia').classList.add('flex');
        }

        function cerrarTransferencia() {
            document.getElementById('modalTransferencia').classList.add('hidden');
            document.getElementById('modalTransferencia').classList.remove('flex');
        }

        // Cerrar modales al presionar ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarRechazo();
                cerrarContra();
                cerrarTransferencia();
            }
        });

        // Cerrar modales al hacer clic fuera del contenido
        document.getElementById('modalRechazo').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarRechazo();
            }
        });

        document.getElementById('modalContra').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarContra();
            }
        });

        document.getElementById('modalTransferencia').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarTransferencia();
            }
        });
    </script>
</body>
</html>