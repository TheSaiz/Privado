<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

error_log("=== DOCUMENTACION_APP INICIO ===");

require_once __DIR__ . '/../../backend/connection.php';

function respond($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function validarCampos($campos, $data) {
    foreach ($campos as $campo) {
        if (!isset($data[$campo]) || empty(trim($data[$campo]))) {
            return "El campo '$campo' es obligatorio";
        }
    }
    return null;
}

function subirImagen($base64, $tipo, $dni) {
    if (empty($base64)) return null;
    
    $uploadDir = __DIR__ . '/../../../uploads/documentacion/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $base64);
    $imageData = base64_decode($base64);
    
    if ($imageData === false) return null;
    
    $filename = $dni . '_' . $tipo . '_' . time() . '.jpg';
    $filepath = $uploadDir . $filename;
    
    if (file_put_contents($filepath, $imageData)) {
        return '/uploads/documentacion/' . $filename;
    }
    
    return null;
}

try {
    $conn = $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, "Método no permitido");
    }
    
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        respond(false, "No se recibieron datos");
    }
    
    $data = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        respond(false, "JSON inválido");
    }
    
    // Validar campos obligatorios
    $camposObligatorios = [
        'dni', 'nombre_completo', 'cuit', 'telefono', 'fecha_nacimiento',
        'contacto1', 'contacto2', 'banco', 'cbu', 'tipo_ingreso',
        'calle', 'numero', 'localidad', 'provincia', 'codigo_postal'
    ];
    
    $error = validarCampos($camposObligatorios, $data);
    if ($error) respond(false, $error);
    
    // Validaciones
    if (!preg_match('/^\d{7,8}$/', $data['dni'])) {
        respond(false, "DNI inválido");
    }
    if (!preg_match('/^\d{11}$/', $data['cuit'])) {
        respond(false, "CUIT inválido");
    }
    if (!preg_match('/^\d{22}$/', $data['cbu'])) {
        respond(false, "CBU inválido");
    }
    if (empty($data['dni_frente']) || empty($data['dni_dorso'])) {
        respond(false, "Las imágenes del DNI son obligatorias");
    }
    if ($data['tipo_ingreso'] !== 'negro' && empty($data['comprobante_ingresos'])) {
        respond(false, "El comprobante de ingresos es obligatorio");
    }
    if (empty($data['monto_ingresos']) || floatval($data['monto_ingresos']) <= 0) {
        respond(false, "El monto de ingresos debe ser mayor a 0");
    }
    
    $dni = $data['dni'];
    
    // Verificar duplicado SOLO en clientes_detalles
    $checkStmt = $conn->prepare("SELECT id, estado_validacion FROM clientes_detalles WHERE dni = ?");
    $checkStmt->execute([$dni]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        if (in_array($existing['estado_validacion'], ['en_revision', 'aprobado'])) {
            respond(false, "Ya existe una solicitud con este DNI en estado: " . $existing['estado_validacion']);
        }
        respond(false, "Ya existe una solicitud con este DNI");
    }
    
    // Subir imágenes
    $dniFrente = subirImagen($data['dni_frente'], 'frente', $dni);
    $dniDorso = subirImagen($data['dni_dorso'], 'dorso', $dni);
    
    if (!$dniFrente || !$dniDorso) {
        respond(false, "Error al procesar las imágenes del DNI");
    }
    
    $comprobanteIngresos = null;
    if ($data['tipo_ingreso'] !== 'negro' && !empty($data['comprobante_ingresos'])) {
        $comprobanteIngresos = subirImagen($data['comprobante_ingresos'], 'comprobante', $dni);
        if (!$comprobanteIngresos) {
            respond(false, "Error al procesar el comprobante de ingresos");
        }
    }
    
    // Convertir fecha
    $fechaNacimiento = $data['fecha_nacimiento'];
    if (strpos($fechaNacimiento, '/') !== false) {
        $partes = explode('/', $fechaNacimiento);
        if (count($partes) === 3) {
            $fechaNacimiento = $partes[2] . '-' . $partes[1] . '-' . $partes[0];
        }
    }
    
    $fecha = DateTime::createFromFormat('Y-m-d', $fechaNacimiento);
    if (!$fecha || $fecha->format('Y-m-d') !== $fechaNacimiento) {
        respond(false, "Formato de fecha inválido");
    }
    
    $direccionCompleta = $data['calle'] . ' ' . $data['numero'];
    
    // Iniciar transacción
    $conn->beginTransaction();
    
    try {
        // Crear usuario SIN buscar por DNI
        $emailTemp = 'user' . $dni . '_' . time() . '@temp.com';
        $passwordTemp = password_hash($dni, PASSWORD_DEFAULT);
        
        $stmtCreateUser = $conn->prepare("
            INSERT INTO usuarios (nombre, telefono, email, password, fecha_registro) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $stmtCreateUser->execute([
            $data['nombre_completo'],
            $data['telefono'],
            $emailTemp,
            $passwordTemp
        ]);
        
        $usuarioId = $conn->lastInsertId();
        error_log("Usuario creado con ID: $usuarioId");
        
        // Insertar en clientes_detalles
        $sql = "INSERT INTO clientes_detalles (
            usuario_id, dni, nombre_completo, cuit, telefono, fecha_nacimiento,
            doc_dni_frente, doc_dni_dorso, contacto1_telefono, contacto2_telefono,
            banco, cbu, tipo_ingreso, monto_ingresos, doc_comprobante_ingresos,
            calle, numero, localidad, ciudad, provincia, codigo_postal, direccion,
            estado_validacion, docs_completos, docs_updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP
        )";
        
        $stmt = $conn->prepare($sql);
        
        $stmt->execute([
            $usuarioId,
            $dni,
            $data['nombre_completo'],
            $data['cuit'],
            $data['telefono'],
            $fechaNacimiento,
            $dniFrente,
            $dniDorso,
            $data['contacto1'],
            $data['contacto2'],
            $data['banco'],
            $data['cbu'],
            $data['tipo_ingreso'],
            floatval($data['monto_ingresos']),
            $comprobanteIngresos,
            $data['calle'],
            $data['numero'],
            $data['localidad'],
            $data['localidad'],
            $data['provincia'],
            $data['codigo_postal'],
            $direccionCompleta,
            'en_revision',
            1
        ]);
        
        $clienteId = $conn->lastInsertId();
        error_log("Cliente insertado con ID: $clienteId");
        
        $conn->commit();
        
        respond(true, "Documentación enviada correctamente", [
            'cliente_id' => $clienteId,
            'usuario_id' => $usuarioId,
            'estado' => 'en_revision',
            'dni' => $dni
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error en transacción: " . $e->getMessage());
        respond(false, "Error al guardar: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    error_log("Error general: " . $e->getMessage());
    respond(false, "Error del servidor: " . $e->getMessage());
}
?>