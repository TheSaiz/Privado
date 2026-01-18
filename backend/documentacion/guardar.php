<?php
session_start();
require_once __DIR__ . '/../connection.php';

if (!isset($_SESSION['cliente_id'])) {
    die(json_encode(['success' => false, 'error' => 'Sesión no válida']));
}

$usuario_id = (int)$_SESSION['cliente_id'];

// Función helper
function logError($msg) {
    $logFile = __DIR__ . '/../../logs/documentacion.log';
    if (!is_dir(dirname($logFile))) {
        @mkdir(dirname($logFile), 0755, true);
    }
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
}

// Validar datos del formulario
$dni = preg_replace('/\D/', '', $_POST['dni'] ?? '');
$cuit = preg_replace('/\D/', '', $_POST['cuit'] ?? '');
$nombre_completo = trim($_POST['nombre_completo'] ?? '');
$cod_area = preg_replace('/\D/', '', $_POST['cod_area'] ?? '');
$telefono = preg_replace('/\D/', '', $_POST['telefono'] ?? '');
$email = trim($_POST['email'] ?? '');

// Validaciones básicas
if (strlen($dni) < 7 || strlen($dni) > 8) {
    $_SESSION['error_msg'] = 'DNI inválido';
    header('Location: ../../documentacion.php');
    exit;
}

if (strlen($cuit) < 10 || strlen($cuit) > 11) {
    $_SESSION['error_msg'] = 'CUIT inválido';
    header('Location: ../../documentacion.php');
    exit;
}

if ($nombre_completo === '') {
    $_SESSION['error_msg'] = 'Nombre completo es obligatorio';
    header('Location: ../../documentacion.php');
    exit;
}

if (strlen($cod_area) < 2) {
    $_SESSION['error_msg'] = 'Código de área inválido';
    header('Location: ../../documentacion.php');
    exit;
}

if (strlen($telefono) < 6) {
    $_SESSION['error_msg'] = 'Teléfono inválido';
    header('Location: ../../documentacion.php');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error_msg'] = 'Email inválido';
    header('Location: ../../documentacion.php');
    exit;
}

