<?php
// system/api/app/prestamos.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  echo json_encode(['success' => true]);
  exit;
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

function jexit(int $code, array $payload) {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// =======================
// Conexión (misma que tu web)
// =======================
try {
  require_once __DIR__ . '/../../../backend/connection.php'; // <-- /system/backend/connection.php
} catch (Throwable $e) {
  jexit(500, ['success' => false, 'error' => 'Error de conexión']);
}

// =======================
// verify_token (obligatorio)
// =======================
$verifyPath = __DIR__ . '/verify_token.php';
if (!file_exists($verifyPath)) {
  jexit(500, ['success' => false, 'error' => 'verify_token.php no encontrado en system/api/app']);
}
require_once $verifyPath;

// Esperado: verify_token($pdo) o verify_token($pdo, $token)
// (Te dejo ambos soportados)
$cliente_id = 0;

try {
  if (function_exists('verify_token')) {
    $ref = new ReflectionFunction('verify_token');
    if ($ref->getNumberOfParameters() >= 2) {
      $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
      $token = '';
      if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) $token = trim($m[1]);
      $out = verify_token($pdo, $token);
    } else {
      $out = verify_token($pdo);
    }

    // Soportar varios formatos comunes:
    // - int cliente_id
    // - ['success'=>true,'cliente_id'=>X]
    // - ['cliente_id'=>X]
    if (is_int($out)) {
      $cliente_id = $out;
    } elseif (is_array($out)) {
      if (($out['success'] ?? null) === false) {
        jexit(401, ['success' => false, 'error' => $out['error'] ?? 'Token inválido']);
      }
      $cliente_id = (int)($out['cliente_id'] ?? $out['user_id'] ?? $out['id'] ?? 0);
    }
  }
} catch (Throwable $e) {
  jexit(401, ['success' => false, 'error' => 'Token inválido']);
}

if ($cliente_id <= 0) {
  jexit(401, ['success' => false, 'error' => 'No autorizado']);
}

// =======================
// Validaciones GET
// =======================
$prestamo_id = (int)($_GET['id'] ?? 0);
$tipo_operacion = strtolower(trim($_GET['tipo'] ?? 'prestamo'));

if ($prestamo_id <= 0) {
  jexit(400, ['success' => false, 'error' => 'ID inválido']);
}
if (!in_array($tipo_operacion, ['prestamo', 'empeno', 'prendario'], true)) {
  jexit(400, ['success' => false, 'error' => 'Tipo inválido']);
}

// Base URL para armar links absolutos
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'prestamolider.com';
$systemBasePath = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/system/api/app/prestamos.php'))); // -> /system
$base = $scheme . '://' . $host . rtrim($systemBasePath, '/'); // https://prestamolider.com/system

$prestamo = null;
$cuotas = [];
$documentos = [];

$solicitud_pendiente = null;
$tiene_solicitud_info = false;

$es_contraoferta = false;
$datos_contraoferta = null;

// Mapeos (para UI)
$estados_map = [
  'pendiente' => ['texto' => 'Pendiente', 'clase' => 'warning'],
  'en_revision' => ['texto' => 'En Revisión', 'clase' => 'info'],
  'info_solicitada' => ['texto' => 'Info Solicitada', 'clase' => 'warning'],
  'contraoferta' => ['texto' => 'Contra-Oferta', 'clase' => 'purple'],
  'aprobado' => ['texto' => 'Aprobado', 'clase' => 'success'],
  'rechazado' => ['texto' => 'Rechazado', 'clase' => 'danger'],
  'activo' => ['texto' => 'Activo', 'clase' => 'success'],
  'finalizado' => ['texto' => 'Finalizado', 'clase' => 'secondary'],
  'cancelado' => ['texto' => 'Cancelado', 'clase' => 'danger'],
];

$tipos_doc_map = [
  'frente' => 'Vista Frontal',
  'trasera' => 'Vista Trasera',
  'lateral_izq' => 'Lateral Izquierdo',
  'lateral_der' => 'Lateral Derecho',
  'producto' => 'Foto del Producto',
  'comprobante_ingresos' => 'Comprobante de Ingresos',
  'dni' => 'DNI',
  'otro' => 'Otro'
];

