<?php
session_start();

if (!isset($_SESSION['usuario_id'], $_SESSION['usuario_rol'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['usuario_rol'], ['admin','asesor'], true)) {
    header("Location: login.php");
    exit;
}

require_once 'backend/connection.php';

$usuario_id  = (int)$_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];

// =========================
// FILTROS DE FECHA
// =========================
$filtro = $_GET['filtro'] ?? 'todas'; // CAMBIO: por defecto "todas"
$whereFecha = '';

switch ($filtro) {
    case 'hoy':
        $whereFecha = "AND DATE(cd.docs_updated_at) = CURDATE()";
        break;
    case 'ayer':
        $whereFecha = "AND DATE(cd.docs_updated_at) = DATE(NOW() - INTERVAL 1 DAY)";
        break;
    case 'semana':
        $whereFecha = "AND cd.docs_updated_at >= NOW() - INTERVAL 7 DAY";
        break;
    case 'mes':
        $whereFecha = "AND cd.docs_updated_at >= NOW() - INTERVAL 30 DAY";
        break;
    case 'todas':
    default:
        $whereFecha = ""; // Sin filtro de fecha
}

// =========================
// QUERY
// =========================
$sql = "
SELECT
    cd.id,
    cd.usuario_id         AS cliente_id,
    cd.nombre_completo    AS nombre,
    cd.dni,
    cd.cuit,
    u.email,
    cd.telefono,
    cd.banco,
    cd.cbu,
    cd.tipo_ingreso,
    cd.monto_ingresos,
    cd.docs_updated_at,
    cd.estado_validacion,
    cd.doc_dni_frente,
    cd.doc_dni_dorso,
    cd.doc_comprobante_ingresos
FROM clientes_detalles cd
INNER JOIN usuarios u ON u.id = cd.usuario_id
WHERE cd.estado_validacion = 'rechazado'
    $whereFecha
ORDER BY cd.docs_updated_at DESC
LIMIT 500
";

try {
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
    error_log("Error en solicitudes_rechazadas: " . $e->getMessage());
}

// Estadística total
$total = count($rows);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Solicitudes Rechazadas</title>
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
  }

  .badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
  }

  .badge.rechazado {
    background: #fee2e2;
    color: #991b1b;
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

  .thumb {
    width: 52px;
    height: 52px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    cursor: pointer;
    background: #fff;
  }

  .thumb:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(0,0,0,.08);
  }
</style>
</head>

<body>

<?php
if ($usuario_rol === 'admin') {
    include 'sidebar.php';
} else {
    include 'sidebar_asesores.php';
}
?>

