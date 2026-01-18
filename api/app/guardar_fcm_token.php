<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../backend/connection.php';

function respond($ok, $msg) {
    echo json_encode(['success' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$usuario_id = (int)($data['usuario_id'] ?? 0);
$fcm_token  = trim($data['fcm_token'] ?? '');

if ($usuario_id <= 0 || $fcm_token === '') {
    respond(false, "Datos inválidos");
}

$stmt = $pdo->prepare("
    UPDATE usuarios
    SET fcm_token = ?, fcm_updated_at = NOW()
    WHERE id = ?
");
$stmt->execute([$fcm_token, $usuario_id]);

respond(true, "Token guardado correctamente");
