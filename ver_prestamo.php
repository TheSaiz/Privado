<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor'], true)) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/backend/connection.php';

$rol        = $_SESSION['usuario_rol'] ?? '';
$es_asesor  = ($rol === 'asesor');
$usuario_id = (int)($_SESSION['usuario_id'] ?? 0);

$permisos = [
    'puede_editar_datos_cliente' => ($rol === 'admin'),
    'puede_validar_documentos'   => ($rol === 'admin'),
];

$mensaje = '';
$error   = '';

// Obtener ID y TIPO
$operacion_id = (int)($_GET['id'] ?? 0);
$tipo         = (string)($_GET['tipo'] ?? 'prestamo');

if ($operacion_id <= 0 || !in_array($tipo, ['prestamo', 'empeno', 'prendario'], true)) {
    die('Parámetros inválidos');
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ============================
// PROCESAR ACCIONES POST
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');

    try {
        $pdo->beginTransaction();

        if ($accion === 'aprobar_documento' && ($rol === 'admin' || $permisos['puede_validar_documentos'])) {
            $doc_id = (int)($_POST['documento_id'] ?? 0);
            if ($doc_id > 0) {
                $stmt = $pdo->prepare("UPDATE prestamos_documentos SET estado_validacion = 'aprobado', validado_por = ?, fecha_validacion = NOW() WHERE id = ?");
                $stmt->execute([$usuario_id, $doc_id]);
                $mensaje = '✅ Documento aprobado';
            }
        } elseif ($accion === 'rechazar_documento' && ($rol === 'admin' || $permisos['puede_validar_documentos'])) {
            $doc_id = (int)($_POST['documento_id'] ?? 0);
            $motivo = trim((string)($_POST['motivo'] ?? ''));
            if ($doc_id > 0 && $motivo !== '') {
                $stmt = $pdo->prepare("UPDATE prestamos_documentos SET estado_validacion = 'rechazado', motivo_rechazo = ?, validado_por = ?, fecha_validacion = NOW() WHERE id = ?");
                $stmt->execute([$motivo, $usuario_id, $doc_id]);
                $mensaje = '✅ Documento rechazado';
            }
        } elseif ($accion === 'validar_legajo_completo' && $tipo === 'prestamo') {
            $stmt = $pdo->prepare("UPDATE prestamos SET legajo_validado = 1, legajo_validado_por = ?, legajo_fecha_validacion = NOW(), estado = 'activo' WHERE id = ?");
            $stmt->execute([$usuario_id, $operacion_id]);
            $mensaje = '✅ Legajo validado y préstamo activado';
        }

        $pdo->commit();
        header("Location: ver_prestamo.php?id=$operacion_id&tipo=$tipo&msg=" . urlencode($mensaje));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

if (isset($_GET['msg'])) $mensaje = htmlspecialchars((string)$_GET['msg'], ENT_QUOTES, 'UTF-8');

// Determinar tabla
$tabla = match ($tipo) {
    'prestamo'   => 'prestamos',
    'empeno'     => 'empenos',
    'prendario'  => 'creditos_prendarios',
    default      => 'prestamos'
};

// ============================
// CARGAR OPERACIÓN (COMPLETA)
// ============================
try {
    $operacion   = null;
    $documentos  = [];
    $imagenes    = [];
    $historial   = [];

    // Contadores documentos (solo préstamos)
    $docs_requeridos       = ['comprobante_ingresos', 'dni'];
    $docs_tipos_aprobados  = [];
    $docs_aprobados        = 0;
    $docs_pendientes       = 0;
    $docs_rechazados       = 0;
    $legajo_completo       = false;
    $nombres_documentos    = [
        'dni' => 'DNI',
        'comprobante_ingresos' => 'Comprobante de Ingresos',
        'comprobante_domicilio' => 'Comprobante de Domicilio',
        'otro' => 'Otro Documento'
    ];

    if ($tipo === 'prestamo') {
        $stmt = $pdo->prepare("
            SELECT
                p.*,
                COALESCE(p.destino_credito, '') as destino,
                u.nombre   as cliente_nombre,
                u.apellido as cliente_apellido,
                u.email    as cliente_email,
                cd.*,
                uv.nombre  as validador_nombre
            FROM prestamos p
            INNER JOIN usuarios u ON p.cliente_id = u.id
            LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
            LEFT JOIN usuarios uv ON p.legajo_validado_por = uv.id
            WHERE p.id = ?
        ");
        $stmt->execute([$operacion_id]);
        $operacion = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($operacion) {
            $hay_contraoferta = !empty($operacion['monto_ofrecido']) && (float)$operacion['monto_ofrecido'] > 0;
            $contraoferta_aceptada = $hay_contraoferta && !empty($operacion['fecha_aceptacion_contraoferta']);

            $monto_solicitado = $operacion['monto_solicitado'] ?? $operacion['monto'] ?? null;
            $cuotas_solicitadas = $operacion['cuotas_solicitadas'] ?? $operacion['cuotas'] ?? null;
            $frecuencia_solicitada = $operacion['frecuencia_solicitada'] ?? $operacion['frecuencia_pago'] ?? null;

            if ($contraoferta_aceptada) {
                $operacion['monto_display']      = $operacion['monto_final'] ?? $operacion['monto_ofrecido'];
                $operacion['cuotas_display']     = $operacion['cuotas_final'] ?? $operacion['cuotas_ofrecidas'] ?? $cuotas_solicitadas;
                $operacion['frecuencia_display'] = $operacion['frecuencia_final'] ?? $operacion['frecuencia_ofrecida'] ?? $frecuencia_solicitada;
                $operacion['monto_total']        = $operacion['monto_total_final'] ?? $operacion['monto_total_ofrecido'] ?? $operacion['monto_total'] ?? null;
            } elseif ($hay_contraoferta && ($operacion['estado'] ?? '') === 'contraoferta') {
                $operacion['monto_display']      = $operacion['monto_ofrecido'];
                $operacion['cuotas_display']     = $operacion['cuotas_ofrecidas'] ?? $cuotas_solicitadas;
                $operacion['frecuencia_display'] = $operacion['frecuencia_ofrecida'] ?? $frecuencia_solicitada;
                $operacion['monto_total']        = $operacion['monto_total_ofrecido'] ?? $operacion['monto_total'] ?? null;
            } elseif (in_array(($operacion['estado'] ?? ''), ['aprobado', 'activo'], true) && !empty($operacion['monto_final'])) {
                $operacion['monto_display']      = $operacion['monto_final'];
                $operacion['cuotas_display']     = $operacion['cuotas_final'] ?? $cuotas_solicitadas;
                $operacion['frecuencia_display'] = $operacion['frecuencia_final'] ?? $frecuencia_solicitada;
                $operacion['monto_total']        = $operacion['monto_total_final'] ?? $operacion['monto_total'] ?? null;
            } else {
                $operacion['monto_display']      = $monto_solicitado;
                $operacion['cuotas_display']     = $cuotas_solicitadas;
                $operacion['frecuencia_display'] = $frecuencia_solicitada;
                if (empty($operacion['monto_total']) && !empty($operacion['monto_display'])) {
                    $tasa = (float)($operacion['tasa_interes'] ?? 0);
                    $operacion['monto_total'] = (float)$operacion['monto_display'] * (1 + ($tasa / 100));
                }
            }

            $stmt = $pdo->prepare("
                SELECT pd.*, u.nombre as validador_nombre
                FROM prestamos_documentos pd
                LEFT JOIN usuarios u ON pd.uploaded_by = u.id
                WHERE pd.prestamo_id = ?
                ORDER BY pd.created_at DESC
            ");
            $stmt->execute([$operacion_id]);
            $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($documentos as $doc) {
                $estado = $doc['estado_validacion'] ?? 'pendiente';
                if ($estado === 'aprobado') {
                    $docs_aprobados++;
                    $docs_tipos_aprobados[] = $doc['tipo'];
                } elseif ($estado === 'pendiente') {
                    $docs_pendientes++;
                } elseif ($estado === 'rechazado') {
                    $docs_rechazados++;
                }
            }
            $docs_tipos_aprobados = array_unique($docs_tipos_aprobados);
            $legajo_completo = count(array_intersect($docs_requeridos, $docs_tipos_aprobados)) === count($docs_requeridos);
        }

    } elseif ($tipo === 'empeno') {
        $stmt = $pdo->prepare("
            SELECT
                e.*,
                e.monto_solicitado as monto_display,
                NULL as cuotas_display,
                NULL as frecuencia_display,
                e.destino_credito as destino,
                u.nombre as cliente_nombre,
                u.apellido as cliente_apellido,
                u.email as cliente_email,
                cd.*
            FROM empenos e
            INNER JOIN usuarios u ON e.cliente_id = u.id
            LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
            WHERE e.id = ?
        ");
        $stmt->execute([$operacion_id]);
        $operacion = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($operacion) {
            $hay_contraoferta = !empty($operacion['monto_ofrecido']) && (float)$operacion['monto_ofrecido'] > 0;
            $contraoferta_aceptada = $hay_contraoferta && !empty($operacion['fecha_aceptacion_contraoferta']);

            if ($contraoferta_aceptada) {
                $operacion['monto_display'] = $operacion['monto_final'] ?? $operacion['monto_ofrecido'];
            } elseif ($hay_contraoferta && ($operacion['estado'] ?? '') === 'contraoferta') {
                $operacion['monto_display'] = $operacion['monto_ofrecido'];
            } else {
                $operacion['monto_display'] = $operacion['monto_solicitado'] ?? $operacion['monto_display'];
            }

            $stmt = $pdo->prepare("SELECT * FROM empenos_imagenes WHERE empeno_id = ? ORDER BY fecha_subida DESC");
            $stmt->execute([$operacion_id]);
            $imagenes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } elseif ($tipo === 'prendario') {
        $stmt = $pdo->prepare("
            SELECT
                cp.*,
                cp.monto_solicitado as monto_display,
                NULL as cuotas_display,
                NULL as frecuencia_display,
                cp.destino_credito as destino,
                u.nombre as cliente_nombre,
                u.apellido as cliente_apellido,
                u.email as cliente_email,
                cd.*
            FROM creditos_prendarios cp
            INNER JOIN usuarios u ON cp.cliente_id = u.id
            LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
            WHERE cp.id = ?
        ");
        $stmt->execute([$operacion_id]);
        $operacion = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($operacion) {
            $hay_contraoferta = !empty($operacion['monto_ofrecido']) && (float)$operacion['monto_ofrecido'] > 0;
            $contraoferta_aceptada = $hay_contraoferta && !empty($operacion['fecha_aceptacion_contraoferta']);

            if ($contraoferta_aceptada) {
                $operacion['monto_display'] = $operacion['monto_final'] ?? $operacion['monto_ofrecido'];
            } elseif ($hay_contraoferta && ($operacion['estado'] ?? '') === 'contraoferta') {
                $operacion['monto_display'] = $operacion['monto_ofrecido'];
            } else {
                $operacion['monto_display'] = $operacion['monto_solicitado'] ?? $operacion['monto_display'];
            }

            $stmt = $pdo->prepare("SELECT * FROM prendarios_imagenes WHERE credito_id = ? ORDER BY fecha_subida DESC");
            $stmt->execute([$operacion_id]);
            $imagenes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if (!$operacion) {
        die('Operación no encontrada');
    }

} catch (PDOException $e) {
    die('ERROR SQL: ' . $e->getMessage());
}

$tipo_display = match ($tipo) {
    'prestamo'  => 'Préstamo Personal',
    'empeno'    => 'Empeño',
    'prendario' => 'Crédito Prendario',
    default     => 'Operación'
};

$monto_cuota = 0;
if ($tipo === 'prestamo' && !empty($operacion['cuotas_display']) && (int)$operacion['cuotas_display'] > 0 && !empty($operacion['monto_total'])) {
    $monto_cuota = (float)$operacion['monto_total'] / (int)$operacion['cuotas_display'];
}

$estado_civil_texto = [
    'soltero' => 'Soltero/a',
    'casado' => 'Casado/a',
    'divorciado' => 'Divorciado/a',
    'viudo' => 'Viudo/a',
    'union_libre' => 'Unión Libre'
];

$tipo_ingreso_texto = [
    'dependencia' => 'Relación de Dependencia',
    'autonomo' => 'Autónomo',
    'monotributo' => 'Monotributista',
    'jubilacion' => 'Jubilado/Pensionado',
    'negro' => 'Trabajo Informal'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ver <?= $tipo_display ?> #<?= $operacion_id ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
<style>
.info-section {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    padding: 24px;
    margin-bottom: 24px;
}
.info-section-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e5e7eb;
}
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
}
.info-item {
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
    border-left: 3px solid #7c5cff;
}
.info-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6b7280;
    font-weight: 600;
    margin-bottom: 4px;
}
.info-value {
    font-size: 1rem;
    font-weight: 600;
    color: #1f2937;
}
.info-value.empty {
    color: #9ca3af;
    font-style: italic;
}
.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
}
.badge-success { background: #d1fae5; color: #065f46; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-danger { background: #fee2e2; color: #991b1b; }
.badge-info { background: #dbeafe; color: #1e40af; }

.solicitud-card {
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
    background: white;
}
.solicitud-card:hover {
    border-color: #3b82f6;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
}
.solicitud-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e5e7eb;
}
.solicitud-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
}
.badge-pendiente {
    background: #fef3c7;
    color: #d97706;
}
.badge-respondida {
    background: #d1fae5;
    color: #059669;
}
.mensaje-admin-box {
    background: #eff6ff;
    border-left: 4px solid #3b82f6;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 16px;
}
.respuesta-cliente-box {
    background: #f0fdf4;
    border-left: 4px solid #10b981;
    padding: 16px;
    border-radius: 8px;
    margin-top: 16px;
}
.archivo-adjunto {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: #f3f4f6;
    border-radius: 8px;
    margin-right: 8px;
    margin-bottom: 8px;
    text-decoration: none;
    color: #374151;
    font-size: 0.875rem;
    transition: all 0.2s;
    cursor: pointer;
}
.archivo-adjunto:hover {
    background: #e5e7eb;
    transform: translateY(-2px);
}
.archivo-imagen {
    position: relative;
}
.archivo-imagen::after {
    content: '🖼️';
    position: absolute;
    top: -4px;
    right: -4px;
    background: #3b82f6;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
}
.nueva-respuesta-indicator {
    display: inline-block;
    width: 8px;
    height: 8px;
    background: #ef4444;
    border-radius: 50%;
    margin-left: 8px;
    animation: pulse-indicator 2s infinite;
}
@keyframes pulse-indicator {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(1.1); }
}

/* Modal vista previa de imágenes */
.modal-imagen-preview {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.95);
    backdrop-filter: blur(8px);
}
.modal-imagen-preview.active {
    display: flex;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.3s ease;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.modal-imagen-content {
    position: relative;
    max-width: 90vw;
    max-height: 90vh;
    animation: zoomIn 0.3s ease;
}
@keyframes zoomIn {
    from {
        opacity: 0;
        transform: scale(0.8);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}
.modal-imagen-content img {
    max-width: 90vw;
    max-height: 85vh;
    object-fit: contain;
    border-radius: 8px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
.modal-imagen-header {
    position: absolute;
    top: -60px;
    left: 0;
    right: 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 20px;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-radius: 12px;
}
.modal-imagen-titulo {
    color: white;
    font-size: 1.1rem;
    font-weight: 600;
}
.modal-imagen-close {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: white;
    font-size: 2rem;
    cursor: pointer;
    padding: 4px 16px;
    border-radius: 8px;
    transition: all 0.2s;
    line-height: 1;
}
.modal-imagen-close:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: scale(1.1);
}
.modal-imagen-footer {
    position: absolute;
    bottom: -70px;
    left: 0;
    right: 0;
    display: flex;
    justify-content: center;
    gap: 12px;
    padding: 16px;
}
.btn-modal-download {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}
.btn-modal-download:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.6);
}
</style>
</head>
<body class="bg-gray-50">

<?php include $es_asesor ? 'sidebar_asesores.php' : 'sidebar.php'; ?>

<div class="ml-64">

<nav class="bg-white shadow-md sticky top-0 z-40">
  <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
    <h1 class="text-2xl font-bold text-gray-800">💼 <?= $tipo_display ?> #<?= $operacion_id ?></h1>
    <a href="prestamos_admin.php" class="text-blue-600 hover:underline flex items-center gap-2 transition duration-200">
      <span class="material-icons-outlined">arrow_back</span>
      Volver a Operaciones
    </a>
  </div>
</nav>

<div class="max-w-7xl mx-auto px-4 py-8">

<?php if ($mensaje): ?>
  <div class="bg-green-100 border border-green-400 text-green-700 px-6 py-4 rounded-lg mb-6 flex items-center gap-3 shadow-md">
    <span class="material-icons-outlined">check_circle</span>
    <?= $mensaje ?>
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="bg-red-100 border border-red-400 text-red-700 px-6 py-4 rounded-lg mb-6 flex items-center gap-3 shadow-md">
    <span class="material-icons-outlined">error</span>
    <?= h($error) ?>
  </div>
<?php endif; ?>

<!-- Resumen -->
<div class="bg-gradient-to-r from-blue-600 to-blue-800 rounded-xl shadow-lg p-8 mb-6 text-white">
  <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
    <div>
      <div class="text-blue-200 text-sm mb-1"><?= $tipo === 'prestamo' ? 'Monto Total' : 'Monto Solicitado' ?></div>
      <div class="text-4xl font-bold">
        $<?= number_format(($tipo === 'prestamo' && !empty($operacion['monto_total'])) ? (float)$operacion['monto_total'] : (float)($operacion['monto_display'] ?? 0), 0, ',', '.') ?>
      </div>
    </div>

    <?php if ($tipo === 'prestamo' && !empty($operacion['cuotas_display'])): ?>
      <div>
        <div class="text-blue-200 text-sm mb-1">Cuotas</div>
        <div class="text-4xl font-bold"><?= (int)$operacion['cuotas_display'] ?></div>
        <div class="text-blue-200 text-sm">$<?= number_format((float)$monto_cuota, 2) ?> c/u</div>
      </div>
    <?php endif; ?>

    <div>
      <div class="text-blue-200 text-sm mb-1">Estado</div>
      <div class="text-2xl font-bold">
        <?php
        $estado_texto = match ($operacion['estado'] ?? '') {
            'pendiente' => '⏳ Pendiente',
            'en_revision' => '👀 En Revisión',
            'en_evaluacion' => '🔍 En Evaluación',
            'aprobado' => '✅ Aprobado',
            'documentacion_pendiente' => '📄 Docs Pendientes',
            'documentacion_completa' => '📋 Docs Completos',
            'contraoferta' => '💼 Contraoferta',
            'activo' => '🟢 Activo',
            'rechazado' => '❌ Rechazado',
            'finalizado' => '✔️ Finalizado',
            'devuelto' => '↩️ Devuelto',
            'perdido' => '⚠️ Perdido',
            'cancelado' => '🚫 Cancelado',
            default => ucfirst((string)($operacion['estado'] ?? ''))
        };
        echo $estado_texto;
        ?>
      </div>
    </div>

    <div>
      <div class="text-blue-200 text-sm mb-1">Fecha Solicitud</div>
      <div class="text-2xl font-bold"><?= !empty($operacion['fecha_solicitud']) ? date('d/m/Y', strtotime((string)$operacion['fecha_solicitud'])) : '—' ?></div>
      <div class="text-blue-200 text-sm"><?= !empty($operacion['fecha_solicitud']) ? date('H:i', strtotime((string)$operacion['fecha_solicitud'])) . ' hs' : '' ?></div>
    </div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

<!-- Columna Izquierda (Más ancha) -->
<div class="lg:col-span-2 space-y-6">

<!-- INFORMACIÓN PERSONAL BÁSICA -->
<div class="info-section">
  <h2 class="info-section-title">
    <span class="material-icons-outlined text-blue-600">person</span>
    Información Personal Básica
  </h2>
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Nombre Completo</div>
      <div class="info-value"><?= h($operacion['nombre_completo'] ?? (($operacion['cliente_nombre'] ?? '') . ' ' . ($operacion['cliente_apellido'] ?? ''))) ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Email</div>
      <div class="info-value"><?= h($operacion['cliente_email'] ?? '') ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">DNI</div>
      <div class="info-value"><?= h($operacion['dni'] ?? 'N/A') ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">CUIL / CUIT</div>
      <div class="info-value"><?= h($operacion['cuil_cuit'] ?? 'No registrado') ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Teléfono</div>
      <div class="info-value">
        <?php
        $tel = '';
        if (!empty($operacion['cod_area'])) $tel .= '(' . $operacion['cod_area'] . ') ';
        if (!empty($operacion['telefono'])) $tel .= $operacion['telefono'];
        echo h($tel ?: 'N/A');
        ?>
      </div>
    </div>
    <div class="info-item">
      <div class="info-label">Fecha de Nacimiento</div>
      <div class="info-value">
        <?php
        echo !empty($operacion['fecha_nacimiento'])
          ? date('d/m/Y', strtotime((string)$operacion['fecha_nacimiento']))
          : '<span class="empty">No registrada</span>';
        ?>
      </div>
    </div>
  </div>
</div>

<!-- INFORMACIÓN FAMILIAR -->
<div class="info-section">
  <h2 class="info-section-title">
    <span class="material-icons-outlined text-purple-600">family_restroom</span>
    Información Familiar
  </h2>

  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Estado Civil</div>
      <div class="info-value">
        <?php
        $estado_civil = (string)($operacion['estado_civil'] ?? '');
        echo $estado_civil !== ''
          ? h($estado_civil_texto[$estado_civil] ?? ucfirst($estado_civil))
          : '<span class="empty">No registrado</span>';
        ?>
      </div>
    </div>

    <div class="info-item">
      <div class="info-label">Hijos a Cargo</div>
      <div class="info-value"><?= (int)($operacion['hijos_a_cargo'] ?? 0) ?></div>
    </div>

    <?php if (($operacion['estado_civil'] ?? '') === 'casado'): ?>
      <div class="info-item">
        <div class="info-label">DNI del Cónyuge</div>
        <div class="info-value"><?= h($operacion['dni_conyuge'] ?? 'No registrado') ?></div>
      </div>
      <div class="info-item">
        <div class="info-label">Nombre del Cónyuge</div>
        <div class="info-value"><?= h($operacion['nombre_conyuge'] ?? 'No registrado') ?></div>
      </div>
    <?php endif; ?>
  </div>

  <?php if (($operacion['estado_civil'] ?? '') === 'casado' && (empty($operacion['dni_conyuge']) || empty($operacion['nombre_conyuge']))): ?>
    <div class="mt-4 p-3 bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 text-sm">
      <strong>⚠️ Atención:</strong> Cliente casado pero faltan datos del cónyuge
    </div>
  <?php endif; ?>
</div>

<!-- DIRECCIÓN COMPLETA -->
<div class="info-section">
  <h2 class="info-section-title">
    <span class="material-icons-outlined text-green-600">home</span>
    Dirección Domicilio
  </h2>

  <div class="info-grid">
    <div class="info-item" style="grid-column: 1 / -1;">
      <div class="info-label">Calle y Número</div>
      <div class="info-value"><?= h($operacion['direccion_calle'] ?? 'No registrada') ?></div>
    </div>

    <div class="info-item">
      <div class="info-label">Piso</div>
      <div class="info-value"><?= h($operacion['direccion_piso'] ?? '-') ?></div>
    </div>

    <div class="info-item">
      <div class="info-label">Departamento</div>
      <div class="info-value"><?= h($operacion['direccion_departamento'] ?? '-') ?></div>
    </div>

    <div class="info-item">
      <div class="info-label">Barrio</div>
      <div class="info-value"><?= h($operacion['direccion_barrio'] ?? 'No registrado') ?></div>
    </div>

    <div class="info-item">
      <div class="info-label">Código Postal</div>
      <div class="info-value"><?= h($operacion['direccion_codigo_postal'] ?? ($operacion['codigo_postal'] ?? 'N/A')) ?></div>
    </div>

    <div class="info-item">
      <div class="info-label">Localidad</div>
      <div class="info-value"><?= h($operacion['direccion_localidad'] ?? ($operacion['localidad'] ?? 'No registrada')) ?></div>
    </div>

    <div class="info-item">
      <div class="info-label">Provincia</div>
      <div class="info-value"><?= h($operacion['direccion_provincia'] ?? ($operacion['provincia'] ?? 'No registrada')) ?></div>
    </div>

    <?php if (!empty($operacion['direccion_latitud']) && !empty($operacion['direccion_longitud'])): ?>
      <div class="info-item" style="grid-column: 1 / -1;">
        <div class="info-label">📍 Ubicación GPS</div>
        <div class="info-value">
          <a href="https://www.google.com/maps?q=<?= h($operacion['direccion_latitud']) ?>,<?= h($operacion['direccion_longitud']) ?>"
             target="_blank"
             class="text-blue-600 hover:underline flex items-center gap-2">
            <span class="material-icons-outlined text-sm">map</span>
            Lat: <?= number_format((float)$operacion['direccion_latitud'], 6) ?>, Lng: <?= number_format((float)$operacion['direccion_longitud'], 6) ?>
          </a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- INFORMACIÓN LABORAL -->
<div class="info-section">
  <h2 class="info-section-title">
    <span class="material-icons-outlined text-orange-600">work</span>
    Información Laboral e Ingresos
  </h2>

  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Tipo de Ingreso</div>
      <div class="info-value">
        <?php
        $tipo_ing = (string)($operacion['tipo_ingreso'] ?? '');
        echo $tipo_ing !== ''
          ? h($tipo_ingreso_texto[$tipo_ing] ?? ucfirst($tipo_ing))
          : '<span class="empty">No registrado</span>';
        ?>
      </div>
    </div>

    <div class="info-item">
      <div class="info-label">Ingresos Mensuales</div>
      <div class="info-value">
        <?php
        if (!empty($operacion['monto_ingresos'])) {
            echo '$' . number_format((float)$operacion['monto_ingresos'], 0, ',', '.');
        } else {
            echo '<span class="empty">No registrado</span>';
        }
        ?>
      </div>
    </div>

    <?php if (in_array(($operacion['tipo_ingreso'] ?? ''), ['dependencia', 'autonomo', 'monotributo'], true)): ?>
      <div class="info-item" style="grid-column: 1 / -1;">
        <div class="info-label">CUIT del Empleador</div>
        <div class="info-value"><?= h($operacion['empleador_cuit'] ?? 'No registrado') ?></div>
      </div>
      <div class="info-item" style="grid-column: 1 / -1;">
        <div class="info-label">Razón Social / Empleador</div>
        <div class="info-value"><?= h($operacion['empleador_razon_social'] ?? 'No registrado') ?></div>
      </div>
      <div class="info-item">
        <div class="info-label">Teléfono del Empleador</div>
        <div class="info-value"><?= h($operacion['empleador_telefono'] ?? 'No registrado') ?></div>
      </div>
      <div class="info-item" style="grid-column: 1 / -1;">
        <div class="info-label">Domicilio Laboral</div>
        <div class="info-value"><?= h($operacion['empleador_direccion'] ?? 'No registrado') ?></div>
      </div>
      <div class="info-item">
        <div class="info-label">Sector</div>
        <div class="info-value"><?= h($operacion['empleador_sector'] ?? 'No registrado') ?></div>
      </div>
      <div class="info-item">
        <div class="info-label">Cargo</div>
        <div class="info-value"><?= h($operacion['cargo'] ?? 'No registrado') ?></div>
      </div>
      <div class="info-item">
        <div class="info-label">Antigüedad</div>
        <div class="info-value">
          <?php
          if (!empty($operacion['antiguedad_laboral'])) {
              $antiguedad = (int)$operacion['antiguedad_laboral'];
              $anos = (int)floor($antiguedad / 12);
              $meses = $antiguedad % 12;
              echo $anos > 0 ? "$anos años" : '';
              echo $anos > 0 && $meses > 0 ? ' y ' : '';
              echo $meses > 0 ? "$meses meses" : '';
              echo " ($antiguedad meses)";
          } else {
              echo '<span class="empty">No registrada</span>';
          }
          ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <?php if (in_array(($operacion['tipo_ingreso'] ?? ''), ['dependencia', 'autonomo', 'monotributo'], true) &&
            (empty($operacion['empleador_razon_social']) || empty($operacion['cargo']) || empty($operacion['antiguedad_laboral']))): ?>
    <div class="mt-4 p-3 bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 text-sm">
      <strong>⚠️ Atención:</strong> Información laboral incompleta
    </div>
  <?php endif; ?>
</div>

<!-- CONTACTOS DE REFERENCIA -->
<?php if (!empty($operacion['contacto1_nombre']) || !empty($operacion['contacto2_nombre'])): ?>
  <div class="info-section">
    <h2 class="info-section-title">
      <span class="material-icons-outlined text-pink-600">contacts</span>
      Contactos de Referencia
    </h2>

    <div class="info-grid">
      <?php if (!empty($operacion['contacto1_nombre'])): ?>
        <div class="info-item" style="grid-column: 1 / -1;">
          <div class="info-label">Contacto 1</div>
          <div class="info-value">
            <strong><?= h($operacion['contacto1_nombre']) ?></strong>
            <?php if (!empty($operacion['contacto1_relacion'])): ?>
              <span class="text-sm text-gray-600"> - <?= h($operacion['contacto1_relacion']) ?></span>
            <?php endif; ?>
            <?php if (!empty($operacion['contacto1_telefono'])): ?>
              <span class="text-sm text-gray-600"> - Tel: <?= h($operacion['contacto1_telefono']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($operacion['contacto2_nombre'])): ?>
        <div class="info-item" style="grid-column: 1 / -1;">
          <div class="info-label">Contacto 2</div>
          <div class="info-value">
            <strong><?= h($operacion['contacto2_nombre']) ?></strong>
            <?php if (!empty($operacion['contacto2_relacion'])): ?>
              <span class="text-sm text-gray-600"> - <?= h($operacion['contacto2_relacion']) ?></span>
            <?php endif; ?>
            <?php if (!empty($operacion['contacto2_telefono'])): ?>
              <span class="text-sm text-gray-600"> - Tel: <?= h($operacion['contacto2_telefono']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<!-- DETALLES ESPECÍFICOS -->
<div class="info-section">
  <h2 class="info-section-title">
    <?php
    echo match ($tipo) {
        'prestamo'  => '<span class="material-icons-outlined text-blue-600">account_balance_wallet</span> Detalles del Préstamo',
        'empeno'    => '<span class="material-icons-outlined text-blue-600">diamond</span> Detalles del Empeño',
        'prendario' => '<span class="material-icons-outlined text-blue-600">directions_car</span> Detalles del Vehículo',
        default     => '<span class="material-icons-outlined text-blue-600">info</span> Detalles'
    };
    ?>
  </h2>

  <?php if ($tipo === 'prestamo'): ?>
    <div class="info-grid">
      <?php if (!empty($operacion['destino'])): ?>
        <div class="info-item" style="grid-column: 1 / -1;">
          <div class="info-label">Destino del Crédito</div>
          <div class="info-value"><?= nl2br(h($operacion['destino'])) ?></div>
        </div>
      <?php endif; ?>
      <?php if (!empty($operacion['tasa_interes'])): ?>
        <div class="info-item">
          <div class="info-label">Tasa de Interés</div>
          <div class="info-value"><?= number_format((float)$operacion['tasa_interes'], 2) ?>%</div>
        </div>
      <?php endif; ?>
      <?php if (!empty($operacion['frecuencia_display'])): ?>
        <div class="info-item">
          <div class="info-label">Frecuencia de Pago</div>
          <div class="info-value"><?= ucfirst((string)$operacion['frecuencia_display']) ?></div>
        </div>
      <?php endif; ?>
    </div>

  <?php elseif ($tipo === 'empeno'): ?>
    <div class="info-grid">
      <div class="info-item" style="grid-column: 1 / -1;">
        <div class="info-label">Descripción del Producto</div>
        <div class="info-value"><?= nl2br(h($operacion['descripcion_producto'] ?? '')) ?></div>
      </div>
      <?php if (!empty($operacion['destino'])): ?>
        <div class="info-item" style="grid-column: 1 / -1;">
          <div class="info-label">Destino del Crédito</div>
          <div class="info-value"><?= h($operacion['destino']) ?></div>
        </div>
      <?php endif; ?>
    </div>

  <?php elseif ($tipo === 'prendario'): ?>
    <div class="info-grid">
      <div class="info-item">
        <div class="info-label">Dominio</div>
        <div class="info-value" style="font-size: 2rem; color: #2563eb;"><?= h($operacion['dominio'] ?? '') ?></div>
      </div>
      <div class="info-item" style="grid-column: 1 / -1;">
        <div class="info-label">Descripción del Vehículo</div>
        <div class="info-value"><?= nl2br(h($operacion['descripcion_vehiculo'] ?? '')) ?></div>
      </div>
      <div class="info-item">
        <div class="info-label">Es Titular del Vehículo</div>
        <div class="info-value">
          <?= !empty($operacion['es_titular']) ? '<span class="badge badge-success">✅ Sí</span>' : '<span class="badge badge-danger">❌ No</span>' ?>
        </div>
      </div>
      <?php if (!empty($operacion['destino'])): ?>
        <div class="info-item" style="grid-column: 1 / -1;">
          <div class="info-label">Destino del Crédito</div>
          <div class="info-value"><?= h($operacion['destino']) ?></div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Botón Editar -->
<?php if (!$es_asesor || $permisos['puede_editar_datos_cliente']): ?>
  <div class="info-section bg-blue-50">
    <a href="editar_datos_cliente.php?cliente_id=<?= (int)($operacion['cliente_id'] ?? 0) ?>&redirect=ver_prestamo&operacion_id=<?= $operacion_id ?>&tipo=<?= h($tipo) ?>"
       class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200 font-semibold">
      <span class="material-icons-outlined">edit</span>
      Editar Información del Cliente
    </a>
  </div>
<?php endif; ?>

<!-- Documentos para Préstamos -->
<?php if ($tipo === 'prestamo' && !empty($documentos)): ?>
  <div class="info-section">
    <div class="flex justify-between items-center mb-4">
      <h2 class="info-section-title" style="margin: 0; padding: 0; border: none;">
        <span class="material-icons-outlined text-blue-600">folder_open</span>
        Documentos del Legajo
      </h2>
      <div class="flex gap-2 flex-wrap">
        <?php if ($docs_aprobados > 0): ?>
          <span class="badge badge-success">✓ <?= $docs_aprobados ?> aprobados</span>
        <?php endif; ?>
        <?php if ($docs_pendientes > 0): ?>
          <span class="badge badge-warning">⏳ <?= $docs_pendientes ?> pendientes</span>
        <?php endif; ?>
        <?php if ($docs_rechazados > 0): ?>
          <span class="badge badge-danger">❌ <?= $docs_rechazados ?> rechazados</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Progreso -->
    <div class="mb-6">
      <div class="flex justify-between text-sm mb-2">
        <span class="font-semibold">Progreso del legajo</span>
        <span class="text-gray-600"><?= count($docs_tipos_aprobados) ?> / <?= count($docs_requeridos) ?> tipos requeridos</span>
      </div>
      <div class="w-full bg-gray-200 rounded-full h-3">
        <?php $progreso = count($docs_requeridos) > 0 ? (int)round((count($docs_tipos_aprobados) / count($docs_requeridos)) * 100) : 0; ?>
        <div class="bg-green-600 h-3 rounded-full transition-all duration-500" style="width: <?= $progreso ?>%"></div>
      </div>
      <div class="text-right text-sm text-gray-600 mt-1"><?= $progreso ?>%</div>
    </div>

    <!-- Grid de Documentos -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
      <?php
      $docs_por_tipo = [];
      foreach ($documentos as $doc) {
          if (!isset($docs_por_tipo[$doc['tipo']]) || strtotime((string)$doc['created_at']) > strtotime((string)$docs_por_tipo[$doc['tipo']]['created_at'])) {
              $docs_por_tipo[$doc['tipo']] = $doc;
          }
      }

      foreach ($docs_por_tipo as $doc):
      ?>
      <div class="border-2 rounded-lg p-4 transition duration-200 <?php
          echo match ($doc['estado_validacion'] ?? 'pendiente') {
              'pendiente' => 'border-yellow-300 bg-yellow-50 hover:bg-yellow-100',
              'aprobado'  => 'border-green-300 bg-green-50 hover:bg-green-100',
              'rechazado' => 'border-red-300 bg-red-50 hover:bg-red-100',
              default     => 'border-gray-300 hover:bg-gray-50'
          };
      ?>">
        <div class="flex justify-between items-start mb-3">
          <div class="font-semibold text-gray-800">
            <?= h($nombres_documentos[$doc['tipo']] ?? ucfirst(str_replace('_', ' ', (string)$doc['tipo']))) ?>
          </div>
          <span class="badge <?php
              echo match ($doc['estado_validacion'] ?? 'pendiente') {
                  'pendiente' => 'badge-warning',
                  'aprobado'  => 'badge-success',
                  'rechazado' => 'badge-danger',
                  default     => 'badge-info'
              };
          ?>">
            <?= h(ucfirst((string)($doc['estado_validacion'] ?? 'Pendiente'))) ?>
          </span>
        </div>

        <div class="text-sm text-gray-600 mb-3 truncate">
          <?= h($doc['nombre_archivo'] ?? '') ?>
        </div>

        <?php if (($doc['estado_validacion'] ?? 'pendiente') === 'rechazado' && !empty($doc['motivo_rechazo'])): ?>
          <div class="text-xs text-red-700 mb-3 p-2 bg-red-100 rounded border border-red-200">
            <strong>Rechazado:</strong> <?= h($doc['motivo_rechazo']) ?>
          </div>
        <?php endif; ?>

        <?php if (($doc['estado_validacion'] ?? 'pendiente') === 'aprobado' && !empty($doc['validador_nombre'])): ?>
          <div class="text-xs text-green-700 mb-3">
            Validado por: <?= h($doc['validador_nombre']) ?>
          </div>
        <?php endif; ?>

        <div class="flex gap-2">
          <a href="<?= h($doc['ruta_archivo'] ?? '#') ?>" target="_blank"
             class="flex-1 px-3 py-2 bg-blue-600 text-white rounded text-center hover:bg-blue-700 text-sm font-semibold transition duration-200 flex items-center justify-center gap-1">
            <span class="material-icons-outlined text-sm">visibility</span>
            Ver
          </a>

          <?php if (($doc['estado_validacion'] ?? 'pendiente') === 'pendiente' && (!$es_asesor || $permisos['puede_validar_documentos'])): ?>
            <form method="POST" class="flex-1" onsubmit="return confirm('¿Aprobar este documento?');">
              <input type="hidden" name="accion" value="aprobar_documento">
              <input type="hidden" name="documento_id" value="<?= (int)$doc['id'] ?>">
              <button type="submit" class="w-full px-3 py-2 bg-green-600 text-white rounded hover:bg-green-700 text-sm font-semibold transition duration-200 flex items-center justify-center gap-1">
                <span class="material-icons-outlined text-sm">check</span>
                Aprobar
              </button>
            </form>

            <button onclick="rechazarDoc(<?= (int)$doc['id'] ?>)"
                    class="flex-1 px-3 py-2 bg-red-600 text-white rounded hover:bg-red-700 text-sm font-semibold transition duration-200 flex items-center justify-center gap-1">
              <span class="material-icons-outlined text-sm">close</span>
              Rechazar
            </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Validar Legajo Completo -->
    <?php if ($legajo_completo && in_array(($operacion['estado'] ?? ''), ['documentacion_completa', 'pendiente'], true) && empty($operacion['legajo_validado'])): ?>
      <div class="border-t-2 pt-6">
        <div class="bg-green-50 border-2 border-green-200 rounded-lg p-4 mb-4">
          <div class="flex items-center gap-2 text-green-800 font-semibold mb-2">
            <span class="material-icons-outlined">check_circle</span>
            ¡Legajo completo y listo para validar!
          </div>
          <div class="text-sm text-green-700">
            Todos los documentos requeridos han sido aprobados. Podés activar el préstamo.
          </div>
        </div>

        <form method="POST" onsubmit="return confirm('¿Confirmar validación del legajo y activación del préstamo?');">
          <input type="hidden" name="accion" value="validar_legajo_completo">
          <button type="submit" class="w-full px-6 py-4 bg-green-600 text-white rounded-lg hover:bg-green-700 font-bold text-lg transition duration-200 flex items-center justify-center gap-2">
            <span class="material-icons-outlined">done_all</span>
            Validar Legajo Completo y Activar Préstamo
          </button>
        </form>
      </div>

    <?php elseif (!empty($operacion['legajo_validado'])): ?>
      <div class="border-t-2 pt-6 bg-green-50 border-2 border-green-200 rounded-lg p-4 text-center">
        <span class="material-icons-outlined text-green-600 text-5xl mb-2">verified</span>
        <div class="text-green-800 font-bold text-lg">Legajo Validado</div>
        <?php if (!empty($operacion['validador_nombre'])): ?>
          <div class="text-sm text-green-700 mt-1">
            Por <?= h($operacion['validador_nombre']) ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($operacion['legajo_fecha_validacion'])): ?>
          <div class="text-xs text-green-600 mt-1">
            el <?= date('d/m/Y H:i', strtotime((string)$operacion['legajo_fecha_validacion'])) ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
<?php endif; ?>

<!-- Imágenes para Empeños y Prendarios -->
<?php if (in_array($tipo, ['empeno', 'prendario'], true) && !empty($imagenes)): ?>
  <div class="info-section">
    <h2 class="info-section-title">
      <span class="material-icons-outlined text-blue-600">photo_library</span>
      <?= $tipo === 'empeno' ? 'Imágenes del Producto' : 'Fotos del Vehículo' ?>
    </h2>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <?php foreach ($imagenes as $img): ?>
        <div class="border-2 rounded-lg overflow-hidden hover:shadow-lg transition">
          <?php if ($tipo === 'prendario'): ?>
            <div class="bg-gray-100 px-3 py-2 font-semibold text-sm flex items-center gap-2">
              <span class="material-icons-outlined text-sm">
                <?= match ($img['tipo'] ?? '') {
                    'frente' => 'arrow_forward',
                    'trasera' => 'arrow_back',
                    'lateral_izq', 'lateral_der' => 'swap_horiz',
                    default => 'photo_camera'
                } ?>
              </span>
              <?= h(ucfirst(str_replace('_', ' ', (string)($img['tipo'] ?? 'foto')))) ?>
            </div>
          <?php endif; ?>

          <a href="<?= h($img['ruta_archivo'] ?? '#') ?>" target="_blank" class="block">
            <img src="<?= h($img['ruta_archivo'] ?? '') ?>" alt="Imagen" class="w-full h-64 object-cover hover:opacity-90 transition">
          </a>

          <div class="p-3 bg-gray-50">
            <div class="text-xs text-gray-600 truncate mb-2"><?= h($img['nombre_original'] ?? '') ?></div>
            <a href="<?= h($img['ruta_archivo'] ?? '#') ?>" target="_blank" class="text-blue-600 hover:underline text-sm font-semibold flex items-center gap-1">
              <span class="material-icons-outlined text-sm">open_in_new</span>
              Ver imagen completa
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

</div>

<!-- Columna Derecha -->
<div class="space-y-6">

<!-- Contraoferta -->
<?php if (!empty($operacion['monto_ofrecido']) && (float)$operacion['monto_ofrecido'] > 0): ?>
  <div class="info-section">
    <h2 class="info-section-title" style="font-size: 1.1rem;">
      <span class="material-icons-outlined text-blue-600">handshake</span>
      Contraoferta

      <?php if (!empty($operacion['fecha_aceptacion_contraoferta'])): ?>
        <span class="badge badge-success ml-2">✓ Aceptada</span>
      <?php elseif (($operacion['estado'] ?? '') === 'contraoferta'): ?>
        <span class="badge badge-warning ml-2">⏳ Pendiente</span>
      <?php endif; ?>
    </h2>

    <div class="space-y-3">
      <div>
        <div class="text-sm text-gray-600">Monto Ofrecido</div>
        <div class="text-3xl font-bold text-green-600">$<?= number_format((float)$operacion['monto_ofrecido'], 0, ',', '.') ?></div>
      </div>

      <?php if ($tipo === 'prestamo'): ?>
        <?php if (!empty($operacion['cuotas_ofrecidas'])): ?>
          <div>
            <div class="text-sm text-gray-600">Cuotas</div>
            <div class="font-semibold">
              <?= (int)$operacion['cuotas_ofrecidas'] ?>
              <?= h(($operacion['frecuencia_ofrecida'] ?? 'mensual') . 'es') ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($operacion['tasa_interes_ofrecida'])): ?>
          <div>
            <div class="text-sm text-gray-600">Tasa</div>
            <div class="font-semibold"><?= number_format((float)$operacion['tasa_interes_ofrecida'], 2) ?>%</div>
          </div>
        <?php endif; ?>

        <?php if (!empty($operacion['monto_total_ofrecido'])): ?>
          <div>
            <div class="text-sm text-gray-600">Monto Total</div>
            <div class="font-semibold text-green-600">$<?= number_format((float)$operacion['monto_total_ofrecido'], 0, ',', '.') ?></div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if (!empty($operacion['fecha_aceptacion_contraoferta'])): ?>
        <div class="mt-4 p-3 bg-green-50 border-l-4 border-green-500 text-sm">
          <strong>✅ Aceptada el:</strong> <?= date('d/m/Y H:i', strtotime((string)$operacion['fecha_aceptacion_contraoferta'])) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<!-- Comentarios Admin -->
<?php if (!empty($operacion['comentarios_admin'])): ?>
  <div class="info-section">
    <h2 class="info-section-title" style="font-size: 1.1rem;">
      <span class="material-icons-outlined text-blue-600">comment</span>
      Comentarios del Asesor
    </h2>
    <div class="bg-gray-50 p-4 rounded-lg border-l-4 border-blue-500">
      <?= nl2br(h($operacion['comentarios_admin'])) ?>
    </div>
  </div>
<?php endif; ?>

<!-- Info Adicional -->
<div class="info-section">
  <h2 class="info-section-title" style="font-size: 1.1rem;">
    <span class="material-icons-outlined text-blue-600">info</span>
    Información Adicional
  </h2>

  <div class="space-y-3 text-sm">
    <?php if ($tipo === 'prestamo' && !empty($operacion['requiere_legajo'])): ?>
      <div>
        <div class="text-gray-600">Requiere Legajo</div>
        <div class="font-semibold text-green-600">Sí</div>
      </div>
    <?php endif; ?>

    <?php if (!empty($operacion['fecha_aprobacion'])): ?>
      <div>
        <div class="text-gray-600">Fecha de Aprobación</div>
        <div class="font-semibold"><?= date('d/m/Y H:i', strtotime((string)$operacion['fecha_aprobacion'])) ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($operacion['fecha_contraoferta'])): ?>
      <div>
        <div class="text-gray-600">Fecha Contraoferta</div>
        <div class="font-semibold"><?= date('d/m/Y H:i', strtotime((string)$operacion['fecha_contraoferta'])) ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($operacion['fecha_inicio_prestamo']) || !empty($operacion['fecha_inicio'])): ?>
      <div>
        <div class="text-gray-600">Fecha de Inicio</div>
        <div class="font-semibold"><?= date('d/m/Y', strtotime((string)($operacion['fecha_inicio_prestamo'] ?? $operacion['fecha_inicio']))) ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($operacion['fecha_fin_estimada'])): ?>
      <div>
        <div class="text-gray-600">Fecha Fin Estimada</div>
        <div class="font-semibold"><?= date('d/m/Y', strtotime((string)$operacion['fecha_fin_estimada'])) ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($operacion['asesor_id'])): ?>
      <div>
        <div class="text-gray-600">Asesor Asignado</div>
        <div class="font-semibold">ID: <?= (int)$operacion['asesor_id'] ?></div>
      </div>
    <?php endif; ?>
  </div>
