<?php
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
$metricsFile = 'metrics.json';

// Cargar métricas
$metrics = [];
if (file_exists($metricsFile)) {
    $metrics = json_decode(file_get_contents($metricsFile), true) ?: [];
}

// Función para calcular estadísticas por período
function getStatsByPeriod($metrics, $period) {
    $now = time();
    $stats = ['visits' => 0, 'total_time' => 0, 'avg_time' => 0];
    
    foreach ($metrics as $metric) {
        $timestamp = $metric['timestamp'];
        $duration = isset($metric['duration']) ? $metric['duration'] : 0;
        
        switch ($period) {
            case 'hour':
                if ($timestamp >= $now - 3600) {
                    $stats['visits']++;
                    $stats['total_time'] += $duration;
                }
                break;
            case 'day':
                if ($timestamp >= $now - 86400) {
                    $stats['visits']++;
                    $stats['total_time'] += $duration;
                }
                break;
            case 'week':
                if ($timestamp >= $now - 604800) {
                    $stats['visits']++;
                    $stats['total_time'] += $duration;
                }
                break;
            case 'month':
                if ($timestamp >= $now - 2592000) {
                    $stats['visits']++;
                    $stats['total_time'] += $duration;
                }
                break;
        }
    }
    
    if ($stats['visits'] > 0) {
        $stats['avg_time'] = round($stats['total_time'] / $stats['visits']);
    }
    
    return $stats;
}

// Función para obtener datos horarios (últimas 24 horas)
function getHourlyData($metrics) {
    $now = time();
    $hourlyData = array_fill(0, 24, 0);
    
    foreach ($metrics as $metric) {
        $timestamp = $metric['timestamp'];
        if ($timestamp >= $now - 86400) {
            $hour = 23 - floor(($now - $timestamp) / 3600);
            if ($hour >= 0 && $hour < 24) {
                $hourlyData[$hour]++;
            }
        }
    }
    
    return $hourlyData;
}

// Función para obtener datos diarios (última semana)
function getDailyData($metrics) {
    $now = time();
    $dailyData = array_fill(0, 7, 0);
    
    foreach ($metrics as $metric) {
        $timestamp = $metric['timestamp'];
        if ($timestamp >= $now - 604800) {
            $day = 6 - floor(($now - $timestamp) / 86400);
            if ($day >= 0 && $day < 7) {
                $dailyData[$day]++;
            }
        }
    }
    
    return $dailyData;
}

// Calcular estadísticas
$hourStats = getStatsByPeriod($metrics, 'hour');
$dayStats = getStatsByPeriod($metrics, 'day');
$weekStats = getStatsByPeriod($metrics, 'week');
$monthStats = getStatsByPeriod($metrics, 'month');

$hourlyData = getHourlyData($metrics);
$dailyData = getDailyData($metrics);

// Formatear tiempo
function formatDuration($seconds) {
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
    return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
}
?>

<!-- ================== ESTILOS ================== -->
<style>
    /* Ajusta la altura de los gráficos del dashboard */
    .chart-container {
        height: 300px; /* Puedes subir a 350 o 400 si querés */
        position: relative;
    }
</style>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Préstamo Líder</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<style>
    /* ============================
   LAYOUT GENERAL Y FIXES
============================ */

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
    background: #f8fafc;
    overflow-x: hidden;
}

/* Wrapper general */
.responsive-container {
    padding: 1rem;
    max-width: 100%;
    overflow-x: hidden;
}

/* ============================
   SIDEBAR – DESKTOP + MOBILE
============================ */

/* Sidebar en desktop */
aside {
    width: 260px;
    min-width: 240px;
    max-width: 280px;
    background: #fff;
    border-right: 1px solid #ddd;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    overflow-y: auto;
    z-index: 1000;
}

/* Contenido debe correr el espacio */
.page-content {
    margin-left: 260px;
    min-width: 0;
}

/* -------- MOBILE -------- */
@media (max-width: 768px) {
    aside {
        left: -100%;
        transition: left 0.3s ease;
        z-index: 9999;
    }

    aside.open {
        left: 0;
    }

    .page-content {
        margin-left: 0 !important;
    }
}

/* Botón flotante para abrir menu */
.mobile-menu-btn {
    display: none;
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 9998;
    background: #2563eb;
    color: white;
    border-radius: 50%;
    width: 56px;
    height: 56px;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 14px rgba(0,0,0,0.25);
}

@media (max-width: 768px) {
    .mobile-menu-btn {
        display: flex;
    }
}

