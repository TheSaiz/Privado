<?php
/**
 * API Registro para App Android - Préstamo Líder
 * 
 * Ubicación: /system/api/app/registro.php
 * Este archivo maneja el registro de nuevos clientes desde la app Android
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../backend/connection.php';

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método no permitido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Leer datos JSON del body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido');
    }
    
    $nombre = trim($data['nombre'] ?? '');
    $apellido = trim($data['apellido'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = trim($data['password'] ?? '');
    
    // Validaciones
    if (empty($nombre) || empty($apellido) || empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Todos los campos son requeridos'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Email inválido'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'La contraseña debe tener al menos 6 caracteres'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    // Verificar si el email ya existe
    $checkStmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
    $checkStmt->execute([$email]);
    
    if ($checkStmt->fetch()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Ya existe una cuenta con ese email'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Hashear contraseña
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    
    // Insertar usuario
    $insertStmt = $pdo->prepare("
        INSERT INTO usuarios 
        (nombre, apellido, email, password, rol, estado, fecha_registro)
        VALUES (?, ?, ?, ?, 'cliente', 'activo', NOW())
    ");
    
    $insertStmt->execute([$nombre, $apellido, $email, $passwordHash]);
    $usuarioId = (int)$pdo->lastInsertId();
    
    if ($usuarioId <= 0) {
        throw new Exception('Error al crear el usuario');
    }
    
    // Crear registro en clientes_detalles
    $detallesStmt = $pdo->prepare("
        INSERT INTO clientes_detalles (usuario_id, docs_completos)
        VALUES (?, 0)
    ");
    $detallesStmt->execute([$usuarioId]);
    
    // Commit de la transacción
    $pdo->commit();
    
    // Intentar enviar email de bienvenida (no bloqueante)
    try {
        if (file_exists(__DIR__ . '/../../correos/EmailDispatcher.php')) {
            require_once __DIR__ . '/../../correos/EmailDispatcher.php';
            
            (new EmailDispatcher())->send(
                'registro',
                $email,
                [
                    'nombre' => trim($nombre . ' ' . $apellido),
                    'email' => $email,
                    'link_login' => 'https://prestamolider.com/system/login_clientes.php',
                ]
            );
        }
    } catch (Throwable $mailError) {
        // Log pero no romper el registro
        error_log('Error enviando email de registro: ' . $mailError->getMessage());
    }
    
    // Respuesta exitosa
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Cuenta creada exitosamente',
        'data' => [
            'usuario_id' => $usuarioId,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => $email
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error en la base de datos'
    ], JSON_UNESCAPED_UNICODE);
    
    error_log('Error DB Registro Android: ' . $e->getMessage());
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}