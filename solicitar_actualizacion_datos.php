<?php
session_start();

if (!isset($_SESSION['cliente_id'])) {
    header('Location: login_clientes.php');
    exit;
}

require_once __DIR__ . '/backend/connection.php';

$usuario_id = (int)$_SESSION['cliente_id'];

// Obtener datos actuales del cliente
$stmt = $pdo->prepare("
    SELECT cd.*, u.nombre, u.apellido, u.email
    FROM clientes_detalles cd
    INNER JOIN usuarios u ON cd.usuario_id = u.id
    WHERE cd.usuario_id = ?
    LIMIT 1
");
$stmt->execute([$usuario_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    die('Cliente no encontrado');
}

$mensaje = '';
$error = '';

// Procesar solicitud
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_solicitud'])) {
    
    $campo_solicitado = $_POST['campo_solicitado'] ?? '';
    $valor_propuesto = trim($_POST['valor_propuesto'] ?? '');
    $motivo = trim($_POST['motivo'] ?? '');
    
    if ($campo_solicitado === '' || $valor_propuesto === '' || $motivo === '') {
        $error = 'Completá todos los campos';
    } else {
        
        try {
            // Subir documentos respaldatorios si hay
            $documentos_respaldo = [];
            
            if (!empty($_FILES['documentos']['name'][0])) {
                $base_dir = __DIR__ . '/../uploads/solicitudes_actualizacion/' . $usuario_id;
                if (!is_dir($base_dir)) {
                    mkdir($base_dir, 0755, true);
                }
                
                foreach ($_FILES['documentos']['name'] as $key => $filename) {
                    if ($_FILES['documentos']['error'][$key] === UPLOAD_ERR_OK) {
                        $tmp = $_FILES['documentos']['tmp_name'][$key];
                        $ext = pathinfo($filename, PATHINFO_EXTENSION);
                        $nombre_unico = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $ruta_completa = $base_dir . '/' . $nombre_unico;
                        
                        if (move_uploaded_file($tmp, $ruta_completa)) {
                            $documentos_respaldo[] = 'uploads/solicitudes_actualizacion/' . $usuario_id . '/' . $nombre_unico;
                        }
                    }
                }
            }
            
            // Obtener valor actual
            $valor_actual = $cliente[$campo_solicitado] ?? 'No especificado';
            
            // Guardar solicitud
            $stmt = $pdo->prepare("
                INSERT INTO cliente_solicitudes_actualizacion
                (usuario_id, campo_solicitado, valor_actual, valor_propuesto, motivo, documentos_respaldo, estado)
                VALUES (?, ?, ?, ?, ?, ?, 'pendiente')
            ");
            $stmt->execute([
                $usuario_id,
                $campo_solicitado,
                $valor_actual,
                $valor_propuesto,
                $motivo,
                json_encode($documentos_respaldo)
            ]);
            
            $mensaje = '✅ Solicitud enviada correctamente. Será revisada por nuestro equipo.';
            
        } catch (Exception $e) {
            $error = 'Error al enviar la solicitud: ' . $e->getMessage();
        }
    }
}

// Obtener solicitudes previas
$stmt = $pdo->prepare("
    SELECT *,
           CONCAT(ur.nombre, ' ', ur.apellido) as revisor_nombre
    FROM cliente_solicitudes_actualizacion csa
    LEFT JOIN usuarios ur ON csa.revisado_por = ur.id
    WHERE csa.usuario_id = ?
    ORDER BY csa.fecha_solicitud DESC
    LIMIT 20
");
$stmt->execute([$usuario_id]);
$solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Campos editables
$campos_editables = [
    'cuil_cuit' => 'CUIL/CUIT',
    'telefono' => 'Teléfono',
    'direccion_calle' => 'Dirección',
    'direccion_barrio' => 'Barrio',
    'direccion_localidad' => 'Localidad',
    'direccion_provincia' => 'Provincia',
    'empleador_razon_social' => 'Empleador',
    'cargo' => 'Cargo',
    'empleador_telefono' => 'Teléfono del Empleador'
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Solicitar Actualización de Datos - Préstamo Líder</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
</head>
<body class="bg-gray-50">

<nav class="bg-white shadow-md sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
        <div class="flex items-center gap-2">
            <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span class="text-xl font-bold text-gray-800">Préstamo Líder</span>
        </div>
        <a href="dashboard_clientes.php" class="text-blue-600 hover:underline">← Volver al Dashboard</a>
    </div>
</nav>

<div class="max-w-5xl mx-auto px-4 py-8">
    
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">📝 Solicitar Actualización de Datos</h1>
        <p class="text-gray-600">Si detectás datos desactualizados, solicitá una actualización con documentación respaldatoria.</p>
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

    <?php if ($cliente['datos_bloqueados']): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 mb-6">
        <h3 class="font-bold text-blue-900 mb-2">ℹ️ Tus datos están protegidos</h3>
        <p class="text-sm text-blue-800">
            Por seguridad, no podés editar directamente tus datos. 
            Para actualizarlos, completá el formulario a continuación con la documentación respaldatoria.
        </p>
    </div>
    <?php endif; ?>

    <!-- Formulario de solicitud -->
    <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6">Nueva Solicitud</h2>
        
        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Campo a Actualizar</label>
                <select name="campo_solicitado" required
                        class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    <option value="">Seleccioná un campo...</option>
                    <?php foreach ($campos_editables as $campo => $label): ?>
                    <option value="<?php echo $campo; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Nuevo Valor</label>
                <input type="text" name="valor_propuesto" required
                       placeholder="Ingresá el nuevo valor"
                       class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Motivo de la Actualización</label>
                <textarea name="motivo" required rows="4"
                          placeholder="Explicá por qué necesitás actualizar este dato"
                          class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">Documentos Respaldatorios</label>
                <input type="file" name="documentos[]" multiple accept=".jpg,.jpeg,.png,.pdf"
                       class="block w-full text-sm file:mr-4 file:py-3 file:px-6 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                <p class="text-xs text-gray-500 mt-2">Podés subir múltiples archivos (JPG, PNG, PDF)</p>
            </div>

            <button type="submit" name="enviar_solicitud"
                    class="w-full py-4 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-bold text-lg">
                📤 Enviar Solicitud
            </button>

        </form>
    </div>

    <!-- Historial de solicitudes -->
    <?php if (!empty($solicitudes)): ?>
    <div class="bg-white rounded-xl shadow-lg p-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6">Historial de Solicitudes</h2>
        
        <div class="space-y-4">
            <?php foreach ($solicitudes as $sol): ?>
            <div class="border rounded-lg p-6 <?php
                echo match($sol['estado']) {
                    'pendiente' => 'bg-yellow-50 border-yellow-200',
                    'aprobado' => 'bg-green-50 border-green-200',
                    'rechazado' => 'bg-red-50 border-red-200',
                    default => 'bg-gray-50 border-gray-200'
                };
            ?>">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="font-bold text-gray-800">
                            <?php echo htmlspecialchars($campos_editables[$sol['campo_solicitado']] ?? $sol['campo_solicitado']); ?>
                        </h3>
                        <p class="text-sm text-gray-600">
                            Solicitado el <?php echo date('d/m/Y H:i', strtotime($sol['fecha_solicitud'])); ?>
                        </p>
                    </div>
                    <span class="px-4 py-2 rounded-full text-sm font-semibold <?php
                        echo match($sol['estado']) {
                            'pendiente' => 'bg-yellow-100 text-yellow-800',
                            'aprobado' => 'bg-green-100 text-green-800',
                            'rechazado' => 'bg-red-100 text-red-800',
                            default => 'bg-gray-100 text-gray-800'
                        };
                    ?>">
                        <?php
                        echo match($sol['estado']) {
                            'pendiente' => '⏳ Pendiente',
                            'aprobado' => '✅ Aprobado',
                            'rechazado' => '❌ Rechazado',
                            default => 'Desconocido'
                        };
                        ?>
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-3">
                    <div>
                        <p class="text-xs text-gray-500">Valor Actual:</p>
                        <p class="font-medium text-gray-800"><?php echo htmlspecialchars($sol['valor_actual'] ?? 'N/A'); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Valor Propuesto:</p>
                        <p class="font-medium text-gray-800"><?php echo htmlspecialchars($sol['valor_propuesto'] ?? 'N/A'); ?></p>
                    </div>
                </div>

                <div class="mb-3">
                    <p class="text-xs text-gray-500">Motivo:</p>
                    <p class="text-sm text-gray-700"><?php echo htmlspecialchars($sol['motivo'] ?? ''); ?></p>
                </div>

                <?php if ($sol['estado'] !== 'pendiente'): ?>
                <div class="pt-3 border-t">
                    <p class="text-xs text-gray-500">
                        <?php echo $sol['estado'] === 'aprobado' ? 'Aprobado' : 'Rechazado'; ?> por 
                        <strong><?php echo htmlspecialchars($sol['revisor_nombre'] ?? 'Sistema'); ?></strong>
                        el <?php echo date('d/m/Y', strtotime($sol['fecha_revision'])); ?>
                    </p>
                    <?php if ($sol['comentarios_revision']): ?>
                    <p class="text-sm text-gray-700 mt-2">
                        <strong>Comentario:</strong> <?php echo htmlspecialchars($sol['comentarios_revision']); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

</body>
</html>