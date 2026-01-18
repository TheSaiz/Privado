<?php
session_start();
require_once __DIR__ . '/backend/connection.php';
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['usuario_rol']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor'])) {
    header('Location: login.php');
    exit;
}
date_default_timezone_set('America/Argentina/Buenos_Aires');
function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 0, ',', '.'); }
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $id = (int)($_POST['pago_id'] ?? 0);
    if ($id > 0) {
        if ($accion === 'aprobar') {
            $stmt = $pdo->prepare("UPDATE prestamos_pagos SET estado='pagado', fecha_pago_real=NOW(), metodo_pago='Aprobado', aprobado_por=?, aprobado_fecha=NOW() WHERE id=?");
            $stmt->execute([$_SESSION['usuario_id'], $id]);
            $msg = 'Pago aprobado correctamente';
        } 
        elseif ($accion === 'rechazar' && !empty($_POST['motivo'])) {
            $stmt = $pdo->prepare("UPDATE prestamos_pagos SET comprobante=NULL, metodo_pago=NULL, rechazado_por=?, rechazado_fecha=NOW(), motivo_rechazo=? WHERE id=?");
            $stmt->execute([$_SESSION['usuario_id'], $_POST['motivo'], $id]);
            $msg = 'Pago rechazado correctamente';
        }
    }
}
$filtro = $_GET['f'] ?? 'pendientes';
$buscar = trim($_GET['buscar'] ?? '');
$fecha_desde = $_GET['desde'] ?? '';
$fecha_hasta = $_GET['hasta'] ?? '';
$sql = "SELECT pp.*, cd.nombre_completo, cd.email, cd.dni, p.cuotas_ofrecidas 
        FROM prestamos_pagos pp 
        JOIN prestamos p ON p.id=pp.prestamo_id 
        JOIN clientes c ON c.id=p.cliente_id
        JOIN clientes_detalles cd ON cd.usuario_id=c.usuario_id
        WHERE pp.comprobante IS NOT NULL";
