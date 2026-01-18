<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../../backend/connection.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método no permitido"]);
    exit;
}

$chat_id = intval($_POST["chat_id"] ?? 0);
$sender = trim($_POST["sender"] ?? "");
$message = trim($_POST["message"] ?? "Archivo adjunto");

if (!$chat_id || !$sender) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Datos incompletos"]);
    exit;
}

// Verificar que se envió un archivo
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "No se recibió archivo válido"]);
    exit;
}

$archivo = $_FILES['archivo'];

// Validar tipo de archivo
$extensiones_permitidas = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'xls', 'xlsx', 'mp3', 'wav', 'ogg', 'm4a', 'webm'];
$extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

if (!in_array($extension, $extensiones_permitidas)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Tipo de archivo no permitido. Solo: PDF, PNG, JPG, JPEG, WEBP, XLS, XLSX, MP3, WAV, OGG, M4A, WEBM"]);
    exit;
}

// Validar tamaño (máximo 10MB)
$max_size = 10 * 1024 * 1024; // 10MB
if ($archivo['size'] > $max_size) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "El archivo es demasiado grande. Máximo 10MB"]);
    exit;
}

try {
    // Crear directorio si no existe
    $upload_dir = __DIR__ . "/../../uploads/";
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Generar nombre único para el archivo
    $nombre_guardado = uniqid() . '_' . time() . '.' . $extension;
    $ruta_completa = $upload_dir . $nombre_guardado;

    // Mover archivo
    if (!move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
        throw new Exception("Error al guardar el archivo");
    }

    // Iniciar transacción
    $pdo->beginTransaction();

    // Verificar que el chat existe
    $stmt = $pdo->prepare("SELECT id FROM chats WHERE id = ?");
    $stmt->execute([$chat_id]);
    
    if (!$stmt->fetch()) {
        unlink($ruta_completa); // Eliminar archivo
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Chat no encontrado"]);
        exit;
    }

    // Insertar mensaje con archivo
    $stmt = $pdo->prepare("
        INSERT INTO mensajes (chat_id, emisor, mensaje, tiene_archivo, fecha)
        VALUES (?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$chat_id, $sender, $message]);
    $mensaje_id = $pdo->lastInsertId();

    // Guardar información del archivo
    $stmt = $pdo->prepare("
        INSERT INTO chat_archivos (mensaje_id, chat_id, nombre_original, nombre_guardado, tipo_mime, tamano, ruta)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $mensaje_id,
        $chat_id,
        $archivo['name'],
        $nombre_guardado,
        $archivo['type'],
        $archivo['size'],
        'uploads/' . $nombre_guardado
    ]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Archivo enviado correctamente",
        "data" => [
            "mensaje_id" => $mensaje_id,
            "archivo_nombre" => $archivo['name'],
            "archivo_url" => 'uploads/' . $nombre_guardado
        ]
    ]);

} catch (Exception $e) {
    if (isset($ruta_completa) && file_exists($ruta_completa)) {
        unlink($ruta_completa);
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error en upload_file: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error al procesar el archivo"]);
}
?>