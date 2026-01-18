<?php
session_start();

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/backend/connection.php';

$rol = $_SESSION['usuario_rol'];
$usuario_id = $_SESSION['usuario_id'];

// Filtros
$estado_filtro = $_GET['estado'] ?? 'todos';
$buscar = trim($_GET['buscar'] ?? '');

// Query: AGRUPAR POR CLIENTE, no por préstamo
$where_conditions = [];
$params = [];

// Filtro por estado
if ($estado_filtro === 'pendiente') {
    $where_conditions[] = "EXISTS (
        SELECT 1 FROM prestamos p2 
        WHERE p2.cliente_id = cd.usuario_id 
        AND p2.requiere_legajo = 1 
        AND p2.legajo_validado = 0
    )";
} elseif ($estado_filtro === 'completo') {
    $where_conditions[] = "EXISTS (
        SELECT 1 FROM prestamos p2 
        WHERE p2.cliente_id = cd.usuario_id 
        AND p2.legajo_completo = 1 
        AND p2.legajo_validado = 0
    )";
} elseif ($estado_filtro === 'validado') {
    $where_conditions[] = "EXISTS (
        SELECT 1 FROM prestamos p2 
        WHERE p2.cliente_id = cd.usuario_id 
        AND p2.legajo_validado = 1
    )";
}

// Búsqueda
if ($buscar !== '') {
    $where_conditions[] = "(cd.nombre_completo LIKE ? OR cd.email LIKE ? OR cd.dni LIKE ?)";
    $buscar_param = '%' . $buscar . '%';
    $params[] = $buscar_param;
    $params[] = $buscar_param;
    $params[] = $buscar_param;
}

// Si es asesor, solo ver sus clientes
if ($rol === 'asesor') {
    $where_conditions[] = "EXISTS (
        SELECT 1 FROM prestamos p2 
        WHERE p2.cliente_id = cd.usuario_id 
        AND p2.asesor_id = ?
    )";
    $params[] = $usuario_id;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'AND ' . implode(' AND ', $where_conditions);
}

// Consultar CLIENTES con sus préstamos agrupados
$sql = "
    SELECT 
        cd.usuario_id as cliente_id,
        cd.nombre_completo,
        cd.email,
        cd.telefono,
        cd.cod_area,
        cd.dni,
        cd.cuit,
        (
            SELECT COUNT(*) 
            FROM prestamos p 
            WHERE p.cliente_id = cd.usuario_id 
            AND p.requiere_legajo = 1
        ) as total_prestamos,
        (
            SELECT COUNT(*) 
            FROM prestamos p 
            WHERE p.cliente_id = cd.usuario_id 
            AND p.requiere_legajo = 1
            AND p.legajo_validado = 1
        ) as prestamos_validados,
        (
            SELECT COUNT(*) 
            FROM prestamos p 
            WHERE p.cliente_id = cd.usuario_id 
            AND p.requiere_legajo = 1
            AND p.legajo_completo = 1 
            AND p.legajo_validado = 0
        ) as prestamos_completos,
        (
            SELECT COUNT(DISTINCT cdl.tipo_documento)
            FROM cliente_documentos_legajo cdl
            INNER JOIN prestamos p ON cdl.prestamo_id = p.id
            WHERE p.cliente_id = cd.usuario_id
            AND cdl.estado_validacion = 'aprobado'
        ) as documentos_aprobados,
        (
            SELECT COUNT(*)
            FROM cliente_documentos_legajo cdl
            INNER JOIN prestamos p ON cdl.prestamo_id = p.id
            WHERE p.cliente_id = cd.usuario_id
            AND cdl.estado_validacion = 'pendiente'
        ) as documentos_pendientes,
        (
            SELECT MIN(p.fecha_solicitud)
            FROM prestamos p
            WHERE p.cliente_id = cd.usuario_id
            AND p.requiere_legajo = 1
        ) as primera_solicitud
    FROM clientes_detalles cd
    WHERE EXISTS (
        SELECT 1 FROM prestamos p
        WHERE p.cliente_id = cd.usuario_id
        AND p.requiere_legajo = 1
    )
    $where_clause
    HAVING total_prestamos > 0
    ORDER BY 
        CASE 
            WHEN prestamos_completos > 0 THEN 1
            WHEN documentos_pendientes > 0 THEN 2
            ELSE 3
        END,
        primera_solicitud DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas
