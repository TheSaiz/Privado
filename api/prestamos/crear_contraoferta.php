<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

function logError($msg) {
    $log = __DIR__ . '/../../logs/api_errors.log';
    @mkdir(dirname($log), 0755, true);
    @file_put_contents($log, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

function jsonResponse($success, $message, $data = null) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Validar sesión admin
    if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'admin') {
        jsonResponse(false, 'No autorizado');
    }
    
    $admin_id = (int)$_SESSION['usuario_id'];
    
    // Conectar BD
    require_once __DIR__ . '/../../backend/connection.php';
    
    // Validar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Método no permitido');
    }
    
    // Obtener datos
    $prestamo_id = isset($_POST['prestamo_id']) ? (int)$_POST['prestamo_id'] : 0;
    $monto_ofrecido = isset($_POST['monto_ofrecido']) ? (float)$_POST['monto_ofrecido'] : 0;
    $cuotas_ofrecidas = isset($_POST['cuotas_ofrecidas']) ? (int)$_POST['cuotas_ofrecidas'] : 0;
    $frecuencia_ofrecida = $_POST['frecuencia_ofrecida'] ?? 'mensual';
    $tasa_interes = isset($_POST['tasa_interes']) ? (float)$_POST['tasa_interes'] : 15;
    $comentarios = $_POST['comentarios_admin'] ?? '';
    
    // Validaciones
    if ($prestamo_id <= 0) {
        jsonResponse(false, 'ID de préstamo inválido');
    }
    
    if ($monto_ofrecido <= 0) {
        jsonResponse(false, 'Monto ofrecido inválido');
    }
    
    if ($cuotas_ofrecidas <= 0) {
        jsonResponse(false, 'Cantidad de cuotas inválida');
    }
    
    $frecuencias_validas = ['diario', 'semanal', 'quincenal', 'mensual'];
    if (!in_array($frecuencia_ofrecida, $frecuencias_validas)) {
        jsonResponse(false, 'Frecuencia no válida');
    }
    
    // Verificar que el préstamo existe y está en estado válido
    $stmt = $pdo->prepare("
        SELECT p.*, u.email as cliente_email, CONCAT(u.nombre, ' ', u.apellido) as cliente_nombre
        FROM prestamos p
        INNER JOIN usuarios u ON p.cliente_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$prestamo_id]);
    $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$prestamo) {
        jsonResponse(false, 'Préstamo no encontrado');
    }
    
    if (!in_array($prestamo['estado'], ['pendiente', 'en_evaluacion'])) {
        jsonResponse(false, 'El préstamo no está en un estado válido para contraoferta');
    }
    
    // Calcular monto total ofrecido
    $monto_interes_ofrecido = $monto_ofrecido * ($tasa_interes / 100);
    $monto_total_ofrecido = $monto_ofrecido + $monto_interes_ofrecido;
    
    // Crear contraoferta
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare("
            UPDATE prestamos SET
                monto_ofrecido = ?,
                cuotas_ofrecidas = ?,
                frecuencia_ofrecida = ?,
                tasa_interes_ofrecida = ?,
                monto_total_ofrecido = ?,
                comentarios_admin = ?,
                estado = 'contraoferta',
                fecha_contraoferta = NOW(),
                asesor_id = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $monto_ofrecido,
            $cuotas_ofrecidas,
            $frecuencia_ofrecida,
            $tasa_interes,
            $monto_total_ofrecido,
            $comentarios,
            $admin_id,
            $prestamo_id
        ]);
        
        // Registrar en historial
        $stmt = $pdo->prepare("
            INSERT INTO prestamos_historial (
                prestamo_id, estado_anterior, estado_nuevo, usuario_id, tipo_usuario, comentario
            ) VALUES (?, ?, 'contraoferta', ?, 'admin', ?)
        ");
        $stmt->execute([
            $prestamo_id,
            $prestamo['estado'],
            $admin_id,
            "Contraoferta: $$monto_ofrecido en $cuotas_ofrecidas cuotas"
        ]);
        
        $pdo->commit();
        
        // Enviar notificación por email
        try {
            require_once __DIR__ . '/../../backend/emailservice.php';
            $emailService = new EmailService();
            
            $emailService->notificarContraoferta(
                $prestamo['cliente_id'],
                $prestamo_id,
                $monto_ofrecido,
                $cuotas_ofrecidas,
                $frecuencia_ofrecida,
                $comentarios
            );
            
        } catch (Exception $e) {
            logError("Error enviando email de contraoferta: " . $e->getMessage());
        }
        
        jsonResponse(true, 'Contraoferta enviada exitosamente');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        logError("Error creando contraoferta: " . $e->getMessage());
        jsonResponse(false, 'Error al crear la contraoferta');
    }
    
} catch (Exception $e) {
    logError("Error general: " . $e->getMessage());
    jsonResponse(false, 'Error del servidor');
}
