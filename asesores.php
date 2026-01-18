<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');
session_start();

session_start();

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['usuario_rol'])) {
    header("Location: login.php");
    exit;
}

// Verificar que sea admin
if ($_SESSION['usuario_rol'] !== 'admin') {
    // Si no es admin, lo sacamos del dashboard
    header("Location: panel_asesor.php");
    exit;
}

require_once 'backend/connection.php';

$admin_id = $_SESSION['admin_id'];

// ====================================================================
// VERIFICAR SISTEMA DE REFERIDOS
// ====================================================================
$check_column = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'referido_por'")->rowCount();
$sistema_disponible = $check_column > 0;

// ====================================================================
// ESTADÍSTICAS GENERALES
// ====================================================================
$stats_generales = [
    'total_asesores' => 0,
    'asesores_activos' => 0,
    'asesores_inactivos' => 0,
    'total_chats' => 0,
    'total_referidos' => 0,
];

$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN estado = 'activo' THEN 1 ELSE 0 END) as activos,
        SUM(CASE WHEN estado != 'activo' THEN 1 ELSE 0 END) as inactivos
    FROM usuarios
    WHERE rol = 'asesor'
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$stats_generales['total_asesores'] = $stats['total'] ?? 0;
$stats_generales['asesores_activos'] = $stats['activos'] ?? 0;
$stats_generales['asesores_inactivos'] = $stats['inactivos'] ?? 0;

// Total de chats
$stmt = $pdo->query("SELECT COUNT(*) as total FROM chats");
$stats_generales['total_chats'] = $stmt->fetchColumn();

if ($sistema_disponible) {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE referido_por IS NOT NULL AND rol = 'asesor'");
    $stats_generales['total_referidos'] = $stmt->fetchColumn();
}

// ====================================================================
// FILTROS Y BÚSQUEDA
// ====================================================================
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : 'todos';
$busqueda = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$orden = isset($_GET['orden']) ? $_GET['orden'] : 'reciente';

// ====================================================================
// OBTENER LISTADO DE ASESORES CON ESTADÍSTICAS
// ====================================================================
$where_conditions = ["u.rol = 'asesor'"];
$params = [];

if ($filtro_estado !== 'todos') {
    $where_conditions[] = "u.estado = ?";
    $params[] = $filtro_estado;
}

