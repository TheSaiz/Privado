<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../backend/connection.php';

$auth = auth_required();
$cliente_id = $auth["id"] ?? null;
if (!$cliente_id) { echo json_encode(["success"=>false,"error"=>"Cliente inválido"]); exit; }

function fail(string $msg, int $code = 400): void {
  http_response_code($code);
  echo json_encode(["success"=>false,"error"=>$msg]);
  exit;
}

function safe_mkdir(string $dir): void {
  if (!is_dir($dir)) { mkdir($dir, 0755, true); }
}

function validate_image_upload(string $tmp, string $name, int $size, int $maxBytes): array {
  if ($size > $maxBytes) throw new Exception("Imagen demasiado grande");
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ["jpg","jpeg","png","webp"], true)) throw new Exception("Extensión no permitida");

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = finfo_file($finfo, $tmp);
  finfo_close($finfo);

  if (!in_array($mime, ["image/jpeg","image/png","image/webp"], true)) throw new Exception("Tipo de imagen no permitido");
  return ["ext"=>$ext,"mime"=>$mime];
}

$monto = (float)($_POST["monto"] ?? 0);
$descripcion = trim((string)($_POST["descripcion"] ?? ""));
$destino = trim((string)($_POST["destino"] ?? ""));

if ($monto < 500) fail("Monto mínimo: 500");
if ($descripcion === "") fail("Descripción requerida");
if (!isset($_FILES["imagenes"])) fail("Imágenes requeridas");

try {
  $pdo->beginTransaction();

  $stmt = $pdo->prepare("
    INSERT INTO empenos (
      cliente_id, monto_solicitado, descripcion_producto,
      destino_credito, estado, fecha_solicitud, usa_documentacion_existente
    ) VALUES (?, ?, ?, ?, 'pendiente', NOW(), 1)
  ");
  $stmt->execute([$cliente_id, $monto, $descripcion, $destino]);

  $empeno_id = (int)$pdo->lastInsertId();

  $dir = __DIR__ . '/../../../uploads/empenos/';
  safe_mkdir($dir);

  $maxImg = 10;
  $maxBytes = 10 * 1024 * 1024; // 10MB c/u

  $names = $_FILES["imagenes"]["name"] ?? [];
  $tmps  = $_FILES["imagenes"]["tmp_name"] ?? [];
  $errs  = $_FILES["imagenes"]["error"] ?? [];
  $sizes = $_FILES["imagenes"]["size"] ?? [];

  $count = is_array($tmps) ? count($tmps) : 0;
  if ($count <= 0) throw new Exception("Imágenes requeridas");
  if ($count > $maxImg) throw new Exception("Máximo {$maxImg} imágenes");

  for ($i=0; $i<$count; $i++) {
    if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

    $name = (string)$names[$i];
    $tmp = (string)$tmps[$i];
    $size = (int)($sizes[$i] ?? 0);

    $meta = validate_image_upload($tmp, $name, $size, $maxBytes);

    $nuevo = "empeno_{$empeno_id}_" . time() . "_{$i}." . $meta["ext"];
    $ruta = $dir . $nuevo;

    if (!move_uploaded_file($tmp, $ruta)) {
      throw new Exception("No se pudo subir imagen");
    }

    $stmt = $pdo->prepare("
      INSERT INTO empenos_imagenes (empeno_id, nombre_original, ruta_archivo)
      VALUES (?, ?, ?)
    ");
    $stmt->execute([$empeno_id, $name, "uploads/empenos/" . $nuevo]);
  }

  try {
    $stmt = $pdo->prepare("
      INSERT INTO clientes_notificaciones (cliente_id, tipo, titulo, mensaje, url_accion, texto_accion)
      VALUES (?, 'info', 'Solicitud Recibida', 'Tu solicitud está siendo evaluada por nuestro equipo. Te notificaremos cuando tengamos novedades.', 'dashboard_clientes.php', 'Ver Dashboard')
    ");
    $stmt->execute([$cliente_id]);
  } catch (Exception $e) { /* no bloquea */ }

  $pdo->commit();

  echo json_encode(["success"=>true,"data"=>["id"=>$empeno_id,"tipo"=>"empeno"]]);
} catch (Exception $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(["success"=>false,"error"=>$e->getMessage()]);
}
