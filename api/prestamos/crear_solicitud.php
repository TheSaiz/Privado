<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

/* =========================
   HELPERS
========================= */
function logError($msg) {
    $log = __DIR__ . '/../../logs/api_errors.log';
    @mkdir(dirname($log), 0755, true);
    @file_put_contents($log, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

function jsonResponse($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function subirArchivo($archivo, $carpeta = 'documentos') {
    $uploadsDir = __DIR__ . "/../../uploads/$carpeta";
    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0755, true);
    }
    
    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    $nombre = uniqid() . '_' . time() . '.' . $ext;
    $ruta = $uploadsDir . '/' . $nombre;
    
    if (move_uploaded_file($archivo['tmp_name'], $ruta)) {
        return [
            'ruta' => "uploads/$carpeta/$nombre",
            'nombre_original' => $archivo['name'],
            'tipo_mime' => $archivo['type'],
            'tamano' => $archivo['size']
        ];
    }
    
    return false;
}

/* =========================
   VALIDAR SESIÓN
========================= */
if (!isset($_SESSION['cliente_id'])) {
    jsonResponse(false, 'Sesión no válida');
}

$cliente_id = (int)$_SESSION['cliente_id'];

/* =========================
   CONEXIÓN BD
========================= */
try {
    require_once __DIR__ . '/../../backend/connection.php';
} catch (Exception $e) {
    logError("Error conexión BD: " . $e->getMessage());
    jsonResponse(false, 'Error de conexión');
}

/* =========================
   VALIDAR MÉTODO
========================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Método no permitido');
}

/* =========================
   TIPO DE OPERACIÓN
========================= */
$tipo = $_POST['tipo'] ?? 'prestamo';
$tipos_validos = ['prestamo', 'empeno', 'prendario'];

if (!in_array($tipo, $tipos_validos, true)) {
    jsonResponse(false, 'Tipo de operación no válido');
}

/* =========================
   VALIDAR CLIENTE
========================= */
$stmt = $pdo->prepare("
    SELECT cd.docs_completos, u.nombre, u.apellido, u.email
    FROM usuarios u
    LEFT JOIN clientes_detalles cd ON cd.usuario_id = u.id
    WHERE u.id = ?
");
$stmt->execute([$cliente_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    jsonResponse(false, 'Cliente no encontrado');
}

// Validar documentación completa
if (!$cliente['docs_completos']) {
    jsonResponse(false, 'Debes completar tu documentación antes de solicitar');
}

/* =========================
   CONFIG PRÉSTAMOS
========================= */
$config = [
    'monto_minimo' => 5000,
    'monto_maximo' => 500000,
    'cuotas_minimas' => 1,
    'cuotas_maximas' => 24,
    'tasa_interes_default' => 50
];

try {
    $stmt = $pdo->query("SELECT clave, valor FROM prestamos_config");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $config[$row['clave']] = $row['valor'];
    }
} catch (Exception $e) {
    logError("Error cargando config: " . $e->getMessage());
}

/* =========================
   PROCESAR SEGÚN TIPO
========================= */
$pdo->beginTransaction();

try {

    switch ($tipo) {

        /* =================== PRÉSTAMO PERSONAL =================== */
        case 'prestamo':
            
            $monto = (float)($_POST['monto'] ?? 0);
            $cuotas = (int)($_POST['cuotas'] ?? 0);
            $frecuencia = $_POST['frecuencia'] ?? 'mensual';
            $destino = trim($_POST['destino'] ?? '');

            // Validaciones
            if ($monto < $config['monto_minimo'] || $monto > $config['monto_maximo']) {
                throw new Exception("Monto debe estar entre $" . number_format($config['monto_minimo'], 0) . " y $" . number_format($config['monto_maximo'], 0));
            }

            if ($cuotas < $config['cuotas_minimas'] || $cuotas > $config['cuotas_maximas']) {
                throw new Exception("Cuotas deben estar entre {$config['cuotas_minimas']} y {$config['cuotas_maximas']}");
            }

            if (!in_array($frecuencia, ['diario', 'semanal', 'quincenal', 'mensual'])) {
                throw new Exception('Frecuencia no válida');
            }

            if (empty($destino)) {
                throw new Exception('El destino del crédito es obligatorio');
            }

            // Validar recibo obligatorio
            if (!isset($_FILES['recibo']) || $_FILES['recibo']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('El comprobante de ingreso es obligatorio');
            }

            // Subir recibo
            $recibo = subirArchivo($_FILES['recibo'], 'prestamos/recibos');
            if (!$recibo) {
                throw new Exception('Error al subir comprobante de ingreso');
            }

            // Calcular monto total
            $tasa = (float)$config['tasa_interes_default'];
            $monto_total = round($monto * (1 + $tasa / 100), 2);

            // Insertar préstamo
            $stmt = $pdo->prepare("
                INSERT INTO prestamos (
                    cliente_id, 
                    monto_solicitado, 
                    cuotas_solicitadas,
                    frecuencia_solicitada, 
                    tasa_interes,
                    monto_total, 
                    destino_credito,
                    estado, 
                    fecha_solicitud,
                    tipo_solicitud,
                    requiere_legajo,
                    legajo_completo,
                    legajo_validado
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente', NOW(), 'manual', 1, 0, 0)
            ");

            $stmt->execute([
                $cliente_id, 
                $monto, 
                $cuotas,
                $frecuencia, 
                $tasa,
                $monto_total, 
                $destino
            ]);

            $operacion_id = $pdo->lastInsertId();

            // Insertar recibo en prestamos_documentos
            $stmt = $pdo->prepare("
                INSERT INTO prestamos_documentos (
                    prestamo_id,
                    tipo,
                    nombre_archivo,
                    ruta_archivo,
                    uploaded_by
                ) VALUES (?, 'comprobante_ingresos', ?, ?, ?)
            ");
            $stmt->execute([
                $operacion_id,
                $recibo['nombre_original'],
                $recibo['ruta'],
                $cliente_id
            ]);

            // Subir archivos adicionales (opcional)
            if (isset($_FILES['adicionales']) && is_array($_FILES['adicionales']['name'])) {
                for ($i = 0; $i < count($_FILES['adicionales']['name']); $i++) {
                    if ($_FILES['adicionales']['error'][$i] === UPLOAD_ERR_OK) {
                        $temp_file = [
                            'name' => $_FILES['adicionales']['name'][$i],
                            'tmp_name' => $_FILES['adicionales']['tmp_name'][$i],
                            'type' => $_FILES['adicionales']['type'][$i],
                            'size' => $_FILES['adicionales']['size'][$i]
                        ];
                        $adicional = subirArchivo($temp_file, 'prestamos/adicionales');
                        if ($adicional) {
                            $stmt = $pdo->prepare("
                                INSERT INTO prestamos_documentos (
                                    prestamo_id,
                                    tipo,
                                    nombre_archivo,
                                    ruta_archivo,
                                    uploaded_by
                                ) VALUES (?, 'otro', ?, ?, ?)
                            ");
                            $stmt->execute([
                                $operacion_id,
                                $adicional['nombre_original'],
                                $adicional['ruta'],
                                $cliente_id
                            ]);
                        }
                    }
                }
            }

            $tipo_display = 'Préstamo Personal';
            break;

        /* =================== EMPEÑO =================== */
        case 'empeno':
            
            $monto = (float)($_POST['monto'] ?? 0);
            $descripcion = trim($_POST['descripcion'] ?? '');
            $destino = trim($_POST['destino'] ?? '');

            // Validaciones
            if ($monto <= 0) {
                throw new Exception('El monto pretendido debe ser mayor a 0');
            }

            if (empty($descripcion)) {
                throw new Exception('Debes describir las características del producto');
            }

            // Validar imagen obligatoria
            if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('La imagen del producto es obligatoria');
            }

            // Subir imagen
            $imagen = subirArchivo($_FILES['imagen'], 'empenos/productos');
            if (!$imagen) {
                throw new Exception('Error al subir la imagen del producto');
            }

            // Insertar empeño (SIN campo imagen_producto)
            $stmt = $pdo->prepare("
                INSERT INTO empenos (
                    cliente_id, 
                    monto_solicitado,
                    descripcion_producto, 
                    destino_credito,
                    estado, 
                    fecha_solicitud
                ) VALUES (?, ?, ?, ?, 'pendiente', NOW())
            ");

            $stmt->execute([
                $cliente_id, 
                $monto, 
                $descripcion, 
                $destino
            ]);

            $operacion_id = $pdo->lastInsertId();

            // Insertar imagen en empenos_imagenes
            $stmt = $pdo->prepare("
                INSERT INTO empenos_imagenes (
                    empeno_id,
                    ruta_archivo,
                    nombre_original,
                    tipo_mime,
                    tamano_bytes
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $operacion_id,
                $imagen['ruta'],
                $imagen['nombre_original'],
                $imagen['tipo_mime'],
                $imagen['tamano']
            ]);

            $tipo_display = 'Empeño';
            break;

        /* =================== CRÉDITO PRENDARIO =================== */
        case 'prendario':
            
            $monto = (float)($_POST['monto'] ?? 0);
            $dominio = strtoupper(trim($_POST['dominio'] ?? ''));
            $descripcion = trim($_POST['descripcion'] ?? '');
            $destino = trim($_POST['destino'] ?? '');
            $es_titular = isset($_POST['es_titular']) ? 1 : 0;

            // Validaciones
            if ($monto <= 0) {
                throw new Exception('El monto pretendido debe ser mayor a 0');
            }

            if (empty($dominio)) {
                throw new Exception('El dominio del vehículo es obligatorio');
            }

            if (empty($descripcion)) {
                throw new Exception('Debes describir el vehículo');
            }

            if (!$es_titular) {
                throw new Exception('Debes ser titular del vehículo para solicitar un crédito prendario');
            }

            // Validar fotos obligatorias
            if (!isset($_FILES['foto_frente']) || $_FILES['foto_frente']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('La foto frontal del vehículo es obligatoria');
            }

            if (!isset($_FILES['foto_trasera']) || $_FILES['foto_trasera']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('La foto trasera del vehículo es obligatoria');
            }

            if (!isset($_FILES['foto_lateral']) || !is_array($_FILES['foto_lateral']['name']) || count($_FILES['foto_lateral']['name']) === 0) {
                throw new Exception('Debes subir al menos una foto lateral del vehículo');
            }

            // Subir foto frente
            $foto_frente = subirArchivo($_FILES['foto_frente'], 'prendarios/vehiculos');
            if (!$foto_frente) {
                throw new Exception('Error al subir foto frontal');
            }

            // Subir foto trasera
            $foto_trasera = subirArchivo($_FILES['foto_trasera'], 'prendarios/vehiculos');
            if (!$foto_trasera) {
                throw new Exception('Error al subir foto trasera');
            }

            // Subir fotos laterales
            $fotos_laterales = [];
            for ($i = 0; $i < count($_FILES['foto_lateral']['name']); $i++) {
                if ($_FILES['foto_lateral']['error'][$i] === UPLOAD_ERR_OK) {
                    $temp_file = [
                        'name' => $_FILES['foto_lateral']['name'][$i],
                        'tmp_name' => $_FILES['foto_lateral']['tmp_name'][$i],
                        'type' => $_FILES['foto_lateral']['type'][$i],
                        'size' => $_FILES['foto_lateral']['size'][$i]
                    ];
                    $foto = subirArchivo($temp_file, 'prendarios/vehiculos');
                    if ($foto) {
                        $fotos_laterales[] = $foto;
                    }
                }
            }

            if (empty($fotos_laterales)) {
                throw new Exception('Error al subir fotos laterales');
            }

            // Insertar crédito prendario (SIN campos de fotos)
            $stmt = $pdo->prepare("
                INSERT INTO creditos_prendarios (
                    cliente_id, 
                    monto_solicitado,
                    dominio, 
                    descripcion_vehiculo,
                    destino_credito,
                    es_titular,
                    estado, 
                    fecha_solicitud
                ) VALUES (?, ?, ?, ?, ?, ?, 'pendiente', NOW())
            ");

            $stmt->execute([
                $cliente_id, 
                $monto, 
                $dominio, 
                $descripcion,
                $destino,
                $es_titular
            ]);

            $operacion_id = $pdo->lastInsertId();

            // Insertar foto frente en prendarios_imagenes
            $stmt = $pdo->prepare("
                INSERT INTO prendarios_imagenes (
                    credito_id,
                    tipo,
                    ruta_archivo,
                    nombre_original,
                    tipo_mime,
                    tamano_bytes
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $operacion_id,
                'frente',
                $foto_frente['ruta'],
                $foto_frente['nombre_original'],
                $foto_frente['tipo_mime'],
                $foto_frente['tamano']
            ]);

            // Insertar foto trasera
            $stmt->execute([
                $operacion_id,
                'trasera',
                $foto_trasera['ruta'],
                $foto_trasera['nombre_original'],
                $foto_trasera['tipo_mime'],
                $foto_trasera['tamano']
            ]);

            // Insertar fotos laterales
            foreach ($fotos_laterales as $index => $foto) {
                $tipo_lateral = $index === 0 ? 'lateral_izq' : 'lateral_der';
                $stmt->execute([
                    $operacion_id,
                    $tipo_lateral,
                    $foto['ruta'],
                    $foto['nombre_original'],
                    $foto['tipo_mime'],
                    $foto['tamano']
                ]);
            }

            $tipo_display = 'Crédito Prendario';
            break;
    }

    $pdo->commit();

    // Log de éxito
    logError("Solicitud creada exitosamente - Tipo: $tipo, ID: $operacion_id, Cliente: {$cliente['nombre']} {$cliente['apellido']}");

    jsonResponse(true, "$tipo_display enviado correctamente", [
        'tipo' => $tipo,
        'id'   => $operacion_id
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    logError("Error creando solicitud - Tipo: $tipo, Cliente ID: $cliente_id, Error: " . $e->getMessage());
    jsonResponse(false, $e->getMessage());
}