if (!empty($busqueda)) {
    $where_conditions[] = "(u.nombre LIKE ? OR u.email LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

$where_sql = implode(' AND ', $where_conditions);

// Definir orden
$order_sql = match($orden) {
    'antiguedad' => 'u.fecha_registro ASC',
    'nombre' => 'u.nombre ASC',
    'chats' => 'total_chats DESC',
    'referidos' => 'total_referidos_propios DESC',
    default => 'u.fecha_registro DESC',
};

$query = "
    SELECT 
        u.id,
        u.nombre,
        u.email,
        u.telefono,
        u.codigo_area,
        u.estado,
        u.fecha_registro,
        u.codigo_referido,
        u.link_referido,
        u.referido_por,
        ap.foto_perfil,
        ap.celular,
        ap.whatsapp,
        referidor.nombre as referidor_nombre,
        (SELECT COUNT(*) FROM chats c WHERE c.asesor_id = u.id) as total_chats,
        (SELECT COUNT(*) FROM chats c WHERE c.asesor_id = u.id AND c.estado = 'en_conversacion') as chats_activos,
        (SELECT COUNT(*) FROM chats c WHERE c.asesor_id = u.id AND c.estado = 'finalizado') as chats_finalizados,
        (SELECT MAX(c.fecha_inicio) FROM chats c WHERE c.asesor_id = u.id) as ultima_actividad_chat,
        (SELECT COUNT(*) FROM usuarios r WHERE r.referido_por = u.id AND r.rol = 'asesor') as total_referidos_propios,
        (SELECT COUNT(*) FROM usuarios r WHERE r.referido_por = u.id AND r.rol = 'asesor' AND r.estado = 'activo') as referidos_activos,
        er.clicks_link as total_clicks,
        er.conversiones,
        er.tasa_conversion
    FROM usuarios u
    LEFT JOIN asesores_perfil ap ON ap.usuario_id = u.id
    LEFT JOIN usuarios referidor ON referidor.id = u.referido_por
    LEFT JOIN estadisticas_referidos er ON er.asesor_id = u.id
    WHERE $where_sql
    ORDER BY $order_sql
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$asesores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ====================================================================
// PROCESAMIENTO DE ACCIONES (Crear, Editar, Eliminar)
// ====================================================================
$mensaje = null;
$tipo_mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (!empty($nombre) && !empty($email) && !empty($password)) {
            // Verificar si el email existe
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->fetch()) {
                $mensaje = "El email ya está registrado";
                $tipo_mensaje = "error";
            } else {
                try {
                    $pdo->beginTransaction();
                    
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO usuarios (nombre, email, password, rol, estado)
                        VALUES (?, ?, ?, 'asesor', 'activo')
                    ");
                    $stmt->execute([$nombre, $email, $password_hash]);
                    
                    $nuevo_id = $pdo->lastInsertId();
                    
                    // Generar código de referido
                    $codigo = 'REF-' . strtoupper(substr(md5($nuevo_id . time()), 0, 8));
                    $link = 'https://prestamolider.com/system/?ref=' . $codigo;
                    
                    $stmt = $pdo->prepare("
                        UPDATE usuarios 
                        SET codigo_referido = ?, link_referido = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$codigo, $link, $nuevo_id]);
                    
                    $pdo->commit();
                    
                    $mensaje = "Asesor creado exitosamente";
                    $tipo_mensaje = "success";
                    
                    // Recargar página
                    header("refresh:1");
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $mensaje = "Error al crear el asesor";
                    $tipo_mensaje = "error";
                }
            }
        }
    } elseif ($accion === 'cambiar_estado') {
        $asesor_id = $_POST['asesor_id'] ?? 0;
        $nuevo_estado = $_POST['nuevo_estado'] ?? '';
        
        if ($asesor_id && in_array($nuevo_estado, ['activo', 'inactivo', 'suspendido'])) {
            $stmt = $pdo->prepare("UPDATE usuarios SET estado = ? WHERE id = ? AND rol = 'asesor'");
            $stmt->execute([$nuevo_estado, $asesor_id]);
            
            $mensaje = "Estado actualizado correctamente";
            $tipo_mensaje = "success";
            header("refresh:1");
        }
    } elseif ($accion === 'eliminar') {
        $asesor_id = $_POST['asesor_id'] ?? 0;
        
        if ($asesor_id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND rol = 'asesor'");
                $stmt->execute([$asesor_id]);
                
                $mensaje = "Asesor eliminado correctamente";
                $tipo_mensaje = "success";
                header("refresh:1");
            } catch (Exception $e) {
                $mensaje = "No se puede eliminar el asesor (tiene registros relacionados)";
                $tipo_mensaje = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Asesores - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: #f8fafc;
        }

        .page-content {
            margin-left: 260px;
            padding: 2rem;
        }

        @media (max-width: 768px) {
            .page-content {
                margin-left: 0 !important;
                padding: 1rem;
            }
        }

        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0.5rem 0;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
        }

        .card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .asesor-row {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            padding: 1.25rem;
            transition: all 0.2s;
        }

        .asesor-row:hover {
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge.activo {
            background: #dcfce7;
            color: #166534;
        }

        .badge.inactivo {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge.suspendido {
            background: #fef3c7;
            color: #92400e;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }

        .btn-success {
            background: #22c55e;
            color: white;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .avatar {
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            object-fit: cover;
        }

        .avatar-placeholder {
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            border: 2px solid #e5e7eb;
            background: white;
            color: #6b7280;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-btn:hover {
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .filter-btn.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            display: none;
            align-items: center;
            gap: 0.5rem;
            z-index: 9999;
        }

        .toast.success {
            background: #22c55e;
            color: white;
        }

        .toast.error {
            background: #ef4444;
            color: white;
        }

        .info-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.875rem;
            color: #6b7280;
        }

        .info-chip .material-icons-outlined {
            font-size: 1rem;
        }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="page-content">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 mb-2">Gestión de Asesores</h1>
                <p class="text-gray-600">Administra todos los asesores del sistema</p>
            </div>
            <button onclick="openModal('modalCrear')" class="btn btn-primary">
                <span class="material-icons-outlined">person_add</span>
                Crear Asesor
            </button>
        </div>
    </div>

    <!-- Toast de mensajes -->
    <?php if ($mensaje): ?>
    <div class="toast <?php echo $tipo_mensaje; ?>" style="display: flex;" id="toast">
        <span class="material-icons-outlined">
            <?php echo $tipo_mensaje === 'success' ? 'check_circle' : 'error'; ?>
        </span>
        <span><?php echo htmlspecialchars($mensaje); ?></span>
    </div>
    <script>
        setTimeout(() => {
            document.getElementById('toast').style.display = 'none';
        }, 3000);
    </script>
    <?php endif; ?>

    <!-- Estadísticas Generales -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
        <div class="stat-card">
            <div class="text-4xl mb-2">👥</div>
            <div class="stat-value text-blue-600"><?php echo $stats_generales['total_asesores']; ?></div>
            <div class="stat-label">Total Asesores</div>
        </div>

        <div class="stat-card">
            <div class="text-4xl mb-2">✅</div>
            <div class="stat-value text-green-600"><?php echo $stats_generales['asesores_activos']; ?></div>
            <div class="stat-label">Activos</div>
        </div>

        <div class="stat-card">
            <div class="text-4xl mb-2">⏸️</div>
            <div class="stat-value text-red-600"><?php echo $stats_generales['asesores_inactivos']; ?></div>
            <div class="stat-label">Inactivos</div>
        </div>

        <div class="stat-card">
            <div class="text-4xl mb-2">💬</div>
            <div class="stat-value text-purple-600"><?php echo $stats_generales['total_chats']; ?></div>
            <div class="stat-label">Total Chats</div>
        </div>

        <div class="stat-card">
            <div class="text-4xl mb-2">🔗</div>
            <div class="stat-value text-orange-600"><?php echo $stats_generales['total_referidos']; ?></div>
            <div class="stat-label">Total Referidos</div>
        </div>
    </div>

    <!-- Filtros y Búsqueda -->
    <div class="card mb-6">
        <div class="flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between">
            <!-- Filtros por Estado -->
            <div class="flex flex-wrap gap-2">
                <a href="?estado=todos&orden=<?php echo $orden; ?><?php echo !empty($busqueda) ? '&buscar=' . urlencode($busqueda) : ''; ?>" 
                   class="filter-btn <?php echo $filtro_estado === 'todos' ? 'active' : ''; ?>">
                    Todos
                </a>
                <a href="?estado=activo&orden=<?php echo $orden; ?><?php echo !empty($busqueda) ? '&buscar=' . urlencode($busqueda) : ''; ?>" 
                   class="filter-btn <?php echo $filtro_estado === 'activo' ? 'active' : ''; ?>">
                    Activos
                </a>
                <a href="?estado=inactivo&orden=<?php echo $orden; ?><?php echo !empty($busqueda) ? '&buscar=' . urlencode($busqueda) : ''; ?>" 
                   class="filter-btn <?php echo $filtro_estado === 'inactivo' ? 'active' : ''; ?>">
                    Inactivos
                </a>
                <a href="?estado=suspendido&orden=<?php echo $orden; ?><?php echo !empty($busqueda) ? '&buscar=' . urlencode($busqueda) : ''; ?>" 
                   class="filter-btn <?php echo $filtro_estado === 'suspendido' ? 'active' : ''; ?>">
                    Suspendidos
                </a>
            </div>

            <!-- Ordenar -->
            <select onchange="window.location.href='?estado=<?php echo $filtro_estado; ?>&orden=' + this.value + '<?php echo !empty($busqueda) ? '&buscar=' . urlencode($busqueda) : ''; ?>'" 
                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="reciente" <?php echo $orden === 'reciente' ? 'selected' : ''; ?>>Más recientes</option>
                <option value="antiguedad" <?php echo $orden === 'antiguedad' ? 'selected' : ''; ?>>Más antiguos</option>
                <option value="nombre" <?php echo $orden === 'nombre' ? 'selected' : ''; ?>>Por nombre</option>
                <option value="chats" <?php echo $orden === 'chats' ? 'selected' : ''; ?>>Por chats</option>
                <option value="referidos" <?php echo $orden === 'referidos' ? 'selected' : ''; ?>>Por referidos</option>
            </select>

            <!-- Búsqueda -->
            <form method="GET" class="relative flex-1 max-w-md">
                <span class="material-icons-outlined absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">search</span>
                <input type="text" 
                       name="buscar" 
                       value="<?php echo htmlspecialchars($busqueda); ?>"
                       placeholder="Buscar por nombre o email..." 
                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <input type="hidden" name="estado" value="<?php echo htmlspecialchars($filtro_estado); ?>">
                <input type="hidden" name="orden" value="<?php echo htmlspecialchars($orden); ?>">
            </form>
        </div>
    </div>

    <!-- Listado de Asesores -->
    <?php if (empty($asesores)): ?>
        <div class="card text-center py-12">
            <span class="material-icons-outlined text-gray-300" style="font-size: 5rem;">person_search</span>
            <h3 class="text-xl font-bold text-gray-700 mt-4 mb-2">No se encontraron asesores</h3>
            <p class="text-gray-500">
                <?php if (!empty($busqueda) || $filtro_estado !== 'todos'): ?>
                    Intenta ajustar los filtros de búsqueda
                <?php else: ?>
                    Crea el primer asesor del sistema
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($asesores as $asesor): ?>
                <div class="asesor-row">
                    <div class="flex flex-col lg:flex-row gap-4">
                        <!-- Avatar e Info Principal -->
                        <div class="flex items-start gap-4 flex-1">
                            <?php if (!empty($asesor['foto_perfil'])): ?>
                                <img src="<?php echo htmlspecialchars($asesor['foto_perfil']); ?>" 
                                     alt="<?php echo htmlspecialchars($asesor['nombre']); ?>"
                                     class="avatar">
                            <?php else: ?>
                                <div class="avatar-placeholder">
                                    <?php echo strtoupper(substr($asesor['nombre'], 0, 2)); ?>
                                </div>
                            <?php endif; ?>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-2 flex-wrap">
                                    <h3 class="text-lg font-bold text-gray-800">
                                        <?php echo htmlspecialchars($asesor['nombre']); ?>
                                    </h3>
                                    <span class="badge <?php echo $asesor['estado']; ?>">
                                        <?php echo ucfirst($asesor['estado']); ?>
                                    </span>
                                    <?php if ($asesor['referido_por']): ?>
                                        <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full">
                                            Referido por: <?php echo htmlspecialchars($asesor['referidor_nombre']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm text-gray-600">
                                    <div class="info-chip">
                                        <span class="material-icons-outlined">email</span>
                                        <span class="truncate"><?php echo htmlspecialchars($asesor['email']); ?></span>
                                    </div>

                                    <?php if (!empty($asesor['whatsapp']) || !empty($asesor['celular'])): ?>
                                    <div class="info-chip">
                                        <span class="material-icons-outlined">phone</span>
                                        <span><?php echo htmlspecialchars($asesor['whatsapp'] ?: $asesor['celular']); ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <div class="info-chip">
                                        <span class="material-icons-outlined">calendar_today</span>
                                        <span>Desde: <?php echo date('d/m/Y', strtotime($asesor['fecha_registro'])); ?></span>
                                    </div>

                                    <?php if ($asesor['codigo_referido']): ?>
                                    <div class="info-chip">
                                        <span class="material-icons-outlined">qr_code</span>
                                        <span>Código: <?php echo htmlspecialchars($asesor['codigo_referido']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Estadísticas -->
                        <div class="lg:border-l lg:pl-6 border-gray-200">
                            <h4 class="text-sm font-semibold text-gray-600 mb-3">Métricas</h4>
                            
                            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-2 gap-4">
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-blue-600">
                                        <?php echo $asesor['total_chats'] ?? 0; ?>
                                    </div>
                                    <div class="text-xs text-gray-500">Chats</div>
                                </div>

                                <div class="text-center">
                                    <div class="text-2xl font-bold text-green-600">
                                        <?php echo $asesor['chats_activos'] ?? 0; ?>
                                    </div>
                                    <div class="text-xs text-gray-500">Activos</div>
                                </div>

                                <div class="text-center">
                                    <div class="text-2xl font-bold text-orange-600">
                                        <?php echo $asesor['total_referidos_propios'] ?? 0; ?>
                                    </div>
                                    <div class="text-xs text-gray-500">Referidos</div>
                                </div>

                                <div class="text-center">
                                    <div class="text-2xl font-bold text-purple-600">
                                        <?php echo $asesor['total_clicks'] ?? 0; ?>
                                    </div>
                                    <div class="text-xs text-gray-500">Clicks</div>
                                </div>
                            </div>

                            <?php if ($asesor['tasa_conversion'] > 0): ?>
                            <div class="mt-3 text-center">
                                <span class="text-xs text-gray-500">Conversión: </span>
                                <span class="text-sm font-bold text-blue-600">
                                    <?php echo $asesor['tasa_conversion']; ?>%
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Acciones -->
                        <div class="flex lg:flex-col gap-2 justify-center lg:justify-start">
                            <?php if ($asesor['estado'] === 'activo'): ?>
                                <button onclick="cambiarEstado(<?php echo $asesor['id']; ?>, 'inactivo')" 
                                        class="btn btn-warning text-sm">
                                    <span class="material-icons-outlined text-sm">pause</span>
                                    <span class="hidden lg:inline">Desactivar</span>
                                </button>
                            <?php else: ?>
                                <button onclick="cambiarEstado(<?php echo $asesor['id']; ?>, 'activo')" 
                                        class="btn btn-success text-sm">
                                    <span class="material-icons-outlined text-sm">play_arrow</span>
                                    <span class="hidden lg:inline">Activar</span>
                                </button>
                            <?php endif; ?>                           

                            <button onclick="confirmarEliminar(<?php echo $asesor['id']; ?>, '<?php echo htmlspecialchars(addslashes($asesor['nombre'])); ?>')" 
                                    class="btn btn-danger text-sm">
                                <span class="material-icons-outlined text-sm">delete</span>
                                <span class="hidden lg:inline">Eliminar</span>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Crear Asesor -->
<div id="modalCrear" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Crear Nuevo Asesor</h2>
            <button onclick="closeModal('modalCrear')" class="text-gray-500 hover:text-gray-700">
                <span class="material-icons-outlined">close</span>
            </button>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="accion" value="crear">

            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-semibold mb-2">Nombre Completo</label>
                <input type="text" 
                       name="nombre" 
                       required
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Juan Pérez">
            </div>

            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-semibold mb-2">Email</label>
                <input type="email" 
                       name="email" 
                       required
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="asesor@prestamolider.com">
            </div>

            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-semibold mb-2">Contraseña</label>
                <input type="password" 
                       name="password" 
                       required
                       minlength="6"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Mínimo 6 caracteres">
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary flex-1">
                    <span class="material-icons-outlined">person_add</span>
                    Crear Asesor
                </button>
                <button type="button" onclick="closeModal('modalCrear')" class="btn btn-secondary">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Confirmar Eliminar -->
<div id="modalEliminar" class="modal">
    <div class="modal-content">
        <div class="text-center mb-6">
            <span class="material-icons-outlined text-red-600" style="font-size: 4rem;">warning</span>
            <h2 class="text-2xl font-bold text-gray-800 mt-4">¿Eliminar Asesor?</h2>
            <p class="text-gray-600 mt-2">Esta acción no se puede deshacer</p>
            <p class="text-gray-800 font-semibold mt-2" id="nombreEliminar"></p>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="asesor_id" id="asesorIdEliminar">

            <div class="flex gap-2">
                <button type="submit" class="btn btn-danger flex-1">
                    <span class="material-icons-outlined">delete</span>
                    Sí, Eliminar
                </button>
                <button type="button" onclick="closeModal('modalEliminar')" class="btn btn-secondary">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

function cambiarEstado(asesorId, nuevoEstado) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="accion" value="cambiar_estado">
        <input type="hidden" name="asesor_id" value="${asesorId}">
        <input type="hidden" name="nuevo_estado" value="${nuevoEstado}">
    `;
    document.body.appendChild(form);
    form.submit();
}

function confirmarEliminar(asesorId, nombre) {
    document.getElementById('asesorIdEliminar').value = asesorId;
    document.getElementById('nombreEliminar').textContent = nombre;
    openModal('modalEliminar');
}

function verDetalles(asesorId) {
    // Redirigir a una página de detalles (puedes crearla)
    window.location.href = 'asesor_detalle.php?id=' + asesorId;
}

// Auto-submit del formulario de búsqueda con delay
let searchTimeout;
const searchInput = document.querySelector('input[name="buscar"]');

if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            this.form.submit();
        }, 500);
    });
}

// Cerrar modales al hacer click fuera
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

</body>
</html>