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
  echo json_encode(["success" => false, "error" => "Método no permitido"], JSON_UNESCAPED_UNICODE);
  exit;
}

$auth = auth_required();
$usuario_id = (int)$auth['user_id'];

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$text = trim((string)($body["message"] ?? ""));
$chat_id_in = (int)($body["chat_id"] ?? 0);

if ($text === "") {
  http_response_code(400);
  echo json_encode(["success" => false, "error" => "Mensaje vacío"], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // 1) Determinar chat_id
  $chat_id = $chat_id_in;

  if ($chat_id <= 0) {
    $stmt = $pdo->prepare("
      SELECT id
      FROM chats
      WHERE cliente_id = ?
        AND estado IN ('pendiente','esperando_asesor','en_conversacion')
      ORDER BY id DESC
      LIMIT 1
    ");
    $stmt->execute([$usuario_id]);
    $chat_id = (int)($stmt->fetchColumn() ?: 0);
  }

  if ($chat_id <= 0) {
    $stmt = $pdo->prepare("
      INSERT INTO chats (cliente_id, departamento_id, origen, estado, fecha_inicio)
      VALUES (?, 1, 'manual', 'pendiente', NOW())
    ");
    $stmt->execute([$usuario_id]);
    $chat_id = (int)$pdo->lastInsertId();
  }

  // 2) Insertar mensaje
  $stmt = $pdo->prepare("
    INSERT INTO mensajes (chat_id, emisor, usuario_id, mensaje)
    VALUES (?, 'cliente', ?, ?)
  ");
  $stmt->execute([$chat_id, $usuario_id, $text]);
  $mensaje_id = (int)$pdo->lastInsertId();

  $time = date('H:i');

  echo json_encode([
    "success" => true,
    "chat_id" => $chat_id,
    "message" => [
      "id" => $mensaje_id,
      "text" => $text,
      "isMe" => true,
      "time" => $time
    ]
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log("chat/send.php ERROR: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["success" => false, "error" => "Error interno"], JSON_UNESCAPED_UNICODE);
}