try {
    // Obtener datos actuales del cliente
    $stmt = $pdo->prepare("SELECT * FROM clientes_detalles WHERE usuario_id = ? LIMIT 1");
    $stmt->execute([$usuario_id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        throw new Exception('Cliente no encontrado');
    }

    // Directorio de uploads
    $uploadDir = __DIR__ . '/../../uploads/documentos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Inicializar rutas de archivos
    $doc_dni_frente = $cliente['doc_dni_frente'] ?? '';
    $doc_dni_dorso = $cliente['doc_dni_dorso'] ?? '';
    $doc_selfie_dni = $cliente['doc_selfie_dni'] ?? '';

    // ===== PROCESAR DNI FRENTE =====
    if (isset($_FILES['doc_dni_frente']) && $_FILES['doc_dni_frente']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['doc_dni_frente'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
            throw new Exception('DNI frente: formato no válido. Solo JPG o PNG.');
        }
        
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception('DNI frente: archivo muy grande (máx 5MB)');
        }
        
        $filename = 'dni_frente_' . $usuario_id . '_' . time() . '.' . $ext;
        $filepath = $uploadDir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $doc_dni_frente = 'uploads/documentos/' . $filename;
            logError("✓ DNI frente guardado: $doc_dni_frente");
        } else {
            throw new Exception('Error al guardar DNI frente');
        }
    }

    // ===== PROCESAR DNI DORSO =====
    if (isset($_FILES['doc_dni_dorso']) && $_FILES['doc_dni_dorso']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['doc_dni_dorso'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
            throw new Exception('DNI dorso: formato no válido. Solo JPG o PNG.');
        }
        
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception('DNI dorso: archivo muy grande (máx 5MB)');
        }
        
        $filename = 'dni_dorso_' . $usuario_id . '_' . time() . '.' . $ext;
        $filepath = $uploadDir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $doc_dni_dorso = 'uploads/documentos/' . $filename;
            logError("✓ DNI dorso guardado: $doc_dni_dorso");
        } else {
            throw new Exception('Error al guardar DNI dorso');
        }
    }

    // ===== PROCESAR SELFIE CON DNI =====
    if (isset($_FILES['doc_selfie_dni']) && $_FILES['doc_selfie_dni']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['doc_selfie_dni'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
            throw new Exception('Selfie: formato no válido. Solo JPG o PNG.');
        }
        
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception('Selfie: archivo muy grande (máx 5MB)');
        }
        
        $filename = 'selfie_dni_' . $usuario_id . '_' . time() . '.' . $ext;
        $filepath = $uploadDir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $doc_selfie_dni = 'uploads/documentos/' . $filename;
            logError("✓ Selfie con DNI guardado: $doc_selfie_dni");
        } else {
            throw new Exception('Error al guardar selfie con DNI');
        }
    }

    // ===== VALIDACIÓN DE COMPLETITUD =====
    // Todos estos campos deben estar presentes y no vacíos
    $campos_requeridos = [
        'dni' => $dni,
        'cuit' => $cuit,
        'nombre_completo' => $nombre_completo,
        'cod_area' => $cod_area,
        'telefono' => $telefono,
        'doc_dni_frente' => $doc_dni_frente,
        'doc_dni_dorso' => $doc_dni_dorso,
        'doc_selfie_dni' => $doc_selfie_dni
    ];

    $todos_completos = true;
    $campos_faltantes = [];

    foreach ($campos_requeridos as $campo => $valor) {
        $valor_limpio = is_string($valor) ? trim($valor) : $valor;
        if (empty($valor_limpio)) {
            $todos_completos = false;
            $campos_faltantes[] = $campo;
        }
    }

    logError("==================== VALIDACIÓN DOCUMENTACIÓN ====================");
    logError("Usuario ID: $usuario_id");
    logError("DNI: " . ($dni ? "✓ $dni" : "✗ FALTA"));
    logError("CUIT: " . ($cuit ? "✓ $cuit" : "✗ FALTA"));
    logError("Nombre completo: " . ($nombre_completo ? "✓ $nombre_completo" : "✗ FALTA"));
    logError("Código área: " . ($cod_area ? "✓ $cod_area" : "✗ FALTA"));
    logError("Teléfono: " . ($telefono ? "✓ $telefono" : "✗ FALTA"));
    logError("DNI frente: " . ($doc_dni_frente ? "✓ $doc_dni_frente" : "✗ FALTA"));
    logError("DNI dorso: " . ($doc_dni_dorso ? "✓ $doc_dni_dorso" : "✗ FALTA"));
    logError("Selfie DNI: " . ($doc_selfie_dni ? "✓ $doc_selfie_dni" : "✗ FALTA"));
    logError("---");
    logError("Todos completos: " . ($todos_completos ? "TRUE ✓" : "FALSE ✗"));
    if (!$todos_completos) {
        logError("Campos faltantes: " . implode(', ', $campos_faltantes));
    }
    logError("================================================================");

    // Determinar el estado
    $docs_completos = $todos_completos ? 1 : 0;
    $estado_validacion = $todos_completos ? 'activo' : 'pendiente';

    // ===== ACTUALIZAR BASE DE DATOS =====
    $stmt = $pdo->prepare("
        UPDATE clientes_detalles
        SET 
            dni = ?,
            cuit = ?,
            nombre_completo = ?,
            cod_area = ?,
            telefono = ?,
            email = ?,
            doc_dni_frente = ?,
            doc_dni_dorso = ?,
            doc_selfie_dni = ?,
            docs_completos = ?,
            estado_validacion = ?,
            docs_updated_at = NOW()
        WHERE usuario_id = ?
    ");

    $stmt->execute([
        $dni,
        $cuit,
        $nombre_completo,
        $cod_area,
        $telefono,
        $email,
        $doc_dni_frente,
        $doc_dni_dorso,
        $doc_selfie_dni,
        $docs_completos,
        $estado_validacion,
        $usuario_id
    ]);

    logError("✓ Base de datos actualizada: docs_completos=$docs_completos, estado_validacion=$estado_validacion");

    // Mensaje de éxito
    if ($todos_completos) {
        $_SESSION['success_msg'] = '✅ Documentación completa. ¡Ya podés solicitar préstamos!';
        logError("🎉 CUENTA ACTIVADA - Usuario $usuario_id puede solicitar préstamos");
    } else {
        $_SESSION['success_msg'] = '✓ Información guardada. Completá los datos faltantes para activar tu cuenta.';
        logError("⚠️ Documentación guardada pero incompleta - Usuario $usuario_id");
    }

    header('Location: ../../documentacion.php');
    exit;

} catch (Exception $e) {
    logError("ERROR: " . $e->getMessage());
    $_SESSION['error_msg'] = $e->getMessage();
    header('Location: ../../documentacion.php');
    exit;
}