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

// Obtener solicitudes de información pendientes
$solicitudes_pendientes = [];

try {
    $stmt = $pdo->prepare("
        SELECT 
            si.id,
            si.operacion_id,
            si.tipo_operacion,
            si.mensaje,
            si.fecha,
            si.respondida,
            si.fecha_respuesta,
            CASE 
                WHEN si.tipo_operacion = 'prestamo' THEN CONCAT('Préstamo #', si.operacion_id)
                WHEN si.tipo_operacion = 'empeno' THEN CONCAT('Empeño #', si.operacion_id)
                WHEN si.tipo_operacion = 'prendario' THEN CONCAT('Crédito Prendario #', si.operacion_id)
            END as nombre_operacion,
            CASE 
                WHEN si.tipo_operacion = 'prestamo' THEN p.monto_solicitado
                WHEN si.tipo_operacion = 'empeno' THEN e.monto_solicitado
                WHEN si.tipo_operacion = 'prendario' THEN cp.monto_solicitado
            END as monto
        FROM solicitudes_info si
        LEFT JOIN prestamos p ON si.tipo_operacion = 'prestamo' AND si.operacion_id = p.id
        LEFT JOIN empenos e ON si.tipo_operacion = 'empeno' AND si.operacion_id = e.id
        LEFT JOIN creditos_prendarios cp ON si.tipo_operacion = 'prendario' AND si.operacion_id = cp.id
        WHERE si.cliente_id = ?
        ORDER BY si.respondida ASC, si.fecha DESC
    ");
    $stmt->execute([$cliente_id]);
    $solicitudes_pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error obteniendo solicitudes: " . $e->getMessage());
    $error = 'Error al cargar las solicitudes';
}

// Procesar respuesta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitud_id'])) {
    $solicitud_id = (int)$_POST['solicitud_id'];
    $mensaje_respuesta = trim($_POST['mensaje_respuesta'] ?? '');
    
    try {
        $pdo->beginTransaction();
        
        // Verificar que la solicitud pertenece al cliente
        $stmt = $pdo->prepare("SELECT id, operacion_id, tipo_operacion FROM solicitudes_info WHERE id = ? AND cliente_id = ? AND respondida = 0");
        $stmt->execute([$solicitud_id, $cliente_id]);
        $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$solicitud) {
            throw new Exception('Solicitud no encontrada o ya fue respondida');
        }
        
        // Marcar como respondida
        $stmt = $pdo->prepare("
            UPDATE solicitudes_info 
            SET respondida = 1, 
                fecha_respuesta = NOW(),
                respuesta_cliente = ?
            WHERE id = ?
        ");
        $stmt->execute([$mensaje_respuesta, $solicitud_id]);
        
        // Subir archivos de respuesta
        if (!empty($_FILES['archivos_respuesta']['name'][0])) {
            $upload_dir = __DIR__ . '/uploads/solicitudes_info_respuestas/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
            $max_file_size = 10 * 1024 * 1024; // 10MB
            
            foreach ($_FILES['archivos_respuesta']['tmp_name'] as $i => $tmp) {
                if ($_FILES['archivos_respuesta']['error'][$i] === UPLOAD_ERR_OK) {
                    $file_size = $_FILES['archivos_respuesta']['size'][$i];
                    if ($file_size > $max_file_size) {
                        continue; // Skip files larger than 10MB
                    }
                    
                    $ext = strtolower(pathinfo($_FILES['archivos_respuesta']['name'][$i], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed_extensions)) {
                        continue; // Skip invalid file types
                    }
                    
                    $nombre = uniqid('respuesta_') . '.' . $ext;
                    $ruta = $upload_dir . $nombre;
                    
                    if (move_uploaded_file($tmp, $ruta)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO solicitudes_info_respuestas (
                                solicitud_id,
                                archivo,
                                nombre_original
                            ) VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$solicitud_id, $nombre, $_FILES['archivos_respuesta']['name'][$i]]);
                    }
                }
            }
        }
        
        // Cambiar estado de la operación de "info_solicitada" a "en_revision"
        $tabla = match($solicitud['tipo_operacion']) {
            'prestamo' => 'prestamos',
            'empeno' => 'empenos',
            'prendario' => 'creditos_prendarios',
            default => null
        };
        
        if ($tabla) {
            $stmt = $pdo->prepare("UPDATE {$tabla} SET estado = 'en_revision' WHERE id = ?");
            $stmt->execute([$solicitud['operacion_id']]);
        }
        
        // Crear notificación para el asesor
        $stmt = $pdo->prepare("
            INSERT INTO clientes_notificaciones (
                cliente_id, tipo, titulo, mensaje, url_accion, texto_accion
            ) VALUES (?, 'success', 'Información enviada', 
            'Tu respuesta ha sido enviada. El asesor la revisará pronto.', 
            'dashboard_clientes.php', 'Ver Dashboard')
        ");
        $stmt->execute([$cliente_id]);
        
        $pdo->commit();
        $mensaje = '✅ Respuesta enviada correctamente. Tu asesor la revisará pronto.';
        
        // Recargar solicitudes
        header("Location: responder_solicitud_info.php?success=1");
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error respondiendo solicitud: " . $e->getMessage());
        $error = $e->getMessage();
    }
}