</div>

</div>

</div>

<!-- SECCIÓN: SOLICITUDES DE INFORMACIÓN -->
<div id="seccionSolicitudesInfo" class="info-section" style="display: none;">
  <h2 class="info-section-title">
    <span class="material-icons-outlined text-blue-600">question_answer</span>
    Historial de Solicitudes de Información
    <span id="badgeTotalSolicitudes" class="badge badge-info ml-2">0</span>
  </h2>
  
  <div id="listaSolicitudes" class="space-y-4">
    <!-- Las solicitudes se cargarán dinámicamente aquí -->
  </div>
  
  <div id="sinSolicitudes" class="text-center py-12 text-gray-500" style="display: none;">
    <span class="material-icons-outlined" style="font-size: 4rem; color: #d1d5db;">mail_outline</span>
    <p class="text-lg mt-4">No hay solicitudes de información para esta operación</p>
  </div>
</div>

</div>
</div>

<!-- Modal Rechazar Documento -->
<div id="modalRechazo" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
  <div class="bg-white rounded-xl p-8 max-w-md w-full mx-4 shadow-2xl">
    <h3 class="text-2xl font-bold text-gray-800 mb-4 flex items-center gap-2">
      <span class="material-icons-outlined text-red-600">block</span>
      Rechazar Documento
    </h3>

    <form method="POST">
      <input type="hidden" name="accion" value="rechazar_documento">
      <input type="hidden" name="documento_id" id="rechazo_documento_id">

      <div class="mb-6">
        <label class="block text-sm font-semibold text-gray-700 mb-2">Motivo del rechazo *</label>
        <textarea name="motivo" required rows="4"
                  placeholder="Explicá claramente por qué se rechaza este documento..."
                  class="w-full px-4 py-3 border-2 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent"></textarea>
        <div class="text-xs text-gray-600 mt-2">
          El cliente recibirá este mensaje y deberá subir el documento nuevamente.
        </div>
      </div>

      <div class="flex gap-3">
        <button type="submit" class="flex-1 px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold transition duration-200">
          Rechazar Documento
        </button>
        <button type="button" onclick="cerrarModalRechazo()"
                class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-semibold transition duration-200">
          Cancelar
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Vista Previa de Imagen -->
<div id="modalImagenPreview" class="modal-imagen-preview">
  <div class="modal-imagen-content">
    <div class="modal-imagen-header">
      <div class="modal-imagen-titulo" id="modalImagenTitulo">Vista Previa</div>
      <button class="modal-imagen-close" onclick="cerrarModalImagen()">&times;</button>
    </div>
    
    <img id="modalImagenImg" src="" alt="Vista previa">
    
    <div class="modal-imagen-footer">
      <a id="modalImagenDownload" href="" download class="btn-modal-download">
        <span class="material-icons-outlined">download</span>
        Descargar Imagen
      </a>
    </div>
  </div>
