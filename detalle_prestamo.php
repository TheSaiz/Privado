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

// Validar ID y tipo
if (!isset($_GET['id']) || (int)$_GET['id'] <= 0) {
    header('Location: dashboard_clientes.php');
    exit;
}

$prestamo_id = (int)$_GET['id'];
$tipo_operacion = strtolower($_GET['tipo'] ?? 'prestamo');

// Conexión
try {
    require_once __DIR__ . '/backend/connection.php';
} catch (Throwable $e) {
    die('Error de conexión');
}

$prestamo = null;
$cuotas = [];
$documentos = [];
$mensaje_respuesta = '';
$error_respuesta = '';

// **PROCESAR ACEPTACIÓN/RECHAZO DE CONTRA-OFERTA**
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_contraoferta'])) {
    $accion = $_POST['accion_contraoferta']; // 'aceptar' o 'rechazar'
    
    try {
        $pdo->beginTransaction();
        
        // Determinar tabla según tipo
        $tabla = match($tipo_operacion) {
            'prendario' => 'creditos_prendarios',
            'empeno' => 'empenos',
            default => 'prestamos'
        };
        
        // Verificar que el préstamo existe y está en contraoferta
        $stmt = $pdo->prepare("SELECT id, estado FROM {$tabla} WHERE id = ? AND cliente_id = ?");
        $stmt->execute([$prestamo_id, $cliente_id]);
        $prestamo_check = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$prestamo_check || $prestamo_check['estado'] !== 'contraoferta') {
            throw new Exception('Operación no encontrada o no es una contra-oferta');
        }
        
        if ($accion === 'aceptar') {
            // Aceptar contra-oferta: cambiar a 'aprobado' y preparar para firma
            $stmt = $pdo->prepare("
                UPDATE {$tabla} SET 
                    estado = 'aprobado',
                    estado_contrato = 'pendiente_firma',
                    fecha_aceptacion_contraoferta = NOW()
                WHERE id = ? AND cliente_id = ?
            ");
            $stmt->execute([$prestamo_id, $cliente_id]);
            
            // Crear notificación de aceptación
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO clientes_notificaciones (
                        cliente_id, tipo, titulo, mensaje, url_accion, texto_accion
                    ) VALUES (?, 'success', '✅ Contra-oferta aceptada', 
                    'Has aceptado la contra-oferta. Ahora debes firmar el contrato para continuar.', 
                    'firmar_contrato.php?id={$prestamo_id}&tipo={$tipo_operacion}', 'Firmar Contrato')
                ");
                $stmt->execute([$cliente_id]);
            } catch (Exception $e) {
                error_log("Error creando notificación: " . $e->getMessage());
            }
            
            $mensaje_respuesta = '✅ ¡Contra-oferta aceptada! Ahora podés firmar tu contrato.';
            
        } elseif ($accion === 'rechazar') {
            // Rechazar contra-oferta
            $stmt = $pdo->prepare("
                UPDATE {$tabla} SET 
                    estado = 'rechazado',
                    fecha_rechazo_contraoferta = NOW()
                WHERE id = ? AND cliente_id = ?
            ");
            $stmt->execute([$prestamo_id, $cliente_id]);
            
            // Crear notificación de rechazo
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO clientes_notificaciones (
                        cliente_id, tipo, titulo, mensaje
                    ) VALUES (?, 'info', 'Contra-oferta rechazada', 
                    'Has rechazado la contra-oferta. Podés solicitar un nuevo préstamo cuando desees.')
                ");
                $stmt->execute([$cliente_id]);
            } catch (Exception $e) {
                error_log("Error creando notificación: " . $e->getMessage());
            }
            
            $mensaje_respuesta = '❌ Contra-oferta rechazada. Podés solicitar un nuevo préstamo.';
        }
        
        $pdo->commit();
        
        // Recargar página con mensaje
        header("Location: detalle_prestamo.php?id={$prestamo_id}&tipo={$tipo_operacion}&msg=" . urlencode($mensaje_respuesta ?? ''));
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error procesando contra-oferta: " . $e->getMessage());
        $error_respuesta = $e->getMessage();
    }
}

// **PROCESAR RESPUESTA A SOLICITUD DE INFORMACIÓN**
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitud_id'])) {
    $solicitud_id = (int)$_POST['solicitud_id'];
    $mensaje_cliente = trim($_POST['mensaje_respuesta'] ?? '');
    
    try {
        $pdo->beginTransaction();
        
        // Verificar que la solicitud pertenece al cliente
        $stmt = $pdo->prepare("SELECT id, operacion_id, tipo_operacion FROM solicitudes_info WHERE id = ? AND cliente_id = ? AND (respuesta IS NULL OR respuesta = '')");
        $stmt->execute([$solicitud_id, $cliente_id]);
        $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$solicitud) {
            throw new Exception('Solicitud no encontrada o ya fue respondida');
        }
        
        // Marcar como respondida
        $stmt = $pdo->prepare("
            UPDATE solicitudes_info 
            SET respuesta = ?, 
                fecha_respuesta = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$mensaje_cliente, $solicitud_id]);
        
        // Subir archivos de respuesta (si la tabla existe)
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
                        continue;
                    }
                    
                    $ext = strtolower(pathinfo($_FILES['archivos_respuesta']['name'][$i], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed_extensions)) {
                        continue;
                    }
                    
                    $nombre = uniqid('respuesta_') . '.' . $ext;
                    $ruta = $upload_dir . $nombre;
                    
                    if (move_uploaded_file($tmp, $ruta)) {
                        // Intentar guardar en tabla de respuestas si existe
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO solicitudes_info_respuestas (
                                    solicitud_id,
                                    archivo,
                                    nombre_original
                                ) VALUES (?, ?, ?)
                            ");
                            $stmt->execute([$solicitud_id, $nombre, $_FILES['archivos_respuesta']['name'][$i]]);
                        } catch (Exception $e) {
                            // Si la tabla no existe, solo guardar el archivo
                            error_log("Tabla solicitudes_info_respuestas no existe: " . $e->getMessage());
                        }
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
        
        // Crear notificación para el cliente
        try {
            $stmt = $pdo->prepare("
                INSERT INTO clientes_notificaciones (
                    cliente_id, tipo, titulo, mensaje, url_accion, texto_accion
                ) VALUES (?, 'success', 'Información enviada', 
                'Tu respuesta ha sido enviada. El asesor la revisará pronto.', 
                'dashboard_clientes.php', 'Ver Dashboard')
            ");
            $stmt->execute([$cliente_id]);
        } catch (Exception $e) {
            // Si la tabla no existe, continuar sin notificación
            error_log("Error creando notificación: " . $e->getMessage());
        }
        
        $pdo->commit();
        
        // Recargar la misma página con mensaje de éxito
        header("Location: detalle_prestamo.php?id={$prestamo_id}&tipo={$tipo_operacion}&success=1");
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error respondiendo solicitud: " . $e->getMessage());
        $error_respuesta = $e->getMessage();
    }
}

