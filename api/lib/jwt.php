<?php
/**
 * Librería JWT
 * Ubicación: /system/api/lib/jwt.php
 */

const JWT_SECRET = 'CAMBIAR_ESTE_SECRETO_ULTRA_SEGURO_123456';
const JWT_EXP = 60 * 60 * 24 * 7; // 7 días

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_create(array $payload) {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXP;
    
    $base64Header = base64url_encode(json_encode($header));
    $base64Payload = base64url_encode(json_encode($payload));
    
    $signature = hash_hmac('sha256', "$base64Header.$base64Payload", JWT_SECRET, true);
    $base64Signature = base64url_encode($signature);
    
    return "$base64Header.$base64Payload.$base64Signature";
}

function jwt_verify($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    
    [$header, $payload, $signature] = $parts;
    
    $validSignature = base64url_encode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    );
    
    if (!hash_equals($validSignature, $signature)) return false;
    
    $data = json_decode(base64url_decode($payload), true);
    if (!$data || time() > ($data['exp'] ?? 0)) return false;
    
    return $data;
}

function jwt_decode($token) {
    return jwt_verify($token);
}