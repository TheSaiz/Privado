<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['cliente_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/../connection.php';

// Leer datos JSON
$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');
$codigo = trim($input['codigo'] ?? '');

if (empty($email) || empty($codigo)) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

try {
    $usuario_id = (int)$_SESSION['cliente_id'];
    
    // Buscar el código en la base de datos
    $stmt = $pdo->prepare("
        SELECT email_codigo, email_codigo_expira 
        FROM clientes_detalles 
        WHERE usuario_id = ? AND email = ?
    ");
    $stmt->execute([$usuario_id, $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'No se encontró el código']);
        exit;
    }
    
    // Verificar si el código expiró
    $expira = strtotime($row['email_codigo_expira']);
    if ($expira < time()) {
        echo json_encode(['success' => false, 'error' => 'El código ha expirado']);
        exit;
    }
    
    // Verificar si el código coincide
    if ($row['email_codigo'] !== $codigo) {
        echo json_encode(['success' => false, 'error' => 'Código incorrecto']);
        exit;
    }
    
    // Marcar el email como verificado
    $stmt = $pdo->prepare("
        UPDATE clientes_detalles 
        SET email_verificado = 1,
            email_codigo = NULL,
            email_codigo_expira = NULL
        WHERE usuario_id = ?
    ");
    $stmt->execute([$usuario_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Email verificado correctamente'
    ]);
    
} catch (Exception $e) {
    error_log("Error verificando código: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error al verificar el código']);
}