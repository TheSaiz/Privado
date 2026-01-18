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

// Helpers
function fail(string $msg, int $code = 400): void {
  http_response_code($code);
  echo json_encode(["success"=>false,"error"=>$msg]);
  exit;
}

function safe_mkdir(string $dir): void {
  if (!is_dir($dir)) { mkdir($dir, 0755, true); }
}

function validate_upload(array $file, array $allowedExt, array $allowedMime, int $maxBytes): array {
  if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new Exception("Archivo inválido");
  }
  if (($file["size"] ?? 0) > $maxBytes) {
    throw new Exception("Archivo demasiado grande");
  }

  $name = (string)($file["name"] ?? "");
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, $allowedExt, true)) {
    throw new Exception("Extensión no permitida");
  }

  $tmp = (string)($file["tmp_name"] ?? "");
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = finfo_file($finfo, $tmp);
  finfo_close($finfo);

  if (!in_array($mime, $allowedMime, true)) {
    throw new Exception("Tipo de archivo no permitido");
  }

  return ["name"=>$name, "ext"=>$ext, "mime"=>$mime, "tmp"=>$tmp];
}

// Inputs
$monto = (float)($_POST["monto"] ?? 0);
$cuotas = (int)($_POST["cuotas"] ?? 0);
$frecuencia = (string)($_POST["frecuencia"] ?? "mensual");
$destino = trim((string)($_POST["destino"] ?? ""));

$frecuenciasPermitidas = ["mensual","quincenal","semanal"];

if ($monto < 1000) fail("Monto mínimo: 1000");
if ($cuotas < 1 || $cuotas > 24) fail("Cuotas entre 1 y 24");
if (!in_array($frecuencia, $frecuenciasPermitidas, true)) fail("Frecuencia inválida");

// Reglas de documentación (igual que tu web)
$docs_completos = false;
$tiene_docs_previos = false;

try {
  $stmt = $pdo->prepare("SELECT docs_completos FROM clientes_detalles WHERE usuario_id = ?");
  $stmt->execute([$cliente_id]);
  $docs_completos = (bool)($stmt->fetchColumn() ?? 0);
} catch (Exception $e) {
  // Si no existe la tabla o el campo, no bloqueamos por esto
  $docs_completos = true;
}

if (!$docs_completos) fail("Documentación incompleta. Completá tu documentación antes de solicitar.");

try {
  $stmt = $pdo->prepare("
    SELECT COUNT(*) as total FROM (
      SELECT id FROM prestamos WHERE cliente_id = ? AND estado IN ('activo','finalizado')
      UNION ALL
      SELECT id FROM empenos WHERE cliente_id = ? AND estado IN ('activo','finalizado')
      UNION ALL
      SELECT id FROM creditos_prendarios WHERE cliente_id = ? AND estado IN ('activo','finalizado')
    ) t
  ");
  $stmt->execute([$cliente_id, $cliente_id, $cliente_id]);
  $tiene_docs_previos = ((int)$stmt->fetchColumn() > 0);
} catch (Exception $e) {
  $tiene_docs_previos = false;
}

$requiere_comprobante = !$tiene_docs_previos;

// Comprobante (si corresponde)
$comprobante = null;
if ($requiere_comprobante) {
  if (!isset($_FILES["comprobante_ingreso"])) {
    fail("Comprobante de ingresos requerido en tu primera solicitud");
  }
}

try {
  $pdo->beginTransaction();

  // Insert préstamo
  $usa_docs_existentes = $tiene_docs_previos ? 1 : 0;

  $stmt = $pdo->prepare("
    INSERT INTO prestamos (
      cliente_id,
      monto_solicitado,
      cuotas_solicitadas,
      frecuencia_solicitada,
      destino_credito,
      estado,
      fecha_solicitud,
      usa_documentacion_existente
    ) VALUES (?, ?, ?, ?, ?, 'pendiente', NOW(), ?)
  ");
  $stmt->execute([$cliente_id, $monto, $cuotas, $frecuencia, $destino, $usa_docs_existentes]);

  $prestamo_id = (int)$pdo->lastInsertId();

  // Guardar comprobante si aplica
  if ($requiere_comprobante) {
    $meta = validate_upload(
      $_FILES["comprobante_ingreso"],
      ["pdf","jpg","jpeg","png"],
      ["application/pdf","image/jpeg","image/png"],
      10 * 1024 * 1024 // 10MB
    );

    $dir = __DIR__ . '/../../../uploads/prestamos/';
    safe_mkdir($dir);

    $nuevo = "comprobante_{$prestamo_id}_" . time() . "." . $meta["ext"];
    $ruta = $dir . $nuevo;

    if (!move_uploaded_file($meta["tmp"], $ruta)) {
      throw new Exception("No se pudo guardar el comprobante");
    }

    // Tabla opcional (si existe)
    try {
      $stmt = $pdo->prepare("
        INSERT INTO prestamos_documentos (prestamo_id, tipo, nombre_archivo, ruta_archivo)
        VALUES (?, 'comprobante_ingresos', ?, ?)
      ");
      $stmt->execute([$prestamo_id, $meta["name"], "uploads/prestamos/" . $nuevo]);
    } catch (Exception $e) { /* no bloquea */ }
  }

  // Notificación (si existe la tabla/campos)
  try {
    $stmt = $pdo->prepare("
      INSERT INTO clientes_notificaciones (cliente_id, tipo, titulo, mensaje, url_accion, texto_accion)
      VALUES (?, 'info', 'Solicitud Recibida', 'Tu solicitud está siendo evaluada por nuestro equipo. Te notificaremos cuando tengamos novedades.', 'dashboard_clientes.php', 'Ver Dashboard')
    ");
    $stmt->execute([$cliente_id]);
  } catch (Exception $e) { /* no bloquea */ }

  $pdo->commit();

  echo json_encode([
    "success" => true,
    "data" => [
      "id" => $prestamo_id,
      "tipo" => "prestamo",
      "usa_documentacion_existente" => (bool)$usa_docs_existentes
    ]
  ]);
} catch (Exception $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(["success"=>false,"error"=>$e->getMessage()]);
}