</div>

<script>
function rechazarDoc(documentoId) {
  document.getElementById('rechazo_documento_id').value = documentoId;
  const m = document.getElementById('modalRechazo');
  m.classList.remove('hidden');
  m.classList.add('flex');
}

function cerrarModalRechazo() {
  const m = document.getElementById('modalRechazo');
  m.classList.add('hidden');
  m.classList.remove('flex');
}

// ========================================
// MODAL VISTA PREVIA DE IMAGEN
// ========================================
function abrirModalImagen(url, nombreArchivo) {
    const modal = document.getElementById('modalImagenPreview');
    const img = document.getElementById('modalImagenImg');
    const titulo = document.getElementById('modalImagenTitulo');
    const download = document.getElementById('modalImagenDownload');
    
    img.src = url;
    titulo.textContent = nombreArchivo;
    download.href = url;
    download.download = nombreArchivo;
    
    modal.classList.add('active');
    
    // Prevenir scroll del body
    document.body.style.overflow = 'hidden';
}

function cerrarModalImagen() {
    const modal = document.getElementById('modalImagenPreview');
    modal.classList.remove('active');
    
    // Restaurar scroll
    document.body.style.overflow = '';
}

// ========================================
// FUNCIONALIDAD: SOLICITUDES DE INFORMACIÓN
// ========================================
const operacionIdGlobal = <?php echo $operacion_id; ?>;
const tipoOperacionGlobal = '<?php echo $tipo; ?>';

