<?php
session_start();

if (!isset($_SESSION['usuario_id'], $_SESSION['usuario_rol'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['usuario_rol'], ['admin','asesor'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/connection.php';

$usuario_rol = $_SESSION['usuario_rol'];

// ====================================================================
// ESTADÍSTICAS DE SOLICITUDES (SIN FILTROS DE FECHA)
// ====================================================================
$stats_solicitudes = [
    'pendientes' => 0,
    'aprobadas' => 0,
    'rechazadas' => 0,
];

try {
    // Pendientes (documentación completa pero sin aprobar/rechazar)
    $stmt = $pdo->query("
        SELECT COUNT(*) as total
        FROM clientes_detalles cd
        WHERE cd.doc_dni_frente IS NOT NULL 
        AND cd.doc_dni_frente != ''
        AND cd.doc_dni_dorso IS NOT NULL 
        AND cd.doc_dni_dorso != ''
        AND cd.doc_comprobante_ingresos IS NOT NULL 
        AND cd.doc_comprobante_ingresos != ''
        AND cd.estado_validacion NOT IN ('aprobado', 'rechazado')
    ");
    $stats_solicitudes['pendientes'] = $stmt->fetchColumn();

    // Aprobadas (TODAS, sin importar fecha)
    $stmt = $pdo->query("
        SELECT COUNT(*) as total
        FROM clientes_detalles cd
        WHERE cd.estado_validacion = 'aprobado'
    ");
    $stats_solicitudes['aprobadas'] = $stmt->fetchColumn();

    // Rechazadas (TODAS, sin importar fecha)
    $stmt = $pdo->query("
        SELECT COUNT(*) as total
        FROM clientes_detalles cd
        WHERE cd.estado_validacion = 'rechazado'
    ");
    $stats_solicitudes['rechazadas'] = $stmt->fetchColumn();

} catch (Exception $e) {
    // En caso de error, mantener en 0
    error_log("Error en estadísticas de solicitudes: " . $e->getMessage());
}

// Debug opcional (comentar en producción si no se necesita)
$show_debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$debug_info = [];

if ($show_debug) {
    try {
        $stmt = $pdo->query("
            SELECT estado_validacion, COUNT(*) as total
            FROM clientes_detalles
            GROUP BY estado_validacion
        ");
        $debug_info = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $debug_info = ['error' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Solicitudes</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

<style>
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
    background: #f8fafc;
  }

  .page-content {
    margin-left: 260px;
    padding: 2rem;
  }

  @media (max-width: 768px) {
    .page-content {
      margin-left: 0 !important;
      padding: 1rem;
    }
  }

  .stat-card {
    background: white;
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    text-align: center;
    transition: transform 0.2s;
  }

  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  }

  .stat-value {
    font-size: 2.5rem;
    font-weight: bold;
    margin: 0.5rem 0;
  }

  .stat-label {
    font-size: 0.875rem;
    color: #6b7280;
    margin-bottom: 0.75rem;
  }

  .btn {
    padding: 0.5rem 1rem;
    border-radius: 0.5rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    border: none;
    text-decoration: none;
  }

  .btn-primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
  }

  .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
  }

  .btn-success {
    background: #22c55e;
    color: white;
  }

  .btn-danger {
    background: #ef4444;
    color: white;
  }

  .thumb{
    width:52px;height:52px;object-fit:cover;border-radius:10px;
    border:1px solid #e5e7eb;cursor:pointer;background:#fff;
  }
  .thumb:hover{ transform: translateY(-1px); box-shadow: 0 8px 18px rgba(0,0,0,.08); }
  .modal-scroll{ max-height: calc(90vh - 120px); overflow:auto; }
</style>
</head>

<body>

<?php
// Sidebar dinámico
if ($usuario_rol === 'admin') {
    include 'sidebar.php';
} else {
    include 'sidebar_asesores.php';
}
?>

<div class="page-content">

  <!-- Header -->
  <div class="mb-8">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Solicitudes</h1>
        <p class="text-gray-600">Gestión de solicitudes de préstamos</p>
      </div>
      <div class="flex items-center gap-2 text-sm text-gray-600">
        <span class="inline-flex items-center gap-2 px-3 py-2 bg-white rounded-lg border">
          <span class="w-2 h-2 rounded-full bg-green-500"></span>
          <span>Actualización automática</span>
        </span>
      </div>
    </div>
  </div>

  <?php if ($show_debug): ?>
  <!-- Panel de Debug -->
  <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6">
    <h3 class="font-bold text-yellow-800 mb-2">🔍 Modo Debug - Estados en BD:</h3>
    <pre class="text-xs text-yellow-900"><?php print_r($debug_info); ?></pre>
    <p class="text-xs text-yellow-700 mt-2">Para ocultar: quita ?debug=1 de la URL</p>
  </div>
  <?php endif; ?>

  <!-- Estadísticas -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="stat-card">
      <div class="text-4xl mb-2">⏳</div>
      <div class="stat-value text-yellow-600"><?php echo $stats_solicitudes['pendientes']; ?></div>
      <div class="stat-label">Pendientes de Revisión</div>
    </div>

    <div class="stat-card">
      <div class="text-4xl mb-2">✅</div>
      <div class="stat-value text-green-600"><?php echo $stats_solicitudes['aprobadas']; ?></div>
      <div class="stat-label">Aprobadas (Total)</div>
      <a href="solicitudes_aprobadas.php" class="btn btn-success text-sm mt-2">
        <span class="material-icons-outlined text-sm">visibility</span>
        Ver todas
      </a>
    </div>

    <div class="stat-card">
      <div class="text-4xl mb-2">❌</div>
      <div class="stat-value text-red-600"><?php echo $stats_solicitudes['rechazadas']; ?></div>
      <div class="stat-label">Rechazadas (Total)</div>
      <a href="solicitudes_rechazadas.php" class="btn btn-danger text-sm mt-2">
        <span class="material-icons-outlined text-sm">visibility</span>
        Ver todas
      </a>
    </div>
  </div>

  <div class="bg-white rounded-2xl shadow overflow-hidden border border-gray-100">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Cliente</th>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Contacto</th>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Documentación</th>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Estado</th>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Score</th>
            <th class="px-6 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Acciones</th>
          </tr>
        </thead>
        <tbody id="tablaSolicitudes" class="divide-y"></tbody>
      </table>
    </div>
  </div>

</div>

<!-- MODAL DOCUMENTO (imagen/pdf) -->
<div id="docModal" class="fixed inset-0 bg-black/70 hidden items-center justify-center z-50">
  <div class="bg-white rounded-2xl max-w-4xl w-full mx-4 p-4 relative">
    <button type="button" onclick="cerrarModal('docModal')" class="absolute top-3 right-3 text-gray-500 hover:text-black">
      <span class="material-icons-outlined">close</span>
    </button>
    <div id="docModalContent" class="text-center"></div>
  </div>
</div>

<!-- MODAL VER (detalle completo) -->
<div id="verModal" class="fixed inset-0 bg-black/70 hidden items-center justify-center z-50">
  <div class="bg-white rounded-2xl max-w-5xl w-full mx-4 relative shadow-2xl border border-gray-100">
    <div class="p-5 border-b flex items-start justify-between gap-4">
      <div>
        <div class="text-xs text-gray-500">Detalle de solicitud</div>
        <div id="verTitulo" class="text-xl font-bold text-gray-900">Solicitud</div>
        <div id="verSub" class="text-sm text-gray-600 mt-1"></div>
      </div>
      <button type="button" onclick="cerrarModal('verModal')" class="text-gray-500 hover:text-black">
        <span class="material-icons-outlined">close</span>
      </button>
    </div>

    <div class="p-5 modal-scroll">
      <div id="verContenido"></div>
    </div>

    <div class="p-5 border-t flex items-center justify-between gap-2">
      <div class="text-xs text-gray-500">Acciones rápidas</div>
      <div class="flex items-center gap-2">
        <button type="button" onclick="cerrarModal('verModal')" class="px-4 py-2 rounded-lg border bg-white hover:bg-gray-50 text-gray-700 text-sm">
          Cerrar
        </button>
      </div>
    </div>
  </div>
</div>

<script>
/* =========================
   STATE GLOBAL (PROD SAFE)
========================= */
let SOLICITUDES = [];

/* =========================
   HELPERS
========================= */
function esc(s){
  return String(s ?? '')
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}
function badgeEstado(estado){
  const e = String(estado ?? 'pendiente').toLowerCase();
  if (e === 'aprobado') return 'bg-green-100 text-green-700';
  if (e === 'rechazado') return 'bg-red-100 text-red-700';
  if (e === 'analisis') return 'bg-sky-100 text-sky-700';
  if (e === 'documentacion') return 'bg-amber-100 text-amber-700';
  if (e === 'contactado') return 'bg-indigo-100 text-indigo-700';
  if (e === 'nuevo') return 'bg-blue-100 text-blue-700';
  return 'bg-yellow-100 text-yellow-700';
}
function fmt(v, fallback='—'){
  const s = String(v ?? '').trim();
  return s ? esc(s) : fallback;
}

/* =========================
   DOC MODAL
========================= */
function abrirDoc(url){
  const u = String(url || '');
  const c = document.getElementById('docModalContent');
  c.innerHTML = '';

  if (!u) return;

  const isPdf = u.toLowerCase().includes('.pdf');

  if (isPdf) {
    c.innerHTML = `
      <div class="text-left mb-3">
        <div class="text-sm font-semibold text-gray-800">Vista previa (PDF)</div>
        <div class="text-xs text-gray-500 break-all">${esc(u)}</div>
      </div>
      <iframe src="${esc(u)}" class="w-full h-[75vh] rounded-xl border"></iframe>
      <div class="mt-3 flex items-center justify-center">
        <a href="${esc(u)}" download class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
          <span class="material-icons-outlined" style="font-size:18px">download</span>
          Descargar PDF
        </a>
      </div>
    `;
  } else {
    c.innerHTML = `
      <div class="text-left mb-3">
        <div class="text-sm font-semibold text-gray-800">Vista previa</div>
        <div class="text-xs text-gray-500 break-all">${esc(u)}</div>
      </div>
      <img src="${esc(u)}" class="max-h-[75vh] mx-auto rounded-xl border bg-white" alt="Documento">
      <div class="mt-3 flex items-center justify-center">
        <a href="${esc(u)}" download class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
          <span class="material-icons-outlined" style="font-size:18px">download</span>
          Descargar
        </a>
      </div>
    `;
  }

  abrirModal('docModal');
}

/* =========================
   MODALS
========================= */
function abrirModal(id){
  cerrarModal('docModal');
  cerrarModal('verModal');

  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('hidden');
  el.classList.add('flex');
}

function cerrarModal(id){
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add('hidden');
  el.classList.remove('flex');
}
document.addEventListener('keydown', (e)=>{
  if (e.key === 'Escape') {
    cerrarModal('docModal');
    cerrarModal('verModal');
  }
});
document.getElementById('docModal').addEventListener('click', (e)=>{
  if (e.target.id === 'docModal') cerrarModal('docModal');
});
document.getElementById('verModal').addEventListener('click', (e)=>{
  if (e.target.id === 'verModal') cerrarModal('verModal');
});

/* =========================
   VER MODAL (detalle PRO)
========================= */
function verDetalle(idx){
  const s = SOLICITUDES[idx];
  if (!s) return;

  const estado = String(s.estado ?? 'pendiente');

  const docs = [];
  if (s.doc_dni_frente) docs.push({label:'DNI Frente', url:s.doc_dni_frente});
  if (s.doc_dni_dorso) docs.push({label:'DNI Dorso', url:s.doc_dni_dorso});
  if (s.doc_selfie_dni) docs.push({label:'Selfie con DNI', url:s.doc_selfie_dni});

  const docsHtml = docs.length ? `
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      ${docs.map(d=>`
        <div class="rounded-xl border bg-white p-3">
          <div class="text-xs font-semibold text-gray-600 mb-2">${esc(d.label)}</div>
          <div class="flex items-center gap-3">
            <img src="${esc(d.url)}" class="thumb" alt="${esc(d.label)}" onclick="abrirDoc('${esc(d.url)}')">
            <div class="min-w-0">
              <div class="text-xs text-gray-500 break-all">${esc(d.url)}</div>
              <div class="mt-2 flex gap-2">
                <button type="button" class="px-3 py-1.5 rounded-lg bg-gray-900 text-white text-xs hover:bg-black"
                        onclick="abrirDoc('${esc(d.url)}')">
                  Ver
                </button>
                <a href="${esc(d.url)}" download class="px-3 py-1.5 rounded-lg border text-xs hover:bg-gray-50">
                  Descargar
                </a>
              </div>
            </div>
          </div>
        </div>
      `).join('')}
    </div>
  ` : `<div class="text-sm text-gray-500">No hay documentos para mostrar.</div>`;

  const html = `
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="rounded-2xl border bg-white p-4">
        <div class="flex items-center gap-2 mb-3">
          <span class="material-icons-outlined text-blue-600">person</span>
          <div class="font-bold text-gray-900">Datos personales</div>
        </div>
        <div class="space-y-2 text-sm">
          <div><span class="text-gray-500">DNI:</span> <span class="font-semibold text-gray-900">${fmt(s.dni)}</span></div>
          <div><span class="text-gray-500">Nombre:</span> <span class="font-semibold text-gray-900">${fmt(s.nombre)}</span></div>
          <div><span class="text-gray-500">CUIT:</span> <span class="font-semibold text-gray-900">${fmt(s.cuit)}</span></div>
        </div>
      </div>

      <div class="rounded-2xl border bg-white p-4">
        <div class="flex items-center gap-2 mb-3">
          <span class="material-icons-outlined text-green-600">contact_phone</span>
          <div class="font-bold text-gray-900">Información de contacto</div>
        </div>
        <div class="space-y-2 text-sm">
          <div><span class="text-gray-500">Teléfono:</span> <span class="font-semibold text-gray-900">${fmt(s.cod_area) ? '(' + fmt(s.cod_area) + ') ' : ''}${fmt(s.telefono)}</span></div>
          <div><span class="text-gray-500">Email:</span> <span class="font-semibold text-gray-900">${fmt(s.email)}</span></div>
        </div>
      </div>
    </div>

    <div class="rounded-2xl border bg-white p-4 mt-4">
      <div class="flex items-center gap-2 mb-3">
        <span class="material-icons-outlined text-purple-600">description</span>
        <div class="font-bold text-gray-900">Documentación</div>
      </div>
      ${docsHtml}
    </div>

    <div class="rounded-2xl border bg-white p-4 mt-4">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="text-sm text-gray-600">
          <span class="font-semibold text-gray-900">Estado actual:</span>
          <span class="px-3 py-1 rounded-full text-xs font-semibold ${badgeEstado(estado)}">
            ${esc(estado)}
          </span>
        </div>

        <div class="flex gap-2">
          <button type="button"
            class="px-4 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700 text-sm inline-flex items-center gap-2"
            onclick="accion(${Number(s.chat_id ?? 0)}, 'aprobado', ${Number(s.cliente_id ?? 0)})">
            <span class="material-icons-outlined" style="font-size:18px">check_circle</span>
            Aprobar
          </button>

          <button type="button"
            class="px-4 py-2 rounded-lg bg-red-600 text-white hover:bg-red-700 text-sm inline-flex items-center gap-2"
            onclick="accion(${Number(s.chat_id ?? 0)}, 'rechazado', ${Number(s.cliente_id ?? 0)})">
            <span class="material-icons-outlined" style="font-size:18px">cancel</span>
            Rechazar
          </button>
        </div>
      </div>

      ${Number(s.chat_id ?? 0) <= 0 ? `
        <div class="mt-3 text-xs text-blue-700 bg-blue-50 border border-blue-100 p-3 rounded-xl">
          ℹ️ Esta solicitud no tiene conversación asociada.<br>
          El cambio de estado se aplicará directamente al cliente y
          <b>se le permitirá continuar en su panel sin volver a cargar documentación</b>.
        </div>
      ` : ''}
    </div>
  `;

  document.getElementById('verContenido').innerHTML = html;
  abrirModal('verModal');
}

/* =========================
   FETCH + RENDER
========================= */
async function cargarSolicitudes(){
  try {
    const r = await fetch('api/solicitudes_list.php', { cache:'no-store' });
    const data = await r.json();

    SOLICITUDES = Array.isArray(data) ? data : [];

    const tbody = document.getElementById('tablaSolicitudes');
    tbody.innerHTML = '';

    if (!SOLICITUDES.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" class="py-10 text-center text-gray-500">
            No hay solicitudes pendientes de revisión
          </td>
        </tr>`;
      return;
    }

    SOLICITUDES.forEach((s, idx) => {
      const estado = String(s.estado ?? 'pendiente');

      const docs = [];
      if (s.doc_dni_frente) docs.push(`<img src="${esc(s.doc_dni_frente)}" class="thumb" onclick="abrirDoc('${esc(s.doc_dni_frente)}')" title="DNI Frente">`);
      if (s.doc_dni_dorso)  docs.push(`<img src="${esc(s.doc_dni_dorso)}" class="thumb" onclick="abrirDoc('${esc(s.doc_dni_dorso)}')" title="DNI Dorso">`);
      if (s.doc_comprobante_ingresos) docs.push(`<img src="${esc(s.doc_comprobante_ingresos)}" class="thumb" onclick="abrirDoc('${esc(s.doc_comprobante_ingresos)}')" title="Comprobante de ingresos">`);

      const chatId = Number(s.chat_id ?? 0);

      tbody.innerHTML += `
        <tr class="hover:bg-gray-50">
          <td class="px-6 py-4">
            <div class="font-semibold text-gray-900">${fmt(s.nombre, 'Cliente')}</div>
            <div class="text-xs text-gray-500">DNI ${fmt(s.dni)}</div>
          </td>

          <td class="px-6 py-4">
            <div class="text-sm text-gray-900">${fmt(s.telefono)}</div>
            <div class="text-xs text-gray-500">${fmt(s.email)}</div>
          </td>

          <td class="px-6 py-4">
            <div class="flex gap-2 flex-wrap items-center">
              ${docs.join('') || '<span class="text-gray-400">—</span>'}
            </div>
          </td>

          <td class="px-6 py-4">
            <span class="px-3 py-1 rounded-full text-xs font-semibold ${badgeEstado(estado)}">
              ${esc(estado)}
            </span>
          </td>

          <td class="px-6 py-4">
            <span class="font-semibold text-gray-900">${esc(s.score ?? '-')}</span>
          </td>

          <td class="px-6 py-4 text-center">
            <div class="flex items-center justify-center gap-2 flex-wrap">
              <button type="button"
                class="px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm inline-flex items-center gap-1"
                onclick="verDetalle(${idx})">
                <span class="material-icons-outlined" style="font-size:18px">visibility</span>
                Ver
              </button>

              <button type="button"
                class="px-3 py-1.5 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm inline-flex items-center gap-1"
                onclick="accion(${chatId}, 'aprobado', ${Number(s.cliente_id ?? 0)})">
                <span class="material-icons-outlined" style="font-size:18px">check_circle</span>
                Aprobar
              </button>

              <button type="button"
                class="px-3 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm inline-flex items-center gap-1"
                onclick="accion(${chatId}, 'rechazado', ${Number(s.cliente_id ?? 0)})">
                <span class="material-icons-outlined" style="font-size:18px">cancel</span>
                Rechazar
              </button>
            </div>
          </td>
        </tr>
      `;
    });

  } catch (e) {
    const tbody = document.getElementById('tablaSolicitudes');
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="py-10 text-center text-red-600">
          Error cargando solicitudes.
        </td>
      </tr>`;
  }
}

/* =========================
   ACCIONES
========================= */
async function accion(chat_id, estado, cliente_id){
  const id = Number(chat_id ?? 0);

  if (!confirm('¿Confirmar acción?')) return;

  try {
    const r = await fetch('api/solicitudes_estado.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        chat_id: id > 0 ? id : null,
        cliente_id: cliente_id ?? null,
        estado
      })
    });

    const j = await r.json();

    if (j && j.ok) {
      cerrarModal('verModal');
      cargarSolicitudes();
      // Recargar página para actualizar contadores
      setTimeout(() => {
        window.location.reload();
      }, 500);
    } else {
      alert(j?.error || 'No se pudo actualizar el estado');
    }

  } catch (e) {
    alert('Error de conexión');
  }
}

/* Init */
cargarSolicitudes();
setInterval(cargarSolicitudes, 3000);

</script>

</body>
</html>