<div class="page-content">

  <!-- Header -->
  <div class="mb-8">
    <div class="flex justify-between items-center flex-wrap gap-4">
      <div>
        <div class="flex items-center gap-2 mb-2">
          <a href="solicitudes.php" class="text-gray-500 hover:text-gray-700">
            <span class="material-icons-outlined">arrow_back</span>
          </a>
          <h1 class="text-3xl font-bold text-gray-800">Solicitudes Rechazadas</h1>
        </div>
        <p class="text-gray-600">Historial de solicitudes rechazadas</p>
      </div>

      <form method="get" class="flex gap-2">
        <select name="filtro" onchange="this.form.submit()"
          class="border rounded-lg px-4 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="todas" <?= $filtro==='todas'?'selected':'' ?>>Todas</option>
          <option value="hoy" <?= $filtro==='hoy'?'selected':'' ?>>Hoy</option>
          <option value="ayer" <?= $filtro==='ayer'?'selected':'' ?>>Ayer</option>
          <option value="semana" <?= $filtro==='semana'?'selected':'' ?>>Últimos 7 días</option>
          <option value="mes" <?= $filtro==='mes'?'selected':'' ?>>Últimos 30 días</option>
        </select>
      </form>
    </div>
  </div>

  <!-- Estadística -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="stat-card">
      <div class="text-4xl mb-2">❌</div>
      <div class="text-3xl font-bold text-red-600 mb-1"><?php echo $total; ?></div>
      <div class="text-sm text-gray-600">
        <?php 
        switch($filtro) {
          case 'hoy': echo 'Rechazadas hoy'; break;
          case 'ayer': echo 'Rechazadas ayer'; break;
          case 'semana': echo 'Últimos 7 días'; break;
          case 'mes': echo 'Últimos 30 días'; break;
          default: echo 'Total de rechazadas';
        }
        ?>
      </div>
    </div>
  </div>

  <!-- Tabla -->
  <div class="bg-white rounded-2xl shadow overflow-hidden border border-gray-100">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Cliente</th>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Contacto</th>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Datos Financieros</th>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Documentos</th>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Fecha Rechazo</th>
            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Estado</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="6" class="text-center py-12">
                <span class="material-icons-outlined text-gray-300" style="font-size: 4rem;">cancel</span>
                <div class="text-gray-500 mt-3">No hay solicitudes rechazadas
                  <?php 
                  if ($filtro !== 'todas') {
                    echo ' para el período seleccionado';
                  }
                  ?>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-6 py-4">
                <div class="font-semibold text-gray-900"><?= htmlspecialchars($r['nombre'] ?? 'Sin nombre') ?></div>
                <div class="text-xs text-gray-500">DNI: <?= htmlspecialchars($r['dni'] ?? '-') ?></div>
                <?php if (!empty($r['cuit'])): ?>
                <div class="text-xs text-gray-500">CUIT: <?= htmlspecialchars($r['cuit']) ?></div>
                <?php endif; ?>
              </td>

              <td class="px-6 py-4">
                <div class="text-sm text-gray-900">
                  <span class="material-icons-outlined text-xs align-middle">phone</span>
                  <?= htmlspecialchars($r['telefono'] ?? '-') ?>
                </div>
                <div class="text-xs text-gray-500">
                  <span class="material-icons-outlined text-xs align-middle">email</span>
                  <?= htmlspecialchars($r['email'] ?? '-') ?>
                </div>
              </td>

              <td class="px-6 py-4">
                <div class="text-sm text-gray-900">
                  <span class="font-semibold">Banco:</span> <?= htmlspecialchars($r['banco'] ?? '-') ?>
                </div>
                <?php if (!empty($r['tipo_ingreso'])): ?>
                <div class="text-xs text-gray-500 mt-1">
                  Ingreso: <?= htmlspecialchars(ucfirst($r['tipo_ingreso'])) ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($r['monto_ingresos'])): ?>
                <div class="text-xs text-gray-500">
                  $<?= number_format($r['monto_ingresos'], 0, ',', '.') ?>
                </div>
                <?php endif; ?>
              </td>

              <td class="px-6 py-4">
                <div class="flex gap-2 flex-wrap items-center">
                  <?php if (!empty($r['doc_dni_frente'])): ?>
                    <img src="<?= htmlspecialchars($r['doc_dni_frente']) ?>" 
                         class="thumb" 
                         onclick="window.open('<?= htmlspecialchars($r['doc_dni_frente']) ?>', '_blank')" 
                         title="DNI Frente">
                  <?php endif; ?>
                  
                  <?php if (!empty($r['doc_dni_dorso'])): ?>
                    <img src="<?= htmlspecialchars($r['doc_dni_dorso']) ?>" 
                         class="thumb" 
                         onclick="window.open('<?= htmlspecialchars($r['doc_dni_dorso']) ?>', '_blank')" 
                         title="DNI Dorso">
                  <?php endif; ?>
                  
                  <?php if (!empty($r['doc_comprobante_ingresos'])): ?>
                    <img src="<?= htmlspecialchars($r['doc_comprobante_ingresos']) ?>" 
                         class="thumb" 
                         onclick="window.open('<?= htmlspecialchars($r['doc_comprobante_ingresos']) ?>', '_blank')" 
                         title="Comprobante ingresos">
                  <?php endif; ?>

                  <?php if (empty($r['doc_dni_frente']) && empty($r['doc_dni_dorso']) && empty($r['doc_comprobante_ingresos'])): ?>
                    <span class="text-gray-400 text-xs">Sin documentos</span>
                  <?php endif; ?>
                </div>
              </td>

              <td class="px-6 py-4">
                <?php if (!empty($r['docs_updated_at'])): ?>
                  <div class="text-sm text-gray-900">
                    <?= date('d/m/Y', strtotime($r['docs_updated_at'])) ?>
                  </div>
                  <div class="text-xs text-gray-500">
                    <?= date('H:i', strtotime($r['docs_updated_at'])) ?>hs
                  </div>
                <?php else: ?>
                  <span class="text-gray-400">-</span>
                <?php endif; ?>
              </td>

              <td class="px-6 py-4">
                <span class="badge rechazado">
                  ✗ Rechazado
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (!empty($rows)): ?>
  <div class="mt-4 text-center text-sm text-gray-500">
    Mostrando <?php echo count($rows); ?> registro(s)
  </div>
  <?php endif; ?>

</div>

</body>
</html>