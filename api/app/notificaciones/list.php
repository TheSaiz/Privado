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
  $stmt = $pdo->prepare("
    SELECT id, titulo, mensaje, tipo, leida, created_at
    FROM clientes_notificaciones
    WHERE cliente_id = ?
    ORDER BY created_at DESC
    LIMIT 50
  ");
  $stmt->execute([$usuario_id]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $out = [];

  foreach ($rows as $r) {
    $time = '';
    if (!empty($r['created_at'])) {
      $ts = strtotime($r['created_at']);
      if ($ts !== false) {
        $diff = time() - $ts;
        if ($diff < 60) $time = 'Hace un momento';
        else if ($diff < 3600) $time = 'Hace ' . floor($diff/60) . ' min';
        else if ($diff < 86400) $time = 'Hace ' . floor($diff/3600) . ' hs';
        else $time = 'Hace ' . floor($diff/86400) . ' días';
      }
    }

    $out[] = [
      "id" => (int)$r["id"],
      "title" => $r["titulo"],
      "message" => $r["mensaje"],
      "type" => $r["tipo"],
      "isNew" => ((int)$r["leida"] === 0),
      "time" => $time
    ];
  }

  echo json_encode([
    "success" => true,
    "notifications" => $out
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "error" => "Error interno"
  ], JSON_UNESCAPED_UNICODE);
}
