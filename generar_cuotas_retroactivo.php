<?php
/**
 * SCRIPT DE MIGRACIÓN
 * Genera cuotas para préstamos ya aprobados que no tienen cuotas
 * 
 * USO:
 * 1. Sube este archivo al servidor
 * 2. Accede desde el navegador: http://tudominio.com/generar_cuotas_retroactivo.php
 * 3. Sigue las instrucciones en pantalla
 * 
 * IMPORTANTE: Por seguridad, este script requiere autenticación de administrador
 */

session_start();
require_once __DIR__ . '/backend/connection.php';
require_once __DIR__ . '/generar_cuotas.php';

// Verificar que sea administrador
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'admin') {
    die('⛔ ACCESO DENEGADO: Este script solo puede ser ejecutado por administradores.');
}

$modo = $_GET['modo'] ?? 'verificar'; // 'verificar' o 'ejecutar'
$limit = (int)($_GET['limit'] ?? 10); // Procesar de a 10 por defecto

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generación Retroactiva de Cuotas</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <h1 class="text-3xl font-bold mb-6 text-gray-800">
                🔧 Generación Retroactiva de Cuotas
            </h1>
            
            <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
                <p class="text-sm text-blue-800">
                    <strong>ℹ️ Información:</strong> Este script genera cuotas automáticamente para préstamos 
                    que ya fueron aprobados pero no tienen cuotas creadas.
                </p>
            </div>

            <?php
            try {
                // Buscar préstamos aprobados/activos sin cuotas
                $stmt = $pdo->query("
                    SELECT 
                        p.id,
                        p.estado,
                        COALESCE(p.monto_ofrecido, p.monto_solicitado, p.monto) as monto,
                        COALESCE(p.cuotas_ofrecidas, p.cuotas_solicitadas, p.cuotas) as cuotas,
                        COALESCE(p.frecuencia_ofrecida, p.frecuencia_solicitada, p.frecuencia_pago) as frecuencia,
                        p.fecha_aprobacion,
                        (SELECT COUNT(*) FROM prestamos_pagos WHERE prestamo_id = p.id) as cuotas_existentes
                    FROM prestamos p
                    WHERE p.estado IN ('aprobado', 'activo')
                    HAVING cuotas_existentes = 0
                    ORDER BY p.fecha_aprobacion DESC
                    LIMIT $limit
                ");
                $prestamos_sin_cuotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $total_sin_cuotas = $pdo->query("
                    SELECT COUNT(*) as total
                    FROM prestamos p
                    WHERE p.estado IN ('aprobado', 'activo')
                    AND (SELECT COUNT(*) FROM prestamos_pagos WHERE prestamo_id = p.id) = 0
                ")->fetch()['total'];
                
                ?>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-yellow-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-600">Préstamos sin cuotas</p>
                        <p class="text-3xl font-bold text-yellow-600"><?= $total_sin_cuotas ?></p>
                    </div>
                    
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-600">Procesando ahora</p>
                        <p class="text-3xl font-bold text-blue-600"><?= count($prestamos_sin_cuotas) ?></p>
                    </div>
                    
                    <div class="bg-green-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-600">Modo actual</p>
                        <p class="text-xl font-bold text-green-600">
                            <?= $modo === 'verificar' ? '👁️ Verificar' : '⚡ Ejecutar' ?>
                        </p>
                    </div>
                </div>

                <?php if ($modo === 'verificar'): ?>
                    <!-- MODO VERIFICACIÓN -->
                    <div class="mb-6">
                        <h2 class="text-xl font-bold mb-4">📋 Préstamos que requieren cuotas:</h2>
                        
                        <?php if (empty($prestamos_sin_cuotas)): ?>
                            <div class="bg-green-50 border border-green-200 rounded-lg p-6 text-center">
                                <p class="text-green-800 font-semibold">
                                    ✅ ¡Perfecto! Todos los préstamos aprobados tienen sus cuotas generadas.
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto mb-6">
                                <table class="w-full border-collapse">
                                    <thead class="bg-gray-100">
                                        <tr>
                                            <th class="border p-3 text-left">ID</th>
                                            <th class="border p-3 text-left">Estado</th>
                                            <th class="border p-3 text-left">Monto</th>
                                            <th class="border p-3 text-left">Cuotas</th>
                                            <th class="border p-3 text-left">Frecuencia</th>
                                            <th class="border p-3 text-left">F. Aprobación</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($prestamos_sin_cuotas as $p): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="border p-3 font-bold">#<?= $p['id'] ?></td>
                                                <td class="border p-3">
                                                    <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded text-sm">
                                                        <?= $p['estado'] ?>
                                                    </span>
                                                </td>
                                                <td class="border p-3">$<?= number_format($p['monto'], 0, ',', '.') ?></td>
                                                <td class="border p-3"><?= $p['cuotas'] ?></td>
                                                <td class="border p-3"><?= $p['frecuencia'] ?></td>
                                                <td class="border p-3"><?= date('d/m/Y', strtotime($p['fecha_aprobacion'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                                <p class="text-yellow-800 mb-2">
                                    <strong>⚠️ Atención:</strong> Se encontraron <?= count($prestamos_sin_cuotas) ?> préstamos 
                                    (de <?= $total_sin_cuotas ?> totales) que necesitan generación de cuotas.
                                </p>
                                <p class="text-sm text-yellow-700">
                                    Presiona el botón de abajo para generar las cuotas automáticamente.
                                </p>
                            </div>
                            
                            <div class="flex gap-4">
                                <a href="?modo=ejecutar&limit=<?= $limit ?>" 
                                   class="flex-1 bg-green-600 text-white px-6 py-3 rounded-lg font-bold text-center hover:bg-green-700"
                                   onclick="return confirm('¿Confirmas que deseas generar las cuotas para estos préstamos?')">
                                    ⚡ Generar Cuotas Ahora
                                </a>
                                
                                <?php if ($total_sin_cuotas > $limit): ?>
                                    <a href="?modo=verificar&limit=<?= $total_sin_cuotas ?>" 
                                       class="bg-blue-600 text-white px-6 py-3 rounded-lg font-bold hover:bg-blue-700">
                                        📊 Ver Todos (<?= $total_sin_cuotas ?>)
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- MODO EJECUCIÓN -->
                    <div class="mb-6">
                        <h2 class="text-xl font-bold mb-4">⚡ Generando Cuotas...</h2>
                        
                        <?php if (empty($prestamos_sin_cuotas)): ?>
                            <div class="bg-green-50 border border-green-200 rounded-lg p-6">
                                <p class="text-green-800 font-semibold">
                                    ✅ No hay préstamos pendientes de procesar.
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php
                                $exitosos = 0;
                                $fallidos = 0;
                                
                                foreach ($prestamos_sin_cuotas as $prestamo):
                                    $resultado = generarCuotasPrestamo($pdo, $prestamo['id']);
                                    
                                    if ($resultado['success']) {
                                        $exitosos++;
                                        $color = 'green';
                                        $icon = '✅';
                                    } else {
                                        $fallidos++;
                                        $color = 'red';
                                        $icon = '❌';
                                    }
                                    ?>
                                    <div class="border-l-4 border-<?= $color ?>-500 bg-<?= $color ?>-50 p-4 rounded">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <p class="font-bold text-<?= $color ?>-800">
                                                    <?= $icon ?> Préstamo #<?= $prestamo['id'] ?>
                                                </p>
                                                <p class="text-sm text-<?= $color ?>-700 mt-1">
                                                    <?= $resultado['message'] ?>
                                                </p>
                                                <?php if ($resultado['success']): ?>
                                                    <p class="text-xs text-<?= $color ?>-600 mt-2">
                                                        Primera cuota: <?= $resultado['primera_cuota'] ?> • 
                                                        Última cuota: <?= $resultado['ultima_cuota'] ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            <span class="px-3 py-1 bg-<?= $color ?>-100 text-<?= $color ?>-800 rounded-full text-sm font-semibold">
                                                <?= $resultado['cuotas_generadas'] ?> cuotas
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <h3 class="font-bold text-blue-800 mb-2">📊 Resumen del Proceso:</h3>
                                <ul class="text-sm text-blue-700 space-y-1">
                                    <li>✅ Exitosos: <strong><?= $exitosos ?></strong></li>
                                    <li>❌ Fallidos: <strong><?= $fallidos ?></strong></li>
                                    <li>📋 Total procesado: <strong><?= $exitosos + $fallidos ?></strong></li>
                                    <?php if ($total_sin_cuotas > ($exitosos + $fallidos)): ?>
                                        <li class="text-yellow-700">
                                            ⏳ Pendientes: <strong><?= $total_sin_cuotas - ($exitosos + $fallidos) ?></strong>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            
                            <div class="mt-6 flex gap-4">
                                <?php if ($total_sin_cuotas > ($exitosos + $fallidos)): ?>
                                    <a href="?modo=ejecutar&limit=<?= $limit ?>" 
                                       class="flex-1 bg-blue-600 text-white px-6 py-3 rounded-lg font-bold text-center hover:bg-blue-700">
                                        🔄 Procesar Siguientes <?= $limit ?>
                                    </a>
                                <?php endif; ?>
                                
                                <a href="?modo=verificar" 
                                   class="bg-gray-200 text-gray-800 px-6 py-3 rounded-lg font-bold hover:bg-gray-300">
                                    🔙 Volver a Verificar
                                </a>
                                
                                <a href="prestamos_admin.php" 
                                   class="bg-green-600 text-white px-6 py-3 rounded-lg font-bold hover:bg-green-700">
                                    ✅ Ir a Administración
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php
            } catch (Exception $e) {
                ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded">
                    <p class="text-red-800 font-bold">❌ Error:</p>
                    <p class="text-red-700 text-sm mt-2"><?= htmlspecialchars($e->getMessage()) ?></p>
                    <p class="text-red-600 text-xs mt-2">
                        Por favor verifica que los archivos estén correctamente instalados y que 
                        la conexión a la base de datos funcione.
                    </p>
                </div>
                <?php
            }
            ?>
            
            <div class="mt-8 pt-6 border-t border-gray-200">
                <p class="text-xs text-gray-500 text-center">
                    Script de Generación Retroactiva de Cuotas v1.0 • 
                    Ejecutado por: <?= htmlspecialchars($_SESSION['usuario_email'] ?? 'Admin') ?>
                </p>
            </div>
        </div>
    </div>
</body>
</html>