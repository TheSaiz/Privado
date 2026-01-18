<?php
session_start();

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/connection.php';

$usuario_id = $_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];

// =====================================================
// MÉTRICAS DEL DASHBOARD
// =====================================================
$metricas = [];

// Total de clientes ÚNICOS
$stmt = $pdo->query("
    SELECT COUNT(DISTINCT u.id) 
    FROM usuarios u 
    WHERE u.rol = 'cliente'
");
$metricas['total'] = $stmt->fetchColumn();

// Clientes registrados (con cuenta activa)
$stmt = $pdo->query("
    SELECT COUNT(DISTINCT u.id)
    FROM usuarios u
    INNER JOIN clientes_detalles cd ON u.id = cd.usuario_id
    WHERE u.rol = 'cliente'
");
$metricas['registrados'] = $stmt->fetchColumn();

// Clientes solo de chatbot (sin registro)
$stmt = $pdo->query("
    SELECT COUNT(DISTINCT c.cliente_id)
    FROM chats c
    LEFT JOIN clientes_detalles cd ON c.cliente_id = cd.usuario_id
    WHERE c.origen = 'chatbot' AND cd.usuario_id IS NULL
");
$metricas['chatbot'] = $stmt->fetchColumn();

// Clientes con documentación completa
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM clientes_detalles
    WHERE docs_completos = 1
");
$metricas['docs_completos'] = $stmt->fetchColumn();

// Clientes con legajos pendientes
$stmt = $pdo->query("
    SELECT COUNT(DISTINCT p.cliente_id)
    FROM prestamos p
    WHERE p.requiere_legajo = 1 
    AND p.legajo_validado = 0
");
$metricas['legajos_pendientes'] = $stmt->fetchColumn();

// =====================================================
// FILTROS Y BÚSQUEDA
// =====================================================
$where = ["u.rol = 'cliente'"];
$params = [];

// Búsqueda general
if (!empty($_GET['buscar'])) {
    $buscar = '%' . $_GET['buscar'] . '%';
    $where[] = "(cd.nombre_completo LIKE ? OR cd.dni LIKE ? OR u.telefono LIKE ? OR u.email LIKE ?)";
    $params = array_merge($params, [$buscar, $buscar, $buscar, $buscar]);
}

// Filtro por origen
if (!empty($_GET['origen'])) {
    if ($_GET['origen'] === 'registrado') {
        $where[] = "cd.usuario_id IS NOT NULL";
    } elseif ($_GET['origen'] === 'chatbot') {
        $where[] = "cd.usuario_id IS NULL AND EXISTS (
            SELECT 1 FROM chats c WHERE c.cliente_id = u.id AND c.origen = 'chatbot'
        )";
    }
}

// Filtro por documentación
if (!empty($_GET['docs'])) {
    if ($_GET['docs'] === 'completos') {
        $where[] = "cd.docs_completos = 1";
    } elseif ($_GET['docs'] === 'incompletos') {
        $where[] = "(cd.docs_completos = 0 OR cd.docs_completos IS NULL)";
    }
}

// Filtro por legajos
if (!empty($_GET['legajo'])) {
    if ($_GET['legajo'] === 'pendiente') {
        $where[] = "EXISTS (
            SELECT 1 FROM prestamos p 
            WHERE p.cliente_id = u.id 
            AND p.requiere_legajo = 1 
            AND p.legajo_validado = 0
        )";
    } elseif ($_GET['legajo'] === 'validado') {
        $where[] = "EXISTS (
            SELECT 1 FROM prestamos p 
            WHERE p.cliente_id = u.id 
            AND p.legajo_validado = 1
        )";
    }
}

// Filtro por estado de solicitud (chatbot)
if (!empty($_GET['estado_solicitud'])) {
    $where[] = "EXISTS (
        SELECT 1 FROM chats c 
        WHERE c.cliente_id = u.id 
        AND c.estado_solicitud = ?
    )";
    $params[] = $_GET['estado_solicitud'];
}

$where_sql = implode(' AND ', $where);

// =====================================================
// PAGINACIÓN
// =====================================================
$por_pagina = 20;
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina_actual - 1) * $por_pagina;

// Contar total
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT u.id)
    FROM usuarios u
    LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
    WHERE $where_sql
");
$stmt->execute($params);
$total_registros = $stmt->fetchColumn();
$total_paginas = ceil($total_registros / $por_pagina);

