<?php
/**
 * Middleware de autenticación JWT
 * Ubicación: /system/api/middleware/auth.php
 */

require_once __DIR__ . '/../lib/jwt.php';

function auth_required() {
    $headers = [];
    
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$name] = $value;
            }
        }
    }
    
    $authHeader = '';
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            $authHeader = $value;
            break;
        }
    }
    
    if (empty($authHeader)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token no proporcionado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $parts = explode(' ', $authHeader);
    
    if (count($parts) !== 2 || strtolower($parts[0]) !== 'bearer') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Formato de token inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $token = $parts[1];
    
    $payload = jwt_verify($token);
    
    if (!$payload || !isset($payload['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token inválido o expirado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    return [
        'user_id' => $payload['user_id'],
        'email' => $payload['email'] ?? '',
        'rol' => $payload['rol'] ?? ''
    ];
}