<?php
session_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Validar sesión
if (!isset($_SESSION['cliente_id']) || !isset($_SESSION['cliente_email'])) {
    header('Location: login_clientes.php');
    exit;
}

$cliente_id = (int)($_SESSION['cliente_id'] ?? 0);

// Validar ID y tipo
if (!isset($_GET['id']) || (int)$_GET['id'] <= 0) {
    header('Location: dashboard_clientes.php');
    exit;
}

$prestamo_id = (int)$_GET['id'];
$tipo_operacion = strtolower($_GET['tipo'] ?? 'prestamo');

// Conexión
try {
    require_once __DIR__ . '/backend/connection.php';
} catch (Throwable $e) {
    die('Error de conexión');
}

$mensaje = '';
$error = '';

// PROCESAR ACCIÓN DEL CLIENTE (Aceptar o Rechazar contraoferta)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_contraoferta'])) {
    $accion = $_POST['accion_contraoferta'];
    
    try {
        $pdo->beginTransaction();
        
        $tabla = match($tipo_operacion) {
            'prestamo' => 'prestamos',
            'empeno' => 'empenos',
            'prendario' => 'creditos_prendarios',
            default => null
        };
        
        if (!$tabla) {
            throw new Exception('Tipo de operación no válido');
        }
        
        // Verificar que el préstamo pertenece al cliente y está en estado contraoferta
        $stmt = $pdo->prepare("
            SELECT * FROM {$tabla} 
            WHERE id = ? AND cliente_id = ? AND estado = 'contraoferta'
        ");
        $stmt->execute([$prestamo_id, $cliente_id]);
        $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$prestamo) {
            throw new Exception('Operación no encontrada o no está en estado de contraoferta');
        }
        
        if ($accion === 'aceptar') {
            // CLIENTE ACEPTA LA CONTRAOFERTA
            // Mover valores ofrecidos a valores finales y pre-aprobar
            $stmt = $pdo->prepare("
                UPDATE {$tabla} SET 
                    monto_final = monto_ofrecido,
                    cuotas_final = cuotas_ofrecidas,
                    frecuencia_final = frecuencia_ofrecida,
                    tasa_interes_final = tasa_interes_ofrecida,
                    monto_total_final = monto_total_ofrecido,
                    estado = 'aprobado',
                    estado_contrato = 'pendiente_firma',
                    fecha_aprobacion = NOW(),
                    fecha_aceptacion = NOW()
                WHERE id = ? AND cliente_id = ?
            ");
            $stmt->execute([$prestamo_id, $cliente_id]);
            
            $mensaje = '✅ ¡Contraoferta aceptada! Tu préstamo ha sido pre-aprobado. Ahora debes firmar el contrato.';
            
        } elseif ($accion === 'rechazar') {
            // CLIENTE RECHAZA LA CONTRAOFERTA
            $motivo_rechazo = trim($_POST['motivo_rechazo_cliente'] ?? 'El cliente rechazó la contraoferta');
            
            $stmt = $pdo->prepare("
                UPDATE {$tabla} SET 
                    estado = 'rechazado',
                    comentarios_cliente = ?
                WHERE id = ? AND cliente_id = ?
            ");
            $stmt->execute([$motivo_rechazo, $prestamo_id, $cliente_id]);
            
            // Notificar al admin/asesor
            if (!empty($prestamo['asesor_id'])) {
                // Si hay un asesor asignado, podríamos crear una notificación para el asesor
                // (esto requeriría una tabla de notificaciones para asesores)
            }
            
            $mensaje = 'Has rechazado la contraoferta. La solicitud ha sido cancelada.';
        }
        
        $pdo->commit();
        
        // Recargar datos del préstamo
        $stmt = $pdo->prepare("SELECT * FROM {$tabla} WHERE id = ? AND cliente_id = ?");
        $stmt->execute([$prestamo_id, $cliente_id]);
        $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        error_log("Error en aceptar/rechazar contraoferta: " . $e->getMessage());
    }
}

// Obtener datos del préstamo
$tabla = match($tipo_operacion) {
    'prestamo' => 'prestamos',
    'empeno' => 'empenos',
    'prendario' => 'creditos_prendarios',
    default => null
};

if (!$tabla) {
    header('Location: dashboard_clientes.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM {$tabla} WHERE id = ? AND cliente_id = ?");
$stmt->execute([$prestamo_id, $cliente_id]);
$prestamo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prestamo) {
    header('Location: dashboard_clientes.php');
    exit;
}

$es_contraoferta = ($prestamo['estado'] === 'contraoferta');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle del Préstamo</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">

    <div class="max-w-4xl mx-auto px-4 py-8">
        
        <!-- Mensajes -->
        <?php if ($mensaje): ?>
            <div class="mb-6 p-4 bg-green-100 border-l-4 border-green-500 text-green-700 rounded-lg shadow-md">
                <?= h($mensaje) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-lg shadow-md">
                ❌ <?= h($error) ?>
            </div>
        <?php endif; ?>

        <!-- SECCIÓN DE CONTRAOFERTA -->
        <?php if ($es_contraoferta): ?>
            <div class="bg-white rounded-xl shadow-lg p-8 mb-6 border-l-4 border-yellow-500">
                <div class="flex items-center mb-4">
                    <span class="text-4xl mr-4">📝</span>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Nueva Contraoferta</h2>
                        <p class="text-gray-600">Hemos revisado tu solicitud y te ofrecemos las siguientes condiciones</p>
                    </div>
                </div>

                <!-- Comparación: Lo que pediste vs Lo que ofrecemos -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                    
                    <!-- LO QUE SOLICITASTE -->
                    <div class="bg-gray-50 rounded-lg p-6 border-2 border-gray-300">
                        <h3 class="text-lg font-bold text-gray-700 mb-4 flex items-center">
                            <span class="mr-2">📋</span> Lo que solicitaste
                        </h3>
                        <div class="space-y-3">
                            <div>
                                <span class="text-gray-600 text-sm">Monto:</span>
                                <div class="text-xl font-bold text-gray-800">
                                    $<?= number_format($prestamo['monto_solicitado'] ?? 0, 2, ',', '.') ?>
                                </div>
                            </div>
                            <div>
                                <span class="text-gray-600 text-sm">Cuotas:</span>
                                <div class="text-lg font-semibold text-gray-800">
                                    <?= $prestamo['cuotas_solicitadas'] ?? '-' ?>
                                </div>
                            </div>
                            <div>
                                <span class="text-gray-600 text-sm">Frecuencia:</span>
                                <div class="text-lg font-semibold text-gray-800 capitalize">
                                    <?= $prestamo['frecuencia_solicitada'] ?? '-' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- LO QUE OFRECEMOS -->
                    <div class="bg-yellow-50 rounded-lg p-6 border-2 border-yellow-400">
                        <h3 class="text-lg font-bold text-yellow-800 mb-4 flex items-center">
                            <span class="mr-2">⭐</span> Nuestra oferta
                        </h3>
                        <div class="space-y-3">
                            <div>
                                <span class="text-yellow-700 text-sm">Monto:</span>
                                <div class="text-2xl font-bold text-yellow-800">
                                    $<?= number_format($prestamo['monto_ofrecido'] ?? 0, 2, ',', '.') ?>
                                </div>
                            </div>
                            <div>
                                <span class="text-yellow-700 text-sm">Cuotas:</span>
                                <div class="text-xl font-bold text-yellow-800">
                                    <?= $prestamo['cuotas_ofrecidas'] ?? '-' ?>
                                </div>
                            </div>
                            <div>
                                <span class="text-yellow-700 text-sm">Frecuencia:</span>
                                <div class="text-xl font-bold text-yellow-800 capitalize">
                                    <?= $prestamo['frecuencia_ofrecida'] ?? '-' ?>
                                </div>
                            </div>
                            <?php if (!empty($prestamo['tasa_interes_ofrecida'])): ?>
                            <div>
                                <span class="text-yellow-700 text-sm">Tasa de interés:</span>
                                <div class="text-xl font-bold text-yellow-800">
                                    <?= $prestamo['tasa_interes_ofrecida'] ?>%
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($prestamo['monto_total_ofrecido'])): ?>
                            <div class="mt-4 pt-4 border-t-2 border-yellow-300">
                                <span class="text-yellow-700 text-sm">Monto total a pagar:</span>
                                <div class="text-2xl font-bold text-yellow-800">
                                    $<?= number_format($prestamo['monto_total_ofrecido'] ?? 0, 2, ',', '.') ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Comentarios del asesor -->
                <?php if (!empty($prestamo['comentarios_admin'])): ?>
                    <div class="mt-6 bg-blue-50 border-l-4 border-blue-400 p-4 rounded">
                        <p class="text-sm font-semibold text-blue-800 mb-2">💬 Comentarios del asesor:</p>
                        <p class="text-gray-700"><?= nl2br(h($prestamo['comentarios_admin'])) ?></p>
                    </div>
                <?php endif; ?>

                <!-- BOTONES DE ACCIÓN -->
                <div class="mt-8 flex flex-col sm:flex-row gap-4">
                    <!-- ACEPTAR CONTRAOFERTA -->
                    <form method="POST" class="flex-1" onsubmit="return confirm('¿Estás seguro de aceptar esta contraoferta? Se pre-aprobará tu préstamo con estas condiciones.')">
                        <input type="hidden" name="accion_contraoferta" value="aceptar">
                        <button type="submit" 
                                class="w-full px-6 py-4 bg-green-600 text-white rounded-lg font-bold text-lg hover:bg-green-700 transition shadow-lg">
                            ✅ Aceptar Contraoferta
                        </button>
                    </form>

                    <!-- RECHAZAR CONTRAOFERTA -->
                    <button onclick="mostrarModalRechazo()" 
                            class="flex-1 px-6 py-4 bg-red-600 text-white rounded-lg font-bold text-lg hover:bg-red-700 transition shadow-lg">
                        ❌ Rechazar Contraoferta
                    </button>
                </div>

                <p class="mt-4 text-center text-sm text-gray-500">
                    💡 Al aceptar, el préstamo se pre-aprobará automáticamente y deberás firmar el contrato.
                </p>
            </div>
        <?php endif; ?>

        <!-- Información general del préstamo -->
        <div class="bg-white rounded-xl shadow-lg p-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Información del Préstamo</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <span class="text-gray-600 text-sm">Estado:</span>
                    <div class="text-lg font-semibold capitalize">
                        <?php
                        $estado_color = match($prestamo['estado']) {
                            'contraoferta' => 'text-yellow-600',
                            'aprobado' => 'text-green-600',
                            'rechazado' => 'text-red-600',
                            'activo' => 'text-blue-600',
                            default => 'text-gray-600'
                        };
                        ?>
                        <span class="<?= $estado_color ?>">
                            <?= str_replace('_', ' ', $prestamo['estado']) ?>
                        </span>
                    </div>
                </div>

                <div>
                    <span class="text-gray-600 text-sm">Fecha de solicitud:</span>
                    <div class="text-lg font-semibold">
                        <?= date('d/m/Y H:i', strtotime($prestamo['fecha_solicitud'])) ?>
                    </div>
                </div>
            </div>

            <div class="mt-6">
                <a href="dashboard_clientes.php" 
                   class="inline-block px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold hover:bg-gray-300 transition">
                    ← Volver al Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- MODAL RECHAZAR CONTRAOFERTA -->
    <div id="modalRechazo" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-8 max-w-md w-full mx-4 shadow-2xl">
            <h3 class="text-2xl font-bold mb-4 text-gray-800">❌ Rechazar Contraoferta</h3>
            <p class="text-gray-600 mb-4">¿Estás seguro de rechazar esta contraoferta? La solicitud será cancelada.</p>
            
            <form method="POST">
                <input type="hidden" name="accion_contraoferta" value="rechazar">
                
                <div class="mb-6">
                    <label class="block text-sm font-semibold mb-2 text-gray-700">
                        Motivo del rechazo (opcional)
                    </label>
                    <textarea name="motivo_rechazo_cliente" 
                              rows="4" 
                              class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500" 
                              placeholder="¿Por qué rechazás la oferta? Esto ayudará a mejorar futuras propuestas..."></textarea>
                </div>

                <div class="flex gap-3">
                    <button type="submit" 
                            class="flex-1 px-6 py-3 bg-red-600 text-white rounded-lg font-semibold hover:bg-red-700 transition">
                        Confirmar Rechazo
                    </button>
                    <button type="button" 
                            onclick="cerrarModalRechazo()" 
                            class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold hover:bg-gray-300 transition">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function mostrarModalRechazo() {
            document.getElementById('modalRechazo').classList.remove('hidden');
            document.getElementById('modalRechazo').classList.add('flex');
        }

        function cerrarModalRechazo() {
            document.getElementById('modalRechazo').classList.add('hidden');
            document.getElementById('modalRechazo').classList.remove('flex');
        }

        // Cerrar modal con ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarModalRechazo();
            }
        });

        // Cerrar modal al hacer clic fuera
        document.getElementById('modalRechazo').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModalRechazo();
            }
        });
    </script>
</body>
</html>