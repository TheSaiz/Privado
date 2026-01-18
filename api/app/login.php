<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../backend/connection.php';
require_once __DIR__ . '/../lib/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'JSON inválido']);
        exit;
    }

    $usuario = trim($data['usuario'] ?? '');
    $password = trim($data['password'] ?? '');

    if ($usuario === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Usuario y contraseña son requeridos']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT 
            id, nombre, apellido, email, password, estado, rol,
            codigo_area, telefono, fecha_registro
        FROM usuarios
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$usuario]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
        exit;
    }

    if ($user['rol'] !== 'cliente') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acceso solo para clientes']);
        exit;
    }

    if ($user['estado'] !== 'activo') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Tu cuenta no está activa']);
        exit;
    }

    if (!password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Credenciales incorrectas']);
        exit;
    }

    $stmtDetalles = $pdo->prepare("
        SELECT 
            dni, direccion, ciudad, provincia, codigo_postal,
            docs_completos, estado_validacion, motivo_rechazo
        FROM clientes_detalles
        WHERE usuario_id = ?
        LIMIT 1
    ");
    $stmtDetalles->execute([$user['id']]);
    $detalles = $stmtDetalles->fetch(PDO::FETCH_ASSOC);

    $estadoValidacion = 'sin_documentacion';
    $motivoRechazo = '';
    $dni = '';

    if ($detalles) {
        $dni = $detalles['dni'] ?? '';
        if (!empty($dni) && $detalles['docs_completos'] == 1) {
            $estadoValidacion = $detalles['estado_validacion'] ?? 'pendiente';
            $motivoRechazo = $detalles['motivo_rechazo'] ?? '';
        } else if (!empty($dni)) {
            $estadoValidacion = 'sin_documentacion';
        }
    }

    $token = jwt_create([
        'user_id' => $user['id'],
        'email'   => $user['email'],
        'rol'     => $user['rol']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Login exitoso',
        'data' => [
            'usuario_id' => $user['id'],
            'nombre' => $user['nombre'],
            'apellido' => $user['apellido'],
            'nombre_completo' => trim($user['nombre'] . ' ' . $user['apellido']),
            'email' => $user['email'],
            'telefono' => $user['telefono'],
            'codigo_area' => $user['codigo_area'],
            'estado' => $user['estado'],
            'fecha_registro' => $user['fecha_registro'],
            'docs_completos' => $detalles ? (bool)$detalles['docs_completos'] : false,
            'direccion' => $detalles['direccion'] ?? null,
            'ciudad' => $detalles['ciudad'] ?? null,
            'provincia' => $detalles['provincia'] ?? null,
            'codigo_postal' => $detalles['codigo_postal'] ?? null,
            'dni' => $dni,
            'estado_validacion' => $estadoValidacion,
            'motivo_rechazo' => $motivoRechazo,
            'token' => $token
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('Login error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor']);
}