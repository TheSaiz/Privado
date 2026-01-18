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
    $input = json_decode(file_get_contents('php://input'), true);
    $prestamo_id = isset($input['prestamo_id']) ? (int)$input['prestamo_id'] : 0;
    $motivo = $input['motivo'] ?? 'Préstamo rechazado por administración';
    
    if ($prestamo_id <= 0) {
        jsonResponse(false, 'ID de préstamo inválido');
    }
    
    // Verificar que el préstamo existe
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
        jsonResponse(false, 'El préstamo no puede ser rechazado en su estado actual');
    }
    
    // Rechazar préstamo
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare("
            UPDATE prestamos SET 
                estado = 'rechazado',
                comentarios_admin = ?
            WHERE id = ?
        ");
        $stmt->execute([$motivo, $prestamo_id]);
        
        // Registrar en historial
        $stmt = $pdo->prepare("
            INSERT INTO prestamos_historial (
                prestamo_id, estado_anterior, estado_nuevo, usuario_id, tipo_usuario, comentario
            ) VALUES (?, ?, 'rechazado', ?, 'admin', ?)
        ");
        $stmt->execute([$prestamo_id, $prestamo['estado'], $admin_id, $motivo]);
        
        $pdo->commit();
        
        // Enviar notificación por email
        try {
            require_once __DIR__ . '/../../backend/emailservice.php';
            $emailService = new EmailService();
            $emailService->notificarPrestamoRechazado($prestamo['cliente_id'], $prestamo_id, $motivo);
        } catch (Exception $e) {
            logError("Error enviando email: " . $e->getMessage());
        }
        
        jsonResponse(true, 'Préstamo rechazado');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        logError("Error rechazando préstamo: " . $e->getMessage());
        jsonResponse(false, 'Error al rechazar el préstamo');
    }
    
} catch (Exception $e) {
    logError("Error general: " . $e->getMessage());
    jsonResponse(false, 'Error del servidor');
}
