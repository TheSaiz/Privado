<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once __DIR__ . '/../../../backend/connection.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["success" => false]);
  exit;
}

$auth = auth_required();
$usuario_id = (int)$auth['user_id'];

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$id = (int)($data["id"] ?? 0);
if ($id <= 0) {
  echo json_encode(["success" => false]);
  exit;
}

$stmt = $pdo->prepare("UPDATE clientes_notificaciones SET leida=1 WHERE id=? AND cliente_id=?");
$stmt->execute([$id, $usuario_id]);

echo json_encode(["success" => true]);
