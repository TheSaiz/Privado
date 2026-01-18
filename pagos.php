<?php
/*************************************************
 * pagos.php - Portal Clientes
 * Gestión de cuotas y pagos
 *************************************************/

session_start();

/* =========================
   LOG SEGURO
========================= */
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/pagos.log';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }

function dlog($msg) {
  global $logFile;
  $ts = date('Y-m-d H:i:s');
  @file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
}

set_exception_handler(function($e){
  dlog("UNCAUGHT EXCEPTION: ".$e->getMessage()." in ".$e->getFile().":".$e->getLine());
  http_response_code(500);
  exit;
});

set_error_handler(function($severity, $message, $file, $line){
  dlog("PHP ERROR [$severity] $message in $file:$line");
  return false;
});

/* =========================
   DB
========================= */
$connectionPath = __DIR__ . '/backend/connection.php';
if (!file_exists($connectionPath)) {
  dlog("FATAL: No existe backend/connection.php");
  http_response_code(500);
  exit;
}
require_once $connectionPath;

if (!isset($pdo) || !($pdo instanceof PDO)) {
  dlog("FATAL: \$pdo no existe o no es PDO");
  http_response_code(500);
  exit;
}

/* =========================
   SESIÓN
========================= */
if (!isset($_SESSION['cliente_id'], $_SESSION['cliente_email'])) {
  header('Location: login_clientes.php');
  exit;
}

$cliente_id = (int)($_SESSION['cliente_id'] ?? 0);
if ($cliente_id <= 0) {
  dlog("cliente_id inválido en sesión");
  header('Location: login_clientes.php');
  exit;
}

/* =========================
   HELPERS
========================= */
function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function money0($n){ return number_format((float)$n, 0, ',', '.'); }
function money2($n){ return number_format((float)$n, 2, ',', '.'); }

// Configurar zona horaria de Argentina
date_default_timezone_set('America/Argentina/Buenos_Aires');

/* =========================
   MENSAJES
========================= */
$mensaje_error = null;
$mensaje_exito = null;

