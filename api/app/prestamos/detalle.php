<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../backend/connection.php';

$auth = auth_required();
$cliente_id = (int)($auth['user_id'] ?? 0);

if ($cliente_id <= 0) {
  http_response_code(401);
  echo json_encode(["success" => false, "error" => "Token inválido o cliente no autenticado"], JSON_UNESCAPED_UNICODE);
  exit;
}

$tipo = strtolower(trim((string)($_GET['tipo'] ?? '')));
$id   = (int)($_GET['id'] ?? 0);

if (!in_array($tipo, ['prestamo','empeno','prendario'], true)) {
  http_response_code(400);
  echo json_encode(["success" => false, "error" => "Tipo inválido"], JSON_UNESCAPED_UNICODE);
  exit;
}
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["success" => false, "error" => "ID inválido"], JSON_UNESCAPED_UNICODE);
  exit;
}

function pick_first($row, array $keys, $default = null) {
  foreach ($keys as $k) {
    if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') return $row[$k];
  }
  return $default;
}

try {
  // -----------------------------
  // 1) Obtener operación por tipo
  // -----------------------------
  if ($tipo === 'prestamo') {
    $stmt = $pdo->prepare("SELECT * FROM prestamos WHERE id = ? AND cliente_id = ? LIMIT 1");
    $stmt->execute([$id, $cliente_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
  } elseif ($tipo === 'empeno') {
    $stmt = $pdo->prepare("SELECT * FROM empenos WHERE id = ? AND cliente_id = ? LIMIT 1");
    $stmt->execute([$id, $cliente_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
  } else { // prendario
    $stmt = $pdo->prepare("SELECT * FROM creditos_prendarios WHERE id = ? AND cliente_id = ? LIMIT 1");
    $stmt->execute([$id, $cliente_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
  }

  if (!$row) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Operación no encontrada"], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // -----------------------------
  // 2) Normalizar campos comunes
  // -----------------------------
  $monto = (float)pick_first($row, ['monto_final','monto_ofrecido','monto_solicitado','monto'], 0);
  $cuotas = (int)pick_first($row, ['cuotas_final','cuotas_ofrecidas','cuotas_solicitadas','cuotas'], 1);
  if ($cuotas <= 0) $cuotas = 1;

  $frecuencia = (string)pick_first($row, ['frecuencia_final','frecuencia_ofrecida','frecuencia_solicitada','frecuencia_pago'], 'mensual');

  $tasa = pick_first($row, ['tasa_interes_final','tasa_interes_ofrecida','tasa_interes'], null);
  $tasa = ($tasa === null) ? null : (float)$tasa;

  $monto_total = pick_first($row, ['monto_total_final','monto_total_ofrecido','monto_total'], null);
  $monto_total = ($monto_total === null) ? null : (float)$monto_total;

  $estado = (string)($row['estado'] ?? '');
  $estado_contrato = $row['estado_contrato'] ?? 'sin_contrato';

  $fecha_solicitud = $row['fecha_solicitud'] ?? null;
  $fecha_aprobacion = $row['fecha_aprobacion'] ?? null;

  // fechas varían por tipo
  $fecha_inicio = null;
  $fecha_fin_estimada = null;

  if ($tipo === 'prestamo') {
    $fecha_inicio = $row['fecha_inicio_prestamo'] ?? null;
    $fecha_fin_estimada = $row['fecha_fin_estimada'] ?? null;
  } else if ($tipo === 'empeno') {
    $fecha_inicio = $row['fecha_inicio'] ?? null;
    $fecha_fin_estimada = $row['fecha_devolucion_estimada'] ?? null;
  } else {
    $fecha_inicio = $row['fecha_inicio'] ?? null;
    $fecha_fin_estimada = $row['fecha_fin_estimada'] ?? null;
  }

  // -----------------------------
  // 3) Cuotas/pagos (solo préstamo)
  // -----------------------------
  $cuotas_list = [];
  $cuotas_pagadas = 0;

  if ($tipo === 'prestamo') {
    $s2 = $pdo->prepare("
      SELECT
        id,
        prestamo_id,
        cuota_num,
        fecha_vencimiento,
        monto,
        monto_interes,
        monto_capital,
        mora_dias,
        monto_mora,
        fecha_pago,
        comprobante,
        metodo_pago,
        estado
      FROM prestamos_pagos
      WHERE prestamo_id = ?
      ORDER BY cuota_num ASC
    ");
    $s2->execute([$id]);
    $cuotas_list = $s2->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cuotas_list as $c) {
      if (($c['estado'] ?? '') === 'pagado') $cuotas_pagadas++;
    }
  }

  // -----------------------------
  // 4) Imágenes (empeño / prendario)
  // -----------------------------
  $imagenes = [];

  if ($tipo === 'empeno') {
    $s3 = $pdo->prepare("SELECT ruta_archivo, nombre_original, tipo_mime, fecha_subida FROM empenos_imagenes WHERE empeno_id = ? ORDER BY id ASC");
    $s3->execute([$id]);
    $imagenes = $s3->fetchAll(PDO::FETCH_ASSOC);
  }

  if ($tipo === 'prendario') {
    $s3 = $pdo->prepare("SELECT tipo, ruta_archivo, nombre_original, tipo_mime, fecha_subida FROM prendarios_imagenes WHERE credito_id = ? ORDER BY id ASC");
    $s3->execute([$id]);
    $imagenes = $s3->fetchAll(PDO::FETCH_ASSOC);
  }

  // -----------------------------
  // 5) Contrato digital (si existe)
  // -----------------------------
  $contrato = null;
  $s4 = $pdo->prepare("
    SELECT id, firmado, fecha_firma, hash_contrato
    FROM contratos_digitales
    WHERE prestamo_id = ? AND cliente_id = ? AND tipo_contrato = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $s4->execute([$id, $cliente_id, $tipo]);
  $cRow = $s4->fetch(PDO::FETCH_ASSOC);

  if ($cRow) {
    $contrato = [
      "id" => (int)$cRow["id"],
      "firmado" => (bool)$cRow["firmado"],
      "fecha_firma" => $cRow["fecha_firma"],
      "hash" => $cRow["hash_contrato"] ?? null,
    ];
  }

  // -----------------------------
  // 6) Respuesta final (completa + normalizada)
  // -----------------------------
  $data = [
    "id" => (int)$row["id"],
    "tipo" => $tipo,
    "cliente_id" => (int)($row["cliente_id"] ?? 0),
    "estado" => $estado,
    "estado_contrato" => $estado_contrato,

    "monto" => $monto,
    "cuotas" => $cuotas,
    "frecuencia" => $frecuencia,
    "tasa_interes" => $tasa,
    "monto_total" => $monto_total,

    "fecha_solicitud" => $fecha_solicitud,
    "fecha_aprobacion" => $fecha_aprobacion,
    "fecha_inicio" => $fecha_inicio,
    "fecha_fin_estimada" => $fecha_fin_estimada,

    // datos específicos extra (se mandan tal cual por si necesitás mostrarlos)
    "raw" => $row,

    // extras
    "cuotas_pagadas" => $cuotas_pagadas,
    "cuotas_list" => $cuotas_list,
    "imagenes" => $imagenes,
    "contrato" => $contrato,
  ];

  echo json_encode(["success" => true, "data" => $data], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log("Detalle prestamo error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["success" => false, "error" => "Error interno del servidor"], JSON_UNESCAPED_UNICODE);
}