// Verificar si viene con mensaje de éxito
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $mensaje_respuesta = '✅ Respuesta enviada correctamente. Tu asesor la revisará pronto.';
}

// Verificar si viene con mensaje del sistema
if (isset($_GET['msg'])) {
    $mensaje_respuesta = $_GET['msg'];
}

// **VERIFICAR SOLICITUDES DE INFORMACIÓN PENDIENTES**
$solicitud_pendiente = null;
$tiene_solicitud_info = false;

try {
    // Buscar solicitudes SIN respuesta (campo respuesta NULL o vacío)
    $stmt = $pdo->prepare("
        SELECT 
            si.id,
            si.mensaje,
            si.fecha,
            si.respuesta,
            si.fecha_respuesta
        FROM solicitudes_info si
        WHERE si.cliente_id = ? 
        AND si.operacion_id = ? 
        AND si.tipo_operacion = ?
        AND (si.respuesta IS NULL OR si.respuesta = '')
        ORDER BY si.fecha DESC
        LIMIT 1
    ");
    $stmt->execute([$cliente_id, $prestamo_id, $tipo_operacion]);
    $solicitud_pendiente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Obtener archivos adjuntos de la solicitud si existe
    if ($solicitud_pendiente) {
        $tiene_solicitud_info = true;
        $solicitud_pendiente['archivos'] = [];
        
        // Intentar obtener archivos si la tabla existe
        try {
            $stmt = $pdo->prepare("SELECT archivo FROM solicitudes_info_archivos WHERE solicitud_id = ?");
            $stmt->execute([$solicitud_pendiente['id']]);
            $solicitud_pendiente['archivos'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            // Si la tabla no existe, continuar sin archivos
            error_log("Tabla solicitudes_info_archivos no existe: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    error_log("Error verificando solicitudes: " . $e->getMessage());
}

// **VERIFICAR SI HAY CONTRA-OFERTA**
$es_contraoferta = false;
$datos_contraoferta = null;

// **OBTENER DATOS DE LA OPERACIÓN**
try {
    if ($tipo_operacion === 'prendario') {
        $stmt = $pdo->prepare("
            SELECT 
                cp.*,
                cd.nombre_completo as cliente_nombre,
                cd.email as cliente_email
            FROM creditos_prendarios cp
            LEFT JOIN clientes_detalles cd ON cp.cliente_id = cd.usuario_id
            WHERE cp.id = ? AND cp.cliente_id = ?
            LIMIT 1
        ");
        $stmt->execute([$prestamo_id, $cliente_id]);
        $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($prestamo) {
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    tipo,
                    nombre_original as nombre_archivo,
                    ruta_archivo,
                    fecha_subida as created_at
                FROM prendarios_imagenes
                WHERE credito_id = ?
                ORDER BY 
                    CASE tipo
                        WHEN 'frente' THEN 1
                        WHEN 'trasera' THEN 2
                        WHEN 'lateral_izq' THEN 3
                        WHEN 'lateral_der' THEN 4
                        ELSE 5
                    END,
                    fecha_subida ASC
            ");
            $stmt->execute([$prestamo_id]);
            $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } elseif ($tipo_operacion === 'empeno') {
        $stmt = $pdo->prepare("
            SELECT 
                e.*,
                cd.nombre_completo as cliente_nombre,
                cd.email as cliente_email
            FROM empenos e
            LEFT JOIN clientes_detalles cd ON e.cliente_id = cd.usuario_id
            WHERE e.id = ? AND e.cliente_id = ?
            LIMIT 1
        ");
        $stmt->execute([$prestamo_id, $cliente_id]);
        $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($prestamo) {
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    'producto' as tipo,
                    nombre_original as nombre_archivo,
                    ruta_archivo,
                    fecha_subida as created_at
                FROM empenos_imagenes
                WHERE empeno_id = ?
                ORDER BY fecha_subida ASC
            ");
            $stmt->execute([$prestamo_id]);
            $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                cd.nombre_completo as cliente_nombre,
                cd.email as cliente_email
            FROM prestamos p
            LEFT JOIN clientes_detalles cd ON p.cliente_id = cd.usuario_id
            WHERE p.id = ? AND p.cliente_id = ?
            LIMIT 1
        ");
        $stmt->execute([$prestamo_id, $cliente_id]);
        $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($prestamo) {
            $stmt = $pdo->prepare("
                SELECT *
                FROM prestamos_pagos
                WHERE prestamo_id = ?
                ORDER BY cuota_num ASC
            ");
            $stmt->execute([$prestamo_id]);
            $cuotas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("
                SELECT *
                FROM prestamos_documentos
                WHERE prestamo_id = ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$prestamo_id]);
            $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if (!$prestamo) {
        header('Location: dashboard_clientes.php');
        exit;
    }

    // **VERIFICAR SI ES CONTRA-OFERTA**
    if (($prestamo['estado'] ?? '') === 'contraoferta') {
        $es_contraoferta = true;
        $datos_contraoferta = [
            'monto_solicitado' => $prestamo['monto_solicitado'] ?? 0,
            'cuotas_solicitadas' => $prestamo['cuotas_solicitadas'] ?? 1,
            'frecuencia_solicitada' => $prestamo['frecuencia_solicitada'] ?? 'mensual',
            'monto_ofrecido' => $prestamo['monto_ofrecido'] ?? 0,
            'cuotas_ofrecidas' => $prestamo['cuotas_ofrecidas'] ?? 1,
            'frecuencia_ofrecida' => $prestamo['frecuencia_ofrecida'] ?? 'mensual',
            'tasa_interes_ofrecida' => $prestamo['tasa_interes_ofrecida'] ?? 0,
            'monto_total_ofrecido' => $prestamo['monto_total_ofrecido'] ?? 0,
            'comentarios_admin' => $prestamo['comentarios_admin'] ?? '',
            'fecha_contraoferta' => $prestamo['fecha_contraoferta'] ?? null
        ];
    }

    $stmt = $pdo->prepare("
        SELECT nombre_completo, email, docs_completos
        FROM clientes_detalles
        WHERE usuario_id = ?
        LIMIT 1
    ");
    $stmt->execute([$cliente_id]);
    $cliente_info = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

} catch (Throwable $e) {
    die('Error al cargar datos: ' . $e->getMessage());
}

// Título según tipo
$titulo_tipo = [
    'prestamo' => 'Préstamo Personal',
    'prendario' => 'Crédito Prendario',
    'empeno' => 'Empeño'
][$tipo_operacion] ?? 'Operación';

// Calcular estadísticas de cuotas
$total_cuotas = count($cuotas);
$cuotas_pagadas = 0;
$cuotas_pendientes = 0;
$cuotas_vencidas = 0;
$total_pagado = 0;
$total_pendiente = 0;

foreach ($cuotas as $c) {
    if ($c['estado'] === 'pagado') {
        $cuotas_pagadas++;
        $total_pagado += (float)$c['monto'];
    } else {
        $cuotas_pendientes++;
        $total_pendiente += (float)$c['monto'];
        if (in_array($c['estado'], ['vencido', 'mora'])) {
            $cuotas_vencidas++;
        }
    }
}

$progreso = $total_cuotas > 0 ? round(($cuotas_pagadas / $total_cuotas) * 100) : 0;

// Extraer valores según el tipo
if ($tipo_operacion === 'prestamo') {
    $monto_solicitado = $prestamo['monto_solicitado'] ?? 0;
    $monto_ofrecido = $prestamo['monto_ofrecido'] ?? 0;
    $monto_aprobado = $prestamo['monto'] ?? 0;
    $cuotas_mostrar = $prestamo['cuotas'] ?? $prestamo['cuotas_ofrecidas'] ?? $prestamo['cuotas_solicitadas'] ?? 0;
    $frecuencia_mostrar = $prestamo['frecuencia_pago'] ?? $prestamo['frecuencia_ofrecida'] ?? $prestamo['frecuencia_solicitada'] ?? 'mensual';
    $tasa_mostrar = $prestamo['tasa_interes'] ?? $prestamo['tasa_interes_ofrecida'] ?? 0;
    $total_mostrar = $prestamo['monto_total'] ?? $prestamo['monto_total_ofrecido'] ?? 0;
} else {
    $monto_solicitado = $prestamo['monto_solicitado'] ?? 0;
    $monto_ofrecido = $prestamo['monto_ofrecido'] ?? 0;
    $monto_aprobado = $prestamo['monto_final'] ?? 0;
    $cuotas_mostrar = $prestamo['cuotas_final'] ?? $prestamo['cuotas_ofrecidas'] ?? 1;
    $frecuencia_mostrar = $prestamo['frecuencia_final'] ?? $prestamo['frecuencia_ofrecida'] ?? 'mensual';
    $tasa_mostrar = $prestamo['tasa_interes_final'] ?? $prestamo['tasa_interes_ofrecida'] ?? 0;
    $total_mostrar = $prestamo['monto_total_final'] ?? $prestamo['monto_total_ofrecido'] ?? 0;
}

// Variables para sidebar
$pagina_activa = 'prestamos';
$docs_completos = (int)($cliente_info['docs_completos'] ?? 0) === 1;
$noti_no_leidas = 0;

// Mapeo de estados
$estados_map = [
    'pendiente' => ['texto' => 'Pendiente', 'clase' => 'warning'],
    'en_revision' => ['texto' => 'En Revisión', 'clase' => 'info'],
    'info_solicitada' => ['texto' => 'Info Solicitada', 'clase' => 'warning'],
    'contraoferta' => ['texto' => 'Contra-Oferta', 'clase' => 'purple'],
    'aprobado' => ['texto' => 'Aprobado', 'clase' => 'success'],
    'rechazado' => ['texto' => 'Rechazado', 'clase' => 'danger'],
    'activo' => ['texto' => 'Activo', 'clase' => 'success'],
    'finalizado' => ['texto' => 'Finalizado', 'clase' => 'secondary'],
    'cancelado' => ['texto' => 'Cancelado', 'clase' => 'danger']
];

$estado_actual = $estados_map[$prestamo['estado']] ?? ['texto' => ucfirst($prestamo['estado']), 'clase' => 'secondary'];

// Mapeo de tipos de documento
$tipos_doc_map = [
    'frente' => 'Vista Frontal',
    'trasera' => 'Vista Trasera',
    'lateral_izq' => 'Lateral Izquierdo',
    'lateral_der' => 'Lateral Derecho',
    'producto' => 'Foto del Producto',
    'comprobante_ingresos' => 'Comprobante de Ingresos',
    'dni' => 'DNI',
    'otro' => 'Otro'
];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?php echo $titulo_tipo; ?> #<?php echo $prestamo_id; ?> - Préstamo Líder</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style_clientes.css">
  
  <style>
    .main { background: #f5f7fb !important; }
    body, .main, .page-header, .page-title, .page-sub, .card, p, span, div, td, th, label {
      color: #1f2937 !important;
    }
    
    /* ALERTA DE CONTRA-OFERTA - VERSIÓN COMPLETA */
    .alerta-contraoferta {
      background: linear-gradient(135deg, #7c5cff, #a78bfa);
      border-radius: 20px;
      padding: 32px;
      margin-bottom: 28px;
      box-shadow: 0 8px 32px rgba(124, 92, 255, 0.4);
      animation: pulse-contraoferta 2s infinite;
      position: relative;
      overflow: hidden;
    }

    .alerta-contraoferta::before {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: linear-gradient(45deg, transparent, rgba(255,255,255,0.15), transparent);
      animation: shine 3s infinite;
    }

    @keyframes pulse-contraoferta {
      0%, 100% {
        box-shadow: 0 8px 32px rgba(124, 92, 255, 0.4);
        transform: scale(1);
      }
      50% {
        box-shadow: 0 12px 48px rgba(124, 92, 255, 0.6);
        transform: scale(1.01);
      }
    }

    @keyframes shine {
      0% { transform: rotate(0deg) translateX(-100%); }
      100% { transform: rotate(0deg) translateX(100%); }
    }

    .alerta-contraoferta-content {
      position: relative;
      z-index: 1;
    }

    .alerta-contraoferta-header {
      display: flex;
      align-items: center;
      gap: 16px;
      margin-bottom: 24px;
      text-align: center;
      justify-content: center;
    }

    .alerta-contraoferta-icon {
      font-size: 4rem;
      animation: bounce 1s infinite;
    }

    @keyframes bounce {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-15px); }
    }

    .alerta-contraoferta-title {
      font-size: 2rem;
      font-weight: 800;
      color: #ffffff !important;
      margin: 0;
      text-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }

    .contraoferta-comparacion {
      background: rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(10px);
      border-radius: 20px;
      padding: 32px;
      margin: 24px 0;
      display: grid;
      grid-template-columns: 1fr auto 1fr;
      gap: 32px;
      align-items: center;
    }

    .contraoferta-columna {
      text-align: center;
    }

    .contraoferta-columna-label {
      font-size: 1rem;
      font-weight: 700;
      color: rgba(255, 255, 255, 0.9) !important;
      text-transform: uppercase;
      letter-spacing: 2px;
      margin-bottom: 16px;
      display: block;
    }

    .contraoferta-valor {
      font-size: 3rem;
      font-weight: 900;
      color: #ffffff !important;
      text-shadow: 0 4px 12px rgba(0,0,0,0.3);
      margin-bottom: 12px;
      display: block;
    }

    .contraoferta-subvalor {
      font-size: 1.1rem;
      color: rgba(255, 255, 255, 0.95) !important;
      margin-top: 8px;
      font-weight: 600;
    }

    .contraoferta-vs {
      font-size: 3rem;
      font-weight: 900;
      color: rgba(255, 255, 255, 0.6) !important;
      text-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }

    .contraoferta-mensaje {
      background: rgba(255, 255, 255, 0.25);
      border-left: 6px solid #ffffff;
      padding: 20px;
      border-radius: 12px;
      margin: 24px 0;
    }

    .contraoferta-mensaje-label {
      font-weight: 800;
      color: #ffffff !important;
      margin-bottom: 12px;
      display: block;
      font-size: 1.1rem;
    }

    .contraoferta-mensaje-texto {
      color: #fef3c7 !important;
      line-height: 1.8;
      font-size: 1.05rem;
    }

    .contraoferta-acciones {
      display: flex;
      gap: 20px;
      margin-top: 32px;
    }

    .btn-contraoferta {
      flex: 1;
      padding: 20px;
      border: none;
      border-radius: 16px;
      font-size: 1.3rem;
      font-weight: 800;
      cursor: pointer;
      transition: all 0.3s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      text-decoration: none;
      box-shadow: 0 4px 20px rgba(0,0,0,0.2);
    }

    .btn-contraoferta-aceptar {
      background: linear-gradient(135deg, #10b981, #059669);
      color: #ffffff !important;
    }

    .btn-contraoferta-aceptar:hover {
      transform: translateY(-4px);
      box-shadow: 0 8px 32px rgba(16, 185, 129, 0.5);
    }

    .btn-contraoferta-rechazar {
      background: rgba(255, 255, 255, 0.25);
      color: #ffffff !important;
      border: 3px solid rgba(255, 255, 255, 0.6);
    }

    .btn-contraoferta-rechazar:hover {
      background: rgba(255, 255, 255, 0.35);
      transform: translateY(-4px);
      box-shadow: 0 8px 32px rgba(255, 255, 255, 0.3);
    }
    
    /* Alerta de solicitud pendiente */
    .alerta-solicitud-info {
      background: linear-gradient(135deg, #ef4444, #dc2626);
      border-radius: 16px;
      padding: 24px;
      margin-bottom: 24px;
      box-shadow: 0 4px 20px rgba(239, 68, 68, 0.3);
      animation: pulse-urgent 2s infinite;
    }

    @keyframes pulse-urgent {
      0%, 100% {
        box-shadow: 0 4px 20px rgba(239, 68, 68, 0.3);
      }
      50% {
        box-shadow: 0 8px 30px rgba(239, 68, 68, 0.5);
      }
    }

    .alerta-header {
      display: flex;
      align-items: center;
      gap: 16px;
      margin-bottom: 16px;
    }

    .alerta-icon {
      font-size: 2.5rem;
      flex-shrink: 0;
    }

    .alerta-title {
      font-size: 1.3rem;
      font-weight: 700;
      color: #ffffff !important;
      margin: 0;
    }

    .alerta-mensaje {
      background: rgba(255, 255, 255, 0.15);
      border-left: 4px solid #ffffff;
      padding: 16px;
      border-radius: 8px;
      margin: 16px 0;
    }

    .alerta-mensaje-label {
      font-weight: 700;
      color: #ffffff !important;
      font-size: 0.9rem;
      margin-bottom: 8px;
      display: block;
    }

    .alerta-mensaje-texto {
      color: #fef2f2 !important;
      line-height: 1.6;
      font-size: 0.95rem;
    }

    .alerta-archivos {
      margin-top: 12px;
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .alerta-archivo {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      background: rgba(255, 255, 255, 0.2);
      color: #ffffff !important;
      border-radius: 8px;
      font-size: 0.85rem;
      text-decoration: none;
      transition: all 0.2s;
    }

    .alerta-archivo:hover {
      background: rgba(255, 255, 255, 0.3);
    }

    .btn-responder {
      padding: 12px 32px;
      background: #ffffff;
      color: #ef4444 !important;
      border: none;
      border-radius: 10px;
      font-weight: 700;
      font-size: 1rem;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s;
    }

    .btn-responder:hover {
      background: #fef2f2;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(255, 255, 255, 0.3);
    }
    
    .detalle-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
      flex-wrap: wrap;
      gap: 16px;
    }

    .detalle-title {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .detalle-title h1 {
      font-size: 1.75rem;
      font-weight: 700;
      color: #0f172a;
      margin: 0;
    }

    .badge-estado {
      display: inline-block;
      padding: 8px 16px;
      border-radius: 20px;
      font-size: 0.9rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .badge-estado.success { background: #d1fae5; color: #065f46; }
    .badge-estado.warning { background: #fef3c7; color: #92400e; }
    .badge-estado.info { background: #dbeafe; color: #1e40af; }
    .badge-estado.danger { background: #fee2e2; color: #991b1b; }
    .badge-estado.secondary { background: #f3f4f6; color: #4b5563; }
    .badge-estado.purple { 
      background: linear-gradient(135deg, #a78bfa, #c4b5fd); 
      color: #5b21b6; 
      animation: pulse-badge 2s infinite;
    }

    @keyframes pulse-badge {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.05); }
    }

    .badge-tipo {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 12px;
      font-size: 0.85rem;
      font-weight: 600;
      background: rgba(124, 92, 255, 0.15);
      color: #7c5cff;
    }

    .badge-tipo.prendario {
      background: rgba(59, 130, 246, 0.15);
      color: #3b82f6;
    }

    .badge-tipo.empeno {
      background: rgba(245, 158, 11, 0.15);
      color: #f59e0b;
    }

    .btn-back {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 20px;
      background: rgba(124, 92, 255, 0.1);
      color: #7c5cff;
      border: 1px solid rgba(124, 92, 255, 0.3);
      border-radius: 10px;
      text-decoration: none;
      font-weight: 600;
      transition: all 0.2s;
    }

    .btn-back:hover {
      background: rgba(124, 92, 255, 0.2);
      transform: translateX(-4px);
    }

    .grid-2 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 20px;
      margin-bottom: 24px;
    }

    .card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      padding: 24px;
    }

    .card h3 {
      font-size: 1.1rem;
      font-weight: 600;
      color: #0f172a;
      margin: 0 0 20px 0;
      padding-bottom: 12px;
      border-bottom: 1px solid #e5e7eb;
    }

    .info-row {
      display: flex;
      justify-content: space-between;
      padding: 12px 0;
      border-bottom: 1px solid #f1f5f9;
      gap: 12px;
    }

    .info-row:last-child { border-bottom: none; }

    .info-label {
      color: #64748b;
      font-size: 0.9rem;
      flex-shrink: 0;
    }

    .info-value {
      color: #0f172a;
      font-weight: 600;
      text-align: right;
      word-break: break-word;
    }

    .info-value.highlight {
      color: #7c5cff;
      font-size: 1.1rem;
    }

    .progress-container { margin: 20px 0; }

    .progress-bar-wrapper {
      width: 100%;
      height: 12px;
      background: #f1f5f9;
      border-radius: 10px;
      overflow: hidden;
      margin: 8px 0;
    }

    .progress-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, #7c5cff, #a78bfa);
      border-radius: 10px;
      transition: width 0.3s ease;
    }

    .progress-text {
      font-size: 0.85rem;
      color: #64748b;
      text-align: center;
    }

    .tabla-cuotas {
      width: 100%;
      border-collapse: collapse;
      margin-top: 16px;
    }

    .tabla-cuotas thead { background: #eef2ff; }

    .tabla-cuotas th {
      padding: 12px;
      text-align: left;
      font-size: 0.85rem;
      font-weight: 600;
      color: #475569;
      text-transform: uppercase;
    }

    .tabla-cuotas td {
      padding: 14px 12px;
      border-bottom: 1px solid #f1f5f9;
      font-size: 0.9rem;
      color: #0f172a;
    }

    .tabla-cuotas tr:hover { background: #f8fafc; }

    .estado-cuota {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 0.8rem;
      font-weight: 600;
    }

    .estado-cuota.pagado { background: #d1fae5; color: #065f46; }
    .estado-cuota.pendiente { background: #fef3c7; color: #92400e; }
    .estado-cuota.vencido,
    .estado-cuota.mora { background: #fee2e2; color: #991b1b; }

    .documento-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px;
      background: #f8fafc;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      margin-bottom: 12px;
      transition: all 0.2s;
    }

    .documento-item:hover {
      background: #f1f5f9;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }

    .documento-info {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .documento-icon {
      width: 48px;
      height: 48px;
      background: linear-gradient(135deg, #7c5cff, #a78bfa);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.4rem;
      flex-shrink: 0;
    }

    .documento-detalles h4 {
      margin: 0 0 4px 0;
      font-size: 0.95rem;
      font-weight: 600;
      color: #0f172a;
    }

    .documento-detalles p {
      margin: 0;
      font-size: 0.8rem;
      color: #64748b;
    }

    .btn-ver-doc {
      padding: 8px 16px;
      background: #7c5cff;
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.2s;
      flex-shrink: 0;
    }

    .btn-ver-doc:hover {
      background: #6a4de8;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(124, 92, 255, 0.3);
    }

    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: #94a3b8;
    }

    .empty-state svg {
      width: 64px;
      height: 64px;
      opacity: 0.3;
      margin-bottom: 16px;
    }

    /* Modal */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.8);
      backdrop-filter: blur(4px);
    }

    .modal.active {
      display: flex;
      align-items: center;
      justify-content: center;
      animation: fadeIn 0.2s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    .modal-content {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      max-width: 90%;
      max-height: 90vh;
      overflow: auto;
      position: relative;
      animation: slideUp 0.3s ease;
    }

    @keyframes slideUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 20px 24px;
      border-bottom: 1px solid #e5e7eb;
    }

    .modal-header h3 {
      margin: 0;
      color: #0f172a;
      font-size: 1.2rem;
    }

    .modal-close {
      background: none;
      border: none;
      color: #6b7280;
      font-size: 1.5rem;
      cursor: pointer;
      padding: 0;
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      transition: all 0.2s;
    }

    .modal-close:hover {
      background: #f3f4f6;
      color: #0f172a;
    }

    .modal-body {
      padding: 24px;
    }

    .modal-body img {
      width: 100%;
      height: auto;
      border-radius: 8px;
    }

    @media (max-width: 768px) {
      .detalle-header {
        flex-direction: column;
        align-items: flex-start;
      }

      .grid-2 {
        grid-template-columns: 1fr;
      }

      .tabla-cuotas {
        font-size: 0.85rem;
      }

      .tabla-cuotas th,
      .tabla-cuotas td {
        padding: 8px 6px;
      }

      .contraoferta-comparacion {
        grid-template-columns: 1fr;
        gap: 16px;
      }

      .contraoferta-vs {
        transform: rotate(90deg);
        font-size: 2rem;
      }

      .contraoferta-acciones {
        flex-direction: column;
      }

      .contraoferta-valor {
        font-size: 2rem;
      }

      .alerta-contraoferta-title {
        font-size: 1.5rem;
      }
    }
  </style>
</head>
<body>

<div class="app">
  <?php include __DIR__ . '/sidebar_clientes.php'; ?>

  <main class="main">
    
    <?php if ($mensaje_respuesta): ?>
      <div class="card p-4 mb-6" style="border-left:4px solid <?php echo strpos($mensaje_respuesta, '✅') !== false ? '#22c55e' : '#ef4444'; ?>; background: <?php echo strpos($mensaje_respuesta, '✅') !== false ? '#d1fae5' : '#fee2e2'; ?>;">
        <p class="font-semibold" style="color:<?php echo strpos($mensaje_respuesta, '✅') !== false ? '#065f46' : '#991b1b'; ?> !important;"><?= h($mensaje_respuesta) ?></p>
      </div>
    <?php endif; ?>

    <?php if ($error_respuesta): ?>
      <div class="card p-4 mb-6" style="border-left:4px solid #ef4444; background: #fee2e2;">
        <p class="font-semibold" style="color:#991b1b !important;">❌ <?= h($error_respuesta) ?></p>
      </div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- ALERTA DE CONTRA-OFERTA - PRIORIDAD MÁXIMA -->
    <!-- ============================================ -->
    <?php if ($es_contraoferta && $datos_contraoferta): ?>
    <div class="alerta-contraoferta">
      <div class="alerta-contraoferta-content">
        <div class="alerta-contraoferta-header">
          <div class="alerta-contraoferta-icon">🎯</div>
          <h2 class="alerta-contraoferta-title">
            ¡TENÉS UNA CONTRA-OFERTA!
          </h2>
        </div>

        <div class="contraoferta-comparacion">
          <div class="contraoferta-columna">
            <span class="contraoferta-columna-label">TU SOLICITUD</span>
            <span class="contraoferta-valor">
              $<?php echo number_format((float)$datos_contraoferta['monto_solicitado'], 0, ',', '.'); ?>
            </span>
            <div class="contraoferta-subvalor">
              <?php echo (int)$datos_contraoferta['cuotas_solicitadas']; ?> 
              cuotas <?php echo h($datos_contraoferta['frecuencia_solicitada']); ?>es
            </div>
          </div>

          <div class="contraoferta-vs">→</div>

          <div class="contraoferta-columna">
            <span class="contraoferta-columna-label">NUESTRA OFERTA</span>
            <span class="contraoferta-valor">
              $<?php echo number_format((float)$datos_contraoferta['monto_ofrecido'], 0, ',', '.'); ?>
            </span>
            <div class="contraoferta-subvalor">
              <?php echo (int)$datos_contraoferta['cuotas_ofrecidas']; ?> 
              cuotas <?php echo h($datos_contraoferta['frecuencia_ofrecida']); ?>es
              <?php if ($datos_contraoferta['tasa_interes_ofrecida']): ?>
                <br>Tasa: <?php echo number_format((float)$datos_contraoferta['tasa_interes_ofrecida'], 2); ?>%
              <?php endif; ?>
            </div>
            <?php if ($datos_contraoferta['monto_total_ofrecido']): ?>
            <div class="contraoferta-subvalor" style="margin-top: 12px; font-size: 1.2rem;">
              <strong>Total a pagar: $<?php echo number_format((float)$datos_contraoferta['monto_total_ofrecido'], 0, ',', '.'); ?></strong>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!empty($datos_contraoferta['comentarios_admin'])): ?>
        <div class="contraoferta-mensaje">
          <span class="contraoferta-mensaje-label">💬 Mensaje de tu asesor:</span>
          <div class="contraoferta-mensaje-texto">
            <?php echo nl2br(h($datos_contraoferta['comentarios_admin'])); ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="contraoferta-acciones">
          <form method="POST" style="flex: 1;" onsubmit="return confirmarAccion('aceptar')">
            <input type="hidden" name="accion_contraoferta" value="aceptar">
            <button type="submit" class="btn-contraoferta btn-contraoferta-aceptar">
              ✅ ACEPTAR OFERTA
            </button>
          </form>

          <form method="POST" style="flex: 1;" onsubmit="return confirmarAccion('rechazar')">
            <input type="hidden" name="accion_contraoferta" value="rechazar">
            <button type="submit" class="btn-contraoferta btn-contraoferta-rechazar">
              ❌ RECHAZAR OFERTA
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <!-- ============================================ -->

    <?php if ($tiene_solicitud_info && $solicitud_pendiente): ?>
    <!-- ALERTA DE SOLICITUD PENDIENTE CON BOTÓN PARA ABRIR MODAL -->
    <div class="alerta-solicitud-info">
      <div class="alerta-header">
        <div class="alerta-icon">⚠️</div>
        <h2 class="alerta-title">¡Atención! Tu asesor solicita más información</h2>
      </div>
      
      <div class="alerta-mensaje">
        <span class="alerta-mensaje-label">📄 Mensaje del asesor:</span>
        <div class="alerta-mensaje-texto">
          <?php echo nl2br(h($solicitud_pendiente['mensaje'])); ?>
        </div>
        
        <?php if (!empty($solicitud_pendiente['archivos'])): ?>
          <div class="alerta-archivos">
            <span class="alerta-mensaje-label" style="display: block; width: 100%; margin-bottom: 8px;">📎 Archivos adjuntos:</span>
            <?php foreach ($solicitud_pendiente['archivos'] as $archivo): ?>
              <a href="uploads/solicitudes_info/<?php echo h($archivo); ?>" 
                 target="_blank" 
                 class="alerta-archivo">
                📄 <?php echo h($archivo); ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div style="margin-top: 20px;">
        <button onclick="abrirModalRespuesta()" class="btn-responder" style="font-size: 1.1rem; padding: 14px 40px;">
          📤 Responder Solicitud
        </button>
      </div>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="detalle-header">
      <div class="detalle-title">
        <h1>
          <?php echo $titulo_tipo; ?> #<?php echo $prestamo_id; ?>
        </h1>
        <span class="badge-tipo <?php echo $tipo_operacion; ?>">
          <?php echo strtoupper($tipo_operacion); ?>
        </span>
        <span class="badge-estado <?php echo $estado_actual['clase']; ?>">
          <?php echo h($estado_actual['texto']); ?>
        </span>
      </div>
      <a href="dashboard_clientes.php" class="btn-back">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
          <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
        Volver
      </a>
    </div>

    <!-- Resumen -->
    <div class="grid-2">
      <div class="card">
        <h3>📋 Información de la Operación</h3>
        <div class="info-row">
          <span class="info-label">Monto Solicitado</span>
          <span class="info-value">$<?php echo number_format((float)$monto_solicitado, 0, ',', '.'); ?></span>
        </div>
        <?php if ($monto_ofrecido > 0): ?>
        <div class="info-row">
          <span class="info-label">Monto Ofrecido</span>
          <span class="info-value">$<?php echo number_format((float)$monto_ofrecido, 0, ',', '.'); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($monto_aprobado > 0): ?>
        <div class="info-row">
          <span class="info-label">Monto Aprobado</span>
          <span class="info-value highlight">$<?php echo number_format((float)$monto_aprobado, 0, ',', '.'); ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
          <span class="info-label">Cuotas</span>
          <span class="info-value"><?php echo (int)$cuotas_mostrar; ?> <?php echo h($frecuencia_mostrar); ?>es</span>
        </div>
        <?php if ($tasa_mostrar > 0): ?>
        <div class="info-row">
          <span class="info-label">Tasa de Interés</span>
          <span class="info-value"><?php echo number_format((float)$tasa_mostrar, 2); ?>%</span>
        </div>
        <?php endif; ?>
        <?php if ($total_mostrar > 0): ?>
        <div class="info-row">
          <span class="info-label">Total a Pagar</span>
          <span class="info-value highlight">$<?php echo number_format((float)$total_mostrar, 0, ',', '.'); ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
          <span class="info-label">Fecha Solicitud</span>
          <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($prestamo['fecha_solicitud'])); ?></span>
        </div>
        <?php if (!empty($prestamo['fecha_aprobacion'])): ?>
        <div class="info-row">
          <span class="info-label">Fecha Aprobación</span>
          <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($prestamo['fecha_aprobacion'])); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($prestamo['fecha_contraoferta'])): ?>
        <div class="info-row">
          <span class="info-label">Fecha Contra-Oferta</span>
          <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($prestamo['fecha_contraoferta'])); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($tipo_operacion === 'prendario' && !empty($prestamo['dominio'])): ?>
        <div class="info-row">
          <span class="info-label">Dominio</span>
          <span class="info-value"><?php echo h($prestamo['dominio']); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($prestamo['destino_credito'])): ?>
        <div class="info-row">
          <span class="info-label">Destino</span>
          <span class="info-value"><?php echo h($prestamo['destino_credito']); ?></span>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($tipo_operacion === 'prestamo' && !empty($cuotas)): ?>
      <div class="card">
        <h3>📊 Estado del Pago</h3>
        <div class="info-row">
          <span class="info-label">Total Cuotas</span>
          <span class="info-value"><?php echo $total_cuotas; ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Pagadas</span>
          <span class="info-value" style="color: #10b981;"><?php echo $cuotas_pagadas; ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Pendientes</span>
          <span class="info-value" style="color: #f59e0b;"><?php echo $cuotas_pendientes; ?></span>
        </div>
        <?php if ($cuotas_vencidas > 0): ?>
        <div class="info-row">
          <span class="info-label">Vencidas</span>
          <span class="info-value" style="color: #ef4444;"><?php echo $cuotas_vencidas; ?></span>
        </div>
        <?php endif; ?>
        <div class="progress-container">
          <div class="progress-text">Progreso: <?php echo $progreso; ?>%</div>
          <div class="progress-bar-wrapper">
            <div class="progress-bar-fill" style="width: <?php echo $progreso; ?>%;"></div>
          </div>
        </div>
        <div class="info-row">
          <span class="info-label">Total Pagado</span>
          <span class="info-value" style="color: #10b981;">$<?php echo number_format($total_pagado, 0, ',', '.'); ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Saldo Pendiente</span>
          <span class="info-value highlight">$<?php echo number_format($total_pendiente, 0, ',', '.'); ?></span>
        </div>
      </div>
      <?php else: ?>
      <div class="card">
        <h3>ℹ️ Información Adicional</h3>
        <?php if ($tipo_operacion === 'prendario' && !empty($prestamo['descripcion_vehiculo'])): ?>
        <div class="info-row">
          <span class="info-label">Descripción</span>
          <span class="info-value"><?php echo nl2br(h($prestamo['descripcion_vehiculo'])); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($tipo_operacion === 'empeno' && !empty($prestamo['descripcion_producto'])): ?>
        <div class="info-row">
          <span class="info-label">Producto</span>
          <span class="info-value"><?php echo nl2br(h($prestamo['descripcion_producto'])); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($prestamo['comentarios_admin']) && !$es_contraoferta): ?>
        <div style="margin-top: 16px; padding: 16px; background: #f8fafc; border-left: 3px solid #7c5cff; border-radius: 8px;">
          <div style="font-weight: 600; color: #0f172a; margin-bottom: 8px;">
            Comentarios del Administrador:
          </div>
          <div style="color: #475569;">
            <?php echo nl2br(h($prestamo['comentarios_admin'])); ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Cuotas -->
    <?php if ($tipo_operacion === 'prestamo' && !empty($cuotas)): ?>
    <div class="card">
      <h3>💳 Detalle de Cuotas</h3>
      <div style="overflow-x: auto;">
        <table class="tabla-cuotas">
          <thead>
            <tr>
              <th>Cuota</th>
              <th>Vencimiento</th>
              <th>Monto</th>
              <th>Estado</th>
              <th>Fecha Pago</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cuotas as $cuota): ?>
              <tr>
                <td><strong><?php echo (int)$cuota['cuota_num']; ?></strong></td>
                <td><?php echo date('d/m/Y', strtotime($cuota['fecha_vencimiento'])); ?></td>
                <td><strong>$<?php echo number_format((float)$cuota['monto'], 0, ',', '.'); ?></strong></td>
                <td>
                  <span class="estado-cuota <?php echo h($cuota['estado']); ?>">
                    <?php echo ucfirst(h($cuota['estado'])); ?>
                  </span>
                </td>
                <td>
                  <?php if ($cuota['fecha_pago']): ?>
                    <?php echo date('d/m/Y', strtotime($cuota['fecha_pago'])); ?>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Documentos -->
    <div class="card">
      <h3>
        📎 
        <?php 
          if ($tipo_operacion === 'prendario') {
            echo 'Fotos del Vehículo';
          } elseif ($tipo_operacion === 'empeno') {
            echo 'Fotos del Producto';
          } else {
            echo 'Documentos Adjuntos';
          }
        ?>
      </h3>
      <?php if (empty($documentos)): ?>
        <div class="empty-state">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          <p>No hay archivos adjuntos</p>
        </div>
      <?php else: ?>
        <?php foreach ($documentos as $doc): ?>
          <div class="documento-item">
            <div class="documento-info">
              <div class="documento-icon">
                <?php echo ($tipo_operacion === 'prestamo') ? '📄' : '📷'; ?>
              </div>
              <div class="documento-detalles">
                <h4><?php echo h($tipos_doc_map[$doc['tipo']] ?? ucfirst($doc['tipo'] ?? 'Documento')); ?></h4>
                <p>
                  <?php echo h($doc['nombre_archivo'] ?? 'archivo'); ?> • 
                  <?php echo isset($doc['created_at']) ? date('d/m/Y', strtotime($doc['created_at'])) : 'Fecha desconocida'; ?>
                </p>
              </div>
            </div>
            <button class="btn-ver-doc" onclick="verDocumento('<?php echo h($doc['ruta_archivo']); ?>', '<?php echo h($doc['nombre_archivo'] ?? 'archivo'); ?>')">
              Ver
            </button>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </main>
</div>

<!-- Modal Responder Solicitud -->
<div id="modalRespuesta" class="modal">
  <div class="modal-content" style="max-width: 600px;">
    <div class="modal-header">
      <h3>📤 Responder Solicitud de Información</h3>
      <button class="modal-close" onclick="cerrarModalRespuesta()">&times;</button>
    </div>
    <div class="modal-body">
      <?php if ($solicitud_pendiente && $solicitud_pendiente['id'] > 0): ?>
      <form method="POST" enctype="multipart/form-data" id="formRespuesta">
        <input type="hidden" name="solicitud_id" value="<?= $solicitud_pendiente['id'] ?>">

        <!-- Mensaje del asesor (recordatorio) -->
        <div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
          <div style="font-weight: 600; color: #1e40af; margin-bottom: 8px;">
            💬 Solicitud del asesor:
          </div>
          <div style="color: #1e40af; font-size: 0.9rem;">
            <?php echo nl2br(h($solicitud_pendiente['mensaje'])); ?>
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 16px;">
          <label style="display: block; font-weight: 600; color: #374151; margin-bottom: 8px;">
            Tu Respuesta *
          </label>
          <textarea name="mensaje_respuesta" 
                    rows="5" 
                    required
                    placeholder="Escribí tu respuesta o proporcioná la información solicitada..."
                    style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95rem;"></textarea>
        </div>

        <div class="form-group" style="margin-bottom: 16px;">
          <label style="display: block; font-weight: 600; color: #374151; margin-bottom: 8px;">
            Adjuntar Archivos (Opcional)
          </label>
          <div style="border: 2px dashed #d1d5db; border-radius: 12px; padding: 24px; text-align: center; background: #f9fafb; cursor: pointer;" onclick="document.getElementById('file-respuesta-modal').click()">
            <input type="file" 
                   name="archivos_respuesta[]" 
                   multiple 
                   accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx"
                   style="display:none;" 
                   id="file-respuesta-modal"
                   onchange="mostrarArchivosSeleccionados()">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" style="margin: 0 auto 12px;">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
            </svg>
            <div style="font-weight: 600; color: #374151; margin-bottom: 4px;">
              Click para subir archivos
            </div>
            <div style="font-size: 0.85rem; color: #6b7280;">
              PDF, imágenes, documentos • Máx. 10MB por archivo
            </div>
          </div>
          <div id="archivosSeleccionados" style="margin-top: 12px; font-size: 0.85rem; color: #6b7280;"></div>
        </div>

        <div style="display: flex; gap: 12px; margin-top: 24px;">
          <button type="submit" 
                  class="btn-responder" 
                  style="flex: 1; padding: 12px; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; transition: all 0.2s;"
                  onmouseover="this.style.transform='translateY(-2px)'"
                  onmouseout="this.style.transform='translateY(0)'">
            📤 Enviar Respuesta
          </button>
          <button type="button" 
                  onclick="cerrarModalRespuesta()" 
                  style="padding: 12px 24px; background: #f3f4f6; color: #374151; border: none; border-radius: 10px; font-weight: 600; cursor: pointer;">
            Cancelar
          </button>
        </div>
      </form>
      <?php else: ?>
      <div style="text-align: center; padding: 40px 20px;">
        <div style="font-size: 3rem; margin-bottom: 16px;">⚠️</div>
        <p style="color: #6b7280; margin-bottom: 20px;">
          No se pudo cargar el formulario de respuesta. Por favor contactate con tu asesor.
        </p>
        <button onclick="cerrarModalRespuesta()" 
                style="padding: 10px 24px; background: #7c5cff; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">
          Cerrar
        </button>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal Documentos -->
<div id="modalDocumento" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3 id="modalTitulo">Documento</h3>
      <button class="modal-close" onclick="cerrarModal()">&times;</button>
    </div>
    <div class="modal-body" id="modalBody"></div>
  </div>
</div>

<script>
// Modal de documentos
function verDocumento(ruta, nombre) {
  const modal = document.getElementById('modalDocumento');
  const titulo = document.getElementById('modalTitulo');
  const body = document.getElementById('modalBody');
  
  titulo.textContent = nombre;
  
  const extension = ruta.split('.').pop().toLowerCase();
  
  if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension)) {
    body.innerHTML = '<img src="' + ruta + '" alt="' + nombre + '">';
  } else if (extension === 'pdf') {
    body.innerHTML = '<iframe src="' + ruta + '" style="width: 100%; height: 70vh; border: none; border-radius: 8px;"></iframe>';
  } else {
    body.innerHTML = '<div style="text-align: center; padding: 40px;"><p style="color: #64748b; margin-bottom: 20px;">No se puede previsualizar</p><a href="' + ruta + '" download class="btn-ver-doc" style="display: inline-block;">Descargar</a></div>';
  }
  
  modal.classList.add('active');
}

function cerrarModal() {
  document.getElementById('modalDocumento').classList.remove('active');
}

// Modal de respuesta
function abrirModalRespuesta() {
  document.getElementById('modalRespuesta').classList.add('active');
}

function cerrarModalRespuesta() {
  document.getElementById('modalRespuesta').classList.remove('active');
}

// Mostrar archivos seleccionados
function mostrarArchivosSeleccionados() {
  const input = document.getElementById('file-respuesta-modal');
  const div = document.getElementById('archivosSeleccionados');
  
  if (input.files.length > 0) {
    const nombres = Array.from(input.files).map(f => '📄 ' + f.name).join('<br>');
    div.innerHTML = '<strong>Archivos seleccionados:</strong><br>' + nombres;
    div.style.color = '#059669';
  } else {
    div.innerHTML = '';
  }
}

// Confirmación de acciones de contra-oferta
function confirmarAccion(accion) {
  if (accion === 'aceptar') {
    return confirm('¿Estás seguro de que querés ACEPTAR esta contra-oferta?\n\n✅ Una vez aceptada, deberás firmar el contrato.\n\nEsta decisión es definitiva.');
  } else if (accion === 'rechazar') {
    return confirm('¿Estás seguro de que querés RECHAZAR esta contra-oferta?\n\n❌ Esta acción no se puede deshacer.\n\nPodrás solicitar un nuevo préstamo en cualquier momento.');
  }
  return false;
}

// Cerrar modales con ESC
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    cerrarModal();
    cerrarModalRespuesta();
  }
});

// Cerrar modales al hacer clic fuera
document.getElementById('modalDocumento')?.addEventListener('click', function(e) {
  if (e.target === this) cerrarModal();
});

document.getElementById('modalRespuesta')?.addEventListener('click', function(e) {
  if (e.target === this) cerrarModalRespuesta();
});

// Validar formulario antes de enviar
document.getElementById('formRespuesta')?.addEventListener('submit', function(e) {
  const mensaje = this.querySelector('textarea[name="mensaje_respuesta"]').value.trim();
  
  if (mensaje.length === 0) {
    e.preventDefault();
    alert('Por favor escribí una respuesta antes de enviar.');
    return false;
  }
  
  if (!confirm('¿Confirmar envío de respuesta? Esta acción no se puede deshacer.')) {
    e.preventDefault();
    return false;
  }
});
</script>

</body>
</html>