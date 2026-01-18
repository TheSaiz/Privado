<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/backend/connection.php';

/* ===========================
   SOLO ADMIN
=========================== */
if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_rol'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit;
}

/* ===========================
   HELPERS
=========================== */
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function parseDate(string $s, string $fallback): string {
    // Espera YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    return $fallback;
}

function dayStart(string $date): string { return $date . " 00:00:00"; }
function dayEnd(string $date): string { return $date . " 23:59:59"; }

function csv_download(string $filename, array $header, array $rows): void {
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename={$filename}");
    header("Pragma: no-cache");
    header("Expires: 0");

    $out = fopen("php://output", "w");
    // BOM para Excel
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

/* ===========================
   FILTROS (por día)
=========================== */
$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-30 days'));

$fromDate = parseDate($_GET['desde'] ?? '', $defaultFrom);
$toDate   = parseDate($_GET['hasta'] ?? '', $today);

$fromDT = dayStart($fromDate);
$toDT   = dayEnd($toDate);

// filtros extra
$asesorId = isset($_GET['asesor_id']) && ctype_digit((string)$_GET['asesor_id']) ? (int)$_GET['asesor_id'] : 0;
$deptoId  = isset($_GET['depto_id']) && ctype_digit((string)$_GET['depto_id']) ? (int)$_GET['depto_id'] : 0;

/* ===========================
   LISTAS PARA SELECTS
=========================== */
$asesores = $pdo->query("SELECT id, CONCAT(nombre,' ',IFNULL(apellido,'')) AS nombre FROM usuarios WHERE rol='asesor' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$deptos   = $pdo->query("SELECT id, nombre FROM departamentos ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

/* ===========================
   QUERIES (KPIs + Breakdown)
   Nota: tus tablas reales: usuarios, clientes_detalles, prestamos, chats, mensajes, prestamos_pagos
=========================== */

// 1) Usuarios (clientes/asesores/admin) total + periodo
$stmt = $pdo->prepare("
    SELECT
        SUM(rol='cliente') AS total_clientes,
        SUM(rol='asesor')  AS total_asesores,
        SUM(rol='admin')   AS total_admins,
        SUM(rol='cliente' AND fecha_registro BETWEEN :fromDT AND :toDT) AS clientes_periodo,
        SUM(rol='asesor' AND fecha_registro BETWEEN :fromDT AND :toDT)  AS asesores_periodo
    FROM usuarios
");
$stmt->execute([':fromDT'=>$fromDT, ':toDT'=>$toDT]);
$k_users = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_clientes'=>0,'total_asesores'=>0,'total_admins'=>0,'clientes_periodo'=>0,'asesores_periodo'=>0];

// 2) Clientes_detalles (validación, docs, email verificado) total + periodo (por docs_updated_at y/o ultima_actualizacion)
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_detalles,
        SUM(email_verificado=1) AS email_verificados,
        SUM(docs_completos=1) AS docs_completos,
        SUM(estado_validacion='aprobado') AS val_aprobados,
        SUM(estado_validacion='rechazado') AS val_rechazados,
        SUM(estado_validacion='en_revision') AS val_en_revision,
        SUM(estado_validacion='pendiente') AS val_pendientes
    FROM clientes_detalles
");
$stmt->execute();
$k_cli = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// 3) Préstamos KPIs por período (fecha_solicitud) + filtros asesor
$paramsPrest = [':fromDT'=>$fromDT, ':toDT'=>$toDT];
$wherePrest = "p.fecha_solicitud BETWEEN :fromDT AND :toDT";
if ($asesorId > 0) { $wherePrest .= " AND p.asesor_id = :asesorId"; $paramsPrest[':asesorId']=$asesorId; }

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_prestamos,
        SUM(p.estado='pendiente') AS st_pendiente,
        SUM(p.estado='en_revision') AS st_en_revision,
        SUM(p.estado='aprobado') AS st_aprobado,
        SUM(p.estado='rechazado') AS st_rechazado,
        SUM(p.estado='activo') AS st_activo,
        SUM(p.estado='finalizado') AS st_finalizado,
        SUM(p.estado='cancelado') AS st_cancelado,

        COALESCE(SUM(p.monto_solicitado),0) AS suma_monto_solicitado,
        COALESCE(SUM(p.monto_ofrecido),0) AS suma_monto_ofrecido,
        COALESCE(SUM(p.monto_total),0) AS suma_monto_total
    FROM prestamos p
    WHERE {$wherePrest}
");
$stmt->execute($paramsPrest);
$k_prest = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Breakdown de préstamos por estado (periodo + filtro asesor)
$stmt = $pdo->prepare("
    SELECT p.estado, COUNT(*) total
    FROM prestamos p
    WHERE {$wherePrest}
    GROUP BY p.estado
    ORDER BY total DESC
");
$stmt->execute($paramsPrest);
$prest_by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4) Chats KPIs por período (fecha_inicio) + filtros depto/asesor
$paramsChat = [':fromDT'=>$fromDT, ':toDT'=>$toDT];
$whereChat = "c.fecha_inicio BETWEEN :fromDT AND :toDT";
if ($asesorId > 0) { $whereChat .= " AND c.asesor_id = :asesorId"; $paramsChat[':asesorId']=$asesorId; }
if ($deptoId > 0)  { $whereChat .= " AND c.departamento_id = :deptoId"; $paramsChat[':deptoId']=$deptoId; }

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_chats,
        COUNT(DISTINCT c.cliente_id) AS clientes_unicos_chat,
        SUM(c.estado='pendiente') AS ch_pendiente,
        SUM(c.estado='esperando_asesor') AS ch_esperando,
        SUM(c.estado='en_conversacion') AS ch_conversacion,
        SUM(c.estado='cerrado') AS ch_cerrado
    FROM chats c
    WHERE {$whereChat}
");
$stmt->execute($paramsChat);
$k_chat = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Chats por departamento
$stmt = $pdo->prepare("
    SELECT d.nombre AS departamento, COUNT(*) total
    FROM chats c
    INNER JOIN departamentos d ON d.id = c.departamento_id
    WHERE {$whereChat}
    GROUP BY d.id
    ORDER BY total DESC
");
$stmt->execute($paramsChat);
$chats_by_depto = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Chats por estado
$stmt = $pdo->prepare("
    SELECT c.estado, COUNT(*) total
    FROM chats c
    WHERE {$whereChat}
    GROUP BY c.estado
    ORDER BY total DESC
");
$stmt->execute($paramsChat);
$chats_by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5) Mensajes (por período usando fecha del mensaje, join chats para filtros)
$paramsMsg = [':fromDT'=>$fromDT, ':toDT'=>$toDT];
$whereMsg = "m.fecha BETWEEN :fromDT AND :toDT";
$joinMsgFilters = "";
if ($asesorId > 0 || $deptoId > 0) {
    $joinMsgFilters = " INNER JOIN chats c ON c.id = m.chat_id ";
    $extra = [];
    if ($asesorId > 0) { $extra[] = "c.asesor_id = :asesorId"; $paramsMsg[':asesorId']=$asesorId; }
    if ($deptoId > 0)  { $extra[] = "c.departamento_id = :deptoId"; $paramsMsg[':deptoId']=$deptoId; }
    if ($extra) $whereMsg .= " AND " . implode(" AND ", $extra);
}

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_mensajes,
        SUM(m.emisor='cliente') AS mensajes_cliente,
        SUM(m.emisor='asesor') AS mensajes_asesor
    FROM mensajes m
    {$joinMsgFilters}
    WHERE {$whereMsg}
");
$stmt->execute($paramsMsg);
$k_msg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// 6) Pagos préstamos (prestamos_pagos) por período (vencimiento) + filtro asesor (join prestamos)
$paramsPag = [':fromDT'=>$fromDT, ':toDT'=>$toDT];
$wherePag = "pp.fecha_vencimiento BETWEEN :fromDT AND :toDT";
$joinPag  = "INNER JOIN prestamos p ON p.id = pp.prestamo_id";
if ($asesorId > 0) { $wherePag .= " AND p.asesor_id = :asesorId"; $paramsPag[':asesorId']=$asesorId; }

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_cuotas,
        SUM(pp.estado='pendiente') AS cuotas_pendientes,
        SUM(pp.estado='pagado') AS cuotas_pagadas,
        SUM(pp.estado='rechazado') AS cuotas_rechazadas,
        SUM(pp.estado='en_revision') AS cuotas_en_revision,

        SUM(pp.estado='pendiente' AND pp.fecha_vencimiento < NOW()) AS cuotas_vencidas,

        COALESCE(SUM(pp.monto),0) AS suma_monto_cuotas,
        COALESCE(SUM(CASE WHEN pp.estado='pagado' THEN pp.monto ELSE 0 END),0) AS suma_pagado,
        COALESCE(SUM(CASE WHEN pp.estado='pendiente' THEN pp.monto ELSE 0 END),0) AS suma_pendiente
    FROM prestamos_pagos pp
    {$joinPag}
    WHERE {$wherePag}
");
$stmt->execute($paramsPag);
$k_pag = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* ===========================
   DESCARGAS CSV (por sección)
=========================== */
if (isset($_GET['action']) && $_GET['action'] === 'csv') {
    $section = $_GET['section'] ?? '';

    // Armamos URLs con filtros: desde/hasta/asesor/depto
    // Descargas por sección:
    if ($section === 'usuarios_clientes') {
        $stmt = $pdo->prepare("
            SELECT id, nombre, apellido, email, telefono, fecha_registro, estado
            FROM usuarios
            WHERE rol='cliente' AND fecha_registro BETWEEN :fromDT AND :toDT
            ORDER BY fecha_registro DESC
        ");
        $stmt->execute([':fromDT'=>$fromDT, ':toDT'=>$toDT]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [$r['id'],$r['nombre'],$r['apellido'],$r['email'],$r['telefono'],$r['fecha_registro'],$r['estado']];
        }
        csv_download("clientes_{$fromDate}_{$toDate}.csv", ['ID','Nombre','Apellido','Email','Teléfono','Fecha Registro','Estado'], $rows);
    }

    if ($section === 'prestamos') {
        $sqlList = "
            SELECT
                p.id,
                p.estado,
                p.fecha_solicitud,
                p.cliente_id,
                CONCAT(u.nombre,' ',IFNULL(u.apellido,'')) AS cliente_nombre,
                p.asesor_id,
                CONCAT(a.nombre,' ',IFNULL(a.apellido,'')) AS asesor_nombre,
                p.monto_solicitado,
                p.monto_ofrecido,
                p.monto_total,
                p.cuotas_solicitadas,
                p.cuotas_ofrecidas
            FROM prestamos p
            LEFT JOIN usuarios u ON u.id = p.cliente_id
            LEFT JOIN usuarios a ON a.id = p.asesor_id
            WHERE {$wherePrest}
            ORDER BY p.fecha_solicitud DESC
        ";
        $stmt = $pdo->prepare($sqlList);
        $stmt->execute($paramsPrest);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                $r['id'], $r['estado'], $r['fecha_solicitud'],
                $r['cliente_id'], $r['cliente_nombre'],
                $r['asesor_id'], $r['asesor_nombre'],
                $r['monto_solicitado'], $r['monto_ofrecido'], $r['monto_total'],
                $r['cuotas_solicitadas'], $r['cuotas_ofrecidas']
            ];
        }
        csv_download("prestamos_{$fromDate}_{$toDate}.csv",
            ['ID','Estado','Fecha','Cliente ID','Cliente','Asesor ID','Asesor','Monto Solicitado','Monto Ofrecido','Monto Total','Cuotas Sol.','Cuotas Of.'],
            $rows
        );
    }

    if ($section === 'chats') {
        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.estado,
                c.fecha_inicio,
                c.fecha_cierre,
                c.cliente_id,
                CONCAT(u.nombre,' ',IFNULL(u.apellido,'')) AS cliente,
                c.asesor_id,
                CONCAT(a.nombre,' ',IFNULL(a.apellido,'')) AS asesor,
                d.nombre AS departamento,
                c.prioridad,
                c.es_cliente_aprobado
            FROM chats c
            LEFT JOIN usuarios u ON u.id = c.cliente_id
            LEFT JOIN usuarios a ON a.id = c.asesor_id
            INNER JOIN departamentos d ON d.id = c.departamento_id
            WHERE {$whereChat}
            ORDER BY c.fecha_inicio DESC
        ");
        $stmt->execute($paramsChat);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                $r['id'], $r['estado'], $r['fecha_inicio'], $r['fecha_cierre'],
                $r['cliente_id'], $r['cliente'],
                $r['asesor_id'], $r['asesor'],
                $r['departamento'], $r['prioridad'],
                (int)$r['es_cliente_aprobado']
            ];
        }
        csv_download("chats_{$fromDate}_{$toDate}.csv",
            ['Chat ID','Estado','Fecha Inicio','Fecha Cierre','Cliente ID','Cliente','Asesor ID','Asesor','Departamento','Prioridad','Cliente Aprobado'],
            $rows
        );
    }

    if ($section === 'pagos') {
        $stmt = $pdo->prepare("
            SELECT
                pp.id,
                pp.prestamo_id,
                pp.cuota_num,
                pp.estado,
                pp.fecha_vencimiento,
                pp.fecha_pago_real,
                pp.monto,
                p.asesor_id,
                CONCAT(a.nombre,' ',IFNULL(a.apellido,'')) AS asesor
            FROM prestamos_pagos pp
            INNER JOIN prestamos p ON p.id = pp.prestamo_id
            LEFT JOIN usuarios a ON a.id = p.asesor_id
            WHERE {$wherePag}
            ORDER BY pp.fecha_vencimiento DESC
        ");
        $stmt->execute($paramsPag);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                $r['id'],$r['prestamo_id'],$r['cuota_num'],$r['estado'],
                $r['fecha_vencimiento'],$r['fecha_pago_real'],$r['monto'],
                $r['asesor_id'],$r['asesor']
            ];
        }
        csv_download("pagos_{$fromDate}_{$toDate}.csv",
            ['ID','Prestamo ID','Cuota #','Estado','Vence','Pago Real','Monto','Asesor ID','Asesor'],
            $rows
        );
    }

    // Si piden sección no contemplada:
    http_response_code(400);
    echo "Sección CSV inválida.";
    exit;
}

