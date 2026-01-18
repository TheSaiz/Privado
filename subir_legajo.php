<?php
session_start();

if (!isset($_SESSION['cliente_id'])) {
    header('Location: login_clientes.php');
    exit;
}

require_once __DIR__ . '/backend/connection.php';

$usuario_id = (int)($_SESSION['cliente_id'] ?? 0);
if ($usuario_id <= 0) {
    header('Location: login_clientes.php');
    exit;
}

// Obtener préstamo aprobado que requiere legajo
$prestamo_id = (int)($_GET['prestamo_id'] ?? 0);

if ($prestamo_id <= 0) {
    die('Préstamo no especificado');
}

// Verificar que el préstamo existe, es del cliente y requiere legajo
$stmt = $pdo->prepare("
    SELECT id, cliente_id, estado, monto_total, requiere_legajo, legajo_completo, legajo_validado
    FROM prestamos
    WHERE id = ? AND cliente_id = ?
    LIMIT 1
");
$stmt->execute([$prestamo_id, $usuario_id]);
$prestamo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prestamo) {
    die('Préstamo no encontrado');
}

if ($prestamo['estado'] !== 'aprobado' && $prestamo['estado'] !== 'documentacion_pendiente' && $prestamo['estado'] !== 'documentacion_completa') {
    die('Este préstamo no requiere documentación en este momento');
}

if (!$prestamo['requiere_legajo']) {
    die('Este préstamo no requiere documentación de legajo');
}

// Obtener documentos ya subidos
$stmt = $pdo->prepare("
    SELECT tipo_documento, archivo_path, archivo_nombre, estado_validacion, motivo_rechazo, fecha_creacion
    FROM cliente_documentos_legajo
    WHERE usuario_id = ? AND prestamo_id = ?
    ORDER BY fecha_creacion DESC
");
$stmt->execute([$usuario_id, $prestamo_id]);
$documentos_subidos = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $documentos_subidos[$row['tipo_documento']] = $row;
}

// Tipos de documentos requeridos
$documentos_requeridos = [
    'dni_frente' => 'DNI - Frente',
    'dni_dorso' => 'DNI - Dorso',
    'selfie_dni' => 'Selfie con DNI',
    'recibo_sueldo' => 'Último recibo de sueldo',
    'boleta_servicio' => 'Boleta de servicio',
    'cbu' => 'Constancia de CBU',
    'movimientos_bancarios' => 'Movimientos bancarios (últimos 3 meses)'
];

$mensaje = '';
$error = '';

