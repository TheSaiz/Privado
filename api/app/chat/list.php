<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once __DIR__ . '/../../../backend/connection.php';
require_once __DIR__ . '/../../middleware/auth.php';

$auth = auth_required();
$usuario_id = (int)$auth['user_id'];

try {
  // 1) Buscar chat activo más reciente del usuario
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

  // 2) Si no existe, crear uno (manual, depto 1)
  if ($chat_id <= 0) {
    $stmt = $pdo->prepare("
      INSERT INTO chats (cliente_id, departamento_id, origen, estado, fecha_inicio)
      VALUES (?, 1, 'manual', 'pendiente', NOW())
    ");
    $stmt->execute([$usuario_id]);
    $chat_id = (int)$pdo->lastInsertId();
  }

  // 3) Traer mensajes
  $stmt = $pdo->prepare("
    SELECT id, emisor, mensaje, fecha
    FROM mensajes
    WHERE chat_id = ?
    ORDER BY id ASC
  ");
  $stmt->execute([$chat_id]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $messages = [];
  foreach ($rows as $r) {
    $fecha = $r['fecha'] ?? null;
    $time = '';
    if (!empty($fecha)) {
      $ts = strtotime($fecha);
      if ($ts !== false) $time = date('H:i', $ts);
    }

    $messages[] = [
      "id"   => (int)$r["id"],
      "text" => (string)$r["mensaje"],
      "isMe" => ((string)$r["emisor"] === "cliente"),
      "time" => $time,
    ];
  }

  echo json_encode([
    "success" => true,
    "chat_id" => $chat_id,
    "messages" => $messages
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log("chat/list.php ERROR: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["success" => false, "error" => "Error interno"], JSON_UNESCAPED_UNICODE);
}
