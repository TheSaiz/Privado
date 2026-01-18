<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Verificar autenticación
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['usuario_rol'])) {
    header("Location: login.php");
    exit;
}

// Solo admin y asesores pueden ver clientes
if (!in_array($_SESSION['usuario_rol'], ['admin', 'asesor'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/connection.php';

$usuario_id = $_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];

// Obtener el ID del cliente
$cliente_id = isset($_GET['cliente_id']) ? intval($_GET['cliente_id']) : 0;

if ($cliente_id === 0) {
    $_SESSION['error'] = 'ID de cliente no válido';
    header("Location: clientes.php");
    exit;
}

// Verificar que el cliente existe
try {
    $stmt = $pdo->prepare("SELECT id, nombre, telefono, email FROM usuarios WHERE id = ?");
    $stmt->execute([$cliente_id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        $_SESSION['error'] = 'Cliente no encontrado';
        header("Location: clientes.php");
        exit;
    }
} catch (Exception $e) {
    error_log("Error al obtener cliente: " . $e->getMessage());
    $_SESSION['error'] = 'Error al cargar información del cliente';
    header("Location: clientes.php");
    exit;
}

// Obtener DNI del cliente
try {
    $stmt = $pdo->prepare("SELECT dni FROM clientes_detalles WHERE usuario_id = ?");
    $stmt->execute([$cliente_id]);
    $cliente_detalle = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cliente_detalle) {
        $cliente_detalle = ['dni' => 'N/A'];
    }
} catch (Exception $e) {
    error_log("Error al obtener detalles del cliente: " . $e->getMessage());
    $cliente_detalle = ['dni' => 'N/A'];
}

// Obtener todas las conversaciones del cliente
try {
    // Debug: verificar cuántos chats tiene este cliente
    $debug_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM chats WHERE cliente_id = ?");
    $debug_stmt->execute([$cliente_id]);
    $debug_total = $debug_stmt->fetchColumn();
    error_log("DEBUG: Cliente ID {$cliente_id} tiene {$debug_total} chats en total");
    
    $debug_stmt2 = $pdo->prepare("SELECT COUNT(*) as total FROM chats WHERE cliente_id = ? AND origen = 'chatbot'");
    $debug_stmt2->execute([$cliente_id]);
    $debug_total2 = $debug_stmt2->fetchColumn();
    error_log("DEBUG: Cliente ID {$cliente_id} tiene {$debug_total2} chats con origen='chatbot'");
    
    $stmt = $pdo->prepare("
        SELECT
            c.id as chat_id,
            c.fecha_inicio,
            c.fecha_fin,
            c.estado_solicitud,
            c.prioridad,
            COALESCE(c.score, 0) as score,
            c.cuil_validado,
            c.nombre_validado,
            c.situacion_laboral,
            c.banco,
            c.ciudad,
            c.pais,
            COALESCE(c.api_enviado, 0) as api_enviado,
            c.api_respuesta,
            c.origen,
            d.nombre as departamento_nombre,
            asesor.nombre as asesor_nombre,
            (SELECT COUNT(*) FROM mensajes WHERE chat_id = c.id) as total_mensajes,
            (SELECT COUNT(*) FROM cliente_notas WHERE chat_id = c.id) as total_notas,
            (SELECT GROUP_CONCAT(tag SEPARATOR ',') FROM cliente_tags WHERE chat_id = c.id) as tags
        FROM chats c
        LEFT JOIN departamentos d ON c.departamento_id = d.id
        LEFT JOIN usuarios asesor ON c.asesor_id = asesor.id
        WHERE c.cliente_id = ?
        ORDER BY c.fecha_inicio DESC
    ");
    $stmt->execute([$cliente_id]);
    $conversaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("DEBUG: Se encontraron " . count($conversaciones) . " conversaciones para cliente_id={$cliente_id}");
    
} catch (Exception $e) {
    error_log("Error al obtener conversaciones: " . $e->getMessage());
    $conversaciones = [];
}

function crmBadge($api_enviado) {
    $api_enviado = (int)$api_enviado;
    if ($api_enviado === 1) {
        return '<span class="px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700">Enviado</span>';
    }
    if ($api_enviado === 2) {
        return '<span class="px-2 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-700">Enviando</span>';
    }
    if ($api_enviado === -1) {
        return '<span class="px-2 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700">Error</span>';
    }
    return '<span class="px-2 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">Pendiente</span>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Conversaciones - <?php echo htmlspecialchars($cliente['nombre']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <style>
        .prioridad-baja { background: #e5e7eb; color: #6b7280; }
        .prioridad-media { background: #dbeafe; color: #1e40af; }
        .prioridad-alta { background: #fef3c7; color: #92400e; }
        .prioridad-urgente { background: #fee2e2; color: #991b1b; }

        .estado-nuevo { background: #dbeafe; color: #1e40af; }
        .estado-contactado { background: #e0e7ff; color: #4338ca; }
        .estado-documentacion { background: #fef3c7; color: #92400e; }
        .estado-analisis { background: #e0f2fe; color: #0369a1; }
        .estado-aprobado { background: #d1fae5; color: #065f46; }
        .estado-rechazado { background: #fee2e2; color: #991b1b; }
        .estado-desistio { background: #f3f4f6; color: #374151; }

        .timeline-line {
            position: absolute;
            left: 23px;
            top: 50px;
            bottom: 0;
            width: 2px;
            background: linear-gradient(to bottom, #3b82f6, #e5e7eb);
        }
    </style>
</head>
<body class="bg-gray-50">

<?php include 'sidebar.php'; ?>

<div class="ml-64">
    <nav class="bg-white shadow-md sticky top-0 z-40">
        <div class="max-w-full mx-auto px-6 py-3 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <a href="clientes.php" class="text-gray-600 hover:text-gray-800 transition">
                    <span class="material-icons-outlined text-3xl">arrow_back</span>
                </a>
                <span class="material-icons-outlined text-blue-600 text-3xl">history</span>
                <h1 class="text-xl font-bold text-gray-800">Historial de Conversaciones (Cliente ID: <?php echo $cliente_id; ?>)</h1>
            </div>
        </div>
    </nav>

    <div class="max-w-full mx-auto px-6 py-6">
        
        <!-- Información del Cliente -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex items-center gap-4">
                <div class="w-20 h-20 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-2xl">
                    <?php echo strtoupper(substr($cliente['nombre'] ?: 'NA', 0, 2)); ?>
                </div>
                <div class="flex-1">
                    <h2 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($cliente['nombre']); ?></h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                        <div class="flex items-center gap-2 text-gray-600">
                            <span class="material-icons-outlined text-sm">badge</span>
                            <span class="text-sm">DNI: <?php echo htmlspecialchars($cliente_detalle['dni'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="flex items-center gap-2 text-gray-600">
                            <span class="material-icons-outlined text-sm">phone</span>
                            <span class="text-sm"><?php echo htmlspecialchars($cliente['telefono'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="flex items-center gap-2 text-gray-600">
                            <span class="material-icons-outlined text-sm">email</span>
                            <span class="text-sm"><?php echo htmlspecialchars($cliente['email'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-sm text-gray-600 font-semibold">Total Conversaciones</p>
                    <p class="text-4xl font-bold text-blue-600"><?php echo count($conversaciones); ?></p>
                </div>
            </div>
        </div>

        <!-- Timeline de Conversaciones -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                <span class="material-icons-outlined text-blue-600">timeline</span>
                Timeline de Interacciones
            </h3>

            <?php if (empty($conversaciones)): ?>
                <div class="text-center py-12 text-gray-500">
                    <span class="material-icons-outlined text-6xl text-gray-300 mb-4">chat_bubble_outline</span>
                    <p class="text-lg">No hay conversaciones registradas</p>
                </div>
            <?php else: ?>
                <div class="relative">
                    <?php if (count($conversaciones) > 1): ?>
                        <div class="timeline-line"></div>
                    <?php endif; ?>
                    
                    <div class="space-y-6">
                        <?php foreach ($conversaciones as $index => $conv): ?>
                        <div class="relative pl-12">
                            <!-- Punto del timeline -->
                            <div class="absolute left-0 top-0 w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold shadow-lg z-10">
                                <?php echo count($conversaciones) - $index; ?>
                            </div>

                            <!-- Card de la conversación -->
                            <div class="bg-gradient-to-br from-gray-50 to-white border-2 border-gray-200 rounded-xl p-6 hover:shadow-lg transition">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-2">
                                            <h4 class="text-lg font-bold text-gray-800">
                                                Conversación #<?php echo $conv['chat_id']; ?>
                                            </h4>
                                            <?php if ($index === 0): ?>
                                                <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">
                                                    Más reciente
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center gap-4 text-sm text-gray-600">
                                            <span class="flex items-center gap-1">
                                                <span class="material-icons-outlined text-sm">calendar_today</span>
                                                <?php echo date('d/m/Y H:i', strtotime($conv['fecha_inicio'])); ?>
                                            </span>
                                            <?php if ($conv['fecha_fin']): ?>
                                            <span class="flex items-center gap-1">
                                                <span class="material-icons-outlined text-sm">schedule</span>
                                                Duración: <?php 
                                                    try {
                                                        $inicio = new DateTime($conv['fecha_inicio']);
                                                        $fin = new DateTime($conv['fecha_fin']);
                                                        $diff = $inicio->diff($fin);
                                                        echo $diff->format('%H:%I:%S');
                                                    } catch (Exception $e) {
                                                        echo 'N/A';
                                                    }
                                                ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <a href="cliente_detalle.php?id=<?php echo $conv['chat_id']; ?>"
                                       class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition flex items-center gap-2">
                                        <span class="material-icons-outlined text-sm">visibility</span>
                                        Ver Detalle
                                    </a>
                                </div>

                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                    <div>
                                        <p class="text-xs text-gray-500 mb-1">Estado</p>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold estado-<?php echo $conv['estado_solicitud']; ?>">
                                            <?php echo ucfirst($conv['estado_solicitud']); ?>
                                        </span>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500 mb-1">Prioridad</p>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold prioridad-<?php echo $conv['prioridad']; ?>">
                                            <?php echo ucfirst($conv['prioridad']); ?>
                                        </span>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500 mb-1">CRM</p>
                                        <?php echo crmBadge($conv['api_enviado']); ?>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500 mb-1">Score</p>
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                                                <div class="h-full bg-gradient-to-r from-yellow-400 to-green-500"
                                                     style="width: <?php echo min(100, max(0, (int)$conv['score'])); ?>%"></div>
                                            </div>
                                            <span class="text-xs font-semibold text-gray-600"><?php echo $conv['score']; ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                    <div class="flex items-center gap-2 text-gray-600">
                                        <span class="material-icons-outlined text-sm">chat</span>
                                        <span><?php echo $conv['total_mensajes']; ?> mensajes</span>
                                    </div>
                                    <div class="flex items-center gap-2 text-gray-600">
                                        <span class="material-icons-outlined text-sm">note</span>
                                        <span><?php echo $conv['total_notas']; ?> notas</span>
                                    </div>
                                    <?php if ($conv['asesor_nombre']): ?>
                                    <div class="flex items-center gap-2 text-gray-600">
                                        <span class="material-icons-outlined text-sm">person</span>
                                        <span><?php echo htmlspecialchars($conv['asesor_nombre']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($conv['departamento_nombre']): ?>
                                    <div class="flex items-center gap-2 text-gray-600">
                                        <span class="material-icons-outlined text-sm">business</span>
                                        <span><?php echo htmlspecialchars($conv['departamento_nombre']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($conv['tags'])): ?>
                                <div class="mt-4 pt-4 border-t border-gray-200">
                                    <div class="flex gap-2 flex-wrap">
                                        <?php foreach (explode(',', $conv['tags']) as $tag): ?>
                                        <span class="px-3 py-1 bg-blue-100 text-blue-700 text-xs rounded-full font-semibold">
                                            <?php echo htmlspecialchars($tag); ?>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Resumen Estadístico -->
        <?php if (!empty($conversaciones)): ?>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mt-6">
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold">Total Mensajes</p>
                        <p class="text-3xl font-bold text-gray-800 mt-2">
                            <?php echo array_sum(array_column($conversaciones, 'total_mensajes')); ?>
                        </p>
                    </div>
                    <span class="material-icons-outlined text-blue-500 text-4xl">chat</span>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold">Total Notas</p>
                        <p class="text-3xl font-bold text-gray-800 mt-2">
                            <?php echo array_sum(array_column($conversaciones, 'total_notas')); ?>
                        </p>
                    </div>
                    <span class="material-icons-outlined text-purple-500 text-4xl">description</span>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold">Score Promedio</p>
                        <p class="text-3xl font-bold text-gray-800 mt-2">
                            <?php 
                                $scores = array_column($conversaciones, 'score');
                                $scores = array_filter($scores, function($s) { return is_numeric($s); });
                                echo count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;
                            ?>
                        </p>
                    </div>
                    <span class="material-icons-outlined text-green-500 text-4xl">bar_chart</span>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold">Periodo</p>
                        <p class="text-sm font-bold text-gray-800 mt-2">
                            <?php 
                                if (count($conversaciones) > 1) {
                                    $primera = end($conversaciones);
                                    $ultima = reset($conversaciones);
                                    try {
                                        $dias = (new DateTime($primera['fecha_inicio']))->diff(new DateTime($ultima['fecha_inicio']))->days;
                                        echo $dias . ' días';
                                    } catch (Exception $e) {
                                        echo 'N/A';
                                    }
                                } else {
                                    echo '1 día';
                                }
                            ?>
                        </p>
                    </div>
                    <span class="material-icons-outlined text-orange-500 text-4xl">event</span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>