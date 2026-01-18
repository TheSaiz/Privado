<?php
session_start();

// Verificar que sea admin
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'admin') {
    header("Location: login.php");
    exit;
}

try {
    require_once __DIR__ . '/backend/connection.php';
} catch (Throwable $e) {
    die("Error de conexión");
}

// Obtener configuración actual
$stmt = $pdo->query("SELECT clave, valor, descripcion FROM prestamos_config ORDER BY id");
$config = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $config[$row['clave']] = $row;
}

// Si es actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_config'])) {
    try {
        $pdo->beginTransaction();
        
        foreach ($_POST as $key => $value) {
            if ($key !== 'guardar_config' && isset($config[$key])) {
                $stmt = $pdo->prepare("UPDATE prestamos_config SET valor = ? WHERE clave = ?");
                $stmt->execute([$value, $key]);
            }
        }
        
        $pdo->commit();
        $mensaje = "✅ Configuración actualizada correctamente";
        
        // Recargar config
        $stmt = $pdo->query("SELECT clave, valor, descripcion FROM prestamos_config ORDER BY id");
        $config = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $config[$row['clave']] = $row;
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "❌ Error al guardar: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Préstamos - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
</head>
<body class="bg-gray-50">
    
    <?php include 'sidebar.php'; ?>

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

    <div class="max-w-4xl mx-auto px-4 py-8">
        
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">⚙️ Configuración de Préstamos</h1>
            <p class="text-gray-600">Administra los parámetros del sistema de préstamos</p>
        </div>

        <?php if (isset($mensaje)): ?>
        <div class="bg-green-50 border border-green-200 text-green-800 px-6 py-4 rounded-xl mb-6">
            <?php echo $mensaje; ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 px-6 py-4 rounded-xl mb-6">
            <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="bg-white rounded-xl shadow-lg p-8">
            
            <div class="mb-8">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <span class="text-2xl">💰</span> Límites de Monto
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Monto Mínimo ($)
                        </label>
                        <input 
                            type="number" 
                            name="monto_minimo" 
                            value="<?php echo $config['monto_minimo']['valor'] ?? 5000; ?>" 
                            step="1000"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg font-semibold"
                        >
                        <p class="text-sm text-gray-500 mt-1">
                            <?php echo $config['monto_minimo']['descripcion'] ?? ''; ?>
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Monto Máximo ($)
                        </label>
                        <input 
                            type="number" 
                            name="monto_maximo" 
                            value="<?php echo $config['monto_maximo']['valor'] ?? 500000; ?>" 
                            step="1000"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg font-semibold"
                        >
                        <p class="text-sm text-gray-500 mt-1">
                            <?php echo $config['monto_maximo']['descripcion'] ?? ''; ?>
                        </p>
                    </div>

                </div>
            </div>

            <div class="mb-8 pb-8 border-b">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <span class="text-2xl">📊</span> Tasas de Interés
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Tasa de Interés por Defecto (%)
                        </label>
                        <input 
                            type="number" 
                            name="tasa_interes_default" 
                            value="<?php echo $config['tasa_interes_default']['valor'] ?? 15; ?>" 
                            step="0.01"
                            min="0"
                            max="100"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg font-semibold"
                        >
                        <p class="text-sm text-gray-500 mt-1">
                            <?php echo $config['tasa_interes_default']['descripcion'] ?? ''; ?>
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Mora Diaria (%)
                        </label>
                        <input 
                            type="number" 
                            name="mora_diaria" 
                            value="<?php echo $config['mora_diaria']['valor'] ?? 2; ?>" 
                            step="0.01"
                            min="0"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg font-semibold"
                        >
                        <p class="text-sm text-gray-500 mt-1">
                            <?php echo $config['mora_diaria']['descripcion'] ?? ''; ?>
                        </p>
                    </div>

                </div>
            </div>

            <div class="mb-8 pb-8 border-b">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <span class="text-2xl">📅</span> Límites de Cuotas
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Cuotas Mínimas
                        </label>
                        <input 
                            type="number" 
                            name="cuotas_minimas" 
                            value="<?php echo $config['cuotas_minimas']['valor'] ?? 1; ?>" 
                            min="1"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg font-semibold"
                        >
                        <p class="text-sm text-gray-500 mt-1">
                            <?php echo $config['cuotas_minimas']['descripcion'] ?? ''; ?>
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Cuotas Máximas
                        </label>
                        <input 
                            type="number" 
                            name="cuotas_maximas" 
                            value="<?php echo $config['cuotas_maximas']['valor'] ?? 24; ?>" 
                            min="1"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg font-semibold"
                        >
                        <p class="text-sm text-gray-500 mt-1">
                            <?php echo $config['cuotas_maximas']['descripcion'] ?? ''; ?>
                        </p>
                    </div>

                </div>
            </div>

            <div class="mb-8">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <span class="text-2xl">⏱️</span> Plazos
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Días para Evaluación
                        </label>
                        <input 
                            type="number" 
                            name="dias_evaluacion" 
                            value="<?php echo $config['dias_evaluacion']['valor'] ?? 3; ?>" 
                            min="1"
                            required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg font-semibold"
                        >
                        <p class="text-sm text-gray-500 mt-1">
                            <?php echo $config['dias_evaluacion']['descripcion'] ?? ''; ?>
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            ¿Requiere Documentos?
                        </label>
                        <select 
                            name="requiere_documentos"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg font-semibold"
                        >
                            <option value="1" <?php echo ($config['requiere_documentos']['valor'] ?? 1) == 1 ? 'selected' : ''; ?>>Sí</option>
                            <option value="0" <?php echo ($config['requiere_documentos']['valor'] ?? 1) == 0 ? 'selected' : ''; ?>>No</option>
                        </select>
                        <p class="text-sm text-gray-500 mt-1">
                            <?php echo $config['requiere_documentos']['descripcion'] ?? ''; ?>
                        </p>
                    </div>

                </div>
            </div>

            <div class="flex gap-4 pt-6">
                <button 
                    type="submit" 
                    name="guardar_config"
                    class="flex-1 px-8 py-4 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-bold text-lg"
                >
                    💾 Guardar Configuración
                </button>
                <a 
                    href="prestamos_admin.php" 
                    class="px-8 py-4 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-semibold text-lg"
                >
                    Cancelar
                </a>
            </div>

        </form>

        <div class="mt-8 bg-blue-50 border border-blue-200 rounded-xl p-6">
            <h3 class="font-bold text-blue-900 mb-2">ℹ️ Información Importante</h3>
            <ul class="text-sm text-blue-800 space-y-1">
                <li>• Los cambios en la configuración afectarán solo a nuevos préstamos</li>
                <li>• Los préstamos activos mantendrán sus condiciones originales</li>
                <li>• La tasa de interés se aplica sobre el monto total del préstamo</li>
                <li>• La mora diaria se calcula automáticamente sobre cuotas vencidas</li>
            </ul>
        </div>

    </div>

</body>
</html>