// Procesamiento de carga de archivos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_documento'])) {
    
    $tipo_documento = $_POST['tipo_documento'] ?? '';
    $descripcion = trim($_POST['descripcion'] ?? '');
    
    if (!array_key_exists($tipo_documento, $documentos_requeridos) && $tipo_documento !== 'otro') {
        $error = 'Tipo de documento inválido';
    } elseif (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Debes seleccionar un archivo';
    } else {
        
        try {
            $archivo = $_FILES['archivo'];
            
            // Validaciones
            $max_size = 10 * 1024 * 1024; // 10MB
            if ($archivo['size'] > $max_size) {
                throw new Exception('El archivo no puede superar los 10MB');
            }
            
            $permitidos = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $archivo['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mime, $permitidos, true)) {
                throw new Exception('Solo se permiten imágenes (JPG, PNG) y PDF');
            }
            
            // Crear directorio si no existe
            $base_dir = __DIR__ . '/../uploads/legajos/' . $usuario_id . '/' . $prestamo_id;
            if (!is_dir($base_dir)) {
                mkdir($base_dir, 0755, true);
            }
            
            // Generar nombre único
            $ext = match($mime) {
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/png' => 'png',
                'application/pdf' => 'pdf',
                default => 'dat'
            };
            
            $nombre_archivo = $tipo_documento . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $ruta_completa = $base_dir . '/' . $nombre_archivo;
            $ruta_relativa = 'uploads/legajos/' . $usuario_id . '/' . $prestamo_id . '/' . $nombre_archivo;
            
            // Mover archivo
            if (!move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
                throw new Exception('Error al guardar el archivo');
            }
            
            // Guardar en BD
            $pdo->beginTransaction();
            
            // Si ya existe un documento de este tipo, marcarlo como reemplazado (no eliminar)
            if (isset($documentos_subidos[$tipo_documento])) {
                $stmt = $pdo->prepare("
                    UPDATE cliente_documentos_legajo
                    SET estado_validacion = 'rechazado',
                        motivo_rechazo = 'Reemplazado por nueva versión'
                    WHERE usuario_id = ? AND prestamo_id = ? AND tipo_documento = ?
                ");
                $stmt->execute([$usuario_id, $prestamo_id, $tipo_documento]);
            }
            
            // Insertar nuevo documento
            $stmt = $pdo->prepare("
                INSERT INTO cliente_documentos_legajo
                (usuario_id, prestamo_id, tipo_documento, archivo_path, archivo_nombre, archivo_tipo, archivo_tamano, descripcion, estado_validacion)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')
            ");
            $stmt->execute([
                $usuario_id,
                $prestamo_id,
                $tipo_documento,
                $ruta_relativa,
                $archivo['name'],
                $mime,
                $archivo['size'],
                $descripcion
            ]);
            
            // Actualizar estado del préstamo si es necesario
            $stmt = $pdo->prepare("
                UPDATE prestamos
                SET estado = 'documentacion_pendiente'
                WHERE id = ? AND estado = 'aprobado'
            ");
            $stmt->execute([$prestamo_id]);
            
            $pdo->commit();
            
            $mensaje = '✅ Documento subido correctamente';
            
            // Recargar documentos
            $stmt = $pdo->prepare("
                SELECT tipo_documento, archivo_path, archivo_nombre, estado_validacion, motivo_rechazo, fecha_creacion
                FROM cliente_documentos_legajo
                WHERE usuario_id = ? AND prestamo_id = ? AND estado_validacion != 'rechazado'
                ORDER BY fecha_creacion DESC
            ");
            $stmt->execute([$usuario_id, $prestamo_id]);
            $documentos_subidos = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $documentos_subidos[$row['tipo_documento']] = $row;
            }
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

// Calcular progreso
$total_requeridos = count($documentos_requeridos);
$total_subidos = 0;
foreach ($documentos_requeridos as $tipo => $nombre) {
    if (isset($documentos_subidos[$tipo])) {
        $total_subidos++;
    }
}
$progreso = ($total_subidos / $total_requeridos) * 100;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Documentación del Legajo - Préstamo Líder</title>
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
        <a href="dashboard_clientes.php" class="text-blue-600 hover:text-blue-700">
            ← Volver al Dashboard
        </a>
    </div>
</nav>

<div class="max-w-5xl mx-auto px-4 py-8">
    
    <!-- Header -->
    <div class="bg-white rounded-xl shadow-lg p-8 mb-6">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">📄 Documentación del Legajo</h1>
        <p class="text-gray-600">Préstamo aprobado por <span class="font-bold text-green-600">${{number_format($prestamo['monto_total'], 2)}}</span></p>
        
        <!-- Progreso -->
        <div class="mt-6">
            <div class="flex justify-between text-sm font-medium mb-2">
                <span>Progreso de documentación</span>
                <span><?php echo $total_subidos; ?> / <?php echo $total_requeridos; ?> documentos</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3">
                <div class="bg-green-600 h-3 rounded-full transition-all duration-500" style="width: <?php echo $progreso; ?>%"></div>
            </div>
        </div>
        
        <?php if ($progreso >= 100): ?>
        <div class="mt-6 bg-green-50 border border-green-200 text-green-800 px-6 py-4 rounded-lg">
            ✅ <strong>¡Documentación completa!</strong> Tu legajo será revisado por nuestro equipo.
        </div>
        <?php endif; ?>
    </div>

    <?php if ($mensaje): ?>
    <div class="bg-green-100 border border-green-400 text-green-700 px-6 py-4 rounded-lg mb-6">
        <?php echo $mensaje; ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-6 py-4 rounded-lg mb-6">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- Listado de documentos -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <?php foreach ($documentos_requeridos as $tipo => $nombre): ?>
        <div class="bg-white rounded-xl shadow-lg p-6">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-800"><?php echo $nombre; ?></h3>
                <?php if (isset($documentos_subidos[$tipo])): ?>
                    <?php
                    $doc = $documentos_subidos[$tipo];
                    $badge_color = match($doc['estado_validacion']) {
                        'pendiente' => 'bg-yellow-100 text-yellow-800',
                        'aprobado' => 'bg-green-100 text-green-800',
                        'rechazado' => 'bg-red-100 text-red-800',
                        default => 'bg-gray-100 text-gray-800'
                    };
                    $badge_text = match($doc['estado_validacion']) {
                        'pendiente' => '⏳ En revisión',
                        'aprobado' => '✅ Aprobado',
                        'rechazado' => '❌ Rechazado',
                        default => 'Desconocido'
                    };
                    ?>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $badge_color; ?>">
                        <?php echo $badge_text; ?>
                    </span>
                <?php else: ?>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">
                        Pendiente
                    </span>
                <?php endif; ?>
            </div>

            <?php if (isset($documentos_subidos[$tipo])): ?>
                <?php $doc = $documentos_subidos[$tipo]; ?>
                
                <div class="mb-4 p-4 bg-gray-50 rounded-lg">
                    <div class="flex items-center gap-3 mb-2">
                        <span class="material-icons-outlined text-blue-600">description</span>
                        <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($doc['archivo_nombre']); ?></span>
                    </div>
                    <div class="text-xs text-gray-500">
                        Subido: <?php echo date('d/m/Y H:i', strtotime($doc['fecha_creacion'])); ?>
                    </div>
                    
                    <?php if ($doc['estado_validacion'] === 'rechazado' && $doc['motivo_rechazo']): ?>
                    <div class="mt-3 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
                        <strong>Motivo de rechazo:</strong><br>
                        <?php echo htmlspecialchars($doc['motivo_rechazo']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($doc['estado_validacion'] === 'rechazado' || $doc['estado_validacion'] === 'pendiente'): ?>
                <!-- Permitir reemplazar -->
                <button onclick="document.getElementById('form_<?php echo $tipo; ?>').style.display='block'; this.style.display='none';" 
                        class="text-sm text-blue-600 hover:underline">
                    Reemplazar documento
                </button>
                
                <form id="form_<?php echo $tipo; ?>" method="POST" enctype="multipart/form-data" style="display:none;" class="mt-4">
                    <input type="hidden" name="tipo_documento" value="<?php echo $tipo; ?>">
                    <input type="file" name="archivo" accept=".jpg,.jpeg,.png,.pdf" required
                           class="block w-full text-sm mb-3 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    <textarea name="descripcion" placeholder="Descripción o aclaración (opcional)" rows="2"
                              class="w-full px-3 py-2 border rounded-lg text-sm mb-3"></textarea>
                    <button type="submit" name="subir_documento" 
                            class="w-full py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm font-semibold">
                        Subir nuevo archivo
                    </button>
                </form>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- Formulario de carga -->
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="tipo_documento" value="<?php echo $tipo; ?>">
                    
                    <input type="file" name="archivo" accept=".jpg,.jpeg,.png,.pdf" required
                           class="block w-full text-sm mb-3 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    
                    <textarea name="descripcion" placeholder="Descripción o aclaración (opcional)" rows="2"
                              class="w-full px-3 py-2 border rounded-lg text-sm mb-3"></textarea>
                    
                    <button type="submit" name="subir_documento" 
                            class="w-full py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold">
                        Subir documento
                    </button>
                </form>
            <?php endif; ?>
            
        </div>
        <?php endforeach; ?>
        
        <!-- Otros documentos -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">📎 Otros documentos</h3>
            <p class="text-sm text-gray-600 mb-4">
                Si necesitás subir documentos adicionales, podés hacerlo aquí.
            </p>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="tipo_documento" value="otro">
                
                <input type="file" name="archivo" accept=".jpg,.jpeg,.png,.pdf" required
                       class="block w-full text-sm mb-3 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                
                <textarea name="descripcion" placeholder="Descripción del documento" rows="2" required
                          class="w-full px-3 py-2 border rounded-lg text-sm mb-3"></textarea>
                
                <button type="submit" name="subir_documento" 
                        class="w-full py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition font-semibold">
                    Subir documento adicional
                </button>
            </form>
        </div>
        
    </div>

    <!-- Documentos adicionales subidos -->
    <?php
    $stmt = $pdo->prepare("
        SELECT archivo_nombre, descripcion, estado_validacion, fecha_creacion, motivo_rechazo
        FROM cliente_documentos_legajo
        WHERE usuario_id = ? AND prestamo_id = ? AND tipo_documento = 'otro' AND estado_validacion != 'rechazado'
        ORDER BY fecha_creacion DESC
    ");
    $stmt->execute([$usuario_id, $prestamo_id]);
    $docs_adicionales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($docs_adicionales)):
    ?>
    <div class="mt-6 bg-white rounded-xl shadow-lg p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Documentos adicionales subidos</h3>
        <div class="space-y-3">
            <?php foreach ($docs_adicionales as $doc): ?>
            <div class="flex items-start justify-between p-4 bg-gray-50 rounded-lg">
                <div class="flex-1">
                    <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($doc['archivo_nombre']); ?></div>
                    <div class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($doc['descripcion']); ?></div>
                    <div class="text-xs text-gray-500 mt-1">
                        <?php echo date('d/m/Y H:i', strtotime($doc['fecha_creacion'])); ?>
                    </div>
                </div>
                <span class="px-3 py-1 rounded-full text-xs font-semibold <?php
                    echo match($doc['estado_validacion']) {
                        'pendiente' => 'bg-yellow-100 text-yellow-800',
                        'aprobado' => 'bg-green-100 text-green-800',
                        'rechazado' => 'bg-red-100 text-red-800',
                        default => 'bg-gray-100 text-gray-800'
                    };
                ?>">
                    <?php
                    echo match($doc['estado_validacion']) {
                        'pendiente' => '⏳ En revisión',
                        'aprobado' => '✅ Aprobado',
                        'rechazado' => '❌ Rechazado',
                        default => 'Desconocido'
                    };
                    ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Información importante -->
    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-6">
        <h3 class="font-bold text-blue-900 mb-2 flex items-center gap-2">
            <span class="material-icons-outlined">info</span>
            Información Importante
        </h3>
        <ul class="text-sm text-blue-800 space-y-1">
            <li>• Los archivos deben estar en formato JPG, PNG o PDF</li>
            <li>• El tamaño máximo por archivo es de 10MB</li>
            <li>• Las imágenes deben ser nítidas y legibles</li>
            <li>• Los documentos serán revisados por nuestro equipo en un plazo de 24-48hs</li>
            <li>• Si algún documento es rechazado, podrás volver a subirlo</li>
        </ul>
    </div>

</div>

</body>
</html>