<?php
/*************************************************
 * notificaciones_clientes.php - Portal Clientes
 *************************************************/

declare(strict_types=1);
session_start();

/* =========================
   DEBUG OFF / LOG OPCIONAL
========================= */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// TZ Argentina
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Helpers
if (!function_exists('h')) {
  function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

function is_ajax_request(): bool {
  $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  return (strtolower($xhr) === 'xmlhttprequest') || (stripos($accept, 'application/json') !== false);
}

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function tiempo_relativo(string $dt): string {
  $ts = strtotime($dt);
  if ($ts <= 0) return '';
  $diff = time() - $ts;
  if ($diff < 60) return 'Hace un momento';
  if ($diff < 3600) return 'Hace ' . floor($diff / 60) . ' min';
  if ($diff < 86400) return 'Hace ' . floor($diff / 3600) . ' hs';
  return 'Hace ' . floor($diff / 86400) . ' días';
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
  header('Location: login_clientes.php');
  exit;
}

/* =========================
   DB
========================= */
$connectionPath = __DIR__ . '/backend/connection.php';
if (!file_exists($connectionPath)) {
  if (is_ajax_request()) json_out(['success' => false, 'message' => 'Falta conexión DB'], 500);
  http_response_code(500);
  exit('Falta backend/connection.php');
}
require_once $connectionPath;

if (!isset($pdo) || !($pdo instanceof PDO)) {
  if (is_ajax_request()) json_out(['success' => false, 'message' => 'DB inválida'], 500);
  http_response_code(500);
  exit('DB inválida');
}

/* =========================
   ACCIONES (AJAX / POST)
========================= */
$action = $_POST['action'] ?? ($_GET['action'] ?? null);

if ($action) {
  try {
    if ($action === 'mark_read') {
      $id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
      if ($id <= 0) json_out(['success' => false, 'message' => 'ID inválido'], 400);

      $stmt = $pdo->prepare("UPDATE clientes_notificaciones SET leida=1, fecha_leida=NOW() WHERE id=? AND cliente_id=?");
      $stmt->execute([$id, $cliente_id]);

      $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM clientes_notificaciones WHERE cliente_id=? AND leida=0");
      $stmt2->execute([$cliente_id]);
      $unread = (int)$stmt2->fetchColumn();

      json_out(['success' => true, 'unread' => $unread]);
    }

    if ($action === 'mark_unread') {
      $id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
      if ($id <= 0) json_out(['success' => false, 'message' => 'ID inválido'], 400);

      $stmt = $pdo->prepare("UPDATE clientes_notificaciones SET leida=0, fecha_leida=NULL WHERE id=? AND cliente_id=?");
      $stmt->execute([$id, $cliente_id]);

      $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM clientes_notificaciones WHERE cliente_id=? AND leida=0");
      $stmt2->execute([$cliente_id]);
      $unread = (int)$stmt2->fetchColumn();

      json_out(['success' => true, 'unread' => $unread]);
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
      if ($id <= 0) json_out(['success' => false, 'message' => 'ID inválido'], 400);

      $stmt = $pdo->prepare("DELETE FROM clientes_notificaciones WHERE id=? AND cliente_id=?");
      $stmt->execute([$id, $cliente_id]);

      $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM clientes_notificaciones WHERE cliente_id=? AND leida=0");
      $stmt2->execute([$cliente_id]);
      $unread = (int)$stmt2->fetchColumn();

      json_out(['success' => true, 'unread' => $unread]);
    }

    if ($action === 'mark_all_read') {
      $stmt = $pdo->prepare("UPDATE clientes_notificaciones SET leida=1, fecha_leida=NOW() WHERE cliente_id=? AND leida=0");
      $stmt->execute([$cliente_id]);
      json_out(['success' => true, 'unread' => 0]);
    }

    if ($action === 'delete_read') {
      $stmt = $pdo->prepare("DELETE FROM clientes_notificaciones WHERE cliente_id=? AND leida=1");
      $stmt->execute([$cliente_id]);

      $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM clientes_notificaciones WHERE cliente_id=? AND leida=0");
      $stmt2->execute([$cliente_id]);
      $unread = (int)$stmt2->fetchColumn();

      json_out(['success' => true, 'unread' => $unread]);
    }

    json_out(['success' => false, 'message' => 'Acción no válida'], 400);

  } catch (Throwable $e) {
    json_out(['success' => false, 'message' => 'Error interno'], 500);
  }
}

/* =========================
   FILTROS / PAGINADO
========================= */
$view   = $_GET['view'] ?? 'all';          // all | unread
$tipo   = $_GET['tipo'] ?? 'all';          // all | info | success | warning | error
$q      = trim((string)($_GET['q'] ?? '')); // búsqueda
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;
$offset = ($page - 1) * $per;

$where = "cliente_id = ?";
$params = [$cliente_id];

if ($view === 'unread') {
  $where .= " AND leida = 0";
}

$tiposValidos = ['info','success','warning','error'];
if ($tipo !== 'all' && in_array($tipo, $tiposValidos, true)) {
  $where .= " AND tipo = ?";
  $params[] = $tipo;
}

if ($q !== '') {
  $where .= " AND (titulo LIKE ? OR mensaje LIKE ?)";
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
}

// Conteos (para badge sidebar y total)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes_notificaciones WHERE cliente_id=? AND leida=0");
$stmt->execute([$cliente_id]);
$noti_no_leidas = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes_notificaciones WHERE $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM clientes_notificaciones WHERE $where ORDER BY created_at DESC LIMIT $per OFFSET $offset");
$stmt->execute($params);
$notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Variables para sidebar
$pagina_activa = 'notificaciones';

// Para que sidebar muestre ✓/⏳ igual que en otros módulos, intentamos cargar docs_completos
$docs_completos = false;
$cliente_info = [];
try {
  $stmt = $pdo->prepare("SELECT nombre_completo, email, docs_completos FROM clientes_detalles WHERE usuario_id=? LIMIT 1");
  $stmt->execute([$cliente_id]);
  $cliente_info = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $docs_completos = (int)($cliente_info['docs_completos'] ?? 0) === 1;
} catch (Throwable $e) {
  // silencio
}

// Paginación
$totalPages = max(1, (int)ceil($total / $per));
$prevPage = $page > 1 ? $page - 1 : null;
$nextPage = $page < $totalPages ? $page + 1 : null;

// Iconos
$iconos = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'error' => '❌'];

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Notificaciones - Préstamo Líder</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style_clientes.css">

  <style>
    body, .main, .card, p, span, div, td, th, label, input, button, a { color: #1f2937; }
    .main { background: #f5f7fb !important; }

    .page-head{
      display:flex; align-items:flex-end; justify-content:space-between;
      gap:16px; margin-bottom:18px;
    }
    .page-title{ font-size:1.6rem; font-weight:900; letter-spacing:-0.02em; }
    .page-sub{ color:#64748b; font-weight:600; margin-top:4px; }

    .toolbar{
      display:flex; flex-wrap:wrap; gap:10px; align-items:center;
      margin: 14px 0 18px;
    }
    .toolbar .chip{
      display:inline-flex; align-items:center; gap:8px;
      padding: 8px 12px; border-radius: 999px;
      background: white; border:1px solid #e5e7eb;
      font-weight:800; font-size:0.85rem; text-decoration:none;
    }
    .toolbar .chip.active{ border-color:#7c5cff; box-shadow:0 0 0 3px rgba(124,92,255,0.12); }
    .toolbar form{ display:flex; gap:10px; flex:1; min-width:260px; }
    .toolbar input{
      flex:1; background:white; border:1px solid #e5e7eb;
      border-radius: 12px; padding:10px 12px; font-weight:700;
      outline:none;
    }
    .btn{
      border:none; cursor:pointer; border-radius:12px;
      padding:10px 12px; font-weight:900; text-decoration:none;
      background:#7c5cff; color:white !important;
      box-shadow: 0 8px 20px rgba(124,92,255,0.25);
      transition: transform .15s ease, box-shadow .15s ease;
    }
    .btn:hover{ transform: translateY(-1px); box-shadow:0 12px 26px rgba(124,92,255,0.28); }
    .btn-ghost{
      background:white; color:#111827 !important; border:1px solid #e5e7eb;
      box-shadow:none;
    }

    .list{ display:flex; flex-direction:column; gap:12px; }
    .notif{
      background:white; border-radius:16px; border:1px solid #eef2f7;
      padding:14px 14px;
      display:flex; gap:12px; align-items:flex-start;
      box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .notif.unread{ border-color:#c7d2fe; box-shadow:0 0 0 4px rgba(99,102,241,0.08); }
    .notif .ico{
      width:44px; height:44px; border-radius:12px;
      display:flex; align-items:center; justify-content:center;
      background:#f1f5f9; font-size:1.4rem; flex-shrink:0;
    }
    .notif.success .ico{ background: #ecfdf5; }
    .notif.warning .ico{ background: #fffbeb; }
    .notif.error .ico{ background: #fef2f2; }
    .notif.info .ico{ background: #eff6ff; }

    .notif .body{ flex:1; min-width:0; }
    .notif .title{ font-weight:900; font-size:1rem; margin-bottom:4px; }
    .notif .msg{ color:#374151; font-weight:600; line-height:1.5; }
    .notif .meta{ display:flex; gap:10px; align-items:center; margin-top:10px; flex-wrap:wrap; }
    .badge{
      font-size:.75rem; font-weight:900; padding:4px 10px; border-radius:999px;
      background:#f1f5f9; color:#334155;
    }
    .badge.unread{ background:#e0e7ff; color:#3730a3; }
    .time{ color:#64748b; font-weight:700; font-size:.85rem; }

    .actions{ display:flex; gap:8px; align-items:center; }
    .a-btn{
      border:1px solid #e5e7eb; background:white;
      padding:8px 10px; border-radius:12px; cursor:pointer;
      font-weight:900; font-size:.85rem;
      transition: transform .12s ease;
    }
    .a-btn:hover{ transform: translateY(-1px); }
    .a-danger{ border-color:#fecaca; color:#991b1b; background:#fff5f5; }
    .link{
      display:inline-flex; align-items:center; gap:8px;
      padding:9px 12px; border-radius:12px;
      background:#7c5cff; color:white !important; text-decoration:none;
      font-weight:900;
    }

    .empty{
      background:white; border:1px dashed #cbd5e1;
      border-radius:16px; padding:22px; text-align:center;
      color:#64748b; font-weight:700;
    }

    .pager{
      display:flex; justify-content:space-between; align-items:center;
      gap:10px; margin-top:18px; flex-wrap:wrap;
    }
    .pager .pinfo{ color:#64748b; font-weight:800; }
    .pager a{ text-decoration:none; }
    @media (max-width: 768px){
      .page-head{ flex-direction:column; align-items:flex-start; }
      .toolbar form{ min-width: 100%; }
    }
  </style>
</head>
<body>

<div class="app">
  <?php include __DIR__ . '/sidebar_clientes.php'; ?>

  <main class="main">

    <div class="page-head">
      <div>
        <div class="page-title">🔔 Notificaciones</div>
        <div class="page-sub">
          Tenés <strong><?php echo (int)$noti_no_leidas; ?></strong> sin leer.
        </div>
      </div>

      <div class="actions">
        <?php if ($noti_no_leidas > 0): ?>
          <button class="btn" onclick="accionGlobal('mark_all_read')">Marcar todas como leídas</button>
        <?php endif; ?>
        <button class="btn btn-ghost" onclick="accionGlobal('delete_read')">Borrar leídas</button>
      </div>
    </div>

    <div class="toolbar">
      <?php
        $base = 'notificaciones_clientes.php';
        $mk = function(array $over) use ($view,$tipo,$q,$base){
          $p = ['view'=>$view,'tipo'=>$tipo,'q'=>$q,'page'=>1];
          foreach ($over as $k=>$v){ $p[$k]=$v; }
          foreach ($p as $k=>$v){ if ($v === '' || $v === null) unset($p[$k]); }
          return $base . '?' . http_build_query($p);
        };
      ?>
      <a class="chip <?php echo $view==='all'?'active':''; ?>" href="<?php echo h($mk(['view'=>'all'])); ?>">Todas</a>
      <a class="chip <?php echo $view==='unread'?'active':''; ?>" href="<?php echo h($mk(['view'=>'unread'])); ?>">Sin leer</a>

      <a class="chip <?php echo $tipo==='all'?'active':''; ?>" href="<?php echo h($mk(['tipo'=>'all'])); ?>">Tipo: Todos</a>
      <a class="chip <?php echo $tipo==='info'?'active':''; ?>" href="<?php echo h($mk(['tipo'=>'info'])); ?>">ℹ️ Info</a>
      <a class="chip <?php echo $tipo==='success'?'active':''; ?>" href="<?php echo h($mk(['tipo'=>'success'])); ?>">✅ Éxito</a>
      <a class="chip <?php echo $tipo==='warning'?'active':''; ?>" href="<?php echo h($mk(['tipo'=>'warning'])); ?>">⚠️ Alerta</a>
      <a class="chip <?php echo $tipo==='error'?'active':''; ?>" href="<?php echo h($mk(['tipo'=>'error'])); ?>">❌ Error</a>

      <form method="get" action="<?php echo $base; ?>">
        <input type="hidden" name="view" value="<?php echo h($view); ?>">
        <input type="hidden" name="tipo" value="<?php echo h($tipo); ?>">
        <input name="q" value="<?php echo h($q); ?>" placeholder="Buscar en notificaciones...">
        <button class="btn" type="submit">Buscar</button>
      </form>
    </div>

    <?php if (empty($notificaciones)): ?>
      <div class="empty">No hay notificaciones para mostrar.</div>
    <?php else: ?>
      <div class="list" id="notifList">
        <?php foreach ($notificaciones as $n): ?>
          <?php
            $nid = (int)($n['id'] ?? 0);
            $ntipo = (string)($n['tipo'] ?? 'info');
            $unread = ((int)($n['leida'] ?? 0) === 0);
          ?>
          <div class="notif <?php echo h($ntipo); ?> <?php echo $unread ? 'unread' : ''; ?>" id="n-<?php echo $nid; ?>">
            <div class="ico"><?php echo h($iconos[$ntipo] ?? 'ℹ️'); ?></div>
            <div class="body">
              <div class="title"><?php echo h($n['titulo'] ?? ''); ?></div>
              <div class="msg"><?php echo nl2br(h($n['mensaje'] ?? '')); ?></div>

              <div class="meta">
                <?php if ($unread): ?>
                  <span class="badge unread">Sin leer</span>
                <?php else: ?>
                  <span class="badge">Leída</span>
                <?php endif; ?>

                <span class="time"><?php echo h(tiempo_relativo((string)($n['created_at'] ?? ''))); ?></span>

                <?php if (!empty($n['url_accion'])): ?>
                  <a class="link" href="<?php echo h($n['url_accion']); ?>">
                    <?php echo h($n['texto_accion'] ?? 'Ver más'); ?> →
                  </a>
                <?php endif; ?>
              </div>
            </div>

            <div class="actions">
              <?php if ($unread): ?>
                <button class="a-btn" onclick="accion('mark_read', <?php echo $nid; ?>)">Marcar leída</button>
              <?php else: ?>
                <button class="a-btn" onclick="accion('mark_unread', <?php echo $nid; ?>)">Marcar sin leer</button>
              <?php endif; ?>
              <button class="a-btn a-danger" onclick="accion('delete', <?php echo $nid; ?>)">Borrar</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="pager">
        <div class="pinfo">
          Página <?php echo (int)$page; ?> de <?php echo (int)$totalPages; ?> — Total: <?php echo (int)$total; ?>
        </div>
        <div class="actions">
          <?php if ($prevPage): ?>
            <a class="a-btn" href="<?php echo h($mk(['page'=>$prevPage])); ?>">← Anterior</a>
          <?php endif; ?>
          <?php if ($nextPage): ?>
            <a class="a-btn" href="<?php echo h($mk(['page'=>$nextPage])); ?>">Siguiente →</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

  </main>
</div>

<script>
async function postAction(action, data = {}) {
  const fd = new FormData();
  fd.append('action', action);
  Object.entries(data).forEach(([k,v]) => fd.append(k, v));

  const res = await fetch('notificaciones_clientes.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: fd
  });

  let json = null;
  try { json = await res.json(); } catch(e) {}
  if (!res.ok || !json || !json.success) {
    throw new Error((json && json.message) ? json.message : 'Error');
  }
  return json;
}

function setBadge(unread) {
  const pills = document.querySelectorAll('.sidebar .pill.pill-info');
  if (pills && pills.length) {
    pills.forEach(p => {
      if (unread > 0) {
        p.textContent = unread;
        p.style.display = 'inline-flex';
      } else {
        p.style.display = 'none';
      }
    });
  }
  const sub = document.querySelector('.page-sub');
  if (sub) sub.innerHTML = 'Tenés <strong>' + unread + '</strong> sin leer.';
}

async function accion(action, id) {
  const card = document.getElementById('n-' + id);
  if (!card) return;

  if (action === 'delete') card.style.opacity = '0.2';

  try {
    const out = await postAction(action, { id });

    if (action === 'delete') {
      card.remove();
    } else if (action === 'mark_read') {
      card.classList.remove('unread');
      const badge = card.querySelector('.badge');
      if (badge) { badge.classList.remove('unread'); badge.textContent = 'Leída'; }
      const btn = card.querySelector('.actions .a-btn');
      if (btn) {
        btn.textContent = 'Marcar sin leer';
        btn.setAttribute('onclick', 'accion("mark_unread",' + id + ')');
      }
    } else if (action === 'mark_unread') {
      card.classList.add('unread');
      const badge = card.querySelector('.badge');
      if (badge) { badge.classList.add('unread'); badge.textContent = 'Sin leer'; }
      const btn = card.querySelector('.actions .a-btn');
      if (btn) {
        btn.textContent = 'Marcar leída';
        btn.setAttribute('onclick', 'accion("mark_read",' + id + ')');
      }
    }

    setBadge(out.unread ?? 0);

  } catch (e) {
    card.style.opacity = '1';
    alert('No se pudo completar la acción.');
  }
}

async function accionGlobal(action) {
  try {
    const out = await postAction(action);
    setBadge(out.unread ?? 0);

    if (action === 'mark_all_read') {
      document.querySelectorAll('.notif').forEach(card => {
        card.classList.remove('unread');
        const badge = card.querySelector('.badge');
        if (badge) { badge.classList.remove('unread'); badge.textContent = 'Leída'; }
        const btn = card.querySelector('.actions .a-btn');
        if (btn) {
          btn.textContent = 'Marcar sin leer';
          const id = card.id.replace('n-', '');
          btn.setAttribute('onclick', 'accion("mark_unread",' + id + ')');
        }
      });
    }

    if (action === 'delete_read') {
      document.querySelectorAll('.notif:not(.unread)').forEach(card => card.remove());
    }

  } catch (e) {
    alert('No se pudo completar la acción.');
  }
}
</script>

</body>
</html>
