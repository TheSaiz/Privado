<?php
/*************************************************
 * reportes_pagos.php - Reportes de Pagos
 * Análisis y estadísticas de pagos
 *************************************************/

session_start();
require_once __DIR__ . '/backend/connection.php';

// Validar sesión de administrador
if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'administrador') {
    header('Location: login.php');
    exit;
}

// Configurar zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

/* =========================
   HELPERS
========================= */
function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function money0($n){ return number_format((float)$n, 0, ',', '.'); }

/* =========================
   FILTROS
========================= */
$fecha_desde = $_GET['desde'] ?? date('Y-m-01'); // Primer día del mes
$fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');

/* =========================
   ESTADÍSTICAS GENERALES
========================= */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_pagos,
        SUM(CASE WHEN estado = 'pagado' THEN 1 ELSE 0 END) as pagos_aprobados,
        SUM(CASE WHEN estado = 'pendiente' AND comprobante IS NOT NULL THEN 1 ELSE 0 END) as pagos_pendientes,
        SUM(CASE WHEN rechazado_fecha IS NOT NULL THEN 1 ELSE 0 END) as pagos_rechazados,
        SUM(CASE WHEN estado = 'pagado' THEN monto ELSE 0 END) as monto_aprobado,
        SUM(CASE WHEN estado = 'pendiente' AND comprobante IS NOT NULL THEN monto ELSE 0 END) as monto_pendiente,
        AVG(CASE WHEN estado = 'pagado' AND aprobado_fecha IS NOT NULL 
            THEN DATEDIFF(aprobado_fecha, DATE(p.fecha_creacion)) END) as dias_promedio_aprobacion
    FROM prestamos_pagos pp
    INNER JOIN prestamos p ON p.id = pp.prestamo_id
    WHERE DATE(pp.fecha_vencimiento) BETWEEN ? AND ?
");
$stmt->execute([$fecha_desde, $fecha_hasta]);
$stats = $stmt->fetch();

