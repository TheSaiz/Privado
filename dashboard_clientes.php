<?php
session_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Validar sesión
if (!isset($_SESSION['cliente_id']) || !isset($_SESSION['cliente_email'])) {
    header('Location: login_clientes.php');
    exit;
}

$cliente_id = (int)($_SESSION['cliente_id'] ?? 0);

if ($cliente_id <= 0) {
    header('Location: login_clientes.php');
    exit;
}

// Conexión
try {
    require_once __DIR__ . '/backend/connection.php';
} catch (Throwable $e) {
    header('Location: login_clientes.php');
    exit;
}

// MARCAR NOTIFICACIÓN COMO LEÍDA (AJAX)
if (isset($_GET['marcar_leida']) && isset($_GET['notif_id'])) {
    $notif_id = (int)$_GET['notif_id'];
    try {
        $stmt = $pdo->prepare("UPDATE clientes_notificaciones SET leida = 1, fecha_leida = NOW() WHERE id = ? AND cliente_id = ?");
        $stmt->execute([$notif_id, $cliente_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Obtener información del cliente
$cliente_info = null;
$prestamos = [];
$notificaciones = [];
$contratos_pendientes = [];

try {
    // Info cliente
    $stmt = $pdo->prepare("
        SELECT 
            usuario_id,
            dni,
            cuit,
            nombre_completo,
            email,
            cod_area,
            telefono,
            doc_dni_frente,
            doc_dni_dorso,
            doc_selfie_dni,
            docs_completos,
            estado_validacion
        FROM clientes_detalles
        WHERE usuario_id = ?
        LIMIT 1
    ");
    $stmt->execute([$cliente_id]);
    $cliente_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // Validar documentación completa
    $docs_completos = false;
    if ($cliente_info) {
        $doc_dni_frente_ok = !empty($cliente_info['doc_dni_frente']) && trim($cliente_info['doc_dni_frente']) !== '';
        $doc_dni_dorso_ok = !empty($cliente_info['doc_dni_dorso']) && trim($cliente_info['doc_dni_dorso']) !== '';
        $doc_selfie_dni_ok = !empty($cliente_info['doc_selfie_dni']) && trim($cliente_info['doc_selfie_dni']) !== '';
        $dni_ok = !empty($cliente_info['dni']) && trim($cliente_info['dni']) !== '';
        $cuit_ok = !empty($cliente_info['cuit']) && trim($cliente_info['cuit']) !== '';
        $nombre_completo_ok = !empty($cliente_info['nombre_completo']) && trim($cliente_info['nombre_completo']) !== '';
        $cod_area_ok = !empty($cliente_info['cod_area']) && trim($cliente_info['cod_area']) !== '';
        $telefono_ok = !empty($cliente_info['telefono']) && trim($cliente_info['telefono']) !== '';

        $docs_completos = (
            $doc_dni_frente_ok &&
            $doc_dni_dorso_ok &&
            $doc_selfie_dni_ok &&
            $dni_ok &&
            $cuit_ok &&
            $nombre_completo_ok &&
            $cod_area_ok &&
            $telefono_ok
        );

        // Sincronizar con BD
        $flag_db = (int)($cliente_info['docs_completos'] ?? 0);
        $nuevoFlag = $docs_completos ? 1 : 0;
        if ($flag_db !== $nuevoFlag) {
            $pdo->prepare("UPDATE clientes_detalles SET docs_completos = ?, estado_validacion = ? WHERE usuario_id = ?")
                ->execute([$nuevoFlag, $docs_completos ? 'activo' : 'pendiente', $cliente_id]);
            $cliente_info['docs_completos'] = $nuevoFlag;
        }
    }

    // Obtener préstamos (TODOS los tipos)
    // 1. Préstamos normales
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            COALESCE(p.monto_final, p.monto_ofrecido, p.monto_solicitado, p.monto) as monto_mostrar,
            COALESCE(p.cuotas_final, p.cuotas_ofrecidas, p.cuotas_solicitadas, p.cuotas) as cuotas_mostrar,
            COALESCE(p.frecuencia_final, p.frecuencia_ofrecida, p.frecuencia_solicitada, p.frecuencia_pago) as frecuencia_mostrar,
            COALESCE(p.tasa_interes_final, p.tasa_interes_ofrecida, p.tasa_interes) as tasa_interes_mostrar,
            COALESCE(p.monto_total_final, p.monto_total_ofrecido, p.monto_total) as monto_total_mostrar,
            p.estado_contrato,
            p.solicitud_desembolso_fecha,
            p.desembolso_estado,
            'prestamo' as tipo_prestamo
        FROM prestamos p
        WHERE p.cliente_id = ?
    ");
    $stmt->execute([$cliente_id]);
    $prestamos_normales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    
    // 2. Créditos prendarios
    $stmt = $pdo->prepare("
        SELECT 
            cp.id,
            cp.cliente_id,
            COALESCE(cp.monto_final, cp.monto_ofrecido, cp.monto_solicitado) as monto_mostrar,
            COALESCE(cp.cuotas_final, cp.cuotas_ofrecidas, 1) as cuotas_mostrar,
            COALESCE(cp.frecuencia_final, cp.frecuencia_ofrecida, 'mensual') as frecuencia_mostrar,
            cp.estado,
            cp.fecha_solicitud,
            cp.fecha_inicio,
            cp.fecha_aprobacion,
            cp.estado_contrato,
            cp.solicitud_desembolso_fecha,
            cp.desembolso_estado,
            'prendario' as tipo_prestamo
        FROM creditos_prendarios cp
        WHERE cp.cliente_id = ?
    ");
    $stmt->execute([$cliente_id]);
    $prendarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Empeños
    $stmt = $pdo->prepare("
        SELECT 
            e.id,
            e.cliente_id,
            COALESCE(e.monto_final, e.monto_ofrecido, e.monto_solicitado) as monto_mostrar,
            COALESCE(e.cuotas_final, e.cuotas_ofrecidas, 1) as cuotas_mostrar,
            COALESCE(e.frecuencia_final, e.frecuencia_ofrecida, 'mensual') as frecuencia_mostrar,
            e.estado,
            e.fecha_solicitud,
            e.fecha_inicio,
            e.fecha_aprobacion,
            e.estado_contrato,
            e.solicitud_desembolso_fecha,
            e.desembolso_estado,
            'empeno' as tipo_prestamo
        FROM empenos e
        WHERE e.cliente_id = ?
    ");
    $stmt->execute([$cliente_id]);
    $empenos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combinar todos
    $prestamos = array_merge($prestamos_normales, $prendarios, $empenos);
    
    // Ordenar por fecha
    usort($prestamos, function($a, $b) {
        return strtotime($b['fecha_solicitud']) - strtotime($a['fecha_solicitud']);
    });

    // Obtener contratos pendientes
    $stmt = $pdo->prepare("
        SELECT * FROM prestamos_contratos 
        WHERE cliente_id = ? AND estado = 'pendiente'
        ORDER BY created_at DESC
    ");
    $stmt->execute([$cliente_id]);
    $contratos_pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener notificaciones no leídas (máximo 3)
    $stmt = $pdo->prepare("
        SELECT * FROM clientes_notificaciones 
        WHERE cliente_id = ? AND leida = 0
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $stmt->execute([$cliente_id]);
    $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log("Error en dashboard: " . $e->getMessage());
}

// Calcular estadísticas
$prestamos_activos = 0;
$prestamos_pre_aprobados = 0;
$prestamos_contraoferta = 0;
$total_adeudado = 0;

foreach ($prestamos as $p) {
    if (($p['estado'] ?? '') === 'activo') {
        $prestamos_activos++;
    }
    if (($p['estado'] ?? '') === 'aprobado' && ($p['estado_contrato'] ?? '') === 'pendiente_firma') {
        $prestamos_pre_aprobados++;
    }
    if (($p['estado'] ?? '') === 'contraoferta') {
        $prestamos_contraoferta++;
    }
}

// Variables para sidebar
$pagina_activa = 'dashboard';
$noti_no_leidas = count($notificaciones);
$nombre_mostrar = trim($cliente_info['nombre_completo'] ?? 'Cliente');

// Mensaje del sistema
$mensaje_sistema = '';
if (isset($_GET['msg'])) {
    $mensaje_sistema = $_GET['msg'];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Dashboard - Préstamo Líder</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style_clientes.css">

  <style>
    /* CORRECCIÓN: Asegurar que todos los textos sean visibles */
    body, .main, .page-header, .page-title, .page-sub, .card, p, span, div, td, th, label {
      color: #1f2937 !important;
    }

    .main {
      background: #f5f7fb !important;
    }

    /* Banner de documentación */
    .banner-documentacion {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 20px;
      background: #fef3c7;
      border: 2px solid #f59e0b;
      border-radius: 16px;
      margin-bottom: 24px;
      animation: slideIn 0.4s ease-out;
    }

    .banner-preaprobado {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 20px;
      background: linear-gradient(135deg, #d1fae5, #a7f3d0);
      border: 2px solid #10b981;
      border-radius: 16px;
      margin-bottom: 24px;
      animation: slideIn 0.4s ease-out;
    }

    @keyframes slideIn {
      from { opacity: 0; transform: translateY(-20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .banner-icon {
      font-size: 2.5rem;
      flex-shrink: 0;
    }

    .banner-content {
      flex: 1;
    }

    .banner-title {
      font-size: 1.25rem;
      font-weight: 700;
      color: #78350f !important;
      margin-bottom: 6px;
    }

    .banner-preaprobado .banner-title {
      color: #065f46 !important;
    }

    .banner-message {
      font-size: 0.95rem;
      color: #92400e !important;
      line-height: 1.5;
    }

    .banner-preaprobado .banner-message {
      color: #047857 !important;
    }

    .banner-action {
      flex-shrink: 0;
    }

    .banner-btn {
      display: inline-block;
      padding: 12px 24px;
      background: #f59e0b;
      color: #000 !important;
      font-weight: 700;
      border-radius: 10px;
      text-decoration: none;
      transition: all 0.2s;
    }

    .banner-btn:hover {
      background: #fbbf24;
      transform: translateY(-2px);
    }

    .banner-btn.green {
      background: #10b981;
      color: #fff !important;
    }

    .banner-btn.green:hover {
      background: #059669;
    }

    /* Mensaje del sistema */
    .mensaje-sistema {
      padding: 16px 20px;
      border-radius: 12px;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      gap: 12px;
      animation: slideIn 0.4s ease-out;
    }

    .mensaje-sistema.exito {
      background: #d1fae5;
      border-left: 4px solid #10b981;
      color: #065f46 !important;
    }

    .mensaje-sistema.error {
      background: #fee2e2;
      border-left: 4px solid #ef4444;
      color: #991b1b !important;
    }

    /* Notificaciones mejoradas */
    .notificaciones-container {
      position: fixed;
      top: 80px;
      right: 20px;
      width: 360px;
      max-height: calc(100vh - 100px);
      overflow-y: auto;
      z-index: 1000;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .notif-card {
      background: white;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      padding: 16px;
      border-left: 4px solid #7c5cff;
      animation: slideInRight 0.3s ease-out;
      position: relative;
      cursor: pointer;
      transition: all 0.2s;
    }

    .notif-card:hover {
      transform: translateX(-4px);
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
    }

    .notif-card.success { border-left-color: #10b981; }
    .notif-card.warning { border-left-color: #f59e0b; }
    .notif-card.error { border-left-color: #ef4444; }
    .notif-card.info { border-left-color: #3b82f6; }

    @keyframes slideInRight {
      from {
        opacity: 0;
        transform: translateX(100px);
      }
      to {
        opacity: 1;
        transform: translateX(0);
      }
    }

    .notif-header {
      display: flex;
      align-items: start;
      gap: 12px;
      margin-bottom: 8px;
    }

    .notif-icon {
      font-size: 1.5rem;
      flex-shrink: 0;
    }

    .notif-content {
      flex: 1;
    }

    .notif-title {
      font-weight: 700;
      color: #1f2937 !important;
      font-size: 0.95rem;
      margin-bottom: 4px;
    }

    .notif-message {
      font-size: 0.85rem;
      color: #6b7280 !important;
      line-height: 1.4;
    }

    .notif-close {
      position: absolute;
      top: 8px;
      right: 8px;
      background: none;
      border: none;
      color: #9ca3af;
      font-size: 1.2rem;
      cursor: pointer;
      width: 24px;
      height: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 4px;
      transition: all 0.2s;
    }

    .notif-close:hover {
      background: #f3f4f6;
      color: #1f2937;
    }

    .notif-action {
      margin-top: 12px;
    }

    .notif-btn {
      display: inline-block;
      padding: 6px 14px;
      background: #7c5cff;
      color: white !important;
      font-size: 0.85rem;
      font-weight: 600;
      border-radius: 8px;
      text-decoration: none;
      transition: all 0.2s;
    }

    .notif-btn:hover {
      background: #6a4de8;
    }

    .notif-time {
      font-size: 0.75rem;
      color: #9ca3af !important;
      margin-top: 8px;
    }

    /* Estados de préstamo */
    .estado-prestamo {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 0.85rem;
      font-weight: 600;
      text-transform: capitalize;
    }

    .estado-prestamo.pendiente { background: #fef3c7; color: #92400e !important; }
    .estado-prestamo.aprobado { background: #d1fae5; color: #065f46 !important; }
    .estado-prestamo.activo { background: #d1fae5; color: #065f46 !important; }
    .estado-prestamo.rechazado { background: #fee2e2; color: #991b1b !important; }
    .estado-prestamo.contraoferta { 
      background: linear-gradient(135deg, #a78bfa, #c4b5fd); 
      color: #5b21b6 !important;
      animation: pulse-estado 2s infinite;
    }

    @keyframes pulse-estado {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.05); }
    }

    .tipo-prestamo {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 600;
      background: rgba(124, 92, 255, 0.15);
      color: #7c5cff !important;
      border: 1px solid rgba(124, 92, 255, 0.3);
    }

    .tipo-prestamo.prendario {
      background: rgba(59, 130, 246, 0.15);
      color: #3b82f6 !important;
      border-color: rgba(59, 130, 246, 0.3);
    }

    .tipo-prestamo.empeno {
      background: rgba(245, 158, 11, 0.15);
      color: #f59e0b !important;
      border-color: rgba(245, 158, 11, 0.3);
    }

    .btn-solicitar {
      display: inline-block;
      padding: 8px 20px;
      background: linear-gradient(135deg, #10b981, #059669);
      color: white !important;
      font-size: 0.9rem;
      font-weight: 700;
      border-radius: 10px;
      text-decoration: none;
      transition: all 0.2s;
      animation: pulse 2s infinite;
    }

    @keyframes pulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
      50% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
    }

    .btn-solicitar:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
    }

    .btn-contraoferta {
      display: inline-block;
      padding: 8px 20px;
      background: linear-gradient(135deg, #7c5cff, #a78bfa);
      color: white !important;
      font-size: 0.9rem;
      font-weight: 700;
      border-radius: 10px;
      text-decoration: none;
      transition: all 0.2s;
      animation: pulse-purple 2s infinite;
    }

    @keyframes pulse-purple {
      0%, 100% { box-shadow: 0 0 0 0 rgba(124, 92, 255, 0.7); }
      50% { box-shadow: 0 0 0 10px rgba(124, 92, 255, 0); }
    }

    .btn-contraoferta:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(124, 92, 255, 0.4);
    }

    /* Estilos de tabla */
    .table {
      background: white;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .table th {
      background: #f9fafb;
      color: #6b7280 !important;
      font-weight: 600;
      padding: 12px;
      text-align: left;
    }

    .table td {
      padding: 12px;
      border-top: 1px solid #f3f4f6;
      color: #1f2937 !important;
    }

    .table tbody tr:hover {
      background: #f9fafb;
    }

    /* Estilos para estadística púrpura */
    .stat.purple {
      background: linear-gradient(135deg, #a78bfa, #c4b5fd);
    }

    .stat.purple .ico {
      background: rgba(124, 92, 255, 0.2);
    }

    @media (max-width: 768px) {
      .notificaciones-container {
        width: calc(100% - 40px);
        right: 20px;
      }

      .banner-documentacion,
      .banner-preaprobado {
        flex-direction: column;
        text-align: center;
      }
    }
  </style>
</head>
<body>

<div class="app">
  <?php include __DIR__ . '/sidebar_clientes.php'; ?>

  <main class="main">

    <!-- MENSAJE DEL SISTEMA -->
    <?php if ($mensaje_sistema): ?>
      <div class="mensaje-sistema <?php echo strpos($mensaje_sistema, '✅') !== false ? 'exito' : 'error'; ?>">
        <span style="font-size: 1.5rem;">
          <?php echo strpos($mensaje_sistema, '✅') !== false ? '✅' : '❌'; ?>
        </span>
        <span><?php echo h($mensaje_sistema); ?></span>
      </div>
    <?php endif; ?>

    <!-- NOTIFICACIONES FLOTANTES -->
    <?php if (!empty($notificaciones)): ?>
    <div class="notificaciones-container">
      <?php foreach ($notificaciones as $notif): ?>
        <div class="notif-card <?php echo h($notif['tipo']); ?>" id="notif-<?php echo $notif['id']; ?>">
          <button class="notif-close" onclick="cerrarNotificacion(<?php echo $notif['id']; ?>)">×</button>
          <div class="notif-header">
            <div class="notif-icon">
              <?php 
                $iconos = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'error' => '❌'];
                echo $iconos[$notif['tipo']] ?? 'ℹ️';
              ?>
            </div>
            <div class="notif-content">
              <div class="notif-title"><?php echo h($notif['titulo']); ?></div>
              <div class="notif-message"><?php echo h($notif['mensaje']); ?></div>
              <?php if (!empty($notif['url_accion'])): ?>
                <div class="notif-action">
                  <a href="<?php echo h($notif['url_accion']); ?>" class="notif-btn">
                    <?php echo h($notif['texto_accion'] ?? 'Ver más'); ?>
                  </a>
                </div>
              <?php endif; ?>
              <div class="notif-time">
                <?php 
                  $tiempo = time() - strtotime($notif['created_at']);
                  if ($tiempo < 60) echo 'Hace un momento';
                  elseif ($tiempo < 3600) echo 'Hace ' . floor($tiempo / 60) . ' min';
                  elseif ($tiempo < 86400) echo 'Hace ' . floor($tiempo / 3600) . ' h';
                  else echo date('d/m/Y H:i', strtotime($notif['created_at']));
                ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- BANNER DOCUMENTACIÓN -->
    <?php if (!$docs_completos): ?>
    <div class="banner-documentacion">
      <div class="banner-icon">⚠️</div>
      <div class="banner-content">
        <div class="banner-title">Completá tu documentación</div>
        <div class="banner-message">
          Para solicitar préstamos, necesitamos que completes tu información personal y subas los documentos requeridos.
        </div>
      </div>
      <div class="banner-action">
        <a href="documentacion.php" class="banner-btn">Completar ahora</a>
      </div>
    </div>
    <?php endif; ?>

    <!-- BANNER PRE-APROBACIÓN -->
    <?php if ($prestamos_pre_aprobados > 0): ?>
    <div class="banner-preaprobado">
      <div class="banner-icon">🎉</div>
      <div class="banner-content">
        <div class="banner-title">¡Felicitaciones! Tenés <?php echo $prestamos_pre_aprobados; ?> <?php echo $prestamos_pre_aprobados === 1 ? 'préstamo pre-aprobado' : 'préstamos pre-aprobados'; ?></div>
        <div class="banner-message">
          Tu solicitud ha sido aprobada. Revisá los detalles y firmá el contrato para recibir el dinero.
        </div>
      </div>
      <div class="banner-action">
        <a href="#prestamos" class="banner-btn green">Ver préstamos</a>
      </div>
    </div>
    <?php endif; ?>

    <div class="page-header">
      <div class="page-title">Dashboard</div>
      <div class="page-sub">Bienvenido, <?php echo h($nombre_mostrar); ?>!</div>
    </div>

    <section class="stats">
      <div class="stat blue">
        <div>
          <div class="label">Préstamos Activos</div>
          <div class="value"><?php echo $prestamos_activos; ?></div>
        </div>
        <div class="ico blue">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
        </div>
      </div>

      <div class="stat green">
        <div>
          <div class="label">Pre-Aprobados</div>
          <div class="value"><?php echo $prestamos_pre_aprobados; ?></div>
        </div>
        <div class="ico green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
      </div>

      <div class="stat purple">
        <div>
          <div class="label">Contra-Ofertas</div>
          <div class="value"><?php echo $prestamos_contraoferta; ?></div>
        </div>
        <div class="ico purple">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
          </svg>
        </div>
      </div>

      <div class="stat yellow">
        <div>
          <div class="label">Contratos Pendientes</div>
          <div class="value"><?php echo count($contratos_pendientes); ?></div>
        </div>
        <div class="ico yellow">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
      </div>
    </section>

    <section class="section" id="prestamos">
      <h2>Mis Préstamos</h2>

      <?php if (empty($prestamos)): ?>
        <div class="empty">
          <?php if ($docs_completos): ?>
            No tenés préstamos activos. <a href="prestamos_clientes.php" style="color: #7c5cff; text-decoration: underline;">Solicitar uno ahora</a>
          <?php else: ?>
            No tenés préstamos activos. <a href="documentacion.php" style="color: #7c5cff; text-decoration: underline;">Completá tu documentación</a> para solicitar uno.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Tipo</th>
              <th>Monto</th>
              <th>Cuotas</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($prestamos as $p): 
              $tipo = $p['tipo_prestamo'] ?? 'prestamo';
              $tipo_clase = strtolower($tipo);
              $tipo_nombre = ['prestamo' => 'Préstamo', 'prendario' => 'Prendario', 'empeno' => 'Empeño'][$tipo] ?? 'Préstamo';
              
              $es_preaprobado = ($p['estado'] ?? '') === 'aprobado' && ($p['estado_contrato'] ?? '') === 'pendiente_firma';
              $es_contraoferta = ($p['estado'] ?? '') === 'contraoferta';
            ?>
              <tr>
                <td><strong>#<?php echo (int)($p['id'] ?? 0); ?></strong></td>
                <td>
                  <span class="tipo-prestamo <?php echo h($tipo_clase); ?>">
                    <?php echo h($tipo_nombre); ?>
                  </span>
                </td>
                <td><strong>$<?php echo number_format((float)($p['monto_mostrar'] ?? 0), 0, ',', '.'); ?></strong></td>
                <td><?php echo (int)($p['cuotas_mostrar'] ?? 0); ?> <?php echo h($p['frecuencia_mostrar'] ?? 'mensual'); ?>es</td>
                <td>
                  <?php if ($es_contraoferta): ?>
                    <span class="estado-prestamo contraoferta">
                      🎯 Contra-Oferta
                    </span>
                  <?php elseif ($es_preaprobado): ?>
                    <span class="estado-prestamo aprobado">
                      ✅ Pre-Aprobado
                    </span>
                  <?php else: ?>
                    <span class="estado-prestamo <?php echo h($p['estado'] ?? 'pendiente'); ?>">
                      <?php echo ucfirst(str_replace('_', ' ', $p['estado'] ?? 'pendiente')); ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php 
                  $contrato_firmado = ($p['estado_contrato'] ?? '') === 'firmado';
                  $tiene_solicitud_desembolso = !empty($p['solicitud_desembolso_fecha']);
                  $desembolso_estado = $p['desembolso_estado'] ?? 'pendiente';
                  ?>
                  
                  <?php if ($es_contraoferta): ?>
                    <!-- BOTÓN PARA VER CONTRA-OFERTA -->
                    <a href="detalle_prestamo.php?id=<?php echo (int)($p['id'] ?? 0); ?>&tipo=<?php echo urlencode($tipo); ?>" class="btn-contraoferta">
                      🎯 Ver Contra-Oferta
                    </a>
                  <?php elseif ($es_preaprobado): ?>
                    <a href="firmar_contrato.php?id=<?php echo (int)($p['id'] ?? 0); ?>&tipo=<?php echo urlencode($tipo); ?>" class="btn-solicitar">
                      📝 Firmar Contrato
                    </a>
                  <?php elseif ($contrato_firmado && !$tiene_solicitud_desembolso): ?>
                    <a href="solicitar_desembolso.php?id=<?php echo (int)($p['id'] ?? 0); ?>&tipo=<?php echo urlencode($tipo); ?>" class="btn-solicitar">
                      💰 Solicitar Desembolso
                    </a>
                  <?php elseif ($tiene_solicitud_desembolso && $desembolso_estado === 'pendiente'): ?>
                    <span style="display: inline-block; padding: 6px 16px; background: #fef3c7; color: #92400e; border-radius: 8px; font-size: 0.85rem; font-weight: 600;">
                      ⏳ Desembolso Pendiente
                    </span>
                  <?php elseif ($desembolso_estado === 'transferido'): ?>
                    <span style="display: inline-block; padding: 6px 16px; background: #d1fae5; color: #065f46; border-radius: 8px; font-size: 0.85rem; font-weight: 600;">
                      ✅ Transferido
                    </span>
                  <?php else: ?>
                    <a href="detalle_prestamo.php?id=<?php echo (int)($p['id'] ?? 0); ?>&tipo=<?php echo urlencode($tipo); ?>" class="btn" style="padding: 6px 16px; font-size: 0.85rem; background: #7c5cff; color: white; border-radius: 8px; text-decoration: none;">
                      Ver
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

  </main>
</div>

<script>
function cerrarNotificacion(id) {
  const notif = document.getElementById('notif-' + id);
  if (notif) {
    notif.style.animation = 'slideOutRight 0.3s ease-out';
    setTimeout(() => {
      notif.remove();
      
      // Marcar como leída en el servidor
      fetch('?marcar_leida=1&notif_id=' + id)
        .then(response => response.json())
        .catch(error => console.error('Error:', error));
    }, 300);
  }
}

// Auto-cerrar notificaciones después de 10 segundos
document.addEventListener('DOMContentLoaded', function() {
  const notifs = document.querySelectorAll('.notif-card');
  notifs.forEach((notif, index) => {
    setTimeout(() => {
      const id = notif.id.replace('notif-', '');
      cerrarNotificacion(id);
    }, 10000 + (index * 2000));
  });
});
</script>

<style>
@keyframes slideOutRight {
  from {
    opacity: 1;
    transform: translateX(0);
  }
  to {
    opacity: 0;
    transform: translateX(100px);
  }
}
</style>

</body>
</html>