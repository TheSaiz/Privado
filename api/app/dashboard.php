<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../backend/connection.php';
require_once __DIR__ . '/../middleware/auth.php';

$auth = auth_required();
$usuario_id = (int)$auth['user_id'];

try {
    // OBTENER INFO DEL CLIENTE
    $stmt = $pdo->prepare("
        SELECT 
            usuario_id,
            dni,
            cuit,
            nombre_completo,
            email,
            cod_area,
            telefono,
            doc_dni_frente,
            doc_dni_dorso,
            doc_selfie_dni,
            docs_completos,
            estado_validacion
        FROM clientes_detalles
        WHERE usuario_id = ?
        LIMIT 1
    ");
    $stmt->execute([$usuario_id]);
    $cliente_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cliente_info) {
        // Si no existe en clientes_detalles, obtener de usuarios
        $stmt = $pdo->prepare("
            SELECT nombre, apellido, email
            FROM usuarios
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Cliente no encontrado']);
            exit;
        }
        
        $nombre_completo = trim($usuario['nombre'] . ' ' . ($usuario['apellido'] ?? ''));
        $email = $usuario['email'];
        $docs_completos = false;
        $estado_validacion = 'sin_documentacion';
    } else {
        $nombre_completo = $cliente_info['nombre_completo'] ?? '';
        $email = $cliente_info['email'] ?? '';
        $docs_completos = (bool)($cliente_info['docs_completos'] ?? 0);
        $estado_validacion = $cliente_info['estado_validacion'] ?? 'sin_documentacion';
    }
    
    // OBTENER PRÉSTAMOS (TODOS LOS TIPOS)
    $prestamos_array = [];
    
    // 1. Préstamos normales
    $stmt = $pdo->prepare("
        SELECT 
            id,
            cliente_id,
            COALESCE(monto_final, monto_ofrecido, monto_solicitado, monto) as monto,
            COALESCE(cuotas_final, cuotas_ofrecidas, cuotas_solicitadas, cuotas) as cuotas,
            COALESCE(frecuencia_final, frecuencia_ofrecida, frecuencia_solicitada, frecuencia_pago) as frecuencia,
            estado,
            fecha_solicitud,
            estado_contrato,
            'prestamo' as tipo
        FROM prestamos
        WHERE cliente_id = ?
        ORDER BY fecha_solicitud DESC
    ");
    $stmt->execute([$usuario_id]);
    $prestamos_normales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Créditos prendarios
    $stmt = $pdo->prepare("
        SELECT 
            id,
            cliente_id,
            COALESCE(monto_final, monto_ofrecido, monto_solicitado) as monto,
            COALESCE(cuotas_final, cuotas_ofrecidas, 1) as cuotas,
            COALESCE(frecuencia_final, frecuencia_ofrecida, 'mensual') as frecuencia,
            estado,
            fecha_solicitud,
            estado_contrato,
            'prendario' as tipo
        FROM creditos_prendarios
        WHERE cliente_id = ?
        ORDER BY fecha_solicitud DESC
    ");
    $stmt->execute([$usuario_id]);
    $prendarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Empeños
    $stmt = $pdo->prepare("
        SELECT 
            id,
            cliente_id,
            COALESCE(monto_final, monto_ofrecido, monto_solicitado) as monto,
            COALESCE(cuotas_final, cuotas_ofrecidas, 1) as cuotas,
            COALESCE(frecuencia_final, frecuencia_ofrecida, 'mensual') as frecuencia,
            estado,
            fecha_solicitud,
            estado_contrato,
            'empeno' as tipo
        FROM empenos
        WHERE cliente_id = ?
        ORDER BY fecha_solicitud DESC
    ");
    $stmt->execute([$usuario_id]);
    $empenos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combinar todos los préstamos
    $prestamos_array = array_merge($prestamos_normales, $prendarios, $empenos);
    
    // Ordenar por fecha
    usort($prestamos_array, function($a, $b) {
        return strtotime($b['fecha_solicitud']) - strtotime($a['fecha_solicitud']);
    });
    
    // CALCULAR ESTADÍSTICAS
    $prestamos_activos = 0;
    $prestamos_pre_aprobados = 0;
    $prestamos_contraoferta = 0;
    
    foreach ($prestamos_array as $p) {
        if (($p['estado'] ?? '') === 'activo') {
            $prestamos_activos++;
        }
        if (($p['estado'] ?? '') === 'aprobado' && ($p['estado_contrato'] ?? '') === 'pendiente_firma') {
            $prestamos_pre_aprobados++;
        }
        if (($p['estado'] ?? '') === 'contraoferta') {
            $prestamos_contraoferta++;
        }
    }
    
    // Formatear préstamos para la respuesta
    $prestamos_formateados = [];
    foreach ($prestamos_array as $p) {
        $prestamos_formateados[] = [
            'id' => (int)$p['id'],
            'tipo' => $p['tipo'],
            'monto' => (float)$p['monto'],
            'cuotas' => (int)$p['cuotas'],
            'frecuencia' => $p['frecuencia'] ?? 'mensual',
            'estado' => $p['estado'],
            'fecha_solicitud' => $p['fecha_solicitud'],
            'estado_contrato' => $p['estado_contrato'] ?? null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'cliente' => [
                'nombre' => $nombre_completo,
                'email' => $email,
                'docs_completos' => $docs_completos,
                'estado_validacion' => $estado_validacion
            ],
            'stats' => [
                'prestamos_activos' => $prestamos_activos,
                'prestamos_pre_aprobados' => $prestamos_pre_aprobados,
                'prestamos_contraoferta' => $prestamos_contraoferta,
                'contratos_pendientes' => 0
            ],
            'prestamos' => $prestamos_formateados
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    error_log('Dashboard error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor']);
}