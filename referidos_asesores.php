<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');
session_start();

// Verificar sesión
if (!isset($_SESSION['asesor_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/connection.php';

$asesor_id = $_SESSION['asesor_id'];
$asesor_nombre = $_SESSION['asesor_nombre'];

// ============================
// VERIFICAR SI EXISTE SISTEMA DE REFERIDOS
// ============================
$check_column = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'referido_por'")->rowCount();
$sistema_disponible = $check_column > 0;

// ============================
// OBTENER REFERIDOS
// ============================
$referidos = [];
$total_referidos = 0;
$referidos_activos = 0;
$referidos_inactivos = 0;

if ($sistema_disponible) {
    // Estadísticas generales
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN estado = 'activo' THEN 1 ELSE 0 END) as activos,
            SUM(CASE WHEN estado != 'activo' THEN 1 ELSE 0 END) as inactivos
        FROM usuarios
        WHERE referido_por = ? AND rol = 'asesor'
    ");
    $stmt->execute([$asesor_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $total_referidos = $stats['total'] ?? 0;
    $referidos_activos = $stats['activos'] ?? 0;
    $referidos_inactivos = $stats['inactivos'] ?? 0;
    
    // Lista detallada de referidos
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.nombre,
            u.email,
            u.telefono,
            u.codigo_area,
            u.estado,
            u.fecha_registro,
            ap.foto_perfil,
            ap.celular,
            ap.whatsapp,
            (SELECT COUNT(*) FROM chats c WHERE c.asesor_id = u.id) as total_chats,
            (SELECT COUNT(*) FROM chats c WHERE c.asesor_id = u.id AND c.estado = 'en_conversacion') as chats_activos,
            (SELECT MAX(c.fecha_inicio) FROM chats c WHERE c.asesor_id = u.id) as ultima_actividad
        FROM usuarios u
        LEFT JOIN asesores_perfil ap ON ap.usuario_id = u.id
        WHERE u.referido_por = ? AND u.rol = 'asesor'
        ORDER BY u.fecha_registro DESC
    ");
    $stmt->execute([$asesor_id]);
    $referidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================
// FILTROS Y BÚSQUEDA
// ============================
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : 'todos';
$busqueda = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';

// Aplicar filtros
if (!empty($referidos)) {
    if ($filtro_estado !== 'todos') {
        $referidos = array_filter($referidos, function($ref) use ($filtro_estado) {
            return $ref['estado'] === $filtro_estado;
        });
    }
    
    if (!empty($busqueda)) {
        $referidos = array_filter($referidos, function($ref) use ($busqueda) {
            $busqueda_lower = strtolower($busqueda);
            return stripos($ref['nombre'], $busqueda_lower) !== false || 
                   stripos($ref['email'], $busqueda_lower) !== false;
        });
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mis Referidos - Asesor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: #f8fafc;
            overflow-x: hidden;
        }

        .page-content {
            margin-left: 260px;
            min-width: 0;
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
            color: #111827;
            margin: 0.5rem 0;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
        }

        .referido-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
            border: 2px solid transparent;
        }

        .referido-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-color: #3b82f6;
        }

        .avatar {
            width: 4rem;
            height: 4rem;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e5e7eb;
        }

        .avatar-placeholder {
            width: 4rem;
            height: 4rem;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.5rem;
            border: 3px solid #e5e7eb;
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

        .search-box {
            border: 2px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            padding-left: 2.5rem;
            width: 100%;
            max-width: 400px;
            transition: border-color 0.2s;
        }

        .search-box:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #9ca3af;
        }

        .empty-state-icon {
            font-size: 5rem;
            margin-bottom: 1rem;
        }

        .action-btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .action-btn.primary {
            background: #3b82f6;
            color: white;
        }

        .action-btn.primary:hover {
            background: #2563eb;
        }

        .action-btn.success {
            background: #22c55e;
            color: white;
        }

        .action-btn.success:hover {
            background: #16a34a;
        }

        @media (max-width: 768px) {
            .referido-card {
                padding: 1rem;
            }

            .stat-value {
                font-size: 2rem;
            }
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #6b7280;
            font-size: 0.875rem;
        }

        .info-row .material-icons-outlined {
            font-size: 1.25rem;
        }
    </style>
</head>
<body>

<?php include 'sidebar_asesores.php'; ?>

<div class="page-content">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Mis Referidos</h1>
        <p class="text-gray-600">Gestiona y monitorea a todos tus asesores referidos</p>
    </div>

    <?php if (!$sistema_disponible): ?>
        <!-- Sistema no instalado -->
        <div class="bg-white rounded-xl p-12 text-center shadow-lg">
            <span class="material-icons-outlined text-gray-300" style="font-size: 5rem;">group_off</span>
            <h3 class="text-2xl font-bold text-gray-800 mt-4 mb-2">Sistema de Referidos No Disponible</h3>
            <p class="text-gray-600 mb-4">El sistema de referidos aún no está configurado en tu cuenta.</p>
            <p class="text-sm text-gray-500">Contacta al administrador para activar esta funcionalidad.</p>
        </div>
    <?php else: ?>

    <!-- Estadísticas -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="stat-card">
            <div class="text-4xl mb-2">👥</div>
            <div class="stat-value"><?php echo $total_referidos; ?></div>
            <div class="stat-label">Total Referidos</div>
        </div>

        <div class="stat-card">
            <div class="text-4xl mb-2">✅</div>
            <div class="stat-value"><?php echo $referidos_activos; ?></div>
            <div class="stat-label">Referidos Activos</div>
        </div>

        <div class="stat-card">
            <div class="text-4xl mb-2">⏸️</div>
            <div class="stat-value"><?php echo $referidos_inactivos; ?></div>
            <div class="stat-label">Referidos Inactivos</div>
        </div>
    </div>

    <!-- Filtros y Búsqueda -->
    <div class="bg-white rounded-xl p-6 shadow-lg mb-6">
        <div class="flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between">
            <!-- Filtros por Estado -->
            <div class="flex flex-wrap gap-2">
                <a href="?estado=todos<?php echo !empty($busqueda) ? '&buscar=' . urlencode($busqueda) : ''; ?>" 
                   class="filter-btn <?php echo $filtro_estado === 'todos' ? 'active' : ''; ?>">
                    Todos
                </a>
                <a href="?estado=activo<?php echo !empty($busqueda) ? '&buscar=' . urlencode($busqueda) : ''; ?>" 
                   class="filter-btn <?php echo $filtro_estado === 'activo' ? 'active' : ''; ?>">
                    Activos
                </a>
                <a href="?estado=inactivo<?php echo !empty($busqueda) ? '&buscar=' . urlencode($busqueda) : ''; ?>" 
                   class="filter-btn <?php echo $filtro_estado === 'inactivo' ? 'active' : ''; ?>">
                    Inactivos
                </a>
                <a href="?estado=suspendido<?php echo !empty($busqueda) ? '&buscar=' . urlencode($busqueda) : ''; ?>" 
                   class="filter-btn <?php echo $filtro_estado === 'suspendido' ? 'active' : ''; ?>">
                    Suspendidos
                </a>
            </div>

            <!-- Búsqueda -->
            <form method="GET" class="relative flex-1 max-w-md">
                <span class="material-icons-outlined search-icon">search</span>
                <input type="text" 
                       name="buscar" 
                       value="<?php echo htmlspecialchars($busqueda); ?>"
                       placeholder="Buscar por nombre o email..." 
                       class="search-box">
                <input type="hidden" name="estado" value="<?php echo htmlspecialchars($filtro_estado); ?>">
            </form>
        </div>
    </div>

    <!-- Lista de Referidos -->
    <?php if (empty($referidos)): ?>
        <div class="bg-white rounded-xl shadow-lg">
            <div class="empty-state">
                <div class="empty-state-icon">👥</div>
                <h3 class="text-xl font-bold text-gray-700 mb-2">No hay referidos</h3>
                <p class="text-gray-500 mb-4">
                    <?php if (!empty($busqueda) || $filtro_estado !== 'todos'): ?>
                        No se encontraron resultados con los filtros aplicados.
                    <?php else: ?>
                        Aún no has referido a ningún asesor.
                    <?php endif; ?>
                </p>
                <a href="link_referidos.php" class="action-btn primary">
                    <span class="material-icons-outlined">share</span>
                    Compartir mi Link
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 gap-6">
            <?php foreach ($referidos as $referido): ?>
                <div class="referido-card">
                    <div class="flex flex-col lg:flex-row gap-4">
                        <!-- Avatar y Info Principal -->
                        <div class="flex items-start gap-4 flex-1">
                            <?php if (!empty($referido['foto_perfil'])): ?>
                                <img src="<?php echo htmlspecialchars($referido['foto_perfil']); ?>" 
                                     alt="<?php echo htmlspecialchars($referido['nombre']); ?>"
                                     class="avatar">
                            <?php else: ?>
                                <div class="avatar-placeholder">
                                    <?php echo strtoupper(substr($referido['nombre'], 0, 2)); ?>
                                </div>
                            <?php endif; ?>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-2">
                                    <h3 class="text-lg font-bold text-gray-800 truncate">
                                        <?php echo htmlspecialchars($referido['nombre']); ?>
                                    </h3>
                                    <span class="badge <?php echo $referido['estado']; ?>">
                                        <?php echo ucfirst($referido['estado']); ?>
                                    </span>
                                </div>

                                <div class="space-y-1">
                                    <div class="info-row">
                                        <span class="material-icons-outlined">email</span>
                                        <span class="truncate"><?php echo htmlspecialchars($referido['email']); ?></span>
                                    </div>

                                    <?php if (!empty($referido['whatsapp']) || !empty($referido['celular'])): ?>
                                        <div class="info-row">
                                            <span class="material-icons-outlined">phone</span>
                                            <span><?php echo htmlspecialchars($referido['whatsapp'] ?: $referido['celular']); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="info-row">
                                        <span class="material-icons-outlined">calendar_today</span>
                                        <span>Registrado: <?php echo date('d/m/Y', strtotime($referido['fecha_registro'])); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Estadísticas de Actividad -->
                        <div class="lg:border-l lg:pl-6 border-gray-200">
                            <h4 class="text-sm font-semibold text-gray-600 mb-3">Actividad</h4>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-blue-600">
                                        <?php echo $referido['total_chats'] ?? 0; ?>
                                    </div>
                                    <div class="text-xs text-gray-500">Total Chats</div>
                                </div>

                                <div class="text-center">
                                    <div class="text-2xl font-bold text-green-600">
                                        <?php echo $referido['chats_activos'] ?? 0; ?>
                                    </div>
                                    <div class="text-xs text-gray-500">Chats Activos</div>
                                </div>
                            </div>

                            <?php if (!empty($referido['ultima_actividad'])): ?>
                                <div class="mt-3 text-xs text-gray-500 text-center">
                                    Última actividad:<br>
                                    <span class="font-semibold">
                                        <?php echo date('d/m/Y H:i', strtotime($referido['ultima_actividad'])); ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="mt-3 text-xs text-gray-400 text-center">
                                    Sin actividad registrada
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Acciones -->
                        <div class="flex lg:flex-col gap-2 justify-center lg:justify-start">
                            <?php if (!empty($referido['whatsapp'])): ?>
                                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $referido['whatsapp']); ?>" 
                                   target="_blank"
                                   class="action-btn success"
                                   title="Contactar por WhatsApp">
                                    <span class="material-icons-outlined">chat</span>
                                    <span class="hidden lg:inline">WhatsApp</span>
                                </a>
                            <?php endif; ?>

                            <a href="mailto:<?php echo htmlspecialchars($referido['email']); ?>" 
                               class="action-btn primary"
                               title="Enviar email">
                                <span class="material-icons-outlined">email</span>
                                <span class="hidden lg:inline">Email</span>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
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
</script>

</body>
</html>