// =====================================================
// OBTENER CLIENTES CON TODA LA INFO
// =====================================================
$stmt = $pdo->prepare("
    SELECT
        u.id as cliente_id,
        u.nombre as usuario_nombre,
        u.telefono,
        u.email,
        cd.nombre_completo,
        cd.dni,
        cd.cuit,
        cd.cod_area,
        cd.direccion,
        cd.ciudad,
        cd.provincia,
        cd.fecha_nacimiento,
        cd.docs_completos,
        cd.email_verificado,
        (
            SELECT estado_solicitud 
            FROM chats 
            WHERE cliente_id = u.id AND origen = 'chatbot'
            ORDER BY fecha_inicio DESC 
            LIMIT 1
        ) as ultimo_estado_chatbot,
        (
            SELECT COUNT(*) 
            FROM chats 
            WHERE cliente_id = u.id AND origen = 'chatbot'
        ) as total_conversaciones,
        (
            SELECT COUNT(*) 
            FROM prestamos 
            WHERE cliente_id = u.id
        ) as total_prestamos,
        (
            SELECT COUNT(*) 
            FROM prestamos 
            WHERE cliente_id = u.id 
            AND requiere_legajo = 1
        ) as prestamos_con_legajo,
        (
            SELECT COUNT(*) 
            FROM prestamos 
            WHERE cliente_id = u.id 
            AND legajo_validado = 1
        ) as legajos_validados,
        (
            SELECT COUNT(*) 
            FROM prestamos 
            WHERE cliente_id = u.id 
            AND legajo_completo = 1 
            AND legajo_validado = 0
        ) as legajos_completos,
        (
            SELECT COUNT(DISTINCT cdl.tipo_documento)
            FROM cliente_documentos_legajo cdl
            INNER JOIN prestamos p ON cdl.prestamo_id = p.id
            WHERE p.cliente_id = u.id
            AND cdl.estado_validacion = 'aprobado'
        ) as documentos_legajo_aprobados,
        (
            SELECT MIN(fecha_inicio) 
            FROM chats 
            WHERE cliente_id = u.id
        ) as primera_interaccion,
        (
            SELECT MAX(fecha_inicio) 
            FROM chats 
            WHERE cliente_id = u.id
        ) as ultima_interaccion,
        (
            SELECT id 
            FROM chats 
            WHERE cliente_id = u.id 
            ORDER BY fecha_inicio DESC 
            LIMIT 1
        ) as ultimo_chat_id
    FROM usuarios u
    LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
    WHERE $where_sql
    ORDER BY 
        CASE 
            WHEN cd.usuario_id IS NOT NULL THEN 0 
            ELSE 1 
        END,
        u.id DESC
    LIMIT $por_pagina OFFSET $offset
");
$stmt->execute($params);
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM - Clientes Unificado</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    
    <style>
        .origen-registrado { background: #d1fae5; color: #065f46; }
        .origen-chatbot { background: #dbeafe; color: #1e40af; }
        .origen-ambos { background: #e0e7ff; color: #4338ca; }
        
        .legajo-pendiente { background: #fef3c7; color: #92400e; }
        .legajo-completo { background: #fde68a; color: #78350f; }
        .legajo-validado { background: #d1fae5; color: #065f46; }
        
        .docs-completos { background: #d1fae5; color: #065f46; }
        .docs-incompletos { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body class="bg-gray-50">

<?php include 'sidebar.php'; ?>

<div class="ml-64">
    <nav class="bg-white shadow-md sticky top-0 z-40">
        <div class="max-w-full mx-auto px-6 py-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="material-icons-outlined text-blue-600 text-3xl">people</span>
                    <div>
                        <h1 class="text-xl font-bold text-gray-800">CRM - Gestión Unificada de Clientes</h1>
                        <p class="text-sm text-gray-600">Chatbot, Registros y Legajos Integrados</p>
                    </div>
                </div>
                <button onclick="exportarCSV()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center gap-2">
                    <span class="material-icons-outlined">download</span>
                    Exportar
                </button>
            </div>
        </div>
    </nav>

    <?php if ($message): ?>
    <div class="max-w-full mx-auto px-6 py-3">
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg flex items-center justify-between">
            <span><?php echo htmlspecialchars($message); ?></span>
            <button onclick="this.parentElement.parentElement.remove()" class="text-green-700 hover:text-green-900">
                <span class="material-icons-outlined">close</span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div class="max-w-full mx-auto px-6 py-6">

        <!-- Métricas -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-6">
            <div class="bg-white rounded-xl shadow-lg p-6 border-t-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold uppercase">Total Clientes</p>
                        <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo number_format($metricas['total']); ?></p>
                    </div>
                    <span class="material-icons-outlined text-blue-500 text-5xl">people</span>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border-t-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold uppercase">Registrados</p>
                        <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo number_format($metricas['registrados']); ?></p>
                    </div>
                    <span class="material-icons-outlined text-green-500 text-5xl">person_add</span>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border-t-4 border-purple-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold uppercase">Solo Chatbot</p>
                        <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo number_format($metricas['chatbot']); ?></p>
                    </div>
                    <span class="material-icons-outlined text-purple-500 text-5xl">chat</span>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border-t-4 border-indigo-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold uppercase">Docs Completos</p>
                        <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo number_format($metricas['docs_completos']); ?></p>
                    </div>
                    <span class="material-icons-outlined text-indigo-500 text-5xl">verified</span>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border-t-4 border-orange-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold uppercase">Legajos Pendientes</p>
                        <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo number_format($metricas['legajos_pendientes']); ?></p>
                    </div>
                    <span class="material-icons-outlined text-orange-500 text-5xl">folder_open</span>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Buscar</label>
                    <input type="text" name="buscar" value="<?php echo htmlspecialchars($_GET['buscar'] ?? ''); ?>"
                           placeholder="Nombre, DNI, teléfono, email..."
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Origen</label>
                    <select name="origen" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Todos</option>
                        <option value="registrado" <?php echo ($_GET['origen'] ?? '') === 'registrado' ? 'selected' : ''; ?>>✅ Registrados</option>
                        <option value="chatbot" <?php echo ($_GET['origen'] ?? '') === 'chatbot' ? 'selected' : ''; ?>>💬 Solo Chatbot</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Documentación</label>
                    <select name="docs" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Todos</option>
                        <option value="completos" <?php echo ($_GET['docs'] ?? '') === 'completos' ? 'selected' : ''; ?>>✓ Completos</option>
                        <option value="incompletos" <?php echo ($_GET['docs'] ?? '') === 'incompletos' ? 'selected' : ''; ?>>⏳ Incompletos</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Legajos</label>
                    <select name="legajo" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Todos</option>
                        <option value="pendiente" <?php echo ($_GET['legajo'] ?? '') === 'pendiente' ? 'selected' : ''; ?>>📋 Pendientes</option>
                        <option value="validado" <?php echo ($_GET['legajo'] ?? '') === 'validado' ? 'selected' : ''; ?>>✅ Validados</option>
                    </select>
                </div>

                <div class="flex items-end gap-2 md:col-span-2">
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition flex items-center gap-2">
                        <span class="material-icons-outlined">search</span>
                        Filtrar
                    </button>
                    <a href="clientes.php" class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">
                        Limpiar
                    </a>
                </div>
            </form>
        </div>

        <!-- Tabla -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Cliente</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Contacto</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Origen</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Documentación</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Legajos</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Préstamos</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($clientes)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <span class="material-icons-outlined text-6xl text-gray-300 mb-4">search_off</span>
                                <p class="text-lg">No se encontraron clientes</p>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($clientes as $cliente): ?>
                                <?php
                                $es_registrado = !empty($cliente['nombre_completo']);
                                $tiene_chatbot = (int)$cliente['total_conversaciones'] > 0;
                                $tiene_prestamos = (int)$cliente['total_prestamos'] > 0;
                                $tiene_legajos = (int)$cliente['prestamos_con_legajo'] > 0;
                                
                                $nombre_mostrar = $cliente['nombre_completo'] ?: $cliente['usuario_nombre'] ?: 'Sin nombre';
                                ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-lg">
                                            <?php echo strtoupper(substr($nombre_mostrar, 0, 2)); ?>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($nombre_mostrar); ?></p>
                                            <?php if ($cliente['dni']): ?>
                                            <p class="text-xs text-gray-500">DNI: <?php echo htmlspecialchars($cliente['dni']); ?></p>
                                            <?php endif; ?>
                                            <?php if ($cliente['cuit']): ?>
                                            <p class="text-xs text-gray-500">CUIT: <?php echo htmlspecialchars($cliente['cuit']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-6 py-4">
                                    <?php if ($cliente['telefono']): ?>
                                    <p class="text-sm text-gray-800 flex items-center gap-1">
                                        <span class="material-icons-outlined text-sm">phone</span>
                                        <?php if ($cliente['cod_area']): ?>(<?php echo htmlspecialchars($cliente['cod_area']); ?>)<?php endif; ?>
                                        <?php echo htmlspecialchars($cliente['telefono']); ?>
                                    </p>
                                    <?php endif; ?>
                                    <?php if ($cliente['email']): ?>
                                    <p class="text-xs text-gray-500 flex items-center gap-1 mt-1">
                                        <span class="material-icons-outlined text-xs">email</span>
                                        <?php echo htmlspecialchars($cliente['email']); ?>
                                        <?php if ((int)$cliente['email_verificado'] === 1): ?>
                                            <span class="text-green-600" title="Email verificado">✓</span>
                                        <?php endif; ?>
                                    </p>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4">
                                    <div class="space-y-1">
                                        <?php if ($es_registrado && $tiene_chatbot): ?>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold origen-ambos block text-center">
                                                🔗 Ambos
                                            </span>
                                        <?php elseif ($es_registrado): ?>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold origen-registrado block text-center">
                                                ✅ Registrado
                                            </span>
                                        <?php elseif ($tiene_chatbot): ?>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold origen-chatbot block text-center">
                                                💬 Chatbot
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($tiene_chatbot): ?>
                                        <p class="text-xs text-gray-500 text-center"><?php echo (int)$cliente['total_conversaciones']; ?> chat<?php echo (int)$cliente['total_conversaciones'] !== 1 ? 's' : ''; ?></p>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="px-6 py-4">
                                    <?php if ($es_registrado): ?>
                                        <?php if ((int)$cliente['docs_completos'] === 1): ?>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold docs-completos">
                                                ✓ Completo
                                            </span>
                                        <?php else: ?>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold docs-incompletos">
                                                ⏳ Incompleto
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">Sin registro</span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4">
                                    <?php if ($tiene_legajos): ?>
                                        <div class="space-y-1">
                                            <?php if ((int)$cliente['legajos_validados'] > 0): ?>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold legajo-validado block text-center">
                                                ✅ <?php echo (int)$cliente['legajos_validados']; ?> Validado<?php echo (int)$cliente['legajos_validados'] !== 1 ? 's' : ''; ?>
                                            </span>
                                            <?php endif; ?>
                                            
                                            <?php if ((int)$cliente['legajos_completos'] > 0): ?>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold legajo-completo block text-center">
                                                📋 <?php echo (int)$cliente['legajos_completos']; ?> Listo<?php echo (int)$cliente['legajos_completos'] !== 1 ? 's' : ''; ?>
                                            </span>
                                            <?php endif; ?>
                                            
                                            <?php 
                                            $pendientes = (int)$cliente['prestamos_con_legajo'] - (int)$cliente['legajos_validados'] - (int)$cliente['legajos_completos'];
                                            if ($pendientes > 0): 
                                            ?>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold legajo-pendiente block text-center">
                                                ⏳ <?php echo $pendientes; ?> Pendiente<?php echo $pendientes !== 1 ? 's' : ''; ?>
                                            </span>
                                            <?php endif; ?>
                                            
                                            <p class="text-xs text-gray-500 text-center mt-1">
                                                <?php echo (int)$cliente['documentos_legajo_aprobados']; ?>/7 docs
                                            </p>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">Sin legajos</span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4">
                                    <?php if ($tiene_prestamos): ?>
                                        <div class="text-center">
                                            <p class="text-2xl font-bold text-gray-800"><?php echo (int)$cliente['total_prestamos']; ?></p>
                                            <p class="text-xs text-gray-500">préstamo<?php echo (int)$cliente['total_prestamos'] !== 1 ? 's' : ''; ?></p>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">Sin préstamos</span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-center gap-2 flex-wrap">
                                        <a href="cliente_detalle.php?id=<?php echo (int)$cliente['cliente_id']; ?>"
                                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm font-semibold"
                                           title="Ver perfil completo">
                                            <span class="material-icons-outlined text-sm mr-1">visibility</span>
                                            Ver Todo
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_paginas > 1): ?>
            <div class="bg-gray-50 px-6 py-4 flex items-center justify-between border-t">
                <div class="text-sm text-gray-600">
                    Mostrando <?php echo (($pagina_actual - 1) * $por_pagina) + 1; ?>
                    a <?php echo min($pagina_actual * $por_pagina, $total_registros); ?>
                    de <?php echo $total_registros; ?> clientes
                </div>
                <div class="flex gap-2">
                    <?php if ($pagina_actual > 1): ?>
                    <a href="?pagina=<?php echo $pagina_actual - 1; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'pagina', ARRAY_FILTER_USE_KEY)); ?>"
                       class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                        Anterior
                    </a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $pagina_actual - 2); $i <= min($total_paginas, $pagina_actual + 2); $i++): ?>
                    <a href="?pagina=<?php echo $i; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'pagina', ARRAY_FILTER_USE_KEY)); ?>"
                       class="px-4 py-2 <?php echo $i === $pagina_actual ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 hover:bg-gray-50'; ?> rounded-lg transition">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($pagina_actual < $total_paginas): ?>
                    <a href="?pagina=<?php echo $pagina_actual + 1; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'pagina', ARRAY_FILTER_USE_KEY)); ?>"
                       class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                        Siguiente
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function exportarCSV() {
    const params = new URLSearchParams(window.location.search);
    params.set('exportar', 'csv');
    window.location.href = 'api/clientes/exportar_csv.php?' + params.toString();
}
</script>

</body>
</html>