try {
  // =======================
  // Solicitud info pendiente (si existe)
  // =======================
  $stmt = $pdo->prepare("
    SELECT si.id, si.mensaje, si.fecha, si.respuesta, si.fecha_respuesta
    FROM solicitudes_info si
    WHERE si.cliente_id = ?
      AND si.operacion_id = ?
      AND si.tipo_operacion = ?
      AND (si.respuesta IS NULL OR si.respuesta = '')
    ORDER BY si.fecha DESC
    LIMIT 1
  ");
  $stmt->execute([$cliente_id, $prestamo_id, $tipo_operacion]);
  $solicitud_pendiente = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($solicitud_pendiente) {
    $tiene_solicitud_info = true;
    $solicitud_pendiente['archivos'] = [];
    try {
      $st2 = $pdo->prepare("SELECT archivo FROM solicitudes_info_archivos WHERE solicitud_id = ?");
      $st2->execute([(int)$solicitud_pendiente['id']]);
      $solicitud_pendiente['archivos'] = $st2->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
      // si no existe tabla, no pasa nada
    }
  }

  // =======================
  // Obtener operación + docs/cuotas según tipo
  // =======================
  if ($tipo_operacion === 'prendario') {
    $stmt = $pdo->prepare("
      SELECT cp.*, cd.nombre_completo as cliente_nombre, cd.email as cliente_email
      FROM creditos_prendarios cp
      LEFT JOIN clientes_detalles cd ON cp.cliente_id = cd.usuario_id
      WHERE cp.id = ? AND cp.cliente_id = ?
      LIMIT 1
    ");
    $stmt->execute([$prestamo_id, $cliente_id]);
    $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($prestamo) {
      $stmt = $pdo->prepare("
        SELECT id, tipo, nombre_original as nombre_archivo, ruta_archivo, fecha_subida as created_at
        FROM prendarios_imagenes
        WHERE credito_id = ?
        ORDER BY
          CASE tipo
            WHEN 'frente' THEN 1
            WHEN 'trasera' THEN 2
            WHEN 'lateral_izq' THEN 3
            WHEN 'lateral_der' THEN 4
            ELSE 5
          END,
          fecha_subida ASC
      ");
      $stmt->execute([$prestamo_id]);
      $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

  } elseif ($tipo_operacion === 'empeno') {
    $stmt = $pdo->prepare("
      SELECT e.*, cd.nombre_completo as cliente_nombre, cd.email as cliente_email
      FROM empenos e
      LEFT JOIN clientes_detalles cd ON e.cliente_id = cd.usuario_id
      WHERE e.id = ? AND e.cliente_id = ?
      LIMIT 1
    ");
    $stmt->execute([$prestamo_id, $cliente_id]);
    $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($prestamo) {
      $stmt = $pdo->prepare("
        SELECT id, 'producto' as tipo, nombre_original as nombre_archivo, ruta_archivo, fecha_subida as created_at
        FROM empenos_imagenes
        WHERE empeno_id = ?
        ORDER BY fecha_subida ASC
      ");
      $stmt->execute([$prestamo_id]);
      $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

  } else {
    $stmt = $pdo->prepare("
      SELECT p.*, cd.nombre_completo as cliente_nombre, cd.email as cliente_email
      FROM prestamos p
      LEFT JOIN clientes_detalles cd ON p.cliente_id = cd.usuario_id
      WHERE p.id = ? AND p.cliente_id = ?
      LIMIT 1
    ");
    $stmt->execute([$prestamo_id, $cliente_id]);
    $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($prestamo) {
      $stmt = $pdo->prepare("
        SELECT *
        FROM prestamos_pagos
        WHERE prestamo_id = ?
        ORDER BY cuota_num ASC
      ");
      $stmt->execute([$prestamo_id]);
      $cuotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

      $stmt = $pdo->prepare("
        SELECT *
        FROM prestamos_documentos
        WHERE prestamo_id = ?
        ORDER BY created_at ASC
      ");
      $stmt->execute([$prestamo_id]);
      $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
  }

  if (!$prestamo) {
    jexit(404, ['success' => false, 'error' => 'Operación no encontrada']);
  }

  // =======================
  // Contraoferta
  // =======================
  if (($prestamo['estado'] ?? '') === 'contraoferta') {
    $es_contraoferta = true;
    $datos_contraoferta = [
      'monto_solicitado' => (float)($prestamo['monto_solicitado'] ?? 0),
      'cuotas_solicitadas' => (int)($prestamo['cuotas_solicitadas'] ?? 1),
      'frecuencia_solicitada' => (string)($prestamo['frecuencia_solicitada'] ?? 'mensual'),
      'monto_ofrecido' => (float)($prestamo['monto_ofrecido'] ?? 0),
      'cuotas_ofrecidas' => (int)($prestamo['cuotas_ofrecidas'] ?? 1),
      'frecuencia_ofrecida' => (string)($prestamo['frecuencia_ofrecida'] ?? 'mensual'),
      'tasa_interes_ofrecida' => (float)($prestamo['tasa_interes_ofrecida'] ?? 0),
      'monto_total_ofrecido' => (float)($prestamo['monto_total_ofrecido'] ?? 0),
      'comentarios_admin' => (string)($prestamo['comentarios_admin'] ?? ''),
      'fecha_contraoferta' => $prestamo['fecha_contraoferta'] ?? null,
    ];
  }

  // =======================
  // Cliente info (para docs_completos si querés mostrar algo)
  // =======================
  $stmt = $pdo->prepare("SELECT nombre_completo, email, docs_completos FROM clientes_detalles WHERE usuario_id = ? LIMIT 1");
  $stmt->execute([$cliente_id]);
  $cliente_info = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

  // =======================
  // Stats cuotas (solo prestamos)
  // =======================
  $total_cuotas = count($cuotas);
  $cuotas_pagadas = 0;
  $cuotas_pendientes = 0;
  $cuotas_vencidas = 0;
  $total_pagado = 0.0;
  $total_pendiente = 0.0;

  foreach ($cuotas as $c) {
    $estado = (string)($c['estado'] ?? 'pendiente');
    $monto = (float)($c['monto'] ?? 0);
    if ($estado === 'pagado') {
      $cuotas_pagadas++;
      $total_pagado += $monto;
    } else {
      $cuotas_pendientes++;
      $total_pendiente += $monto;
      if (in_array($estado, ['vencido', 'mora'], true)) $cuotas_vencidas++;
    }
  }

  $progreso = $total_cuotas > 0 ? (int)round(($cuotas_pagadas / $total_cuotas) * 100) : 0;

  // =======================
  // “Valores a mostrar” como tu HTML
  // =======================
  if ($tipo_operacion === 'prestamo') {
    $monto_solicitado = (float)($prestamo['monto_solicitado'] ?? 0);
    $monto_ofrecido = (float)($prestamo['monto_ofrecido'] ?? 0);
    $monto_aprobado = (float)($prestamo['monto'] ?? 0);
    $cuotas_mostrar = (int)($prestamo['cuotas'] ?? $prestamo['cuotas_ofrecidas'] ?? $prestamo['cuotas_solicitadas'] ?? 0);
    $frecuencia_mostrar = (string)($prestamo['frecuencia_pago'] ?? $prestamo['frecuencia_ofrecida'] ?? $prestamo['frecuencia_solicitada'] ?? 'mensual');
    $tasa_mostrar = (float)($prestamo['tasa_interes'] ?? $prestamo['tasa_interes_ofrecida'] ?? 0);
    $total_mostrar = (float)($prestamo['monto_total'] ?? $prestamo['monto_total_ofrecido'] ?? 0);
  } else {
    $monto_solicitado = (float)($prestamo['monto_solicitado'] ?? 0);
    $monto_ofrecido = (float)($prestamo['monto_ofrecido'] ?? 0);
    $monto_aprobado = (float)($prestamo['monto_final'] ?? 0);
    $cuotas_mostrar = (int)($prestamo['cuotas_final'] ?? $prestamo['cuotas_ofrecidas'] ?? 1);
    $frecuencia_mostrar = (string)($prestamo['frecuencia_final'] ?? $prestamo['frecuencia_ofrecida'] ?? 'mensual');
    $tasa_mostrar = (float)($prestamo['tasa_interes_final'] ?? $prestamo['tasa_interes_ofrecida'] ?? 0);
    $total_mostrar = (float)($prestamo['monto_total_final'] ?? $prestamo['monto_total_ofrecido'] ?? 0);
  }

  // Estado actual mapeado
  $estado_key = (string)($prestamo['estado'] ?? 'pendiente');
  $estado_actual = $estados_map[$estado_key] ?? ['texto' => ucfirst($estado_key), 'clase' => 'secondary'];

  // Títulos
  $titulo_tipo = [
    'prestamo' => 'Préstamo Personal',
    'prendario' => 'Crédito Prendario',
    'empeno' => 'Empeño'
  ][$tipo_operacion] ?? 'Operación';

  // Normalizar docs (agregar label y URL absoluta)
  $docs_out = [];
  foreach ($documentos as $doc) {
    $tipo_doc = (string)($doc['tipo'] ?? 'otro');
    $ruta = (string)($doc['ruta_archivo'] ?? '');
    $nombre = (string)($doc['nombre_archivo'] ?? $doc['nombre_original'] ?? 'archivo');

    $url = $ruta;
    if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) {
      $url = $base . '/' . ltrim($url, '/'); // https://prestamolider.com/system/...
    }

    $docs_out[] = [
      'id' => (int)($doc['id'] ?? 0),
      'tipo' => $tipo_doc,
      'tipo_label' => $tipos_doc_map[$tipo_doc] ?? ucfirst($tipo_doc),
      'nombre' => $nombre,
      'url' => $url,
      'created_at' => $doc['created_at'] ?? $doc['fecha_subida'] ?? null,
    ];
  }

  // Cuotas out (para Flutter)
  $cuotas_out = [];
  foreach ($cuotas as $c) {
    $cuotas_out[] = [
      'cuota_num' => (int)($c['cuota_num'] ?? 0),
      'fecha_vencimiento' => $c['fecha_vencimiento'] ?? null,
      'monto' => (float)($c['monto'] ?? 0),
      'estado' => (string)($c['estado'] ?? 'pendiente'),
      'fecha_pago' => $c['fecha_pago'] ?? null,
    ];
  }

  // Payload final
  jexit(200, [
    'success' => true,
    'data' => [
      'id' => $prestamo_id,
      'tipo' => $tipo_operacion,
      'titulo_tipo' => $titulo_tipo,

      'estado' => [
        'key' => $estado_key,
        'texto' => $estado_actual['texto'],
        'clase' => $estado_actual['clase'],
      ],

      'operacion' => [
        'monto_solicitado' => $monto_solicitado,
        'monto_ofrecido' => $monto_ofrecido,
        'monto_aprobado' => $monto_aprobado,
        'cuotas' => $cuotas_mostrar,
        'frecuencia' => $frecuencia_mostrar,
        'tasa' => $tasa_mostrar,
        'total' => $total_mostrar,
        'fecha_solicitud' => $prestamo['fecha_solicitud'] ?? null,
        'fecha_aprobacion' => $prestamo['fecha_aprobacion'] ?? null,
        'fecha_contraoferta' => $prestamo['fecha_contraoferta'] ?? null,
        'dominio' => $prestamo['dominio'] ?? null,
        'destino_credito' => $prestamo['destino_credito'] ?? null,
        'descripcion_vehiculo' => $prestamo['descripcion_vehiculo'] ?? null,
        'descripcion_producto' => $prestamo['descripcion_producto'] ?? null,
        'comentarios_admin' => $prestamo['comentarios_admin'] ?? null,
        'estado_contrato' => $prestamo['estado_contrato'] ?? null,
      ],

      'stats' => [
        'total_cuotas' => $total_cuotas,
        'pagadas' => $cuotas_pagadas,
        'pendientes' => $cuotas_pendientes,
        'vencidas' => $cuotas_vencidas,
        'progreso' => $progreso,
        'total_pagado' => $total_pagado,
        'saldo_pendiente' => $total_pendiente,
      ],

      'contraoferta' => [
        'activa' => $es_contraoferta,
        'data' => $datos_contraoferta,
      ],

      'solicitud_info' => [
        'activa' => $tiene_solicitud_info,
        'data' => $solicitud_pendiente,
      ],

      'cuotas' => $cuotas_out,
      'documentos' => $docs_out,

      'cliente' => [
        'nombre_completo' => $cliente_info['nombre_completo'] ?? null,
        'email' => $cliente_info['email'] ?? null,
        'docs_completos' => (int)($cliente_info['docs_completos'] ?? 0),
      ],

      'base_url' => $base,
    ],
  ]);

} catch (Throwable $e) {
  error_log("prestamos.php error: " . $e->getMessage());
  jexit(500, ['success' => false, 'error' => 'Error al cargar datos']);
}
