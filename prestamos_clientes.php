<?php
session_start();

if (!isset($_SESSION['cliente_id']) || !isset($_SESSION['cliente_email'])) {
    header('Location: login_clientes.php');
    exit;
}

require_once __DIR__ . '/backend/connection.php';

$cliente_id = (int)$_SESSION['cliente_id'];
$mensaje = '';
$error = '';

// Verificar si hay solicitudes de información pendientes
$solicitudes_pendientes = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM solicitudes_info WHERE cliente_id = ? AND respondida = 0");
    $stmt->execute([$cliente_id]);
    $solicitudes_pendientes = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Error verificando solicitudes: " . $e->getMessage());
}

// Verificar documentación completa
$docs_completos = false;
$tiene_docs_previos = false;

try {
    $stmt = $pdo->prepare("SELECT docs_completos FROM clientes_detalles WHERE usuario_id = ?");
    $stmt->execute([$cliente_id]);
    $docs_completos = (bool)($stmt->fetchColumn() ?? 0);
    
    // Verificar si ya tiene préstamos anteriores (para reutilizar docs)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total FROM (
            SELECT id FROM prestamos WHERE cliente_id = ? AND estado IN ('activo', 'finalizado')
            UNION ALL
            SELECT id FROM empenos WHERE cliente_id = ? AND estado IN ('activo', 'finalizado')
            UNION ALL
            SELECT id FROM creditos_prendarios WHERE cliente_id = ? AND estado IN ('activo', 'finalizado')
        ) as prestamos_totales
    ");
    $stmt->execute([$cliente_id, $cliente_id, $cliente_id]);
    $tiene_docs_previos = ($stmt->fetchColumn() > 0);
    
} catch (Exception $e) {
    error_log("Error verificando docs: " . $e->getMessage());
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $docs_completos) {
    $tipo = $_POST['tipo'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        if ($tipo === 'prestamo') {
            $monto = (float)($_POST['monto'] ?? 0);
            $cuotas = (int)($_POST['cuotas'] ?? 0);
            $frecuencia = $_POST['frecuencia'] ?? 'mensual';
            $destino = trim($_POST['destino'] ?? '');
            
            if ($monto < 1000) throw new Exception('Monto mínimo: $1.000');
            if ($cuotas < 1 || $cuotas > 24) throw new Exception('Cuotas entre 1 y 24');
            
            $usa_docs_existentes = $tiene_docs_previos;
            
            if (!$tiene_docs_previos) {
                if (empty($_FILES['comprobante_ingreso']['name'])) {
                    throw new Exception('Comprobante de ingresos requerido en tu primera solicitud');
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO prestamos (
                    cliente_id, monto_solicitado, cuotas_solicitadas, 
                    frecuencia_solicitada, destino_credito, estado, 
                    fecha_solicitud, usa_documentacion_existente
                ) VALUES (?, ?, ?, ?, ?, 'pendiente', NOW(), ?)
            ");
            $stmt->execute([$cliente_id, $monto, $cuotas, $frecuencia, $destino, $usa_docs_existentes ? 1 : 0]);
            
            $prestamo_id = $pdo->lastInsertId();
            
            if (!$tiene_docs_previos && !empty($_FILES['comprobante_ingreso']['name'])) {
                $dir = __DIR__ . '/uploads/prestamos/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                
                $file = $_FILES['comprobante_ingreso'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $nuevo_nombre = 'comprobante_' . $prestamo_id . '_' . time() . '.' . $ext;
                $ruta = $dir . $nuevo_nombre;
                
                if (move_uploaded_file($file['tmp_name'], $ruta)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO prestamos_documentos (prestamo_id, tipo, nombre_archivo, ruta_archivo)
                        VALUES (?, 'comprobante_ingresos', ?, ?)
                    ");
                    $stmt->execute([$prestamo_id, $file['name'], 'uploads/prestamos/' . $nuevo_nombre]);
                }
            }
            
            $mensaje = $tiene_docs_previos 
                ? '✅ Solicitud enviada correctamente. Estamos revisando tu información.' 
                : '✅ Solicitud enviada correctamente con tu documentación.';
        }
        elseif ($tipo === 'empeno') {
            $monto = (float)($_POST['monto'] ?? 0);
            $descripcion = trim($_POST['descripcion_producto'] ?? '');
            $destino = trim($_POST['destino'] ?? '');
            
            if ($monto < 500) throw new Exception('Monto mínimo: $500');
            if (empty($descripcion)) throw new Exception('Descripción del producto requerida');
            if (empty($_FILES['imagenes']['name'][0])) throw new Exception('Fotos del producto requeridas');
            
            $stmt = $pdo->prepare("
                INSERT INTO empenos (
                    cliente_id, monto_solicitado, descripcion_producto,
                    destino_credito, estado, fecha_solicitud, usa_documentacion_existente
                ) VALUES (?, ?, ?, ?, 'pendiente', NOW(), 1)
            ");
            $stmt->execute([$cliente_id, $monto, $descripcion, $destino]);
            
            $empeno_id = $pdo->lastInsertId();
            
            $dir = __DIR__ . '/uploads/empenos/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            
            foreach ($_FILES['imagenes']['name'] as $key => $name) {
                if ($_FILES['imagenes']['error'][$key] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $nuevo_nombre = 'empeno_' . $empeno_id . '_' . time() . '_' . $key . '.' . $ext;
                    $ruta = $dir . $nuevo_nombre;
                    
                    if (move_uploaded_file($_FILES['imagenes']['tmp_name'][$key], $ruta)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO empenos_imagenes (empeno_id, nombre_original, ruta_archivo)
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$empeno_id, $name, 'uploads/empenos/' . $nuevo_nombre]);
                    }
                }
            }
            
            $mensaje = '✅ Solicitud de empeño enviada correctamente';
        }
        elseif ($tipo === 'prendario') {
            $monto = (float)($_POST['monto'] ?? 0);
            $dominio = strtoupper(trim($_POST['dominio'] ?? ''));
            $descripcion = trim($_POST['descripcion_vehiculo'] ?? '');
            $es_titular = isset($_POST['es_titular']) ? 1 : 0;
            $destino = trim($_POST['destino'] ?? '');
            
            if ($monto < 5000) throw new Exception('Monto mínimo: $5.000');
            if (empty($dominio)) throw new Exception('Dominio requerido');
            if (empty($descripcion)) throw new Exception('Descripción del vehículo requerida');
            if (empty($_FILES['imagenes']['name'][0])) throw new Exception('Fotos del vehículo requeridas');
            
            $stmt = $pdo->prepare("
                INSERT INTO creditos_prendarios (
                    cliente_id, monto_solicitado, dominio,
                    descripcion_vehiculo, es_titular, destino_credito,
                    estado, fecha_solicitud, usa_documentacion_existente
                ) VALUES (?, ?, ?, ?, ?, ?, 'pendiente', NOW(), 1)
            ");
            $stmt->execute([$cliente_id, $monto, $dominio, $descripcion, $es_titular, $destino]);
            
            $prendario_id = $pdo->lastInsertId();
            
            $dir = __DIR__ . '/uploads/prendarios/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            
            $tipos_img = ['frente', 'trasera', 'lateral_izq', 'lateral_der'];
            
            foreach ($_FILES['imagenes']['name'] as $key => $name) {
                if ($_FILES['imagenes']['error'][$key] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $tipo_img = $tipos_img[$key] ?? 'otra';
                    $nuevo_nombre = 'prendario_' . $prendario_id . '_' . $tipo_img . '_' . time() . '.' . $ext;
                    $ruta = $dir . $nuevo_nombre;
                    
                    if (move_uploaded_file($_FILES['imagenes']['tmp_name'][$key], $ruta)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO prendarios_imagenes (credito_id, tipo, nombre_original, ruta_archivo)
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([$prendario_id, $tipo_img, $name, 'uploads/prendarios/' . $nuevo_nombre]);
                    }
                }
            }
            
            $mensaje = '✅ Solicitud de crédito prendario enviada correctamente';
        }
        
        // Crear notificación
        $stmt = $pdo->prepare("
            INSERT INTO clientes_notificaciones (cliente_id, tipo, titulo, mensaje, url_accion, texto_accion)
            VALUES (?, 'info', 'Solicitud Recibida', 'Tu solicitud está siendo evaluada por nuestro equipo. Te notificaremos cuando tengamos novedades.', 'dashboard_clientes.php', 'Ver Dashboard')
        ");
        $stmt->execute([$cliente_id]);
        
        $pdo->commit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Variables para sidebar
$pagina_activa = 'prestamos';
$noti_no_leidas = 0;

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes_notificaciones WHERE cliente_id = ? AND leida = 0");
    $stmt->execute([$cliente_id]);
    $noti_no_leidas = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Error notificaciones: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Solicitar Operación - Préstamo Líder</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="style_clientes.css">
<style>
/* CORRECCIÓN: Textos visibles */
body, .main, p, span, div, label, .page-title, .page-sub {
    color: #1f2937 !important;
}

.main {
    background: #f5f7fb !important;
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    color: #1f2937 !important;
}

.page-sub {
    font-size: 1.1rem;
    color: #6b7280 !important;
}

.tipo-card {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 16px;
    padding: 24px;
    cursor: pointer;
    transition: all 0.3s;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.tipo-card:hover {
    border-color: #7c5cff;
    background: #f9fafb;
    transform: translateY(-4px);
    box-shadow: 0 4px 12px rgba(124, 92, 255, 0.2);
}

.tipo-icon {
    font-size: 3rem;
    margin-bottom: 12px;
}

.tipo-card .font-bold {
    color: #1f2937 !important;
}

.tipo-card .text-sm {
    color: #6b7280 !important;
}

.badge-reutiliza {
    display: inline-block;
    padding: 6px 14px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white !important;
    font-size: 0.8rem;
    font-weight: 700;
    border-radius: 20px;
    margin-top: 10px;
}

.info-reutilizacion {
    background: #d1fae5;
    border: 2px solid #10b981;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 20px;
    display: flex;
    align-items: start;
    gap: 12px;
}

.info-reutilizacion .icon {
    font-size: 2rem;
    flex-shrink: 0;
}

.info-reutilizacion .content {
    flex: 1;
}

.info-reutilizacion .title {
    font-weight: 700;
    color: #065f46 !important;
    margin-bottom: 6px;
}

.info-reutilizacion .message {
    font-size: 0.9rem;
    color: #047857 !important;
    line-height: 1.5;
}

/* Alerta de solicitudes pendientes */
.alerta-solicitudes {
    background: linear-gradient(135deg, #f59e0b, #dc2626);
    border-radius: 16px;
    padding: 20px 24px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    animation: pulse-soft 2s infinite;
}

.alerta-solicitudes .icon {
    font-size: 2.5rem;
    flex-shrink: 0;
}

.alerta-solicitudes .content {
    flex: 1;
}

.alerta-solicitudes .title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #ffffff !important;
    margin-bottom: 6px;
}

.alerta-solicitudes .message {
    color: #fef3c7 !important;
    font-size: 0.95rem;
}

.alerta-solicitudes .btn {
    background: white;
    color: #dc2626 !important;
    padding: 10px 24px;
    border-radius: 10px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
}

.alerta-solicitudes .btn:hover {
    background: #fef3c7;
}

@keyframes pulse-soft {
    0%, 100% {
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    }
    50% {
        box-shadow: 0 6px 20px rgba(245, 158, 11, 0.5);
    }
}

/* Estilos del modal */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
}

.modal-content {
    background: #FFFFFF;
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    max-width: 600px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 24px 30px;
    border-bottom: 1px solid #e5e7eb;
}

.modal-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #111827 !important;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #6b7280 !important;
    cursor: pointer;
    padding: 4px 8px;
    line-height: 1;
}

.modal-close:hover {
    color: #111827 !important;
}

.modal-body {
    padding: 24px 30px 30px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: #374151 !important;
    margin-bottom: 8px;
}

.form-input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 1rem;
    color: #111827 !important;
    background: #FFFFFF;
}

.form-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-textarea {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 1rem;
    color: #111827 !important;
    background: #FFFFFF;
    resize: vertical;
}

.form-textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 1rem;
    color: #111827 !important;
    background: #FFFFFF;
}

.form-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.form-hint {
    font-size: 0.75rem;
    color: #6b7280 !important;
    margin-top: 6px;
}

.modal-footer {
    display: flex;
    gap: 12px;
    padding-top: 24px;
}

.btn-cancel {
    flex: 1;
    padding: 12px 24px;
    border: 1px solid #d1d5db;
    background: #FFFFFF;
    color: #374151 !important;
    font-weight: 600;
    border-radius: 10px;
    cursor: pointer;
}

.btn-cancel:hover {
    background: #f9fafb;
}

.btn-submit {
    flex: 1;
    padding: 12px 24px;
    border: none;
    background: linear-gradient(135deg, #3b82f6 0%, #10b981 100%);
    color: #FFFFFF !important;
    font-weight: 700;
    border-radius: 10px;
    cursor: pointer;
}

.btn-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
}

.file-upload-area {
    border: 2px dashed #d1d5db;
    border-radius: 12px;
    padding: 32px 20px;
    text-align: center;
    background: #f9fafb;
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

.file-upload-text {
    font-size: 0.95rem;
    font-weight: 600;
    color: #374151 !important;
}

.file-upload-hint {
    font-size: 0.8rem;
    color: #9ca3af !important;
}

@media (max-width: 640px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<div class="app">
<?php include __DIR__ . '/sidebar_clientes.php'; ?>

<main class="main">
<div class="page-header">
<div class="page-title">💼 Solicitar Operación</div>
<div class="page-sub">Elegí el tipo de operación que deseás realizar</div>
</div>

<?php if ($solicitudes_pendientes > 0): ?>
<div class="alerta-solicitudes">
    <div class="icon">⚠️</div>
    <div class="content">
        <div class="title">¡Tenés <?= $solicitudes_pendientes ?> solicitud<?= $solicitudes_pendientes > 1 ? 'es' : '' ?> pendiente<?= $solicitudes_pendientes > 1 ? 's' : '' ?>!</div>
        <div class="message">Tu asesor necesita más información para continuar con tu trámite.</div>
    </div>
    <a href="responder_solicitud_info.php" class="btn">Ver Solicitudes</a>
</div>
<?php endif; ?>

<?php if ($mensaje): ?>
<div class="card p-4 mb-6" style="border-left:4px solid #22c55e; background: #d1fae5;">
<p class="font-semibold" style="color:#065f46 !important;"><?= htmlspecialchars($mensaje) ?></p>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="card p-4 mb-6" style="border-left:4px solid #ef4444; background: #fee2e2;">
<p class="font-semibold" style="color:#991b1b !important;">❌ <?= htmlspecialchars($error) ?></p>
</div>
<?php endif; ?>

<?php if (!$docs_completos): ?>
<div class="card p-6 mb-6" style="border-left:4px solid #f59e0b; background: #fef3c7;">
<div class="flex items-center gap-4">
<div style="font-size:2.5rem;">⚠️</div>
<div class="flex-1">
<div class="font-bold text-lg mb-2" style="color:#92400e !important;">Documentación Incompleta</div>
<p style="color:#78350f !important;">Para solicitar operaciones, primero debés completar tu documentación.</p>
</div>
<a href="documentacion.php" class="btn btn-primary">Completar ahora</a>
</div>
</div>
<?php else: ?>

<?php if ($tiene_docs_previos): ?>
<div class="info-reutilizacion">
<div class="icon">✨</div>
<div class="content">
<div class="title">¡Proceso Simplificado!</div>
<div class="message">
Como ya tenés préstamos anteriores, solo necesitamos información específica de esta solicitud.
<strong>No es necesario volver a cargar tu documentación personal.</strong>
</div>
</div>
</div>
<?php endif; ?>

<!-- Selector de tipo -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
<div class="tipo-card" onclick="abrirModal('prestamo')">
<div class="tipo-icon">💰</div>
<div class="font-bold text-xl mb-2">Préstamo Personal</div>
<p class="text-sm">Hasta 24 cuotas.</p>
<?php if ($tiene_docs_previos): ?>
<div class="badge-reutiliza">✓ Sin docs adicionales</div>
<?php endif; ?>
</div>

<div class="tipo-card" onclick="abrirModal('empeno')">
<div class="tipo-icon">💎</div>
<div class="font-bold text-xl mb-2">Empeño</div>
<p class="text-sm">Dinero rápido con garantía.</p>
<div class="badge-reutiliza">✓ Solo fotos del producto</div>
</div>

<div class="tipo-card" onclick="abrirModal('prendario')">
<div class="tipo-icon">🚗</div>
<div class="font-bold text-xl mb-2">Crédito Prendario</div>
<p class="text-sm">Usá tu vehículo como garantía.</p>
<div class="badge-reutiliza">✓ Solo fotos del vehículo</div>
</div>
</div>

<?php endif; ?>

<!-- MODALES -->
<div id="modal-prestamo" class="modal-overlay" onclick="cerrarSiFondo(event, 'prestamo')">
<div class="modal-content" onclick="event.stopPropagation()">
<div class="modal-header">
<h3 class="modal-title">💰 Solicitar Préstamo Personal</h3>
<button type="button" class="modal-close" onclick="cerrarModal('prestamo')">✕</button>
</div>
<div class="modal-body">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="tipo" value="prestamo">
<div class="form-grid">
<div class="form-group">
<label class="form-label">Monto Solicitado *</label>
<input type="number" name="monto" required min="1000" step="1000" class="form-input" placeholder="50000">
<p class="form-hint">Mínimo: $1.000</p>
</div>
<div class="form-group">
<label class="form-label">Cantidad de Cuotas *</label>
<input type="number" name="cuotas" required min="1" max="24" class="form-input" placeholder="12">
<p class="form-hint">Entre 1 y 24 cuotas</p>
</div>
</div>
<div class="form-group">
<label class="form-label">Frecuencia de Pago *</label>
<select name="frecuencia" required class="form-select">
<option value="mensual">Mensual</option>
<option value="quincenal">Quincenal</option>
<option value="semanal">Semanal</option>
</select>
</div>
<div class="form-group">
<label class="form-label">Destino del Crédito</label>
<textarea name="destino" rows="3" class="form-textarea" placeholder="¿Para qué vas a usar el dinero?"></textarea>
</div>
<?php if (!$tiene_docs_previos): ?>
<div class="form-group">
<label class="form-label">Comprobante de Ingreso (OBLIGATORIO) *</label>
<div class="file-upload-area">
<input type="file" name="comprobante_ingreso" id="file-comprobante" accept=".pdf,.jpg,.jpeg,.png" required style="display: none;">
<label for="file-comprobante" class="file-upload-label">
<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2">
<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
<polyline points="14 2 14 8 20 8"></polyline>
</svg>
<div class="file-upload-text">Último recibo de sueldo</div>
<div class="file-upload-hint">PDF o imagen • Máx 10MB</div>
</label>
</div>
</div>
<?php else: ?>
<div class="info-reutilizacion">
<div class="icon">✅</div>
<div class="content">
<div class="message">
<strong>Tu documentación ya está en nuestro sistema.</strong><br>
No necesitás volver a cargar comprobantes de ingresos.
</div>
</div>
</div>
<?php endif; ?>
<div class="modal-footer">
<button type="button" class="btn-cancel" onclick="cerrarModal('prestamo')">Cancelar</button>
<button type="submit" class="btn-submit">Enviar Solicitud</button>
</div>
</form>
</div>
</div>
</div>

<!-- MODAL EMPEÑO -->
<div id="modal-empeno" class="modal-overlay" onclick="cerrarSiFondo(event, 'empeno')">
  <div class="modal-content" onclick="event.stopPropagation()">
    <div class="modal-header">
      <h3 class="modal-title">💎 Solicitar Empeño</h3>
      <button type="button" class="modal-close" onclick="cerrarModal('empeno')">✕</button>
    </div>
    <div class="modal-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="tipo" value="empeno">

        <div class="form-group">
          <label class="form-label">Monto solicitado *</label>
          <input type="number" name="monto" required min="500" step="500" class="form-input" placeholder="20000">
          <p class="form-hint">Mínimo: $500</p>
        </div>

        <div class="form-group">
          <label class="form-label">Descripción del producto *</label>
          <textarea name="descripcion_producto" required rows="3" class="form-textarea" placeholder="Ej: Anillo de oro 18k, TV 50 pulgadas, etc."></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Destino del dinero</label>
          <textarea name="destino" rows="2" class="form-textarea" placeholder="¿Para qué vas a usar el dinero?"></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Fotos del producto *</label>
          <div class="file-upload-area">
            <input type="file" name="imagenes[]" multiple accept="image/*" required style="display:none;" id="file-empeno">
            <label for="file-empeno" class="file-upload-label">
              <div class="file-upload-text">Subir imágenes</div>
              <div class="file-upload-hint">Podés subir varias fotos</div>
            </label>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-cancel" onclick="cerrarModal('empeno')">Cancelar</button>
          <button type="submit" class="btn-submit">Enviar solicitud</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL PRENDARIO -->
<div id="modal-prendario" class="modal-overlay" onclick="cerrarSiFondo(event, 'prendario')">
  <div class="modal-content" onclick="event.stopPropagation()">
    <div class="modal-header">
      <h3 class="modal-title">🚗 Solicitar Crédito Prendario</h3>
      <button type="button" class="modal-close" onclick="cerrarModal('prendario')">✕</button>
    </div>
    <div class="modal-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="tipo" value="prendario">

        <div class="form-group">
          <label class="form-label">Monto solicitado *</label>
          <input type="number" name="monto" required min="5000" step="1000" class="form-input" placeholder="300000">
          <p class="form-hint">Mínimo: $5.000</p>
        </div>

        <div class="form-group">
          <label class="form-label">Dominio (patente) *</label>
          <input type="text" name="dominio" required class="form-input" placeholder="AA123BB">
        </div>

        <div class="form-group">
          <label class="form-label">Descripción del vehículo *</label>
          <textarea name="descripcion_vehiculo" required rows="3" class="form-textarea" placeholder="Marca, modelo, año, estado, etc."></textarea>
        </div>

        <div class="form-group">
          <label>
            <input type="checkbox" name="es_titular" value="1">
            Soy titular del vehículo
          </label>
        </div>

        <div class="form-group">
          <label class="form-label">Destino del dinero</label>
          <textarea name="destino" rows="2" class="form-textarea" placeholder="¿Para qué vas a usar el dinero?"></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Fotos del vehículo *</label>
          <div class="file-upload-area">
            <input type="file" name="imagenes[]" multiple accept="image/*" required style="display:none;" id="file-prendario">
            <label for="file-prendario" class="file-upload-label">
              <div class="file-upload-text">Subir imágenes</div>
              <div class="file-upload-hint">Frente, atrás, laterales, interior</div>
            </label>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-cancel" onclick="cerrarModal('prendario')">Cancelar</button>
          <button type="submit" class="btn-submit">Enviar solicitud</button>
        </div>
      </form>
    </div>
  </div>
</div>

</main>
</div>

<script>
function abrirModal(tipo) {
    document.getElementById('modal-' + tipo).style.display = 'flex';
}

function cerrarModal(tipo) {
    document.getElementById('modal-' + tipo).style.display = 'none';
}

function cerrarSiFondo(event, tipo) {
    if (event.target === event.currentTarget) {
        cerrarModal(tipo);
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.style.display = 'none';
        });
    }
});
</script>

</body>
</html>