/* Overlay para cerrar menu */
.sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9997;
}

.sidebar-overlay.active {
    display: block;
}

/* ============================
   TARJETAS Y GRID
============================ */

.grid { min-width: 0; }

@media (max-width: 640px) {
    .grid-cols-1 { grid-template-columns: repeat(1,minmax(0,1fr)); }
}

@media (min-width:641px) and (max-width:1024px) {
    .md\:grid-cols-2 { grid-template-columns: repeat(2,minmax(0,1fr)); }
}

/* ============================
   GRÁFICOS – CHART.JS
============================ */

.chart-container {
    width: 100%;
    height: 260px;
    position: relative;
    min-width: 0;
}

@media (min-width: 768px) {
    .chart-container { height: 320px; }
}

@media (min-width: 1024px) {
    .chart-container { height: 380px; }
}

canvas {
    max-width: 100%;
    min-width: 0;
}

/* ============================
   TABLA RESPONSIVE
============================ */

.overflow-x-auto {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

@media (max-width:768px) {
    table { min-width: 650px; }
    th, td { padding: 0.55rem; font-size: 0.85rem; }
}

/* ============================
   NAVBAR
============================ */

nav .max-w-7xl {
    padding-left: 1rem;
    padding-right: 1rem;
}

@media (max-width: 640px) {
    nav h1 { font-size: 1.05rem; }
    
    nav .flex {
        flex-wrap: wrap;
        gap: 0.5rem;
    }
}

/* ============================
   SCROLL Y OPTIMIZACIONES
============================ */

html, body {
    height: 100%;
    -webkit-overflow-scrolling: touch;
}

* {
    -webkit-tap-highlight-color: transparent;
}

@supports (padding: max(0px)) {
    body {
        padding-left: max(0px, env(safe-area-inset-left));
        padding-right: max(0px, env(safe-area-inset-right));
    }
}

</style>


</head>
<body 

class="bg-gray-50">
    
    <?php include 'sidebar.php'; ?>
    <!-- Navegación -->
    <nav class="bg-white shadow-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="text-xl font-bold text-gray-800">Préstamo Líder</span>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Dashboard de Analíticas</h1>
            <p class="text-gray-600">Métricas de visitas y permanencia en el sitio</p>
        </div>

       <!-- Tarjetas de estadísticas -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    
    <!-- Última hora -->
    <div class="bg-white rounded-xl shadow-lg p-6 border-t-4 border-blue-500">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-600 uppercase">Última Hora</h3>
            <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>
        <div class="text-3xl font-bold text-gray-800 mb-2"><?php echo $hourStats['visits']; ?></div>
        <div class="text-sm text-gray-600">Visitas</div>
        <div class="mt-3 pt-3 border-t border-gray-100">
            <span class="text-xs text-gray-500">Permanencia promedio: </span>
            <span class="text-sm font-semibold text-blue-600"><?php echo formatDuration($hourStats['avg_time']); ?></span>
        </div>
    </div>

    <!-- Último día -->
    <div class="bg-white rounded-xl shadow-lg p-6 border-t-4 border-green-500">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-600 uppercase">Último Día</h3>
            <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
        </div>
        <div class="text-3xl font-bold text-gray-800 mb-2"><?php echo $dayStats['visits']; ?></div>
        <div class="text-sm text-gray-600">Visitas</div>
        <div class="mt-3 pt-3 border-t border-gray-100">
            <span class="text-xs text-gray-500">Permanencia promedio: </span>
            <span class="text-sm font-semibold text-green-600"><?php echo formatDuration($dayStats['avg_time']); ?></span>
        </div>
    </div>

    <!-- Última semana -->
    <div class="bg-white rounded-xl shadow-lg p-6 border-t-4 border-purple-500">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-600 uppercase">Última Semana</h3>
            <svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
        </div>
        <div class="text-3xl font-bold text-gray-800 mb-2"><?php echo $weekStats['visits']; ?></div>
        <div class="text-sm text-gray-600">Visitas</div>
        <div class="mt-3 pt-3 border-t border-gray-100">
            <span class="text-xs text-gray-500">Permanencia promedio: </span>
            <span class="text-sm font-semibold text-purple-600"><?php echo formatDuration($weekStats['avg_time']); ?></span>
        </div>
    </div>

    <!-- Último mes -->
    <div class="bg-white rounded-xl shadow-lg p-6 border-t-4 border-orange-500">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-600 uppercase">Último Mes</h3>
            <svg class="w-8 h-8 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
        </div>
        <div class="text-3xl font-bold text-gray-800 mb-2"><?php echo $monthStats['visits']; ?></div>
        <div class="text-sm text-gray-600">Visitas</div>
        <div class="mt-3 pt-3 border-t border-gray-100">
            <span class="text-xs text-gray-500">Permanencia promedio: </span>
            <span class="text-sm font-semibold text-orange-600"><?php echo formatDuration($monthStats['avg_time']); ?></span>
        </div>
    </div>

</div>

<!-- Gráficos -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- Gráfico por hora -->
    <div class="bg-white rounded-xl shadow-lg p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Visitas por Hora (Últimas 24h)</h3>
        <div class="chart-container">
            <canvas id="hourlyChart"></canvas>
        </div>
    </div>

    <!-- Gráfico por día -->
    <div class="bg-white rounded-xl shadow-lg p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Visitas por Día (Última Semana)</h3>
        <div class="chart-container">
            <canvas id="dailyChart"></canvas>
        </div>
    </div>

</div>

<!-- Tabla de visitas recientes -->
<div class="mt-8 bg-white rounded-xl shadow-lg p-6">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Últimas 10 Visitas</h3>

    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="border-b border-gray-200">
                    <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600">Sesión</th>
                    <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600">Fecha y Hora</th>
                    <th class="text-left py-3 px-4 text-sm font-semibold text-gray-600">Duración</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $recentMetrics = array_slice(array_reverse($metrics), 0, 10);
                foreach ($recentMetrics as $metric):
                ?>
                <tr class="border-b border-gray-100 hover:bg-gray-50">
                    <td class="py-3 px-4 text-sm text-gray-600 font-mono">
                        <?php echo substr($metric['session_id'], 0, 8); ?>...
                    </td>
                    <td class="py-3 px-4 text-sm text-gray-800">
                        <?php echo date('d/m/Y H:i:s', $metric['timestamp']); ?>
                    </td>
                    <td class="py-3 px-4 text-sm text-gray-800 font-semibold">
                        <?php echo isset($metric['duration']) ? formatDuration($metric['duration']) : 'En curso'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>


    <script>
        // Datos para gráfico por hora
        const hourlyData = <?php echo json_encode($hourlyData); ?>;
        const hourLabels = [];
        for (let i = 23; i >= 0; i--) {
            const hour = new Date();
            hour.setHours(hour.getHours() - i);
            hourLabels.push(hour.getHours() + ':00');
        }

        const hourlyChart = new Chart(document.getElementById('hourlyChart'), {
            type: 'line',
            data: {
                labels: hourLabels,
                datasets: [{
                    label: 'Visitas',
                    data: hourlyData,
                    borderColor: 'rgb(37, 99, 235)',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
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

        // Datos para gráfico por día
        const dailyData = <?php echo json_encode($dailyData); ?>;
        const dayLabels = [];
        const dayNames = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
        for (let i = 6; i >= 0; i--) {
            const day = new Date();
            day.setDate(day.getDate() - i);
            dayLabels.push(dayNames[day.getDay()]);
        }

        const dailyChart = new Chart(document.getElementById('dailyChart'), {
            type: 'bar',
            data: {
                labels: dayLabels,
                datasets: [{
                    label: 'Visitas',
                    data: dailyData,
                    backgroundColor: 'rgba(34, 197, 94, 0.8)',
                    borderColor: 'rgb(34, 197, 94)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
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

        // Auto-refresh cada 30 segundos
        setTimeout(() => location.reload(), 30000);

    // Mobile menu toggle
    const mobileMenuBtn = document.createElement('button');
    mobileMenuBtn.className = 'mobile-menu-btn';
    mobileMenuBtn.innerHTML = '<span class="material-icons-outlined">menu</span>';
    document.body.appendChild(mobileMenuBtn);

    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);

    const sidebar = document.querySelector('aside');

    mobileMenuBtn.addEventListener('click', () => {
        sidebar.classList.add('open');
        overlay.classList.add('active');
    });

    overlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
    });

    // Responsive chart resize
    window.addEventListener('resize', () => {
        if (window.hourlyChart) window.hourlyChart.resize();
        if (window.dailyChart) window.dailyChart.resize();
    });

    // Touch scroll optimization para iOS
    const tables = document.querySelectorAll('.overflow-x-auto');
    tables.forEach(table => {
        table.style.webkitOverflowScrolling = 'touch';
    });
    </script>

    
</body>
</html>