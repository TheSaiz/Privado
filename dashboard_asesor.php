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
// ESTADÍSTICAS DEL ASESOR
// ============================

// Total de chats atendidos
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT c.id) as total_chats
    FROM chats c
    WHERE c.asesor_id = ?
");
$stmt->execute([$asesor_id]);
$stats_chats = $stmt->fetch(PDO::FETCH_ASSOC);

// Chats activos actualmente
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT c.id) as chats_activos
    FROM chats c
    WHERE c.asesor_id = ? AND c.estado = 'en_conversacion'
");
$stmt->execute([$asesor_id]);
$stats_activos = $stmt->fetch(PDO::FETCH_ASSOC);

// Total de referidos (verificar si la columna existe)
$check_column = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'referido_por'")->rowCount();
if ($check_column > 0) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_referidos
        FROM usuarios
        WHERE referido_por = ? AND rol = 'asesor'
    ");
    $stmt->execute([$asesor_id]);
    $stats_referidos = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stats_referidos = ['total_referidos' => 0];
}

// ============================
// ÚLTIMOS REFERIDOS
// ============================
$ultimos_referidos = [];
if ($check_column > 0) {
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.nombre,
            u.email,
            u.fecha_registro as fecha_creacion
        FROM usuarios u
        WHERE u.referido_por = ? AND u.rol = 'asesor'
        ORDER BY u.fecha_registro DESC
        LIMIT 5
    ");
    $stmt->execute([$asesor_id]);
    $ultimos_referidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================
// ACTIVIDAD RECIENTE (CHATS)
// ============================
$stmt = $pdo->prepare("
    SELECT 
        c.id as chat_id,
        c.fecha_inicio,
        c.estado,
        u.nombre as cliente_nombre,
        d.nombre as departamento_nombre
    FROM chats c
    INNER JOIN usuarios u ON c.cliente_id = u.id
    LEFT JOIN departamentos d ON c.departamento_id = d.id
    WHERE c.asesor_id = ?
    ORDER BY c.fecha_inicio DESC
    LIMIT 10
");
$stmt->execute([$asesor_id]);
$actividad_reciente = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================
// DATOS PARA GRÁFICO (última semana)
// ============================
$stmt = $pdo->prepare("
    SELECT 
        DATE(c.fecha_inicio) as fecha,
        COUNT(*) as total
    FROM chats c
    WHERE c.asesor_id = ? 
        AND c.fecha_inicio >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(c.fecha_inicio)
    ORDER BY fecha ASC
");
$stmt->execute([$asesor_id]);
$datos_grafico = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Preparar datos para el gráfico
$labels_grafico = [];
$datos_chats = [];
$dias_semana = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

// Llenar últimos 7 días
for ($i = 6; $i >= 0; $i--) {
    $fecha = date('Y-m-d', strtotime("-$i days"));
    $dia_semana = $dias_semana[date('w', strtotime($fecha))];
    $labels_grafico[] = $dia_semana;
    
    // Buscar si hay datos para esta fecha
    $encontrado = false;
    foreach ($datos_grafico as $dato) {
        if ($dato['fecha'] === $fecha) {
            $datos_chats[] = (int)$dato['total'];
            $encontrado = true;
            break;
        }
    }
    if (!$encontrado) {
        $datos_chats[] = 0;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard - Asesor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

    <style>
    
    /* =====================================================
   ESTILOS PARA NOTIFICACIONES DE REFERIDOS
   ===================================================== */

/* Badge de "TU REFERIDO" */
.badge-referido-propio {
    display: inline-block;
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: #78350f;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    animation: badgePulse 2s infinite;
}

@keyframes badgePulse {
    0%, 100% { 
        transform: scale(1);
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }
    50% { 
        transform: scale(1.05);
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.5);
    }
}

/* Badge de "Referido" (de otro) */
.badge-referido-otro {
    display: inline-block;
    background: linear-gradient(135deg, #c4b5fd, #a78bfa);
    color: #5b21b6;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

/* Notificación de referido propio */
.toast-notification.referido-propio {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border-left: 4px solid #f59e0b !important;
    box-shadow: 0 10px 40px rgba(245, 158, 11, 0.3);
}

.toast-notification.referido-propio h4 {
    color: #78350f;
}

.toast-notification.referido-propio p {
    color: #92400e;
}

/* Icono estrella pulsante */
.pulse-icon {
    animation: pulseScale 1.5s infinite;
}

@keyframes pulseScale {
    0%, 100% { 
        transform: scale(1);
        opacity: 1;
    }
    50% { 
        transform: scale(1.2);
        opacity: 0.8;
    }
}

/* Destacar notificación */
.notification-highlight {
    animation: highlightPulse 1s ease-out;
}

@keyframes highlightPulse {
    0% { 
        transform: scale(1); 
        box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.7); 
    }
    50% { 
        transform: scale(1.05); 
        box-shadow: 0 0 20px 10px rgba(37, 99, 235, 0); 
    }
    100% { 
        transform: scale(1); 
        box-shadow: 0 0 0 0 rgba(37, 99, 235, 0); 
    }
}

/* Override para referidos propios */
.notification-highlight.referido-propio {
    animation: highlightPulseGold 1s ease-out;
}

@keyframes highlightPulseGold {
    0% { 
        transform: scale(1); 
        box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7); 
    }
    50% { 
        transform: scale(1.05); 
        box-shadow: 0 0 20px 10px rgba(245, 158, 11, 0); 
    }
    100% { 
        transform: scale(1); 
        box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); 
    }
}

/* Botón dorado para referidos */
.bg-gradient-to-r.from-yellow-500 {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    box-shadow: 0 8px 20px rgba(245, 158, 11, 0.4);
}

.bg-gradient-to-r.from-yellow-500:hover {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    box-shadow: 0 12px 25px rgba(245, 158, 11, 0.5);
}

/* Animación de entrada para notificaciones especiales */
@keyframes slideInGold {
    from { 
        transform: translateX(400px) rotate(5deg); 
        opacity: 0; 
    }
    to { 
        transform: translateX(0) rotate(0); 
        opacity: 1; 
    }
}

.toast-notification.referido-propio {
    animation: slideInGold 0.4s ease-out;
}
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
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .stat-card-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-card-value {
            font-size: 2rem;
            font-weight: bold;
            color: #111827;
            margin: 0.5rem 0;
        }

        .stat-card-label {
            font-size: 0.875rem;
            color: #6b7280;
        }

        .chart-container {
            width: 100%;
            height: 300px;
            position: relative;
            min-width: 0;
        }

        @media (min-width: 768px) {
            .chart-container { height: 350px; }
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        @media (max-width: 768px) {
            table { min-width: 600px; }
            th, td { padding: 0.5rem; font-size: 0.875rem; }
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge.activo { background: #dcfce7; color: #166534; }
        .status-badge.cerrado { background: #fee2e2; color: #991b1b; }
        .status-badge.pendiente { background: #fef3c7; color: #92400e; }

        .notification-badge {
            background: #ef4444;
            color: white;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            min-width: 20px;
            text-align: center;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #9ca3af;
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        #notificaciones-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
        }

        .toast-notification {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            border-left: 4px solid #3b82f6;
            animation: slideIn 0.3s ease-out;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .toast-notification:hover {
            transform: translateX(-5px);
        }

        @keyframes slideIn {
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(400px); opacity: 0; }
        }
    </style>
</head>
<body>

<?php include 'sidebar_asesores.php'; ?>

<div class="page-content">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Dashboard</h1>
        <p class="text-gray-600">Bienvenido, <?php echo htmlspecialchars($asesor_nombre); ?></p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
        <div class="stat-card">
            <div class="flex items-start justify-between mb-3">
                <div class="stat-card-icon" style="background: #dbeafe; color: #1d4ed8;">
                    <span class="material-icons-outlined">chat_bubble</span>
                </div>
            </div>
            <div class="stat-card-value"><?php echo $stats_chats['total_chats'] ?? 0; ?></div>
            <div class="stat-card-label">Total de Chats</div>
        </div>

        <div class="stat-card">
            <div class="flex items-start justify-between mb-3">
                <div class="stat-card-icon" style="background: #dcfce7; color: #15803d;">
                    <span class="material-icons-outlined">forum</span>
                </div>
                <span id="chats-activos-badge" class="notification-badge" style="display: none;">0</span>
            </div>
            <div class="stat-card-value"><?php echo $stats_activos['chats_activos'] ?? 0; ?></div>
            <div class="stat-card-label">Chats Activos</div>
        </div>

        <div class="stat-card">
            <div class="flex items-start justify-between mb-3">
                <div class="stat-card-icon" style="background: #fce7f3; color: #be185d;">
                    <span class="material-icons-outlined">group_add</span>
                </div>
            </div>
            <div class="stat-card-value"><?php echo $stats_referidos['total_referidos'] ?? 0; ?></div>
            <div class="stat-card-label">Total Referidos</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Actividad de la Semana</h3>
            <div class="chart-container">
                <canvas id="activityChart"></canvas>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-800">Últimos Referidos</h3>
                <a href="referidos_asesores.php" class="text-sm text-blue-600 hover:text-blue-800 font-semibold">Ver todos →</a>
            </div>

            <?php if (empty($ultimos_referidos)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">👥</div>
                    <p class="text-sm">Aún no tienes referidos</p>
                    <a href="link_referidos.php" class="inline-block mt-3 text-sm text-blue-600 hover:text-blue-800 font-semibold">Compartir mi link</a>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($ultimos_referidos as $referido): ?>
                        <div class="flex items-center gap-3 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 transition">
                            <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-green-500 rounded-full flex items-center justify-center text-white font-bold flex-shrink-0">
                                <?php echo strtoupper(substr($referido['nombre'], 0, 2)); ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-gray-800 text-sm truncate"><?php echo htmlspecialchars($referido['nombre']); ?></p>
                                <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($referido['email']); ?></p>
                            </div>
                            <div class="text-xs text-gray-400 flex-shrink-0"><?php echo date('d/m/Y', strtotime($referido['fecha_creacion'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-lg p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Actividad Reciente</h3>

        <?php if (empty($actividad_reciente)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📋</div>
                <p class="text-sm">No hay actividad reciente</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600">Cliente</th>
                            <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600">Departamento</th>
                            <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600">Fecha</th>
                            <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600">Estado</th>
                            <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($actividad_reciente as $chat): ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                <td class="py-3 px-4 text-sm text-gray-800"><?php echo htmlspecialchars($chat['cliente_nombre']); ?></td>
                                <td class="py-3 px-4 text-sm text-gray-600"><?php echo htmlspecialchars($chat['departamento_nombre']); ?></td>
                                <td class="py-3 px-4 text-sm text-gray-600"><?php echo date('d/m/Y H:i', strtotime($chat['fecha_inicio'])); ?></td>
                                <td class="py-3 px-4">
                                    <?php
                                    $estado_clase = match($chat['estado']) {
                                        'en_conversacion' => 'activo',
                                        'cerrado' => 'cerrado',
                                        'pendiente' => 'pendiente',
                                        default => 'cerrado'
                                    };
                                    $estado_texto = match($chat['estado']) {
                                        'en_conversacion' => 'Activo',
                                        'cerrado' => 'Cerrado',
                                        'pendiente' => 'Pendiente',
                                        default => ucfirst($chat['estado'])
                                    };
                                    ?>
                                    <span class="status-badge <?php echo $estado_clase; ?>"><?php echo $estado_texto; ?></span>
                                </td>
                                <td class="py-3 px-4">
                                    <?php if ($chat['estado'] === 'en_conversacion'): ?>
                                        <a href="panel_asesor.php?chat_id=<?php echo $chat['chat_id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm font-semibold">Ver chat →</a>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-sm">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="notificaciones-container"></div>

<script>
// =====================================================
// NOTIFICACIONES CON SISTEMA DE REFERIDOS - VERSIÓN UNIFICADA
// =====================================================

const asesorId = <?php echo $asesor_id; ?>;
let knownPendingChats = new Set();
let activeNotifications = new Map();

// Gráfico
const activityChart = new Chart(document.getElementById('activityChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($labels_grafico); ?>,
        datasets: [{
            label: 'Chats',
            data: <?php echo json_encode($datos_chats); ?>,
            borderColor: 'rgb(37, 99, 235)',
            backgroundColor: 'rgba(37, 99, 235, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

// =====================================================
// FUNCIÓN PARA MOSTRAR NOTIFICACIÓN DE CHAT
// =====================================================
function showNewChatNotification(chat, isFirst) {
    const container = document.getElementById('notificaciones-container');
    if (document.getElementById('notif-' + chat.id)) return;

    const notif = document.createElement('div');
    notif.id = 'notif-' + chat.id;
    
    // 🔥 CLASES ESPECIALES SEGÚN TIPO
    let notifClass = 'toast-notification';
    let borderColor = '#3b82f6';
    let badge = '';
    let bgGradient = '';
    
    if (chat.tipo_notificacion === 'referido_propio') {
        notifClass += ' referido-propio';
        borderColor = '#f59e0b';
        badge = '<div class="badge-referido-propio">⭐ TU REFERIDO</div>';
        bgGradient = 'linear-gradient(135deg, #fef3c7, #fde68a)';
    } else if (chat.tipo_notificacion === 'referido_otro') {
        borderColor = '#8b5cf6';
        badge = '<div class="badge-referido-otro">👥 Referido</div>';
    }
    
    notif.className = notifClass;
    notif.style.borderLeft = `4px solid ${borderColor}`;
    if (bgGradient) notif.style.background = bgGradient;
    
    notif.innerHTML = `
        <div class="flex items-start gap-3">
            <span class="material-icons-outlined ${chat.tipo_notificacion === 'referido_propio' ? 'text-yellow-600 pulse-icon' : 'text-blue-600'}">${chat.tipo_notificacion === 'referido_propio' ? 'star' : 'notifications_active'}</span>
            <div class="flex-1">
                ${badge}
                <h4 class="font-bold text-sm ${chat.tipo_notificacion === 'referido_propio' ? 'text-gray-800' : 'text-gray-800'}">
                    ${chat.tipo_notificacion === 'referido_propio' ? '⭐ Nuevo chat de tu referido' : '🔔 Nuevo chat disponible'}
                </h4>
                <p class="text-xs ${chat.tipo_notificacion === 'referido_propio' ? 'text-gray-700' : 'text-gray-600'} mt-1">
                    ${escapeHtml(chat.cliente_nombre)} - ${escapeHtml(chat.departamento_nombre)}
                </p>
                <p class="text-xs ${chat.tipo_notificacion === 'referido_propio' ? 'text-gray-600' : 'text-gray-500'} mt-1">
                    📅 ${new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })}
                </p>
                <div class="mt-3">
                    ${getAcceptButton(chat, isFirst)}
                </div>
            </div>
            <button onclick="dismissNotification(${chat.id})" class="text-gray-400 hover:text-gray-600 transition">
                <span class="material-icons-outlined text-sm">close</span>
            </button>
        </div>
    `;
    
    container.appendChild(notif);
    activeNotifications.set(chat.id, notif.id);
    
    if (isFirst || chat.tipo_notificacion === 'referido_propio') {
        playNotificationSound();
    }
}

// =====================================================
// BOTÓN DE ACEPTAR SEGÚN TIPO Y POSICIÓN
// =====================================================
function getAcceptButton(chat, isFirst) {
    if (chat.tipo_notificacion === 'referido_propio') {
        return `
            <button onclick="acceptChat(${chat.id})" 
                    class="w-full px-3 py-2 bg-gradient-to-r from-yellow-500 to-yellow-600 text-white rounded-lg text-sm font-semibold hover:from-yellow-600 hover:to-yellow-700 transition shadow-md flex items-center justify-center gap-2">
                <span class="material-icons-outlined text-sm">star</span>
                Aceptar Mi Referido
            </button>
        `;
    }
    
    if (isFirst) {
        return `
            <button onclick="acceptChat(${chat.id})" 
                    class="w-full px-3 py-2 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg text-sm font-semibold hover:from-green-600 hover:to-green-700 transition shadow-md flex items-center justify-center gap-2">
                <span class="material-icons-outlined text-sm">check_circle</span>
                Aceptar Chat
            </button>
        `;
    }
    
    return `
        <button disabled 
                class="w-full px-3 py-2 bg-gray-300 text-gray-500 rounded-lg text-sm font-semibold cursor-not-allowed flex items-center justify-center gap-2">
            <span class="material-icons-outlined text-sm">schedule</span>
            En espera
        </button>
    `;
}

// =====================================================
// ACEPTAR CHAT
// =====================================================
async function acceptChat(chatIdToAccept) {
    const notif = document.getElementById('notif-' + chatIdToAccept);
    if (notif) {
        const button = notif.querySelector('button:not([onclick*="dismiss"])');
        if (button) {
            button.disabled = true;
            button.innerHTML = '<span class="material-icons-outlined text-sm animate-spin">hourglass_empty</span> Aceptando...';
        }
    }

    try {
        const response = await fetch('api/asesores/accept_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ chat_id: chatIdToAccept, asesor_id: asesorId })
        });
        const data = await response.json();

        if (data.success) {
            dismissNotification(chatIdToAccept);
            
            const mensaje = data.data && data.data.tipo === 'referido' 
                ? '⭐ ¡Referido aceptado correctamente!' 
                : 'Chat aceptado correctamente';
                
            showToast(mensaje, 'success');
            window.location.href = `panel_asesor.php?chat_id=${chatIdToAccept}`;
        } else {
            dismissNotification(chatIdToAccept);
            showToast(data.message || 'El chat ya fue aceptado por otro asesor', 'info');
        }
    } catch (error) {
        console.error('Error:', error);
        if (notif) {
            const button = notif.querySelector('button:not([onclick*="dismiss"])');
            if (button) {
                button.disabled = false;
                button.innerHTML = getAcceptButton({tipo_notificacion: 'normal'}, true);
            }
        }
        alert('Error al aceptar el chat');
    }
}

// =====================================================
// ACTUALIZAR ESTADO DE NOTIFICACIÓN
// =====================================================
function updateNotificationState(chatId, isFirst, chat) {
    const notif = document.getElementById('notif-' + chatId);
    if (!notif) return;
    
    const actionDiv = notif.querySelector('.mt-3');
    if (!actionDiv) return;
    
    actionDiv.innerHTML = getAcceptButton(chat, isFirst);
    
    if (isFirst || chat.tipo_notificacion === 'referido_propio') {
        notif.classList.add('notification-highlight');
        setTimeout(() => notif.classList.remove('notification-highlight'), 1000);
        playNotificationSound();
    }
}

// =====================================================
// POLLING DE CHATS PENDIENTES
// =====================================================
setInterval(async () => {
    try {
        const response = await fetch(`api/asesores/get_pending_chats.php?asesor_id=${asesorId}`);
        const data = await response.json();
        const badge = document.getElementById('chats-activos-badge');

        if (data.success && data.data.chats.length > 0) {
            const pendingChats = data.data.chats;
            
            // 🔥 SEPARAR REFERIDOS PROPIOS DEL RESTO
            const misReferidos = pendingChats.filter(c => c.tipo_notificacion === 'referido_propio');
            const otrosChats = pendingChats.filter(c => c.tipo_notificacion !== 'referido_propio');
            
            badge.textContent = pendingChats.length;
            badge.style.display = 'inline-flex';

            const currentChatIds = new Set(pendingChats.map(c => c.id));
            activeNotifications.forEach((notifId, chatId) => {
                if (!currentChatIds.has(chatId)) dismissNotification(chatId);
            });

            // 🔥 PROCESAR MIS REFERIDOS PRIMERO
            misReferidos.forEach((chat) => {
                if (!knownPendingChats.has(chat.id)) {
                    knownPendingChats.add(chat.id);
                    showNewChatNotification(chat, true);
                } else {
                    updateNotificationState(chat.id, true, chat);
                }
            });
            
            // PROCESAR OTROS CHATS
            otrosChats.forEach((chat, index) => {
                const isFirst = index === 0;
                if (!knownPendingChats.has(chat.id)) {
                    knownPendingChats.add(chat.id);
                    showNewChatNotification(chat, isFirst);
                } else {
                    updateNotificationState(chat.id, isFirst, chat);
                }
            });
            
        } else {
            badge.style.display = 'none';
            activeNotifications.forEach((notifId, chatId) => dismissNotification(chatId));
        }
    } catch (error) {
        console.error('Error polling chats:', error);
    }
}, 3000);

// =====================================================
// FUNCIONES AUXILIARES
// =====================================================

function dismissNotification(chatId) {
    const notif = document.getElementById('notif-' + chatId);
    if (!notif) return;
    notif.style.animation = "slideOut 0.3s forwards";
    setTimeout(() => notif.parentNode?.removeChild(notif), 300);
    activeNotifications.delete(chatId);
    knownPendingChats.delete(chatId);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function playNotificationSound() {
    try {
        const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBSuCzvLbjzgIG2S59+qSP');
        audio.volume = 0.5;
        audio.play().catch(e => console.warn('No se pudo reproducir sonido:', e));
    } catch (e) {}
}

function showToast(message, type = 'info') {
    const container = document.getElementById('notificaciones-container');
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'success' ? 'bg-green-100 border-green-500' : 'bg-blue-100 border-blue-500'} border-l-4 p-4 rounded-xl shadow-lg max-w-sm`;
    const icon = type === 'success' ? 'check_circle' : 'info';
    const iconColor = type === 'success' ? 'text-green-600' : 'text-blue-600';
    toast.innerHTML = `<div class="flex items-center gap-3"><span class="material-icons-outlined ${iconColor}">${icon}</span><span class="text-sm font-semibold">${escapeHtml(message)}</span></div>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s forwards';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

window.addEventListener('resize', () => { if (activityChart) activityChart.resize(); });
</script>

</body>
</html>