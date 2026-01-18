<?php
session_start();

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/backend/connection.php';

$prestamo_id = (int)($_GET['prestamo_id'] ?? 0);

if ($prestamo_id <= 0) {
    die('Préstamo no especificado');
}

// Obtener préstamo
$stmt = $pdo->prepare("
    SELECT p.*, CONCAT(u.nombre, ' ', u.apellido) as cliente_nombre, u.email as cliente_email
    FROM prestamos p
    INNER JOIN usuarios u ON p.cliente_id = u.id
    WHERE p.id = ?
    LIMIT 1
");
$stmt->execute([$prestamo_id]);
$prestamo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prestamo) {
    die('Préstamo no encontrado');
}

// Obtener documentos
$stmt = $pdo->prepare("
    SELECT * FROM cliente_documentos_legajo
    WHERE prestamo_id = ?
    ORDER BY fecha_creacion DESC
");
$stmt->execute([$prestamo_id]);
$documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mensaje = '';
$error = '';

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $accion = $_POST['accion'] ?? '';
    $documento_id = (int)($_POST['documento_id'] ?? 0);
    
    if ($accion === 'aprobar_documento' && $documento_id > 0) {
        try {
            $stmt = $pdo->prepare("
                UPDATE cliente_documentos_legajo
                SET estado_validacion = 'aprobado',
                    validado_por = ?,
                    fecha_validacion = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['usuario_id'], $documento_id]);
            $mensaje = 'Documento aprobado';
            
            // Recargar
            $stmt = $pdo->prepare("SELECT * FROM cliente_documentos_legajo WHERE prestamo_id = ? ORDER BY fecha_creacion DESC");
            $stmt->execute([$prestamo_id]);
            $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    elseif ($accion === 'rechazar_documento' && $documento_id > 0) {
        $motivo = trim($_POST['motivo'] ?? '');
        
        if ($motivo === '') {
            $error = 'Debe especificar un motivo';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE cliente_documentos_legajo
                    SET estado_validacion = 'rechazado',
                        motivo_rechazo = ?,
                        validado_por = ?,
                        fecha_validacion = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$motivo, $_SESSION['usuario_id'], $documento_id]);
                $mensaje = 'Documento rechazado';
                
                // Recargar
                $stmt = $pdo->prepare("SELECT * FROM cliente_documentos_legajo WHERE prestamo_id = ? ORDER BY fecha_creacion DESC");
                $stmt->execute([$prestamo_id]);
                $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Verificar si todos los documentos requeridos están aprobados
$docs_requeridos = ['dni_frente', 'dni_dorso', 'selfie_dni', 'recibo_sueldo', 'boleta_servicio', 'cbu', 'movimientos_bancarios'];
$docs_aprobados = [];
foreach ($documentos as $doc) {
    if ($doc['estado_validacion'] === 'aprobado' && in_array($doc['tipo_documento'], $docs_requeridos)) {
        $docs_aprobados[] = $doc['tipo_documento'];
    }
}

$legajo_completo = count(array_intersect($docs_requeridos, $docs_aprobados)) === count($docs_requeridos);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Revisar Legajo - Préstamo Líder</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
</head>
<body class="bg-gray-50">

<?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
    <?php include 'sidebar.php'; ?>
    <div class="ml-64">
<?php else: ?>
    <?php include 'sidebar_asesores.php'; ?>
    <div class="ml-64">
<?php endif; ?>

<nav class="bg-white shadow-md sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
        <h1 class="text-xl font-bold text-gray-800">Revisar Legajo - Préstamo #<?php echo $prestamo_id; ?></h1>
        <a href="prestamos_admin.php" class="text-blue-600 hover:underline">← Volver</a>
    </div>
</nav>

<div class="max-w-7xl mx-auto px-4 py-8">
    
    <!-- Info del préstamo -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <div class="grid grid-cols-3 gap-6">
            <div>
                <div class="text-sm text-gray-600">Cliente</div>
                <div class="font-bold"><?php echo htmlspecialchars($prestamo['cliente_nombre']); ?></div>
                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($prestamo['cliente_email']); ?></div>
            </div>
            <div>
                <div class="text-sm text-gray-600">Monto Total</div>
                <div class="font-bold text-green-600 text-2xl">${<?php echo number_format($prestamo['monto_total'], 2); ?></div>
            </div>
            <div>
                <div class="text-sm text-gray-600">Estado</div>
                <div class="font-bold"><?php echo ucfirst($prestamo['estado']); ?></div>
            </div>
        </div>
    </div>

    <?php if ($mensaje): ?>
    <div class="bg-green-100 border border-green-400 text-green-700 px-6 py-4 rounded-lg mb-6">
        <?php echo $mensaje; ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-6 py-4 rounded-lg mb-6">
        <?php echo $error; ?>
    </div>
    <?php endif; ?>

    <!-- Progreso -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold mb-4">Progreso del Legajo</h2>
        <div class="flex justify-between text-sm mb-2">
            <span><?php echo count($docs_aprobados); ?> / <?php echo count($docs_requeridos); ?> documentos aprobados</span>
            <span><?php echo round((count($docs_aprobados) / count($docs_requeridos)) * 100); ?>%</span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-4">
            <div class="bg-green-600 h-4 rounded-full" style="width: <?php echo round((count($docs_aprobados) / count($docs_requeridos)) * 100); ?>%"></div>
        </div>
        
        <?php if ($legajo_completo): ?>
        <div class="mt-4">
            <form method="POST" action="prestamos_admin.php" onsubmit="return confirm('¿Validar legajo y activar préstamo?');">
                <input type="hidden" name="prestamo_id" value="<?php echo $prestamo_id; ?>">
                <input type="hidden" name="accion" value="validar_legajo">
                <button type="submit" class="w-full px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-bold">
                    ✅ Validar Legajo Completo y Activar Préstamo
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- Documentos -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        
        <?php foreach ($documentos as $doc): ?>
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            
            <div class="p-6">
                <div class="flex justify-between items-start mb-4">
                    <h3 class="font-bold"><?php echo ucfirst(str_replace('_', ' ', $doc['tipo_documento'])); ?></h3>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?php
                        echo match($doc['estado_validacion']) {
                            'pendiente' => 'bg-yellow-100 text-yellow-800',
                            'aprobado' => 'bg-green-100 text-green-800',
                            'rechazado' => 'bg-red-100 text-red-800',
                            default => 'bg-gray-100 text-gray-800'
                        };
                    ?>">
                        <?php echo ucfirst($doc['estado_validacion']); ?>
                    </span>
                </div>

                <!-- Preview del documento -->
                <div class="mb-4">
                    <?php if (str_ends_with($doc['archivo_path'], '.pdf')): ?>
                        <div class="bg-gray-100 p-8 rounded-lg text-center">
                            <span class="material-icons-outlined text-red-600 text-6xl">picture_as_pdf</span>
                            <div class="mt-2 text-sm"><?php echo htmlspecialchars($doc['archivo_nombre']); ?></div>
                        </div>
                    <?php else: ?>
                        <img src="<?php echo htmlspecialchars($doc['archivo_path']); ?>" alt="Documento" class="w-full rounded-lg">
                    <?php endif; ?>
                </div>

                <div class="text-sm text-gray-600 mb-4">
                    Subido: <?php echo date('d/m/Y H:i', strtotime($doc['fecha_creacion'])); ?>
                </div>

                <?php if ($doc['descripcion']): ?>
                <div class="text-sm text-gray-700 mb-4 p-3 bg-gray-50 rounded">
                    <?php echo htmlspecialchars($doc['descripcion']); ?>
                </div>
                <?php endif; ?>

                <?php if ($doc['estado_validacion'] === 'rechazado' && $doc['motivo_rechazo']): ?>
                <div class="text-sm text-red-700 mb-4 p-3 bg-red-50 rounded border border-red-200">
                    <strong>Motivo de rechazo:</strong><br>
                    <?php echo htmlspecialchars($doc['motivo_rechazo']); ?>
                </div>
                <?php endif; ?>

                <!-- Acciones -->
                <?php if ($doc['estado_validacion'] === 'pendiente'): ?>
                <div class="flex gap-2">
                    <form method="POST" class="flex-1">
                        <input type="hidden" name="accion" value="aprobar_documento">
                        <input type="hidden" name="documento_id" value="<?php echo $doc['id']; ?>">
                        <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold">
                            Aprobar
                        </button>
                    </form>
                    <button onclick="mostrarModalRechazo(<?php echo $doc['id']; ?>)" 
                            class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm font-semibold">
                        Rechazar
                    </button>
                </div>
                <?php endif; ?>

                <a href="<?php echo htmlspecialchars($doc['archivo_path']); ?>" target="_blank" 
                   class="block mt-2 text-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-semibold">
                    Ver Completo
                </a>
            </div>
            
        </div>
        <?php endforeach; ?>
        
    </div>

</div>

</div>

<!-- Modal rechazo -->
<div id="modalRechazo" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl p-8 max-w-md w-full mx-4">
        <h3 class="text-2xl font-bold text-gray-800 mb-4">Rechazar Documento</h3>
        
        <form method="POST">
            <input type="hidden" name="accion" value="rechazar_documento">
            <input type="hidden" name="documento_id" id="rechazo_documento_id">
            
            <div class="mb-6">
                <label class="block text-sm font-semibold text-gray-700 mb-2">Motivo del rechazo</label>
                <textarea name="motivo" required rows="4" 
                          placeholder="Explicá por qué se rechaza este documento"
                          class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-red-500"></textarea>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="flex-1 px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">
                    Rechazar
                </button>
                <button type="button" onclick="cerrarModalRechazo()" 
                        class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-semibold">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function mostrarModalRechazo(documentoId) {
    document.getElementById('rechazo_documento_id').value = documentoId;
    document.getElementById('modalRechazo').classList.remove('hidden');
    document.getElementById('modalRechazo').classList.add('flex');
}

function cerrarModalRechazo() {
    document.getElementById('modalRechazo').classList.add('hidden');
    document.getElementById('modalRechazo').classList.remove('flex');
}
</script>

</body>
</html>