function esImagen(nombreArchivo) {
    const extensionesImagen = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    const extension = nombreArchivo.split('.').pop().toLowerCase();
    return extensionesImagen.includes(extension);
}

function cargarSolicitudesInfo(operacionId, tipoOperacion) {
    fetch(`obtener_respuestas_solicitudes.php?operacion_id=${operacionId}&tipo=${tipoOperacion}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.solicitudes) {
                mostrarSolicitudesInfo(data.solicitudes);
            }
        })
        .catch(error => {
            console.error('Error cargando solicitudes:', error);
        });
}

function mostrarSolicitudesInfo(solicitudes) {
    const seccion = document.getElementById('seccionSolicitudesInfo');
    const lista = document.getElementById('listaSolicitudes');
    const sinSolicitudes = document.getElementById('sinSolicitudes');
    const badge = document.getElementById('badgeTotalSolicitudes');
    
    if (!solicitudes || solicitudes.length === 0) {
        seccion.style.display = 'block';
        lista.style.display = 'none';
        sinSolicitudes.style.display = 'block';
        badge.textContent = '0';
        return;
    }
    
    seccion.style.display = 'block';
    lista.style.display = 'block';
    sinSolicitudes.style.display = 'none';
    badge.textContent = solicitudes.length;
    
    let html = '';
    
    solicitudes.forEach(sol => {
        const esRespondida = sol.respuesta && sol.respuesta.trim() !== '';
        const badgeClass = esRespondida ? 'badge-respondida' : 'badge-pendiente';
        const badgeText = esRespondida ? '✅ Respondida' : '⏳ Pendiente';
        const esNueva = esRespondida && new Date(sol.fecha_respuesta) > new Date(Date.now() - 24*60*60*1000);
        
        html += `
            <div class="solicitud-card">
                <div class="solicitud-header">
                    <div>
                        <span class="solicitud-badge ${badgeClass}">
                            ${badgeText}
                        </span>
                        ${esNueva ? '<span class="nueva-respuesta-indicator" title="Nueva respuesta"></span>' : ''}
                    </div>
                    <div style="text-align: right; color: #6b7280; font-size: 0.875rem;">
                        <div style="display: flex; align-items: center; gap: 6px; justify-content: flex-end;">
                            <span class="material-icons-outlined" style="font-size: 1rem;">schedule</span>
                            Solicitado: ${formatearFecha(sol.fecha)}
                        </div>
                        ${esRespondida ? `
                            <div style="display: flex; align-items: center; gap: 6px; justify-content: flex-end; margin-top: 4px;">
                                <span class="material-icons-outlined" style="font-size: 1rem;">check_circle</span>
                                Respondido: ${formatearFecha(sol.fecha_respuesta)}
                            </div>
                        ` : ''}
                    </div>
                </div>
                
                <div class="mensaje-admin-box">
                    <div style="font-weight: 600; color: #1e40af; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <span class="material-icons-outlined" style="font-size: 1.2rem;">help_outline</span>
                        Tu solicitud:
                    </div>
                    <div style="color: #1e40af;">
                        ${nl2br(escapeHtml(sol.mensaje))}
                    </div>
                    ${sol.archivos_solicitud && sol.archivos_solicitud.length > 0 ? `
                        <div style="margin-top: 12px;">
                            <div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                                <span class="material-icons-outlined" style="font-size: 1rem;">attach_file</span>
                                Archivos que adjuntaste:
                            </div>
                            ${sol.archivos_solicitud.map(archivo => `
                                <a href="uploads/solicitudes_info/${archivo}" 
                                   target="_blank" 
                                   class="archivo-adjunto">
                                    <span class="material-icons-outlined" style="font-size: 1rem;">description</span>
                                    ${archivo}
                                </a>
                            `).join('')}
                        </div>
                    ` : ''}
                </div>
                
                ${esRespondida ? `
                    <div class="respuesta-cliente-box">
                        <div style="font-weight: 600; color: #047857; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                            <span class="material-icons-outlined" style="font-size: 1.2rem;">reply</span>
                            Respuesta del cliente:
                        </div>
                        <div style="color: #047857; margin-bottom: 8px; padding: 8px; background: rgba(16, 185, 129, 0.1); border-radius: 6px;">
                            <strong>Cliente:</strong> ${escapeHtml(sol.cliente_nombre)} ${escapeHtml(sol.cliente_apellido)} 
                            <span style="color: #059669;">(${escapeHtml(sol.cliente_email)})</span>
                        </div>
                        <div style="color: #047857;">
                            ${nl2br(escapeHtml(sol.respuesta))}
                        </div>
                        ${sol.archivos_respuesta && sol.archivos_respuesta.length > 0 ? `
                            <div style="margin-top: 12px;">
                                <div style="font-size: 0.875rem; color: #059669; margin-bottom: 8px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                                    <span class="material-icons-outlined" style="font-size: 1rem;">cloud_download</span>
                                    Archivos adjuntos por el cliente:
                                </div>
                                ${sol.archivos_respuesta.map(archivo => {
                                    const nombreArchivo = escapeHtml(archivo.nombre_original || archivo.archivo);
                                    const urlArchivo = 'uploads/solicitudes_info_respuestas/' + archivo.archivo;
                                    const esImg = esImagen(archivo.archivo);
                                    
                                    if (esImg) {
                                        return `
                                            <a href="javascript:void(0)" 
                                               onclick="abrirModalImagen('${urlArchivo}', '${nombreArchivo}')"
                                               class="archivo-adjunto archivo-imagen">
                                                <span class="material-icons-outlined" style="font-size: 1rem;">image</span>
                                                ${nombreArchivo}
                                            </a>
                                        `;
                                    } else {
                                        return `
                                            <a href="${urlArchivo}" 
                                               target="_blank" 
                                               class="archivo-adjunto"
                                               download="${nombreArchivo}">
                                                <span class="material-icons-outlined" style="font-size: 1rem;">download</span>
                                                ${nombreArchivo}
                                            </a>
                                        `;
                                    }
                                }).join('')}
                            </div>
                        ` : ''}
                    </div>
                ` : `
                    <div style="padding: 16px; text-align: center; background: #fef3c7; border-radius: 8px; margin-top: 16px;">
                        <span class="material-icons-outlined" style="font-size: 2rem; color: #d97706;">schedule</span>
                        <p style="color: #d97706; margin: 8px 0 0 0; font-weight: 600;">
                            Esperando respuesta del cliente...
                        </p>
                    </div>
                `}
            </div>
        `;
    });
    
    lista.innerHTML = html;
}

function formatearFecha(fecha) {
    if (!fecha) return 'N/A';
    const date = new Date(fecha);
    return date.toLocaleString('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function nl2br(text) {
    return escapeHtml(text).replace(/\n/g, '<br>');
}

// Eventos
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    cerrarModalRechazo();
    cerrarModalImagen();
  }
});

document.getElementById('modalRechazo')?.addEventListener('click', function(e) {
  if (e.target === this) cerrarModalRechazo();
});

document.getElementById('modalImagenPreview')?.addEventListener('click', function(e) {
  if (e.target === this) cerrarModalImagen();
});

// Cargar solicitudes
document.addEventListener('DOMContentLoaded', function() {
    cargarSolicitudesInfo(operacionIdGlobal, tipoOperacionGlobal);
    
    setInterval(() => {
        cargarSolicitudesInfo(operacionIdGlobal, tipoOperacionGlobal);
    }, 30000);
});

</script>

</body>
</html>