/* =========================
   PAGOS POR DÍA
========================= */
$stmt = $pdo->prepare("
    SELECT 
        DATE(pp.fecha_vencimiento) as fecha,
        COUNT(*) as total,
        SUM(CASE WHEN estado = 'pagado' THEN 1 ELSE 0 END) as aprobados,
        SUM(monto) as monto_total
    FROM prestamos_pagos pp
    WHERE DATE(pp.fecha_vencimiento) BETWEEN ? AND ?
    GROUP BY DATE(pp.fecha_vencimiento)
    ORDER BY fecha ASC
");
$stmt->execute([$fecha_desde, $fecha_hasta]);
$pagos_por_dia = $stmt->fetchAll();

/* =========================
   TOP CLIENTES CON PAGOS
========================= */
$stmt = $pdo->prepare("
    SELECT 
        c.id,
        c.nombre,
        c.apellido,
        c.email,
        COUNT(pp.id) as total_pagos,
        SUM(CASE WHEN pp.estado = 'pagado' THEN 1 ELSE 0 END) as pagos_cumplidos,
        SUM(pp.monto) as monto_total
    FROM clientes c
    INNER JOIN prestamos p ON p.cliente_id = c.id
    INNER JOIN prestamos_pagos pp ON pp.prestamo_id = p.id
    WHERE DATE(pp.fecha_vencimiento) BETWEEN ? AND ?
    GROUP BY c.id
    ORDER BY pagos_cumplidos DESC, monto_total DESC
    LIMIT 10
");
$stmt->execute([$fecha_desde, $fecha_hasta]);
$top_clientes = $stmt->fetchAll();

/* =========================
   PAGOS VENCIDOS
========================= */
$stmt = $pdo->query("
    SELECT 
        pp.*,
        p.id as prestamo_id,
        c.nombre,
        c.apellido,
        c.email,
        DATEDIFF(CURDATE(), pp.fecha_vencimiento) as dias_vencido
    FROM prestamos_pagos pp
    INNER JOIN prestamos p ON p.id = pp.prestamo_id
    INNER JOIN clientes c ON c.id = p.cliente_id
    WHERE pp.estado = 'pendiente'
    AND pp.fecha_vencimiento < CURDATE()
    ORDER BY dias_vencido DESC
    LIMIT 20
");
$pagos_vencidos = $stmt->fetchAll();

/* =========================
   TIEMPO PROMEDIO DE APROBACIÓN POR ADMINISTRADOR
========================= */
$stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.nombre,
        COUNT(pp.id) as total_aprobados,
        AVG(DATEDIFF(pp.aprobado_fecha, DATE(p.fecha_creacion))) as dias_promedio
    FROM prestamos_pagos pp
    INNER JOIN prestamos p ON p.id = pp.prestamo_id
    INNER JOIN usuarios u ON u.id = pp.aprobado_por
    WHERE pp.estado = 'pagado'
    AND DATE(pp.aprobado_fecha) BETWEEN ? AND ?
    GROUP BY u.id
    ORDER BY total_aprobados DESC
");
$stmt->execute([$fecha_desde, $fecha_hasta]);
$admins_performance = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes de Pagos - Sistema</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f3f4f6; }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .chart-container {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="ml-64">
        <main class="p-8">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-800 mb-2">Reportes de Pagos</h1>
                <p class="text-gray-600">Análisis y estadísticas del período seleccionado</p>
            </div>
            
            <!-- Filtros de fecha -->
            <div class="bg-white p-6 rounded-lg shadow mb-6">
                <form method="GET" class="flex gap-4 items-end">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Desde</label>
                        <input 
                            type="date" 
                            name="desde" 
                            value="<?= h($fecha_desde) ?>"
                            class="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Hasta</label>
                        <input 
                            type="date" 
                            name="hasta" 
                            value="<?= h($fecha_hasta) ?>"
                            class="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                    </div>
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Filtrar
                    </button>
                    <a href="reportes_pagos.php" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                        Limpiar
                    </a>
                </form>
            </div>
            
            <!-- Estadísticas generales -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="stat-card">
                    <div class="text-sm text-gray-600 mb-1">Total Pagos</div>
                    <div class="text-3xl font-bold text-gray-800"><?= (int)$stats['total_pagos'] ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="text-sm text-gray-600 mb-1">Pagos Aprobados</div>
                    <div class="text-3xl font-bold text-green-600"><?= (int)$stats['pagos_aprobados'] ?></div>
                    <div class="text-xs text-gray-500 mt-1">
                        $<?= money0($stats['monto_aprobado']) ?>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="text-sm text-gray-600 mb-1">Pagos Pendientes</div>
                    <div class="text-3xl font-bold text-yellow-600"><?= (int)$stats['pagos_pendientes'] ?></div>
                    <div class="text-xs text-gray-500 mt-1">
                        $<?= money0($stats['monto_pendiente']) ?>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="text-sm text-gray-600 mb-1">Tasa de Aprobación</div>
                    <div class="text-3xl font-bold text-blue-600">
                        <?php 
                            $tasa = $stats['total_pagos'] > 0 
                                ? round(($stats['pagos_aprobados'] / $stats['total_pagos']) * 100, 1)
                                : 0;
                            echo $tasa;
                        ?>%
                    </div>
                </div>
            </div>
            
            <!-- Gráfico de pagos por día -->
            <div class="chart-container">
                <h3 class="text-xl font-bold mb-4">Pagos por Día</h3>
                <canvas id="chartPagosPorDia" height="80"></canvas>
            </div>
            
            <!-- Top clientes -->
            <div class="bg-white rounded-lg shadow mb-8">
                <div class="p-6 border-b">
                    <h3 class="text-xl font-bold">Top 10 Clientes</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Cliente</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Total Pagos</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Cumplidos</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Monto Total</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">% Cumplimiento</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($top_clientes as $cliente): ?>
                                <?php 
                                    $porcentaje = $cliente['total_pagos'] > 0 
                                        ? round(($cliente['pagos_cumplidos'] / $cliente['total_pagos']) * 100, 1)
                                        : 0;
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="font-semibold"><?= h($cliente['nombre'] . ' ' . $cliente['apellido']) ?></div>
                                        <div class="text-sm text-gray-500"><?= h($cliente['email']) ?></div>
                                    </td>
                                    <td class="px-6 py-4"><?= (int)$cliente['total_pagos'] ?></td>
                                    <td class="px-6 py-4 text-green-600 font-semibold"><?= (int)$cliente['pagos_cumplidos'] ?></td>
                                    <td class="px-6 py-4">$<?= money0($cliente['monto_total']) ?></td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 bg-gray-200 rounded-full h-2">
                                                <div 
                                                    class="bg-blue-600 h-2 rounded-full" 
                                                    style="width: <?= $porcentaje ?>%"
                                                ></div>
                                            </div>
                                            <span class="text-sm font-semibold"><?= $porcentaje ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagos vencidos -->
            <?php if (!empty($pagos_vencidos)): ?>
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b">
                    <h3 class="text-xl font-bold text-red-600">Pagos Vencidos Críticos</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Cliente</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Cuota</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Monto</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Vencimiento</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Días Vencido</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($pagos_vencidos as $pago): ?>
                                <tr class="hover:bg-red-50">
                                    <td class="px-6 py-4">
                                        <div class="font-semibold"><?= h($pago['nombre'] . ' ' . $pago['apellido']) ?></div>
                                        <div class="text-sm text-gray-500"><?= h($pago['email']) ?></div>
                                    </td>
                                    <td class="px-6 py-4">Cuota <?= (int)$pago['cuota_num'] ?></td>
                                    <td class="px-6 py-4 font-semibold">$<?= money0($pago['monto']) ?></td>
                                    <td class="px-6 py-4"><?= date('d/m/Y', strtotime($pago['fecha_vencimiento'])) ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 bg-red-100 text-red-800 rounded-full text-sm font-semibold">
                                            <?= (int)$pago['dias_vencido'] ?> días
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
        // Gráfico de pagos por día
        const ctxPagos = document.getElementById('chartPagosPorDia');
        const datosPagos = <?= json_encode($pagos_por_dia) ?>;
        
        new Chart(ctxPagos, {
            type: 'line',
            data: {
                labels: datosPagos.map(d => {
                    const fecha = new Date(d.fecha);
                    return fecha.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit' });
                }),
                datasets: [{
                    label: 'Total Pagos',
                    data: datosPagos.map(d => d.total),
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4
                }, {
                    label: 'Pagos Aprobados',
                    data: datosPagos.map(d => d.aprobados),
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>