<?php
/**
 * solicitudes_estado.php
 */

session_start();
require_once __DIR__ . '/../backend/connection.php';

header('Content-Type: application/json; charset=UTF-8');

// Función helper para logging
function logAPI(string $msg): void {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $file = $logDir . '/api_solicitudes_estado.log';
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$ts] $msg\n", FILE_APPEND);
}

logAPI("========== NUEVA PETICIÓN ==========");

// =========================
// AUTH
// =========================
if (!isset($_SESSION['usuario_id'], $_SESSION['usuario_rol'])) {
    logAPI("ERROR: Usuario no autenticado");
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

if (!in_array($_SESSION['usuario_rol'], ['admin', 'asesor'], true)) {
    logAPI("ERROR: Rol no permitido: " . $_SESSION['usuario_rol']);
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
    exit;
}

logAPI("✓ Usuario autenticado: ID=" . $_SESSION['usuario_id'] . ", Rol=" . $_SESSION['usuario_rol']);

// =========================
// INPUT VALIDATION
// =========================
$rawInput = file_get_contents('php://input');
logAPI("Input recibido: $rawInput");

$data = json_decode($rawInput, true);

if (!is_array($data)) {
    logAPI("ERROR: JSON inválido");
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

$estado = trim((string)($data['estado'] ?? ''));
$cliente_id = isset($data['cliente_id']) ? (int)$data['cliente_id'] : 0;
$chat_id = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;

logAPI("Datos parseados - Estado: $estado, Cliente ID: $cliente_id, Chat ID: $chat_id");

// Validar estado
if (!in_array($estado, ['aprobado', 'rechazado'], true)) {
    logAPI("ERROR: Estado inválido: $estado");
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Estado inválido. Debe ser: aprobado o rechazado']);
    exit;
}

// =========================
// RESOLVER CLIENTE_ID
// =========================
if ($cliente_id <= 0 && $chat_id > 0) {
    try {
        logAPI("Intentando resolver cliente_id desde chat_id=$chat_id");
        
        $stmt = $pdo->prepare("
            SELECT cliente_id
            FROM chats
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$chat_id]);
        $cliente_id = (int)$stmt->fetchColumn();
        
        logAPI("Cliente ID resuelto: $cliente_id");
        
    } catch (PDOException $e) {
        logAPI("ERROR PDO al resolver cliente_id: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Error al buscar cliente_id']);
        exit;
    }
}

if ($cliente_id <= 0) {
    logAPI("ERROR: No se pudo resolver cliente_id");
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No se pudo resolver cliente_id']);
    exit;
}

logAPI("✓ Cliente ID final: $cliente_id");

// =========================
// UPDATE ESTADO
// =========================
try {
    logAPI("Actualizando estado a '$estado' para cliente_id=$cliente_id");
    
    $stmt = $pdo->prepare("
        UPDATE clientes_detalles
        SET estado_validacion = ?, docs_updated_at = NOW()
        WHERE usuario_id = ?
    ");
    $stmt->execute([$estado, $cliente_id]);
    
    $rowCount = $stmt->rowCount();
    logAPI("Filas afectadas: $rowCount");
    
    if ($rowCount === 0) {
        logAPI("WARNING: No se actualizó ningún registro");
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'error' => 'No se encontró el cliente o no hubo cambios'
        ]);
        exit;
    }
    
    logAPI("✓ Estado actualizado correctamente en BD");
    
    /* =====================================================
   🔔 NOTIFICACIÓN PUSH DOCUMENTACIÓN (HOOK INTERNO)
   ===================================================== */
try {
    $notificadorPath = __DIR__ . '/app/eventos/notificar_documentacion.php';

    if (file_exists($notificadorPath)) {
        $_POST['usuario_id'] = $cliente_id;
        require $notificadorPath;

        logAPI("✓ Evento notificar_documentacion ejecutado (usuario_id=$cliente_id)");
    } else {
        logAPI("WARNING: notificar_documentacion.php no encontrado en $notificadorPath");
    }
} catch (Throwable $e) {
    logAPI("ERROR al ejecutar notificación push: " . $e->getMessage());
}
    
} catch (PDOException $e) {
    logAPI("ERROR PDO al actualizar: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al actualizar estado']);
    exit;
}

// =========================
// ENVÍO DE EMAIL (NO BLOQUEANTE)
// =========================
logAPI("Iniciando proceso de envío de email...");

try {
    $emailDispatcherPath = __DIR__ . '/../correos/EmailDispatcher.php';
    
    logAPI("Verificando EmailDispatcher en: $emailDispatcherPath");
    
    if (!file_exists($emailDispatcherPath)) {
        throw new Exception("EmailDispatcher no encontrado en: $emailDispatcherPath");
    }
    
    logAPI("✓ EmailDispatcher encontrado, cargando...");
    require_once $emailDispatcherPath;
    
    // Obtener datos del cliente
    logAPI("Obteniendo datos del cliente...");
    
    $stmt = $pdo->prepare("
        SELECT 
            cd.nombre_completo,
            u.email
        FROM clientes_detalles cd
        JOIN usuarios u ON u.id = cd.usuario_id
        WHERE cd.usuario_id = ?
        LIMIT 1
    ");
    $stmt->execute([$cliente_id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cliente) {
        logAPI("WARNING: No se encontraron datos del cliente");
        throw new Exception("Cliente no encontrado");
    }
    
    logAPI("✓ Datos del cliente obtenidos: " . $cliente['nombre_completo'] . " <" . $cliente['email'] . ">");
    
    if (empty($cliente['email'])) {
        logAPI("WARNING: Cliente sin email");
        throw new Exception("Cliente sin email");
    }
    
    // Crear instancia del mailer
    logAPI("Creando instancia de EmailDispatcher...");
    $mailer = new EmailDispatcher();
    logAPI("✓ EmailDispatcher instanciado");
    
    // Enviar email según el estado
    $emailEnviado = false;
    
    if ($estado === 'aprobado') {
        logAPI("Enviando email de documentos aprobados...");
        
        $emailEnviado = $mailer->send(
            'docs_aprobados',
            $cliente['email'],
            [
                'nombre' => $cliente['nombre_completo'] ?: 'Cliente'
            ]
        );
    }
    
    if ($estado === 'rechazado') {
        logAPI("Enviando email de documentos rechazados...");
        
        $emailEnviado = $mailer->send(
            'docs_rechazados',
            $cliente['email'],
            [
                'nombre' => $cliente['nombre_completo'] ?: 'Cliente',
                'mensaje' => 'La documentación enviada no pudo ser validada'
            ]
        );
    }
    
    if ($emailEnviado) {
        logAPI("✓ ✓ ✓ EMAIL ENVIADO EXITOSAMENTE ✓ ✓ ✓");
    } else {
        logAPI("✗ Email NO fue enviado (revisar logs de EmailDispatcher)");
    }
    
} catch (Throwable $e) {
    logAPI("✗ EXCEPCIÓN al enviar email: " . $e->getMessage());
    logAPI("Stack trace: " . $e->getTraceAsString());
    
    // Guardar también en el log de correos
    $logDir = __DIR__ . '/../correos/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    @file_put_contents(
        $logDir . '/emails.log',
        '[' . date('Y-m-d H:i:s') . '] ERROR desde solicitudes_estado: ' . $e->getMessage() . "\n",
        FILE_APPEND
    );
}

// =========================
// RESPUESTA EXITOSA
// =========================
logAPI("========== RESPUESTA EXITOSA ==========");
echo json_encode(['ok' => true]);
exit;