/* =========================
   PROCESAR COMPROBANTE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cargar_comprobante'])) {
  try {
    $cuota_id        = (int)($_POST['cuota_id'] ?? 0);
    $monto_declarado = (float)($_POST['monto_declarado'] ?? 0);
    $fecha_pago      = $_POST['fecha_pago'] ?? null;
    $comentario      = trim($_POST['comentario'] ?? '');

    if ($cuota_id <= 0) $mensaje_error = 'Cuota inválida.';
    if (!$mensaje_error && $monto_declarado <= 0) $mensaje_error = 'Monto declarado inválido.';

    if (!$mensaje_error) {
      if ($fecha_pago !== null && $fecha_pago !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_pago)) {
          $mensaje_error = 'Fecha de pago inválida.';
        }
      } else {
        $fecha_pago = null;
      }
    }

    // Validar que la cuota pertenece al cliente
    if (!$mensaje_error) {
      $stmt = $pdo->prepare("
        SELECT pp.id
        FROM prestamos_pagos pp
        INNER JOIN prestamos p ON p.id = pp.prestamo_id
        WHERE pp.id = ? AND p.cliente_id = ?
        LIMIT 1
      ");
      $stmt->execute([$cuota_id, $cliente_id]);
      if (!$stmt->fetch()) $mensaje_error = 'Cuota inválida o no pertenece a tu cuenta.';
    }

    // Validar archivo
    if (!$mensaje_error) {
      if (!isset($_FILES['comprobante']) || $_FILES['comprobante']['error'] !== UPLOAD_ERR_OK) {
        $mensaje_error = 'Seleccioná un archivo.';
      }
    }

    if (!$mensaje_error) {
      $archivo    = $_FILES['comprobante'];
      $extension  = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
      $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];

      if (!in_array($extension, $allowedExt, true)) {
        $mensaje_error = 'Formato de archivo no permitido. Solo JPG, PNG o PDF.';
      }

      if (!$mensaje_error && ($archivo['size'] ?? 0) > 5 * 1024 * 1024) {
        $mensaje_error = 'El archivo supera el tamaño máximo de 5MB.';
      }

      // Verificar MIME real
      if (!$mensaje_error && function_exists('finfo_open')) {
        $mimePermitidos = ['image/jpeg','image/png','application/pdf'];
        $mime = null;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
          $mime = finfo_file($finfo, $archivo['tmp_name']);
          finfo_close($finfo);
        }
        if ($mime !== null && !in_array($mime, $mimePermitidos, true)) {
          $mensaje_error = 'Tipo de archivo no válido.';
        }
      }

      if (!$mensaje_error) {
        $nombre_archivo = 'comprobante_' . $cuota_id . '_' . time() . '.' . $extension;
        
        $dirFisico = __DIR__ . '/uploads/comprobantes/';
        $ruta_destino = $dirFisico . $nombre_archivo;
        $ruta_publica = 'uploads/comprobantes/' . $nombre_archivo;

        if (!is_dir($dirFisico)) { @mkdir($dirFisico, 0755, true); }

        if (move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
          // Actualizar comprobante en prestamos_pagos
          $stmt = $pdo->prepare("
            UPDATE prestamos_pagos 
            SET comprobante = ?, 
                metodo_pago = 'Pendiente de revisión'
            WHERE id = ?
          ");
          $stmt->execute([
            $ruta_publica,
            $cuota_id
          ]);

          $mensaje_exito = 'Comprobante cargado correctamente. Será revisado por nuestro equipo.';
          dlog("Comprobante cargado exitosamente para cuota_id=$cuota_id por cliente_id=$cliente_id");
        } else {
          $mensaje_error = 'Error al subir el archivo. Intentá nuevamente.';
          dlog("Error al mover archivo para cuota_id=$cuota_id");
        }
      }
    }
  } catch (Throwable $e) {
    dlog("EXCEPTION upload: ".$e->getMessage());
    $mensaje_error = 'Error interno al procesar el comprobante.';
  }
}

/* =========================
   OBTENER CUOTAS Y COMPROBANTES
========================= */
try {
  // Obtener cuotas
  $stmt = $pdo->prepare("
    SELECT 
      pp.*,
      p.id as prestamo_id,
      p.monto_ofrecido,
      p.monto_solicitado,
      p.cuotas_ofrecidas,
      p.cuotas_solicitadas,
      COALESCE(p.monto_ofrecido, p.monto_solicitado, p.monto) as monto_prestamo,
      COALESCE(p.cuotas_ofrecidas, p.cuotas_solicitadas, p.cuotas) as cuotas_total,
      COALESCE(p.tasa_interes_ofrecida, p.tasa_interes) as tasa,
      DATEDIFF(pp.fecha_vencimiento, CURDATE()) AS dias_restantes
    FROM prestamos_pagos pp
    INNER JOIN prestamos p ON p.id = pp.prestamo_id
    WHERE p.cliente_id = ?
      AND p.estado IN ('aprobado', 'activo')
    ORDER BY pp.fecha_vencimiento ASC
  ");
  $stmt->execute([$cliente_id]);
  $cuotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  dlog("Cuotas encontradas para cliente_id=$cliente_id: " . count($cuotas));
} catch (Throwable $e) {
  dlog("DB cuotas exception: ".$e->getMessage());
  $cuotas = [];
  $mensaje_error = $mensaje_error ?: 'Error al cargar cuotas.';
}

// Actualizar estados de cuotas vencidas
$hoy = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
foreach ($cuotas as &$cuota) {
  $fecha_venc = new DateTime($cuota['fecha_vencimiento'], new DateTimeZone('America/Argentina/Buenos_Aires'));
  $dias_diff = (int)$cuota['dias_restantes'];
  
  // Actualizar mora_dias si está vencida
  if ($dias_diff < 0 && $cuota['estado'] === 'pendiente') {
    $mora_dias = abs($dias_diff);
    $cuota['mora_dias'] = $mora_dias;
    
    // Calcular monto de mora (ejemplo: 2% por día)
    $cuota['monto_mora'] = ($cuota['monto'] ?? 0) * 0.02 * $mora_dias;
  }
}
unset($cuota);

// Clasificar cuotas
$cuotas_pendientes = array_filter($cuotas, fn($c) => in_array($c['estado'] ?? '', ['pendiente']) && ((int)($c['dias_restantes'] ?? 0) >= 0));
$cuotas_vencidas   = array_filter($cuotas, fn($c) => ($c['estado'] ?? '') === 'pendiente' && ((int)($c['dias_restantes'] ?? 0) < 0));
$cuotas_pagadas    = array_filter($cuotas, fn($c) => ($c['estado'] ?? '') === 'pagado');

// Obtener historial de comprobantes (incluye pendientes y aprobados)
try {
  $stmt = $pdo->prepare("
    SELECT 
      pp.id as cuota_id,
      pp.cuota_num,
      pp.monto,
      pp.fecha_vencimiento,
      pp.fecha_pago,
      pp.comprobante,
      pp.estado as estado_cuota,
      pp.metodo_pago
    FROM prestamos_pagos pp
    INNER JOIN prestamos p ON p.id = pp.prestamo_id
    WHERE p.cliente_id = ?
      AND p.estado IN ('aprobado', 'activo')
      AND pp.comprobante IS NOT NULL
    ORDER BY pp.fecha_vencimiento DESC
  ");
  $stmt->execute([$cliente_id]);
  $historial_comprobantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  dlog("Error al obtener historial: ".$e->getMessage());
  $historial_comprobantes = [];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mis Pagos - Préstamo Líder</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  
  <!-- Tailwind CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  
  <link rel="stylesheet" href="style_clientes.css">
  
  <style>
    /* Estilos adicionales para la página de pagos */
    .timeline {
      position: relative;
      padding-left: 40px;
    }
    
    .timeline::before {
      content: '';
      position: absolute;
      left: 12px;
      top: 0;
      bottom: 0;
      width: 2px;
      background: linear-gradient(180deg, 
        rgba(124, 92, 255, 0.5) 0%, 
        rgba(124, 92, 255, 0.1) 100%);
    }
    
    .timeline-item {
      position: relative;
      margin-bottom: 24px;
    }
    
    .timeline-dot {
      position: absolute;
      left: -34px;
      top: 12px;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      border: 3px solid var(--bg);
      z-index: 1;
    }
    
    .timeline-dot.ok { background: #22c55e; }
    .timeline-dot.warn { background: #f59e0b; }
    .timeline-dot.danger { background: #ef4444; }
    
    .timeline-card {
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 12px;
      padding: 20px;
      transition: all 0.3s ease;
    }
    
    .timeline-card:hover {
      background: rgba(255, 255, 255, 0.06);
      border-color: rgba(124, 92, 255, 0.3);
      transform: translateX(4px);
    }
    
    .urgency-bar {
      width: 100%;
      height: 6px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 10px;
      overflow: hidden;
      margin-top: 16px;
    }
    
    .urgency-fill {
      height: 100%;
      border-radius: 10px;
      transition: width 0.3s ease;
    }
    
    .urgency-fill.ok { background: linear-gradient(90deg, #22c55e, #10b981); }
    .urgency-fill.warn { background: linear-gradient(90deg, #f59e0b, #fb923c); }
    .urgency-fill.danger { background: linear-gradient(90deg, #ef4444, #f87171); }
    
    .chip {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 600;
    }
    
    .chip.ok { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
    .chip.warn { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
    .chip.danger { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
    .chip.info { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
    .chip.bad { background: rgba(239, 68, 68, 0.2); color: #fecdd3; }
    .chip.pending { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
    
    /* Modal con fondo blanco */
    .modal-bg {
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(4px);
    }
    
    .modal-card {
      background: #FFFFFF;
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      animation: modalSlideIn 0.3s ease-out;
    }
    
    @keyframes modalSlideIn {
      from {
        opacity: 0;
        transform: translateY(-30px) scale(0.9);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }
    
    .modal-card h3 {
      color: #111827;
      font-size: 1.5rem;
      font-weight: 700;
    }
    
    .modal-card label {
      color: #374151;
      font-size: 0.875rem;
      font-weight: 600;
    }
    
    .modal-card .input,
    .modal-card select,
    .modal-card textarea {
      width: 100%;
      padding: 12px 16px;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      font-size: 1rem;
      color: #111827;
      background: #FFFFFF;
      transition: all 0.2s;
    }
    
    .modal-card .input:focus,
    .modal-card select:focus,
    .modal-card textarea:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .modal-card .text-xs {
      font-size: 0.75rem;
      color: #6b7280;
    }
    
    .modal-card .btn-ghost {
      flex: 1;
      padding: 12px 24px;
      border: 1px solid #d1d5db;
      background: #FFFFFF;
      color: #374151;
      font-weight: 600;
      border-radius: 10px;
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .modal-card .btn-ghost:hover {
      background: #f9fafb;
      border-color: #9ca3af;
    }
    
    .modal-card .btn-primary {
      flex: 1;
      padding: 12px 24px;
      border: none;
      background: linear-gradient(135deg, #3b82f6 0%, #10b981 100%);
      color: #FFFFFF;
      font-weight: 700;
      border-radius: 10px;
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .modal-card .btn-primary:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
    }
    
    .modal-close {
      background: none;
      border: none;
      font-size: 1.5rem;
      color: #6b7280;
      cursor: pointer;
      padding: 4px 8px;
      line-height: 1;
      transition: color 0.2s;
    }
    
    .modal-close:hover {
      color: #111827;
    }
    
    /* Área de carga de archivos */
    .file-upload-area {
      border: 2px dashed #d1d5db;
      border-radius: 12px;
      padding: 32px 20px;
      text-align: center;
      background: #f9fafb;
      transition: all 0.2s;
      cursor: pointer;
    }
    
    .file-upload-area:hover {
      border-color: #3b82f6;
      background: #eff6ff;
    }
    
    .file-upload-label {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      cursor: pointer;
    }
    
    .file-upload-label svg {
      color: #6b7280;
    }
    
    .file-upload-text {
      font-size: 0.95rem;
      font-weight: 600;
      color: #374151;
    }
    
    .file-upload-hint {
      font-size: 0.8rem;
      color: #9ca3af;
    }
  </style>
</head>

<body>
  <div class="app-layout">

    <!-- SIDEBAR -->
    <?php
      $sidebarPath = __DIR__ . '/sidebar_clientes.php';
      if (file_exists($sidebarPath)) {
        require_once $sidebarPath;
      } else {
        dlog("WARNING: Falta sidebar_clientes.php");
      }
    ?>

    <!-- CONTENT -->
    <main class="main-content">
      <div class="mb-6 flex items-center justify-between">
        <div>
          <h1 class="text-3xl font-extrabold">Mis Pagos</h1>
          <p class="mt-1" style="color:var(--muted);">Gestioná tus cuotas y cargá tus comprobantes de pago</p>
        </div>
        
        <!-- Botón cargar comprobante -->
        <button
          type="button"
          class="btn btn-primary"
          onclick="abrirModalComprobante(0, 0)"
        >
          + Cargar Comprobante
        </button>
      </div>

      <?php if ($mensaje_exito): ?>
        <div class="card p-4 mb-6" style="border-left:4px solid #22c55e; background: rgba(34,197,94,.10);">
          <p class="font-semibold" style="color:#bbf7d0;">✓ <?= h($mensaje_exito) ?></p>
        </div>
      <?php endif; ?>

      <?php if ($mensaje_error): ?>
        <div class="card p-4 mb-6" style="border-left:4px solid #ef4444; background: rgba(239,68,68,.10);">
          <p class="font-semibold" style="color:#fecdd3;">✕ <?= h($mensaje_error) ?></p>
        </div>
      <?php endif; ?>

      <!-- RESUMEN -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="card p-6">
          <p style="color:var(--muted);" class="text-sm font-semibold mb-2">Cuotas Pendientes</p>
          <p class="text-3xl font-extrabold" style="color:#3b82f6;"><?= count($cuotas_pendientes) ?></p>
        </div>
        
        <div class="card p-6">
          <p style="color:var(--muted);" class="text-sm font-semibold mb-2">Cuotas Vencidas</p>
          <p class="text-3xl font-extrabold" style="color:#ef4444;"><?= count($cuotas_vencidas) ?></p>
        </div>
        
        <div class="card p-6">
          <p style="color:var(--muted);" class="text-sm font-semibold mb-2">Cuotas Pagadas</p>
          <p class="text-3xl font-extrabold" style="color:#22c55e;"><?= count($cuotas_pagadas) ?></p>
        </div>
      </div>

      <!-- TIMELINE VENCIMIENTOS -->
      <div class="card p-6 mb-8">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-xl font-extrabold">Próximos Vencimientos</h2>
          <span class="chip info"><?= count($cuotas_pendientes) ?> próximas</span>
        </div>

        <?php if (empty($cuotas_pendientes)): ?>
          <p class="text-center py-10" style="color:var(--muted);">
            No tenés cuotas próximas a vencer 🎉
          </p>
        <?php else: ?>
          <div class="timeline">
            <?php foreach ($cuotas_pendientes as $cuota): ?>
              <?php
                $dias = (int)($cuota['dias_restantes'] ?? 0);

                if ($dias < 0) {
                  $estado = 'vencida';
                  $color  = 'danger';
                  $label  = 'Vencida';
                } elseif ($dias === 0) {
                  $estado = 'hoy';
                  $color  = 'danger';
                  $label  = 'Vence hoy';
                } elseif ($dias <= 5) {
                  $estado = 'pronto';
                  $color  = 'warn';
                  $label  = "En $dias " . ($dias == 1 ? 'día' : 'días');
                } else {
                  $estado = 'ok';
                  $color  = 'ok';
                  $label  = "En $dias días";
                }
                
                $urgencia_pct = max(10, min(100, 100 - ($dias * 10)));
              ?>

              <div class="timeline-item">
                <div class="timeline-dot <?= $color ?>"></div>

                <div class="timeline-card">
                  <div class="flex items-center justify-between mb-3">
                    <div>
                      <p class="font-extrabold text-lg">
                        Cuota <?= (int)$cuota['cuota_num'] ?> de <?= (int)$cuota['cuotas_total'] ?>
                      </p>
                      <p class="text-sm" style="color:var(--muted);">
                        Vence el <?= date('d/m/Y', strtotime($cuota['fecha_vencimiento'])) ?>
                      </p>
                    </div>

                    <div class="text-right">
                      <p class="text-2xl font-extrabold">
                        $<?= money0($cuota['monto']) ?>
                      </p>
                      <span class="chip <?= $color ?>"><?= $label ?></span>
                    </div>
                  </div>

                  <!-- Barra de urgencia -->
                  <div class="urgency-bar">
                    <div class="urgency-fill <?= $color ?>" style="width:<?= $urgencia_pct ?>%"></div>
                  </div>
                  
                  <div class="mt-4">
                    <?php if (!empty($cuota['comprobante'])): ?>
                      <span class="chip pending">⏳ En revisión</span>
                    <?php else: ?>
                      <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        onclick="abrirModalComprobante(<?= (int)$cuota['id'] ?>, <?= (float)$cuota['monto'] ?>)"
                      >
                        Pagar Cuota
                      </button>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- VENCIDAS -->
      <?php if (!empty($cuotas_vencidas)): ?>
        <div class="card p-6 mb-8" style="border-left:4px solid #ef4444;">
          <h2 class="text-xl font-extrabold mb-4" style="color:#fecdd3;">⚠️ Cuotas Vencidas</h2>
          <div class="space-y-4">
            <?php foreach ($cuotas_vencidas as $cuota): ?>
              <?php
                $mora = abs((int)($cuota['dias_restantes'] ?? 0));
                $recargo = (float)($cuota['monto_mora'] ?? 0);
                $montoTotal = (float)($cuota['monto'] ?? 0) + $recargo;
              ?>
              <div class="p-4 rounded-xl" style="background: rgba(239, 68, 68, 0.08); border:1px solid rgba(239, 68, 68, 0.2);">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                  <div>
                    <p class="font-bold text-lg">
                      Cuota <?= (int)$cuota['cuota_num'] ?> de <?= (int)$cuota['cuotas_total'] ?>
                    </p>
                    <p style="color:var(--muted);" class="text-sm">
                      Venció el <?= date('d/m/Y', strtotime($cuota['fecha_vencimiento'])) ?>
                    </p>
                    <div class="mt-2 flex flex-wrap gap-2">
                      <span class="chip bad"><?= $mora ?> días de mora</span>
                      <?php if ($recargo > 0): ?>
                        <span class="chip bad">Recargo: $<?= money2($recargo) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="text-right">
                    <p class="text-2xl font-extrabold" style="color:#fecdd3;">
                      $<?= money0($montoTotal) ?>
                    </p>
                    <?php if (!empty($cuota['comprobante'])): ?>
                      <span class="chip pending mt-2">⏳ En revisión</span>
                    <?php else: ?>
                      <button
                        type="button"
                        class="btn btn-danger mt-2"
                        onclick="abrirModalComprobante(<?= (int)$cuota['id'] ?>, <?= (float)$montoTotal ?>)"
                      >
                        Pagar Ahora
                      </button>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- HISTORIAL DE PAGOS -->
      <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-xl font-extrabold">Historial de Pagos</h2>
        </div>

        <?php if (empty($historial_comprobantes)): ?>
          <p class="py-8 text-center" style="color:var(--muted);">
            Aún no tenés comprobantes cargados
          </p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr style="border-bottom:1px solid rgba(255,255,255,.10);">
                  <th class="text-left py-3 px-4" style="color:var(--muted);">Cuota</th>
                  <th class="text-left py-3 px-4" style="color:var(--muted);">Monto</th>
                  <th class="text-left py-3 px-4" style="color:var(--muted);">Fecha Venc.</th>
                  <th class="text-left py-3 px-4" style="color:var(--muted);">Estado</th>
                  <th class="text-left py-3 px-4" style="color:var(--muted);">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($historial_comprobantes as $comp): ?>
                  <?php
                    $estado_pago = ($comp['estado_cuota'] ?? '') === 'pagado' ? 'pagado' : 'pendiente';
                    $estado_texto = $estado_pago === 'pagado' ? 'Aprobado' : 'Pendiente';
                    $estado_class = $estado_pago === 'pagado' ? 'ok' : 'pending';
                  ?>
                  <tr style="border-bottom:1px solid rgba(255,255,255,.08);">
                    <td class="py-3 px-4 font-semibold">
                      Cuota <?= (int)$comp['cuota_num'] ?>
                    </td>
                    <td class="py-3 px-4 font-bold">
                      $<?= money0($comp['monto']) ?>
                    </td>
                    <td class="py-3 px-4">
                      <?= date('d/m/Y', strtotime($comp['fecha_vencimiento'])) ?>
                    </td>
                    <td class="py-3 px-4">
                      <span class="chip <?= $estado_class ?>">
                        <?= $estado_pago === 'pagado' ? '✓' : '⏳' ?> <?= $estado_texto ?>
                      </span>
                    </td>
                    <td class="py-3 px-4">
                      <a 
                        href="<?= h($comp['comprobante']) ?>" 
                        target="_blank" 
                        class="btn btn-sm btn-ghost"
                      >
                        👁️ Ver
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- MODAL COMPROBANTE -->
      <div id="modal-comprobante" class="fixed inset-0 hidden items-center justify-center z-50 modal-bg" onclick="cerrarSiFondoClick(event)">
        <div class="modal-card max-w-md w-full mx-4 p-0" onclick="event.stopPropagation()">
          <div class="flex justify-between items-center p-6 border-b border-gray-200">
            <h3>💳 Cargar Comprobante de Pago</h3>
            <button type="button" onclick="cerrarModalComprobante()" class="modal-close">✕</button>
          </div>

          <div class="p-6">
            <form method="POST" enctype="multipart/form-data">
              <input type="hidden" name="cuota_id" id="modal-cuota-id">
              <input type="hidden" name="cargar_comprobante" value="1">

              <div class="mb-4" id="selector-cuota-container">
                <label class="block mb-2">Seleccionar Cuota *</label>
                <select name="cuota_id_select" id="modal-cuota-select" class="input" onchange="actualizarMontoCuota()">
                  <option value="0">-- Seleccionar cuota --</option>
                  <?php foreach (array_merge($cuotas_pendientes, $cuotas_vencidas) as $c): ?>
                    <?php if (empty($c['comprobante'])): ?>
                      <option value="<?= (int)$c['id'] ?>" data-monto="<?= (float)$c['monto'] ?>">
                        Cuota <?= (int)$c['cuota_num'] ?> - $<?= money0($c['monto']) ?> - Vence <?= date('d/m/Y', strtotime($c['fecha_vencimiento'])) ?>
                      </option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="mb-4">
                <label class="block mb-2">Monto Pagado *</label>
                <input type="number" name="monto_declarado" id="modal-monto" step="0.01" required class="input" placeholder="0.00">
                <p class="text-xs mt-1">Verificá que coincida con el comprobante</p>
              </div>

              <div class="mb-4">
                <label class="block mb-2">Fecha de Pago *</label>
                <input type="date" name="fecha_pago" required max="<?= date('Y-m-d'); ?>" class="input">
              </div>

              <div class="mb-4">
                <label class="block mb-2">Comprobante de Pago *</label>
                <div class="file-upload-area">
                  <input type="file" name="comprobante" id="file-comprobante" accept=".jpg,.jpeg,.png,.pdf" required hidden>
                  <label for="file-comprobante" class="file-upload-label">
                    <svg width="48" height="48" fill="none" stroke="#6b7280" stroke-width="2" viewBox="0 0 24 24">
                      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                      <polyline points="14 2 14 8 20 8"></polyline>
                      <line x1="12" y1="18" x2="12" y2="12"></line>
                      <line x1="9" y1="15" x2="15" y2="15"></line>
                    </svg>
                    <div class="file-upload-text">Subir comprobante de pago</div>
                    <div class="file-upload-hint">JPG, PNG o PDF • Máximo 5MB</div>
                    <div id="file-name" class="file-upload-hint mt-2" style="color:#3b82f6; font-weight:600; display:none;"></div>
                  </label>
                </div>
              </div>

              <div class="mb-6">
                <label class="block mb-2">Comentario (opcional)</label>
                <textarea name="comentario" rows="3" class="input" placeholder="Agregá algún detalle si querés..."></textarea>
              </div>

              <div class="flex gap-3">
                <button type="button" onclick="cerrarModalComprobante()" class="btn btn-ghost">
                  Cancelar
                </button>
                <button type="submit" class="btn btn-primary">
                  Cargar Comprobante
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

    </main>
  </div>

  <script>
    // Variables globales
    let cuotaIdActual = 0;
    
    // Modal
    function abrirModalComprobante(cuotaId, monto) {
      cuotaIdActual = cuotaId;
      
      if (cuotaId > 0) {
        // Abrir desde botón de cuota específica
        document.getElementById('modal-cuota-id').value = cuotaId;
        document.getElementById('modal-monto').value = monto.toFixed(2);
        document.getElementById('selector-cuota-container').style.display = 'none';
        document.getElementById('modal-cuota-select').removeAttribute('required');
      } else {
        // Abrir desde botón principal - mostrar selector
        document.getElementById('modal-cuota-id').value = '';
        document.getElementById('modal-monto').value = '';
        document.getElementById('selector-cuota-container').style.display = 'block';
        document.getElementById('modal-cuota-select').setAttribute('required', 'required');
      }
      
      const m = document.getElementById('modal-comprobante');
      m.classList.remove('hidden');
      m.classList.add('flex');
    }
    
    function actualizarMontoCuota() {
      const select = document.getElementById('modal-cuota-select');
      const option = select.options[select.selectedIndex];
      const monto = option.getAttribute('data-monto') || 0;
      const cuotaId = option.value;
      
      document.getElementById('modal-monto').value = parseFloat(monto).toFixed(2);
      document.getElementById('modal-cuota-id').value = cuotaId;
    }
    
    function cerrarModalComprobante() {
      const m = document.getElementById('modal-comprobante');
      m.classList.add('hidden');
      m.classList.remove('flex');
      
      // Reset form
      document.querySelector('#modal-comprobante form').reset();
    }
    
    function cerrarSiFondoClick(event) {
      if (event.target === event.currentTarget) {
        cerrarModalComprobante();
      }
    }
    
    // Cerrar con ESC
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        const modal = document.getElementById('modal-comprobante');
        if (modal.classList.contains('flex')) {
          cerrarModalComprobante();
        }
      }
    });
    
    // Mostrar nombre de archivo seleccionado
    document.getElementById('file-comprobante').addEventListener('change', function(e) {
      const fileName = e.target.files[0]?.name;
      const fileNameDisplay = document.getElementById('file-name');
      
      if (fileName) {
        fileNameDisplay.textContent = '📎 ' + fileName;
        fileNameDisplay.style.display = 'block';
      } else {
        fileNameDisplay.style.display = 'none';
      }
    });
  </script>
</body>
</html>