$stats = [
    'total' => 0,
    'pendientes' => 0,
    'completos' => 0,
    'validados' => 0
];

foreach ($clientes as $c) {
    $stats['total']++;
    if ($c['prestamos_validados'] > 0) {
        $stats['validados']++;
    } elseif ($c['prestamos_completos'] > 0) {
        $stats['completos']++;
    } else {
        $stats['pendientes']++;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Legajos - Préstamo Líder</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    
    <style>
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
        }
        
        .cliente-card {
            transition: all 0.2s ease;
        }
        .cliente-card:hover {
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }
        
        .prestamo-mini {
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-block;
            margin: 2px;
        }
    </style>
</head>
<body class="bg-gray-50">

<?php if ($rol === 'admin'): ?>
    <?php include 'sidebar.php'; ?>
<?php else: ?>
    <?php include 'sidebar_asesores.php'; ?>
<?php endif; ?>

<div class="ml-64">

    <!-- Header -->
    <nav class="bg-white shadow-md sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                        <span class="material-icons-outlined text-blue-600">folder_shared</span>
                        Gestión de Legajos
                    </h1>
                    <p class="text-sm text-gray-600 mt-1">Revisa y valida la documentación de los clientes</p>
                </div>
                <a href="prestamos_admin.php" class="text-blue-600 hover:text-blue-800 font-semibold flex items-center gap-2 transition">
                    <span class="material-icons-outlined">arrow_back</span>
                    Volver
                </a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-6 py-8">
        
        <!-- Estadísticas -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="stat-card bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold">Total Clientes</p>
                        <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $stats['total']; ?></p>
                    </div>
                    <div class="w-14 h-14 bg-blue-100 rounded-full flex items-center justify-center">
                        <span class="material-icons-outlined text-blue-600 text-3xl">people</span>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold">Pendientes</p>
                        <p class="text-3xl font-bold text-yellow-600 mt-2"><?php echo $stats['pendientes']; ?></p>
                    </div>
                    <div class="w-14 h-14 bg-yellow-100 rounded-full flex items-center justify-center">
                        <span class="material-icons-outlined text-yellow-600 text-3xl">pending_actions</span>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold">Listos</p>
                        <p class="text-3xl font-bold text-orange-600 mt-2"><?php echo $stats['completos']; ?></p>
                    </div>
                    <div class="w-14 h-14 bg-orange-100 rounded-full flex items-center justify-center">
                        <span class="material-icons-outlined text-orange-600 text-3xl">assignment_turned_in</span>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 font-semibold">Validados</p>
                        <p class="text-3xl font-bold text-green-600 mt-2"><?php echo $stats['validados']; ?></p>
                    </div>
                    <div class="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center">
                        <span class="material-icons-outlined text-green-600 text-3xl">verified_user</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <form method="GET" class="flex flex-col md:flex-row gap-4">
                <div class="flex-1">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Buscar Cliente</label>
                    <div class="relative">
                        <span class="material-icons-outlined absolute left-3 top-3 text-gray-400">search</span>
                        <input type="text" 
                               name="buscar" 
                               value="<?php echo htmlspecialchars($buscar); ?>"
                               placeholder="Nombre, email o DNI..."
                               class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <div class="w-full md:w-64">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Estado</label>
                    <select name="estado" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="todos" <?php echo $estado_filtro === 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="pendiente" <?php echo $estado_filtro === 'pendiente' ? 'selected' : ''; ?>>⏳ Pendientes</option>
                        <option value="completo" <?php echo $estado_filtro === 'completo' ? 'selected' : ''; ?>>📋 Listos para validar</option>
                        <option value="validado" <?php echo $estado_filtro === 'validado' ? 'selected' : ''; ?>>✅ Validados</option>
                    </select>
                </div>

                <div class="flex items-end gap-2">
                    <button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold flex items-center gap-2 transition shadow-lg hover:shadow-xl">
                        <span class="material-icons-outlined">filter_list</span>
                        Filtrar
                    </button>
                    <a href="ver_legajos.php" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-semibold transition">
                        Limpiar
                    </a>
                </div>
            </form>
        </div>

        <!-- Lista de Clientes -->
        <?php if (empty($clientes)): ?>
            <div class="bg-white rounded-xl shadow-lg p-16 text-center">
                <span class="material-icons-outlined text-gray-300 text-8xl mb-6">folder_off</span>
                <h3 class="text-2xl font-bold text-gray-800 mb-2">No hay legajos</h3>
                <p class="text-gray-600">No se encontraron clientes con legajos para revisar</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-6">
                <?php foreach ($clientes as $cliente): ?>
                    <?php
                    // Obtener préstamos del cliente
                    $stmt_prestamos = $pdo->prepare("
                        SELECT 
                            p.id,
                            p.estado,
                            p.legajo_completo,
                            p.legajo_validado,
                            COALESCE(p.monto, p.monto_ofrecido, p.monto_solicitado) as monto
                        FROM prestamos p
                        WHERE p.cliente_id = ? 
                        AND p.requiere_legajo = 1
                        ORDER BY p.fecha_solicitud DESC
                    ");
                    $stmt_prestamos->execute([$cliente['cliente_id']]);
                    $prestamos_cliente = $stmt_prestamos->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Determinar estado visual del cliente
                    if ($cliente['prestamos_completos'] > 0) {
                        $estado_class = 'border-orange-500';
                        $estado_badge = 'bg-orange-100 text-orange-800';
                        $estado_icon = 'assignment_turned_in';
                        $estado_text = 'Listo para validar';
                    } elseif ($cliente['documentos_pendientes'] > 0) {
                        $estado_class = 'border-yellow-500';
                        $estado_badge = 'bg-yellow-100 text-yellow-800';
                        $estado_icon = 'pending';
                        $estado_text = 'En revisión';
                    } elseif ($cliente['prestamos_validados'] > 0) {
                        $estado_class = 'border-green-500';
                        $estado_badge = 'bg-green-100 text-green-800';
                        $estado_icon = 'verified';
                        $estado_text = 'Validado';
                    } else {
                        $estado_class = 'border-gray-300';
                        $estado_badge = 'bg-gray-100 text-gray-800';
                        $estado_icon = 'folder_open';
                        $estado_text = 'Incompleto';
                    }
                    
                    $progreso = round(($cliente['documentos_aprobados'] / 7) * 100);
                    ?>
                    
                    <div class="cliente-card bg-white rounded-xl shadow-lg border-l-4 <?php echo $estado_class; ?> overflow-hidden">
                        <div class="p-6">
                            
                            <!-- Header del Cliente -->
                            <div class="flex items-start justify-between mb-6">
                                <div class="flex items-start gap-4 flex-1">
                                    <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-xl shadow-lg">
                                        <?php echo strtoupper(substr($cliente['nombre_completo'] ?: 'C', 0, 2)); ?>
                                    </div>
                                    
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-2">
                                            <h3 class="text-xl font-bold text-gray-800">
                                                <?php echo htmlspecialchars($cliente['nombre_completo'] ?: 'Sin nombre'); ?>
                                            </h3>
                                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $estado_badge; ?> flex items-center gap-1">
                                                <span class="material-icons-outlined text-sm"><?php echo $estado_icon; ?></span>
                                                <?php echo $estado_text; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="flex flex-wrap gap-4 text-sm text-gray-600">
                                            <div class="flex items-center gap-2">
                                                <span class="material-icons-outlined text-sm">email</span>
                                                <?php echo htmlspecialchars($cliente['email']); ?>
                                            </div>
                                            
                                            <?php if ($cliente['dni']): ?>
                                            <div class="flex items-center gap-2">
                                                <span class="material-icons-outlined text-sm">badge</span>
                                                DNI: <?php echo htmlspecialchars($cliente['dni']); ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($cliente['cuit']): ?>
                                            <div class="flex items-center gap-2">
                                                <span class="material-icons-outlined text-sm">credit_card</span>
                                                CUIT: <?php echo htmlspecialchars($cliente['cuit']); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Progreso y Préstamos -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                
                                <!-- Progreso de Documentos -->
                                <div class="bg-gray-50 rounded-lg p-4">
                                    <div class="flex justify-between items-center mb-3">
                                        <h4 class="font-semibold text-gray-700">Documentos</h4>
                                        <span class="text-sm text-gray-600"><?php echo $cliente['documentos_aprobados']; ?> / 7</span>
                                    </div>
                                    
                                    <div class="w-full bg-gray-200 rounded-full h-3 mb-3">
                                        <div class="<?php echo $progreso >= 100 ? 'bg-green-500' : 'bg-blue-500'; ?> h-3 rounded-full transition-all" 
                                             style="width: <?php echo $progreso; ?>%">
                                        </div>
                                    </div>

                                    <div class="flex gap-4 text-xs">
                                        <?php if ($cliente['documentos_aprobados'] > 0): ?>
                                        <div class="flex items-center gap-1 text-green-700">
                                            <span class="material-icons-outlined text-sm">check_circle</span>
                                            <span><?php echo $cliente['documentos_aprobados']; ?> aprobados</span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($cliente['documentos_pendientes'] > 0): ?>
                                        <div class="flex items-center gap-1 text-yellow-700">
                                            <span class="material-icons-outlined text-sm">pending</span>
                                            <span><?php echo $cliente['documentos_pendientes']; ?> pendientes</span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Préstamos -->
                                <div class="bg-gray-50 rounded-lg p-4">
                                    <h4 class="font-semibold text-gray-700 mb-3">
                                        Préstamos (<?php echo count($prestamos_cliente); ?>)
                                    </h4>
                                    
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($prestamos_cliente as $p): ?>
                                            <?php
                                            $color = 'bg-gray-200 text-gray-700';
                                            if ($p['legajo_validado']) {
                                                $color = 'bg-green-100 text-green-700';
                                                $icon = '✓';
                                            } elseif ($p['legajo_completo']) {
                                                $color = 'bg-orange-100 text-orange-700';
                                                $icon = '📋';
                                            } else {
                                                $color = 'bg-yellow-100 text-yellow-700';
                                                $icon = '⏳';
                                            }
                                            ?>
                                            <span class="prestamo-mini <?php echo $color; ?> font-semibold">
                                                <?php echo $icon; ?> #<?php echo $p['id']; ?> 
                                                <span class="text-xs">($<?php echo number_format($p['monto'], 0, ',', '.'); ?>)</span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Acciones -->
                            <div class="flex gap-3 mt-6">
                                <?php if (!empty($prestamos_cliente)): ?>
                                    <?php 
                                    // Usar el primer préstamo para revisar (ya que todos son del mismo cliente)
                                    $primer_prestamo = $prestamos_cliente[0];
                                    ?>
                                    <a href="cliente_detalle.php?id=<?php echo $cliente['cliente_id']; ?>" 
                                       class="flex-1 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold text-center flex items-center justify-center gap-2 transition shadow-lg hover:shadow-xl">
                                        <span class="material-icons-outlined">visibility</span>
                                        Ver Perfil Completo
                                    </a>
                                <?php else: ?>
                                    <div class="flex-1 px-6 py-3 bg-gray-400 text-white rounded-lg text-center font-semibold">
                                        Sin préstamos disponibles
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($cliente['telefono']): ?>
                                <a href="https://wa.me/<?php echo $cliente['cod_area'] . $cliente['telefono']; ?>" 
                                   target="_blank"
                                   class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold flex items-center justify-center gap-2 transition shadow-lg hover:shadow-xl">
                                    <span class="material-icons-outlined">whatsapp</span>
                                    WhatsApp
                                </a>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

</div>

</body>
</html>