/* ===========================
   DATA PARA CHARTS (simple)
=========================== */
$jsPrestStatusLabels = array_map(fn($r)=>$r['estado'], $prest_by_status);
$jsPrestStatusData   = array_map(fn($r)=>(int)$r['total'], $prest_by_status);

$jsChatStatusLabels = array_map(fn($r)=>$r['estado'], $chats_by_status);
$jsChatStatusData   = array_map(fn($r)=>(int)$r['total'], $chats_by_status);

$jsChatDeptoLabels = array_map(fn($r)=>$r['departamento'], $chats_by_depto);
$jsChatDeptoData   = array_map(fn($r)=>(int)$r['total'], $chats_by_depto);

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reportes - Préstamo Líder</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
</head>

<body class="bg-gray-50">
<div class="flex min-h-screen">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="flex-1 ml-64 p-8">
        <div class="max-w-7xl mx-auto">

            <!-- Header -->
            <div class="flex items-center justify-between gap-4 flex-wrap mb-6">
                <div>
                    <h1 class="text-3xl font-extrabold text-gray-800">📈 Reportes del Sistema</h1>
                    <p class="text-gray-500 mt-1">Filtrá por día y descargá cada sección en CSV.</p>
                </div>
            </div>

            <!-- Filtros -->
            <form method="get" class="bg-white rounded-2xl shadow p-5 mb-8">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Desde</label>
                        <input type="date" name="desde" value="<?= h($fromDate) ?>"
                               class="mt-1 w-full rounded-xl border border-gray-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-200">
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Hasta</label>
                        <input type="date" name="hasta" value="<?= h($toDate) ?>"
                               class="mt-1 w-full rounded-xl border border-gray-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-200">
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Asesor</label>
                        <select name="asesor_id"
                                class="mt-1 w-full rounded-xl border border-gray-200 px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-200">
                            <option value="0">Todos</option>
                            <?php foreach ($asesores as $a): ?>
                                <option value="<?= (int)$a['id'] ?>" <?= ($asesorId === (int)$a['id']) ? 'selected' : '' ?>>
                                    <?= h($a['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Departamento (Chats)</label>
                        <select name="depto_id"
                                class="mt-1 w-full rounded-xl border border-gray-200 px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-200">
                            <option value="0">Todos</option>
                            <?php foreach ($deptos as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= ($deptoId === (int)$d['id']) ? 'selected' : '' ?>>
                                    <?= h($d['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mt-4 flex gap-3 flex-wrap">
                    <button class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded-xl shadow transition">
                        <span class="material-icons-outlined text-[20px]">filter_alt</span>
                        Aplicar filtros
                    </button>

                    <a href="reportes.php"
                       class="inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold px-4 py-2 rounded-xl transition">
                        <span class="material-icons-outlined text-[20px]">restart_alt</span>
                        Reset
                    </a>

                    <div class="text-sm text-gray-500 flex items-center">
                        Mostrando: <span class="font-semibold text-gray-700 ml-1"><?= h($fromDate) ?></span>
                        <span class="mx-2">→</span>
                        <span class="font-semibold text-gray-700"><?= h($toDate) ?></span>
                    </div>
                </div>
            </form>

            <!-- KPIs -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-white rounded-2xl shadow p-5">
                    <div class="text-gray-500 text-sm">Clientes (Total / Periodo)</div>
                    <div class="text-2xl font-extrabold text-gray-800 mt-1">
                        <?= (int)$k_users['total_clientes'] ?> <span class="text-sm font-semibold text-gray-400">/ <?= (int)$k_users['clientes_periodo'] ?></span>
                    </div>
                </div>
                <div class="bg-white rounded-2xl shadow p-5">
                    <div class="text-gray-500 text-sm">Préstamos (Periodo)</div>
                    <div class="text-2xl font-extrabold text-gray-800 mt-1"><?= (int)($k_prest['total_prestamos'] ?? 0) ?></div>
                    <div class="text-xs text-gray-500 mt-1">Aprob: <?= (int)($k_prest['st_aprobado'] ?? 0) ?> · Rech: <?= (int)($k_prest['st_rechazado'] ?? 0) ?></div>
                </div>
                <div class="bg-white rounded-2xl shadow p-5">
                    <div class="text-gray-500 text-sm">Chats (Periodo)</div>
                    <div class="text-2xl font-extrabold text-gray-800 mt-1"><?= (int)($k_chat['total_chats'] ?? 0) ?></div>
                    <div class="text-xs text-gray-500 mt-1">Clientes únicos: <?= (int)($k_chat['clientes_unicos_chat'] ?? 0) ?></div>
                </div>
                <div class="bg-white rounded-2xl shadow p-5">
                    <div class="text-gray-500 text-sm">Mensajes (Periodo)</div>
                    <div class="text-2xl font-extrabold text-gray-800 mt-1"><?= (int)($k_msg['total_mensajes'] ?? 0) ?></div>
                    <div class="text-xs text-gray-500 mt-1">Cliente: <?= (int)($k_msg['mensajes_cliente'] ?? 0) ?> · Asesor: <?= (int)($k_msg['mensajes_asesor'] ?? 0) ?></div>
                </div>
            </div>

            <!-- Secciones -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-10">
                <!-- Prestamos -->
                <section class="bg-white rounded-2xl shadow p-6">
                    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
                        <h2 class="text-xl font-extrabold text-gray-800">💸 Préstamos (Periodo)</h2>
                        <a class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-4 py-2 rounded-xl shadow transition"
                           href="reportes.php?action=csv&section=prestamos&desde=<?= h($fromDate) ?>&hasta=<?= h($toDate) ?>&asesor_id=<?= (int)$asesorId ?>&depto_id=<?= (int)$deptoId ?>">
                            <span class="material-icons-outlined text-[20px]">download</span> CSV Préstamos
                        </a>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Pendiente</div>
                            <div class="text-lg font-bold"><?= (int)($k_prest['st_pendiente'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">En revisión</div>
                            <div class="text-lg font-bold"><?= (int)($k_prest['st_en_revision'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Aprobado</div>
                            <div class="text-lg font-bold"><?= (int)($k_prest['st_aprobado'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Rechazado</div>
                            <div class="text-lg font-bold"><?= (int)($k_prest['st_rechazado'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Activo</div>
                            <div class="text-lg font-bold"><?= (int)($k_prest['st_activo'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Finalizado</div>
                            <div class="text-lg font-bold"><?= (int)($k_prest['st_finalizado'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Cancelado</div>
                            <div class="text-lg font-bold"><?= (int)($k_prest['st_cancelado'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Monto Solicitado (Σ)</div>
                            <div class="text-lg font-bold">$<?= number_format((float)($k_prest['suma_monto_solicitado'] ?? 0), 2, ',', '.') ?></div>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-xl p-3">
                        <canvas id="prestamosStatusChart" height="120"></canvas>
                    </div>
                </section>

                <!-- Chats -->
                <section class="bg-white rounded-2xl shadow p-6">
                    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
                        <h2 class="text-xl font-extrabold text-gray-800">💬 Chats (Periodo)</h2>
                        <a class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-xl shadow transition"
                           href="reportes.php?action=csv&section=chats&desde=<?= h($fromDate) ?>&hasta=<?= h($toDate) ?>&asesor_id=<?= (int)$asesorId ?>&depto_id=<?= (int)$deptoId ?>">
                            <span class="material-icons-outlined text-[20px]">download</span> CSV Chats
                        </a>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Pendiente</div>
                            <div class="text-lg font-bold"><?= (int)($k_chat['ch_pendiente'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Esperando asesor</div>
                            <div class="text-lg font-bold"><?= (int)($k_chat['ch_esperando'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">En conversación</div>
                            <div class="text-lg font-bold"><?= (int)($k_chat['ch_conversacion'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Cerrado</div>
                            <div class="text-lg font-bold"><?= (int)($k_chat['ch_cerrado'] ?? 0) ?></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-sm font-semibold text-gray-700 mb-2">Chats por estado</div>
                            <canvas id="chatsStatusChart" height="160"></canvas>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-sm font-semibold text-gray-700 mb-2">Chats por departamento</div>
                            <canvas id="chatsDeptoChart" height="160"></canvas>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Clientes / Validación -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-10">
                <section class="bg-white rounded-2xl shadow p-6">
                    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
                        <h2 class="text-xl font-extrabold text-gray-800">👤 Clientes / Validación (Global)</h2>
                        <a class="inline-flex items-center gap-2 bg-slate-700 hover:bg-slate-800 text-white font-semibold px-4 py-2 rounded-xl shadow transition"
                           href="reportes.php?action=csv&section=usuarios_clientes&desde=<?= h($fromDate) ?>&hasta=<?= h($toDate) ?>&asesor_id=<?= (int)$asesorId ?>&depto_id=<?= (int)$deptoId ?>">
                            <span class="material-icons-outlined text-[20px]">download</span> CSV Clientes (Periodo)
                        </a>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Email verificado</div>
                            <div class="text-lg font-bold"><?= (int)($k_cli['email_verificados'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Docs completos</div>
                            <div class="text-lg font-bold"><?= (int)($k_cli['docs_completos'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Validación aprobada</div>
                            <div class="text-lg font-bold"><?= (int)($k_cli['val_aprobados'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Validación rechazada</div>
                            <div class="text-lg font-bold"><?= (int)($k_cli['val_rechazados'] ?? 0) ?></div>
                        </div>

                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">En revisión</div>
                            <div class="text-lg font-bold"><?= (int)($k_cli['val_en_revision'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Pendiente</div>
                            <div class="text-lg font-bold"><?= (int)($k_cli['val_pendientes'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Asesores (Total)</div>
                            <div class="text-lg font-bold"><?= (int)$k_users['total_asesores'] ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Admins (Total)</div>
                            <div class="text-lg font-bold"><?= (int)$k_users['total_admins'] ?></div>
                        </div>
                    </div>
                </section>

                <!-- Pagos -->
                <section class="bg-white rounded-2xl shadow p-6">
                    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
                        <h2 class="text-xl font-extrabold text-gray-800">🧾 Pagos / Cuotas (Periodo)</h2>
                        <a class="inline-flex items-center gap-2 bg-amber-600 hover:bg-amber-700 text-white font-semibold px-4 py-2 rounded-xl shadow transition"
                           href="reportes.php?action=csv&section=pagos&desde=<?= h($fromDate) ?>&hasta=<?= h($toDate) ?>&asesor_id=<?= (int)$asesorId ?>&depto_id=<?= (int)$deptoId ?>">
                            <span class="material-icons-outlined text-[20px]">download</span> CSV Pagos
                        </a>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Total cuotas</div>
                            <div class="text-lg font-bold"><?= (int)($k_pag['total_cuotas'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Pagadas</div>
                            <div class="text-lg font-bold"><?= (int)($k_pag['cuotas_pagadas'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Pendientes</div>
                            <div class="text-lg font-bold"><?= (int)($k_pag['cuotas_pendientes'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Vencidas</div>
                            <div class="text-lg font-bold text-red-600"><?= (int)($k_pag['cuotas_vencidas'] ?? 0) ?></div>
                        </div>

                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Monto cuotas (Σ)</div>
                            <div class="text-lg font-bold">$<?= number_format((float)($k_pag['suma_monto_cuotas'] ?? 0), 2, ',', '.') ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Pagado (Σ)</div>
                            <div class="text-lg font-bold text-emerald-700">$<?= number_format((float)($k_pag['suma_pagado'] ?? 0), 2, ',', '.') ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Pendiente (Σ)</div>
                            <div class="text-lg font-bold text-amber-700">$<?= number_format((float)($k_pag['suma_pendiente'] ?? 0), 2, ',', '.') ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">Rechazadas</div>
                            <div class="text-lg font-bold"><?= (int)($k_pag['cuotas_rechazadas'] ?? 0) ?></div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Accesos rápidos -->
            <div class="bg-white rounded-2xl shadow p-6">
                <h2 class="text-xl font-extrabold text-gray-800 mb-4">⬇️ Descargas rápidas (con filtros aplicados)</h2>
                <div class="flex flex-wrap gap-3">
                    <a class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 font-semibold text-slate-800"
                       href="reportes.php?action=csv&section=usuarios_clientes&desde=<?= h($fromDate) ?>&hasta=<?= h($toDate) ?>&asesor_id=<?= (int)$asesorId ?>&depto_id=<?= (int)$deptoId ?>">Clientes</a>

                    <a class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 font-semibold text-slate-800"
                       href="reportes.php?action=csv&section=prestamos&desde=<?= h($fromDate) ?>&hasta=<?= h($toDate) ?>&asesor_id=<?= (int)$asesorId ?>&depto_id=<?= (int)$deptoId ?>">Préstamos</a>

                    <a class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 font-semibold text-slate-800"
                       href="reportes.php?action=csv&section=pagos&desde=<?= h($fromDate) ?>&hasta=<?= h($toDate) ?>&asesor_id=<?= (int)$asesorId ?>&depto_id=<?= (int)$deptoId ?>">Pagos</a>

                    <a class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 font-semibold text-slate-800"
                       href="reportes.php?action=csv&section=chats&desde=<?= h($fromDate) ?>&hasta=<?= h($toDate) ?>&asesor_id=<?= (int)$asesorId ?>&depto_id=<?= (int)$deptoId ?>">Chats</a>
                </div>
            </div>

            <div class="h-10"></div>
        </div>
    </main>
</div>

<script>
/* ===========================
   CHARTS
=========================== */
const prestLabels = <?= json_encode($jsPrestStatusLabels, JSON_UNESCAPED_UNICODE) ?>;
const prestData   = <?= json_encode($jsPrestStatusData) ?>;

new Chart(document.getElementById('prestamosStatusChart'), {
    type: 'bar',
    data: {
        labels: prestLabels,
        datasets: [{ data: prestData }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

const chatStatusLabels = <?= json_encode($jsChatStatusLabels, JSON_UNESCAPED_UNICODE) ?>;
const chatStatusData   = <?= json_encode($jsChatStatusData) ?>;

new Chart(document.getElementById('chatsStatusChart'), {
    type: 'doughnut',
    data: {
        labels: chatStatusLabels,
        datasets: [{ data: chatStatusData }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } }
    }
});

const chatDeptLabels = <?= json_encode($jsChatDeptoLabels, JSON_UNESCAPED_UNICODE) ?>;
const chatDeptData   = <?= json_encode($jsChatDeptoData) ?>;

new Chart(document.getElementById('chatsDeptoChart'), {
    type: 'bar',
    data: {
        labels: chatDeptLabels,
        datasets: [{ data: chatDeptData }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});
</script>
</body>
</html>