// Mensaje de éxito desde redirect
if (isset($_GET['success'])) {
    $mensaje = '✅ Respuesta enviada correctamente. Tu asesor la revisará pronto.';
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
    <title>Responder Solicitudes de Información - Préstamo Líder</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style_clientes.css">
    <style>
        body, .main, p, span, div, label {
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
            margin-top: 8px;
        }

        .solicitud-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #f59e0b;
        }

        .solicitud-card.respondida {
            border-left-color: #10b981;
            opacity: 0.7;
        }

        .solicitud-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .solicitud-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827 !important;
        }

        .solicitud-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .badge-pendiente {
            background: #fef3c7;
            color: #92400e !important;
        }

        .badge-respondida {
            background: #d1fae5;
            color: #065f46 !important;
        }

        .solicitud-info {
            display: flex;
            gap: 24px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-label {
            font-weight: 600;
            color: #6b7280 !important;
            font-size: 0.875rem;
        }

        .info-value {
            color: #111827 !important;
            font-weight: 700;
        }

        .mensaje-asesor {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .mensaje-asesor-title {
            font-weight: 700;
            color: #1e40af !important;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .mensaje-asesor-text {
            color: #1e40af !important;
            line-height: 1.6;
        }

        .archivos-adjuntos {
            margin-top: 12px;
        }

        .archivos-title {
            font-weight: 600;
            color: #374151 !important;
            font-size: 0.875rem;
            margin-bottom: 8px;
        }

        .archivo-item {
            display: inline-block;
            background: #f3f4f6;
            padding: 8px 12px;
            border-radius: 8px;
            margin-right: 8px;
            margin-bottom: 8px;
            font-size: 0.875rem;
            color: #374151 !important;
        }

        .form-respuesta {
            background: #f9fafb;
            padding: 20px;
            border-radius: 12px;
            margin-top: 16px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151 !important;
            margin-bottom: 8px;
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

        .file-upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            background: #ffffff;
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

        .btn-enviar {
            padding: 12px 32px;
            background: linear-gradient(135deg, #3b82f6 0%, #10b981 100%);
            color: #FFFFFF !important;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            font-size: 1rem;
        }

        .btn-enviar:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }

        .respuesta-enviada {
            background: #d1fae5;
            border: 2px solid #10b981;
            padding: 16px;
            border-radius: 12px;
            margin-top: 16px;
        }

        .respuesta-enviada-title {
            font-weight: 700;
            color: #065f46 !important;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .respuesta-enviada-text {
            color: #047857 !important;
            font-size: 0.9rem;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 16px;
        }

        .empty-state-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #111827 !important;
            margin-bottom: 8px;
        }

        .empty-state-text {
            color: #6b7280 !important;
            font-size: 1rem;
        }
    </style>
</head>
<body>

<div class="app">
    <?php include __DIR__ . '/sidebar_clientes.php'; ?>

    <main class="main">
        <div class="page-header">
            <div class="page-title">📄 Solicitudes de Información</div>
            <div class="page-sub">Respondé las solicitudes de tu asesor para continuar con tu trámite</div>
        </div>

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

        <?php if (empty($solicitudes_pendientes)): ?>
            <div class="card">
                <div class="empty-state">
                    <div class="empty-state-icon">✅</div>
                    <div class="empty-state-title">No tenés solicitudes pendientes</div>
                    <div class="empty-state-text">Todas tus solicitudes han sido respondidas</div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($solicitudes_pendientes as $solicitud): 
                $archivos_adjuntos = [];
                try {
                    $stmt = $pdo->prepare("SELECT archivo FROM solicitudes_info_archivos WHERE solicitud_id = ?");
                    $stmt->execute([$solicitud['id']]);
                    $archivos_adjuntos = $stmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e) {
                    error_log("Error cargando archivos: " . $e->getMessage());
                }
            ?>
                <div class="solicitud-card <?= $solicitud['respondida'] ? 'respondida' : '' ?>">
                    <div class="solicitud-header">
                        <div>
                            <div class="solicitud-title"><?= htmlspecialchars($solicitud['nombre_operacion']) ?></div>
                        </div>
                        <span class="solicitud-badge <?= $solicitud['respondida'] ? 'badge-respondida' : 'badge-pendiente' ?>">
                            <?= $solicitud['respondida'] ? '✓ Respondida' : '⏳ Pendiente' ?>
                        </span>
                    </div>

                    <div class="solicitud-info">
                        <div class="info-item">
                            <span class="info-label">Fecha:</span>
                            <span class="info-value"><?= date('d/m/Y H:i', strtotime($solicitud['fecha'])) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Monto:</span>
                            <span class="info-value">$<?= number_format($solicitud['monto'], 0, ',', '.') ?></span>
                        </div>
                    </div>

                    <div class="mensaje-asesor">
                        <div class="mensaje-asesor-title">
                            <span>💬</span> Mensaje del Asesor:
                        </div>
                        <div class="mensaje-asesor-text">
                            <?= nl2br(htmlspecialchars($solicitud['mensaje'])) ?>
                        </div>

                        <?php if (!empty($archivos_adjuntos)): ?>
                            <div class="archivos-adjuntos">
                                <div class="archivos-title">📎 Archivos adjuntos:</div>
                                <?php foreach ($archivos_adjuntos as $archivo): ?>
                                    <a href="uploads/solicitudes_info/<?= htmlspecialchars($archivo) ?>" 
                                       target="_blank" 
                                       class="archivo-item">
                                        📄 <?= htmlspecialchars($archivo) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$solicitud['respondida']): ?>
                        <form method="POST" enctype="multipart/form-data" class="form-respuesta">
                            <input type="hidden" name="solicitud_id" value="<?= $solicitud['id'] ?>">

                            <div class="form-group">
                                <label class="form-label">Tu Respuesta</label>
                                <textarea name="mensaje_respuesta" 
                                          rows="4" 
                                          class="form-textarea" 
                                          placeholder="Explicá lo solicitado o proporcioná la información necesaria..."></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Adjuntar Archivos (Opcional)</label>
                                <div class="file-upload-area">
                                    <input type="file" 
                                           name="archivos_respuesta[]" 
                                           multiple 
                                           accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx"
                                           style="display:none;" 
                                           id="file-respuesta-<?= $solicitud['id'] ?>">
                                    <label for="file-respuesta-<?= $solicitud['id'] ?>" class="file-upload-label">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                            <polyline points="14 2 14 8 20 8"></polyline>
                                        </svg>
                                        <div class="file-upload-text">Subir archivos</div>
                                        <div class="file-upload-hint">PDF, imágenes, documentos • Máx. 10MB por archivo</div>
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="btn-enviar" onclick="return confirm('¿Confirmar envío de respuesta?')">
                                📤 Enviar Respuesta
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="respuesta-enviada">
                            <div class="respuesta-enviada-title">
                                <span>✅</span> Respuesta Enviada
                            </div>
                            <div class="respuesta-enviada-text">
                                Enviaste tu respuesta el <?= date('d/m/Y', strtotime($solicitud['fecha_respuesta'])) ?>. 
                                Tu asesor la está revisando.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</div>

<script>
    // Preview de archivos seleccionados
    document.querySelectorAll('input[type="file"]').forEach(input => {
        input.addEventListener('change', function() {
            const label = this.nextElementSibling || this.previousElementSibling;
            if (this.files.length > 0) {
                const fileNames = Array.from(this.files).map(f => f.name).join(', ');
                console.log('Archivos seleccionados:', fileNames);
            }
        });
    });
</script>

</body>
</html>