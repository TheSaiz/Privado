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
    // Validar sesión cliente
    if (!isset($_SESSION['cliente_id'])) {
        jsonResponse(false, 'Sesión no válida');
    }
    
    $cliente_id = (int)$_SESSION['cliente_id'];
    
    // Conectar BD
    require_once __DIR__ . '/../../backend/connection.php';
    
    // Validar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Método no permitido');
    }
    
    // Obtener datos
    $input = json_decode(file_get_contents('php://input'), true);
    $tipo = $input['tipo'] ?? 'prestamo';
    $operacion_id = (int)($input[$tipo . '_id'] ?? 0);
    $motivo = $input['motivo'] ?? 'Cliente rechazó la contraoferta';
    
    if ($operacion_id <= 0) {
        jsonResponse(false, 'ID de operación inválido');
    }
    
    // Determinar tabla según tipo
    $tabla = match($tipo) {
        'prestamo' => 'prestamos',
        'empeno' => 'empenos',
        'prendario' => 'creditos_prendarios',
        default => null
    };
    
    if (!$tabla) {
        jsonResponse(false, 'Tipo de operación no válido');
    }
    
    // Verificar que la operación existe y pertenece al cliente
    $stmt = $pdo->prepare("SELECT * FROM $tabla WHERE id = ? AND cliente_id = ?");
    $stmt->execute([$operacion_id, $cliente_id]);
    $operacion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$operacion) {
        jsonResponse(false, ucfirst($tipo) . ' no encontrado');
    }
    
    if ($operacion['estado'] !== 'contraoferta') {
        jsonResponse(false, 'Esta operación no tiene una contraoferta pendiente');
    }
    
    // Rechazar contraoferta
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare("
            UPDATE $tabla SET 
                estado = 'rechazado',
                comentarios_cliente = ?
            WHERE id = ?
        ");
        $stmt->execute([$motivo, $operacion_id]);
        
        $pdo->commit();
        
        logError("Contraoferta rechazada - Tipo: $tipo, ID: $operacion_id, Cliente ID: $cliente_id, Motivo: $motivo");
        
        jsonResponse(true, 'Contraoferta rechazada');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        logError("Error rechazando contraoferta: " . $e->getMessage());
        jsonResponse(false, 'Error al rechazar la contraoferta');
    }
    
} catch (Exception $e) {
    logError("Error general: " . $e->getMessage());
    jsonResponse(false, 'Error del servidor');
}