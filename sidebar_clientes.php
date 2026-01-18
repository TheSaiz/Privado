<?php
if (!function_exists('h')) {
    function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// Asegurar cliente_info
$cliente_info = $cliente_info ?? [];

// Usar el MISMO $cliente_id que usa el dashboard
if (
    empty($cliente_info['nombre_completo']) &&
    isset($cliente_id) &&
    (int)$cliente_id > 0
) {
    require_once __DIR__ . '/backend/connection.php';

    if (isset($pdo)) {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    nombre_completo,
                    email
                FROM clientes_detalles
                WHERE usuario_id = ?
                LIMIT 1
            ");
            $stmt->execute([(int)$cliente_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $cliente_info = $result;
            }
        } catch (Exception $e) {
            error_log("Error en sidebar: " . $e->getMessage());
        }
    }
}

// ================================
// Nombre a mostrar
// ================================
$nombreSidebar = 'Cliente';

if (!empty($cliente_info['nombre_completo']) && trim($cliente_info['nombre_completo']) !== '') {
    $nombreSidebar = trim($cliente_info['nombre_completo']);
}

// ================================
// Iniciales avatar
// ================================
$iniciales = 'CL';

if (!empty($cliente_info['nombre_completo']) && trim($cliente_info['nombre_completo']) !== '') {
    $partes = array_values(array_filter(explode(' ', trim($cliente_info['nombre_completo']))));
    if (count($partes) >= 2) {
        $iniciales = strtoupper(substr($partes[0], 0, 1) . substr($partes[1], 0, 1));
    } elseif (count($partes) === 1) {
        $iniciales = strtoupper(substr($partes[0], 0, 2));
    }
}

$pagina_activa = $pagina_activa ?? '';

$cuotasVencidas = (int)($cuotas_vencidas ?? 0);
$notiCount      = (int)($noti_no_leidas ?? 0);

// Verificar si los documentos están completos
$puede_solicitar_prestamo = ($docs_completos ?? false);
?>
<aside class="sidebar">

  <!-- BRAND -->
  <div class="sidebar__brand">
    <h1>Préstamo Líder</h1>
    <p>Portal de Clientes</p>
  </div>

  <!-- USER -->
  <div class="sidebar__user">
    <div class="avatar"><?php echo h($iniciales); ?></div>
    <div class="user__meta">
      <div class="name">
        <?php echo h($nombreSidebar); ?>
        <?php if ($puede_solicitar_prestamo): ?>
          <span style="color: #10b981; font-size: 1rem; margin-left: 4px;" title="Documentación completa">✓</span>
        <?php else: ?>
          <span style="font-size: 0.9rem; margin-left: 4px; opacity: 0.6;" title="Documentación pendiente">⏳</span>
        <?php endif; ?>
      </div>
      <div class="mail"><?php echo h($cliente_info['email'] ?? ''); ?></div>
    </div>
  </div>

  <!-- NAV -->
  <nav class="sidebar__nav">

    <!-- DASHBOARD -->
    <a class="navlink <?php echo $pagina_activa==='dashboard'?'active':''; ?>" href="dashboard_clientes.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
        <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
          d="M3 12l2-2 7-7 7 7M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3m-6 0v-4a1 1 0 011-1h2a1 1 0 011 1v4m-6 0h6"/>
      </svg>
      Dashboard
    </a>

    <!-- PRÉSTAMOS (con indicador si falta documentación) -->
    <a class="navlink <?php echo $pagina_activa==='prestamos'?'active':''; ?>" href="prestamos_clientes.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
        <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
          d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      Préstamos
      <?php if ($puede_solicitar_prestamo): ?>
        <span class="pill pill-success" style="margin-left:auto;">✓</span>
      <?php else: ?>
        <span class="pill pill-warning" style="margin-left:auto;">⏳</span>
      <?php endif; ?>
    </a>

    <!-- PAGOS -->
    <a class="navlink <?php echo $pagina_activa==='pagos'?'active':''; ?>" href="pagos.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
        <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
          d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
      </svg>
      Pagos
      <?php if ($cuotasVencidas > 0): ?>
        <span class="pill pill-danger"><?php echo $cuotasVencidas; ?></span>
      <?php endif; ?>
    </a>

    <!-- DOCUMENTACIÓN -->
    <a class="navlink <?php echo $pagina_activa==='documentacion'?'active':''; ?>" href="documentacion.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
        <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
      </svg>
      Documentación
      <?php if ($puede_solicitar_prestamo): ?>
        <span class="pill pill-success" style="margin-left:auto;">✓</span>
      <?php else: ?>
        <span class="pill pill-warning" style="margin-left:auto;">⏳</span>
      <?php endif; ?>
    </a>

    <!-- PERFIL -->
    <a class="navlink <?php echo $pagina_activa==='perfil'?'active':''; ?>" href="perfil_clientes.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
        <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
          d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
      </svg>
      Mi Perfil
    </a>

    <!-- NOTIFICACIONES -->
    <a class="navlink <?php echo $pagina_activa==='notificaciones'?'active':''; ?>" href="clientes_notificaciones.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
        <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
          d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1"/>
      </svg>
      Notificaciones
      <?php if ($notiCount > 0): ?>
        <span class="pill pill-info"><?php echo $notiCount; ?></span>
      <?php endif; ?>
    </a>

    <!-- HABLAR CON ASESOR -->
    <a class="navlink <?php echo $pagina_activa==='chat'?'active':''; ?>" href="hablar_con_asesor.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
        <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
          d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
      </svg>
      Hablar con Asesor
    </a>

  </nav>

  <!-- FOOTER -->
  <div class="sidebar__footer">
    <a class="logout" href="logout.php">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor">
        <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
          d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
      </svg>
      Cerrar Sesión
    </a>
  </div>

</aside>

<style>
.pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.15rem 0.5rem;
  border-radius: 12px;
  font-size: 0.7rem;
  font-weight: 700;
  color: white;
  min-width: 20px;
  height: 18px;
}

.pill-danger {
  background: #ef4444;
}

.pill-warning {
  background: #f59e0b;
}

.pill-info {
  background: #3b82f6;
}

.pill-success {
  background: #10b981;
}
</style>