$params = [];
if ($filtro === 'pendientes') $sql .= " AND pp.estado='pendiente'";
elseif ($filtro === 'aprobados') $sql .= " AND pp.estado='pagado'";
elseif ($filtro === 'rechazados') $sql .= " AND pp.rechazado_fecha IS NOT NULL";
if ($buscar) {
    $sql .= " AND (cd.nombre_completo LIKE ? OR cd.dni LIKE ? OR cd.email LIKE ?)";
    $params[] = "%$buscar%";
    $params[] = "%$buscar%";
    $params[] = "%$buscar%";
}
if ($fecha_desde) {
    $sql .= " AND pp.fecha_vencimiento >= ?";
    $params[] = $fecha_desde;
}
if ($fecha_hasta) {
    $sql .= " AND pp.fecha_vencimiento <= ?";
    $params[] = $fecha_hasta;
}
$sql .= " ORDER BY pp.fecha_vencimiento DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pagos = $stmt->fetchAll();
$stats = $pdo->query("SELECT COUNT(*) t, SUM(CASE WHEN estado='pendiente' AND comprobante IS NOT NULL THEN 1 ELSE 0 END) p, SUM(CASE WHEN estado='pagado' THEN monto ELSE 0 END) m FROM prestamos_pagos")->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Administración de Pagos</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
<style>
.card-hover{transition:all 0.3s ease;}
.card-hover:hover{transform:translateY(-4px);box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04);}
.badge-pulse{animation:pulse 2s cubic-bezier(0.4,0,0.6,1) infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
.gradient-blue{background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);}
.gradient-green{background:linear-gradient(135deg, #f093fb 0%, #f5576c 100%);}
.gradient-orange{background:linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);}
</style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
<?php include 'sidebar.php'; ?>
<div class="ml-64 p-8">
<div class="mb-8">
<div class="flex items-center justify-between">
<div>
<h1 class="text-4xl font-bold text-gray-800 flex items-center">
<span class="material-icons-outlined text-blue-600 mr-3" style="font-size:40px">account_balance_wallet</span>
Administración de Pagos
</h1>
<p class="text-gray-500 mt-2 ml-14">Gestión completa de comprobantes y aprobación de pagos</p>
</div>
<div class="text-right">
<div class="text-sm text-gray-500">Última actualización</div>
<div class="text-lg font-semibold text-gray-700"><?= date('d/m/Y H:i') ?></div>
</div>
</div>
</div>
<?php if($msg): ?>
<div class="bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 text-green-800 p-4 mb-6 rounded-lg shadow-md animate-fade-in">
<div class="flex items-center">
<span class="material-icons-outlined mr-3 text-green-600">check_circle</span>
<span class="font-semibold"><?= h($msg) ?></span>
</div>
</div>
<?php endif; ?>
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
<div class="bg-white rounded-2xl shadow-lg p-6 card-hover border-l-4 border-yellow-400">
<div class="flex items-center justify-between">
<div>
<p class="text-gray-500 text-sm font-medium mb-1">Pagos Pendientes</p>
<p class="text-4xl font-bold text-yellow-600 mb-2"><?= $stats['p'] ?></p>
<p class="text-xs text-gray-400">Requieren revisión</p>
</div>
<div class="bg-yellow-50 p-4 rounded-xl">
<span class="material-icons-outlined text-yellow-500 badge-pulse" style="font-size:48px">pending_actions</span>
</div>
</div>
</div>
<div class="bg-white rounded-2xl shadow-lg p-6 card-hover border-l-4 border-blue-400">
<div class="flex items-center justify-between">
<div>
<p class="text-gray-500 text-sm font-medium mb-1">Total Pagos</p>
<p class="text-4xl font-bold text-blue-600 mb-2"><?= $stats['t'] ?></p>
<p class="text-xs text-gray-400">Con comprobante</p>
</div>
<div class="bg-blue-50 p-4 rounded-xl">
<span class="material-icons-outlined text-blue-500" style="font-size:48px">receipt_long</span>
</div>
</div>
</div>
<div class="bg-white rounded-2xl shadow-lg p-6 card-hover border-l-4 border-green-400">
<div class="flex items-center justify-between">
<div>
<p class="text-gray-500 text-sm font-medium mb-1">Monto Aprobado</p>
<p class="text-4xl font-bold text-green-600 mb-2">$<?= money($stats['m']) ?></p>
<p class="text-xs text-gray-400">Total histórico</p>
</div>
<div class="bg-green-50 p-4 rounded-xl">
<span class="material-icons-outlined text-green-500" style="font-size:48px">paid</span>
</div>
</div>
</div>
</div>
<div class="bg-white rounded-2xl shadow-lg p-6 mb-6">
<div class="flex flex-wrap gap-3 items-end">
<div class="flex gap-2">
<a href="?f=pendientes" class="px-5 py-2.5 rounded-xl font-semibold transition flex items-center gap-2 <?= $filtro==='pendientes'?'bg-gradient-to-r from-blue-600 to-blue-700 text-white shadow-lg':'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
<span class="material-icons-outlined text-sm">schedule</span> Pendientes
</a>
<a href="?f=aprobados" class="px-5 py-2.5 rounded-xl font-semibold transition flex items-center gap-2 <?= $filtro==='aprobados'?'bg-gradient-to-r from-green-600 to-green-700 text-white shadow-lg':'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
<span class="material-icons-outlined text-sm">check_circle</span> Aprobados
</a>
<a href="?f=rechazados" class="px-5 py-2.5 rounded-xl font-semibold transition flex items-center gap-2 <?= $filtro==='rechazados'?'bg-gradient-to-r from-red-600 to-red-700 text-white shadow-lg':'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
<span class="material-icons-outlined text-sm">cancel</span> Rechazados
</a>
</div>
<div class="flex-1"></div>
<form method="GET" class="flex gap-3 items-end">
<input type="hidden" name="f" value="<?= h($filtro) ?>">
<div>
<label class="block text-xs font-semibold text-gray-600 mb-1">Buscar (DNI, Nombre, Email)</label>
<input type="text" name="buscar" value="<?= h($buscar) ?>" placeholder="Buscar..." class="px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent w-64">
</div>
<div>
<label class="block text-xs font-semibold text-gray-600 mb-1">Desde</label>
<input type="date" name="desde" value="<?= h($fecha_desde) ?>" class="px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent">
</div>
<div>
<label class="block text-xs font-semibold text-gray-600 mb-1">Hasta</label>
<input type="date" name="hasta" value="<?= h($fecha_hasta) ?>" class="px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent">
</div>
<button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-xl font-semibold hover:shadow-lg transition flex items-center gap-2">
<span class="material-icons-outlined text-sm">search</span> Buscar
</button>
<?php if($buscar || $fecha_desde || $fecha_hasta): ?>
<a href="?f=<?= h($filtro) ?>" class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-xl font-semibold hover:bg-gray-200 transition flex items-center gap-2">
<span class="material-icons-outlined text-sm">close</span> Limpiar
</a>
<?php endif; ?>
</form>
</div>
</div>
<div class="bg-white rounded-2xl shadow-lg overflow-hidden">
<?php if(empty($pagos)): ?>
<div class="p-16 text-center">
<div class="bg-gray-50 rounded-full w-32 h-32 flex items-center justify-center mx-auto mb-6">
<span class="material-icons-outlined text-gray-300" style="font-size:80px">inbox</span>
</div>
<p class="text-gray-700 text-xl font-semibold mb-2">No hay pagos en esta categoría</p>
<p class="text-gray-400">Los comprobantes subidos por clientes aparecerán aquí para su revisión</p>
</div>
<?php else: ?>
<table class="w-full">
<thead class="bg-gradient-to-r from-gray-50 to-gray-100 border-b-2 border-gray-200">
<tr>
<th class="px-6 py-4 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">Cliente</th>
<th class="px-6 py-4 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">DNI</th>
<th class="px-6 py-4 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">Cuota</th>
<th class="px-6 py-4 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">Monto</th>
<th class="px-6 py-4 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">Vencimiento</th>
<th class="px-6 py-4 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">Estado</th>
<th class="px-6 py-4 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">Acciones</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php foreach($pagos as $p): 
$vencido = strtotime($p['fecha_vencimiento']) < time() && $p['estado'] === 'pendiente';
?>
<tr class="hover:bg-blue-50 transition-colors <?= $vencido ? 'bg-red-50' : '' ?>">
<td class="px-6 py-4">
<div class="flex items-center">
<div class="bg-blue-100 rounded-full w-10 h-10 flex items-center justify-center mr-3">
<span class="material-icons-outlined text-blue-600 text-sm">person</span>
</div>
<div>
<div class="font-bold text-gray-900"><?= h($p['nombre_completo']) ?></div>
<div class="text-sm text-gray-500"><?= h($p['email']) ?></div>
</div>
</div>
</td>
<td class="px-6 py-4 text-gray-700 font-mono font-semibold"><?= h($p['dni']) ?></td>
<td class="px-6 py-4">
<span class="inline-flex items-center px-3 py-1 bg-indigo-100 text-indigo-800 rounded-full text-sm font-semibold">
<span class="material-icons-outlined text-xs mr-1">description</span>
<?= $p['cuota_num'] ?> / <?= $p['cuotas_ofrecidas'] ?>
</span>
</td>
<td class="px-6 py-4">
<div class="text-xl font-bold text-gray-900">$<?= money($p['monto']) ?></div>
</td>
<td class="px-6 py-4">
<div class="text-sm font-semibold text-gray-700"><?= date('d/m/Y', strtotime($p['fecha_vencimiento'])) ?></div>
<?php if($vencido): ?>
<div class="text-xs text-red-600 font-bold mt-1">⚠ Vencido</div>
<?php endif; ?>
</td>
<td class="px-6 py-4">
<?php if($p['estado']==='pagado'): ?>
<span class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-green-100 to-emerald-100 text-green-800 rounded-xl text-sm font-bold shadow-sm">
<span class="material-icons-outlined text-sm mr-2">check_circle</span> Aprobado
</span>
<?php elseif($p['rechazado_fecha']): ?>
<span class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-red-100 to-rose-100 text-red-800 rounded-xl text-sm font-bold shadow-sm">
<span class="material-icons-outlined text-sm mr-2">cancel</span> Rechazado
</span>
<?php else: ?>
<span class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-yellow-100 to-amber-100 text-yellow-800 rounded-xl text-sm font-bold shadow-sm">
<span class="material-icons-outlined text-sm mr-2">schedule</span> Pendiente
</span>
<?php endif; ?>
</td>
<td class="px-6 py-4">
<div class="flex gap-2">
<a href="<?= h($p['comprobante']) ?>" target="_blank" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm font-semibold shadow-md hover:shadow-lg">
<span class="material-icons-outlined text-sm mr-1">visibility</span> Ver
</a>
<?php if($p['estado']==='pendiente' && !$p['rechazado_fecha']): ?>
<button onclick="aprobar(<?= $p['id'] ?>)" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-sm font-semibold shadow-md hover:shadow-lg">
<span class="material-icons-outlined text-sm mr-1">check</span> Aprobar
</button>
<button onclick="rechazar(<?= $p['id'] ?>)" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition text-sm font-semibold shadow-md hover:shadow-lg">
<span class="material-icons-outlined text-sm mr-1">close</span> Rechazar
</button>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>
<div id="modal" class="hidden fixed inset-0 bg-black bg-opacity-60 backdrop-blur-sm flex items-center justify-center z-50">
<div class="bg-white rounded-2xl p-8 max-w-lg w-full mx-4 shadow-2xl transform transition-all">
<div class="flex items-center mb-6">
<div class="bg-red-100 rounded-full p-3 mr-4">
<span class="material-icons-outlined text-red-600 text-3xl">error_outline</span>
</div>
<h3 class="text-2xl font-bold text-gray-800">Rechazar Comprobante</h3>
</div>
<form method="POST">
<input type="hidden" name="accion" value="rechazar">
<input type="hidden" name="pago_id" id="pid">
<label class="block text-sm font-bold text-gray-700 mb-3">Motivo del rechazo *</label>
<textarea name="motivo" required rows="5" class="w-full border-2 border-gray-300 rounded-xl p-4 mb-6 focus:ring-4 focus:ring-red-200 focus:border-red-500 transition" placeholder="Explica detalladamente por qué se rechaza este comprobante..."></textarea>
<div class="flex gap-4">
<button type="button" onclick="cerrar()" class="flex-1 px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 font-bold transition">
Cancelar
</button>
<button type="submit" class="flex-1 px-6 py-3 bg-gradient-to-r from-red-600 to-red-700 text-white rounded-xl hover:shadow-lg font-bold transition">
Rechazar Pago
</button>
</div>
</form>
</div>
</div>
<script>
function aprobar(id){
if(!confirm('¿Confirmar la aprobación de este pago?')) return;
const f=document.createElement('form');
f.method='POST';
f.innerHTML='<input name="accion" value="aprobar"><input name="pago_id" value="'+id+'">';
document.body.appendChild(f);
f.submit();
}
function rechazar(id){
document.getElementById('pid').value=id;
document.getElementById('modal').classList.remove('hidden');
}
function cerrar(){
document.getElementById('modal').classList.add('hidden');
}
</script>
</body>
</html>