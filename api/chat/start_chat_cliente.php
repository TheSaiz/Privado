<?php
/**
 * start_chat_cliente.php - CON SISTEMA PREFERENCIAL VIP
 * API para iniciar chat desde un cliente aprobado
 */

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Cargar conexión a BD
try {
    require_once __DIR__ . "/../../backend/connection.php";
} catch (Exception $e) {
    error_log("ERROR cargando connection.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error de configuración del servidor"
    ]);
    exit;
}

if (!isset($pdo)) {
    error_log("ERROR: \$pdo no existe después de cargar connection.php");
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error de conexión a base de datos"
    ]);
    exit;
}

// Validar método
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Método no permitido"
    ]);
    exit;
}

// Leer JSON
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "JSON inválido"
    ]);
    exit;
}

$cliente_id = intval($data['cliente_id'] ?? 0);

if ($cliente_id <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "cliente_id requerido"
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // =====================================================
    // VERIFICAR QUE EL CLIENTE ESTÉ APROBADO
    // =====================================================
    $stmt = $pdo->prepare("
        SELECT cd.estado_validacion, u.nombre, u.email, u.telefono
        FROM clientes_detalles cd
        INNER JOIN usuarios u ON u.id = cd.usuario_id
        WHERE cd.usuario_id = ?
        LIMIT 1
    ");
    $stmt->execute([$cliente_id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Cliente no encontrado"
        ]);
        exit;
    }

    if ($cliente['estado_validacion'] !== 'aprobado') {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Cliente no aprobado. Debe completar la validación de documentos primero."
        ]);
        exit;
    }

    // =====================================================
    // 🔥 BUSCAR CHAT CERRADO RECIENTE PARA REABRIR
    // =====================================================
    $stmt = $pdo->prepare("
        SELECT id, estado
        FROM chats
        WHERE cliente_id = ?
          AND estado IN ('cerrado', 'cerrado_cliente', 'esperando_asesor', 'en_conversacion')
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$cliente_id]);
    $chat_existente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($chat_existente) {
        // Si el chat está activo, devolverlo
        if (in_array($chat_existente['estado'], ['esperando_asesor', 'en_conversacion'])) {
            $pdo->commit();
            echo json_encode([
                "success" => true,
                "message" => "Chat ya existente",
                "data" => [
                    "chat_id" => (int)$chat_existente['id'],
                    "estado" => $chat_existente['estado'],
                    "es_nuevo" => false
                ]
            ]);
            exit;
        }
        
        // Si está cerrado, reabrirlo
        if (in_array($chat_existente['estado'], ['cerrado', 'cerrado_cliente'])) {
            $stmt = $pdo->prepare("
                UPDATE chats 
                SET estado = 'esperando_asesor',
                    fecha_reapertura = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$chat_existente['id']]);
            
            // Mensaje de reapertura
            $stmt = $pdo->prepare("
                INSERT INTO mensajes (chat_id, emisor, mensaje, fecha)
                VALUES (?, 'sistema', '🔄 Conversación reabierta por el cliente', NOW())
            ");
            $stmt->execute([$chat_existente['id']]);
            
            $pdo->commit();
            
            echo json_encode([
                "success" => true,
                "message" => "Chat reabierto exitosamente",
                "data" => [
                    "chat_id" => (int)$chat_existente['id'],
                    "estado" => "esperando_asesor",
                    "es_nuevo" => false,
                    "fue_reabierto" => true
                ]
            ]);
            
            error_log("✅ Chat reabierto: chat_id={$chat_existente['id']}, cliente_id=$cliente_id");
            exit;
        }
    }

    // =====================================================
    // 🔥 CREAR NUEVO CHAT (MARCADO COMO PREFERENCIAL)
    // =====================================================
    
    // Verificar si las columnas existen
    $check_columns = $pdo->query("SHOW COLUMNS FROM chats LIKE 'es_cliente_aprobado'")->rowCount();
    $tiene_columnas_vip = $check_columns > 0;
    
    if ($tiene_columnas_vip) {
        // Con sistema VIP
        $stmt = $pdo->prepare("
            INSERT INTO chats (
                cliente_id,
                departamento_id,
                estado,
                fecha_inicio,
                origen,
                es_cliente_aprobado,
                prioridad
            ) VALUES (?, 1, 'esperando_asesor', NOW(), 'web_cliente_aprobado', 1, 0)
        ");
    } else {
        // Sin sistema VIP (legacy)
        $stmt = $pdo->prepare("
            INSERT INTO chats (
                cliente_id,
                departamento_id,
                estado,
                fecha_inicio,
                origen
            ) VALUES (?, 1, 'esperando_asesor', NOW(), 'web_cliente_aprobado')
        ");
    }
    
    $stmt->execute([$cliente_id]);
    $chat_id = $pdo->lastInsertId();

    // =====================================================
    // MENSAJE INICIAL DEL SISTEMA
    // =====================================================
    $stmt = $pdo->prepare("
        INSERT INTO mensajes (chat_id, emisor, mensaje, fecha)
        VALUES (?, 'bot', ?, NOW())
    ");
    
    $mensaje_bienvenida = "👋 ¡Hola {$cliente['nombre']}! Un asesor especializado se pondrá en contacto contigo en breve.";
    $stmt->execute([$chat_id, $mensaje_bienvenida]);

    $pdo->commit();

    // =====================================================
    // RESPUESTA EXITOSA
    // =====================================================
    echo json_encode([
        "success" => true,
        "message" => "Chat creado exitosamente",
        "data" => [
            "chat_id" => $chat_id,
            "estado" => "esperando_asesor",
            "es_nuevo" => true,
            "cliente_aprobado" => true,
            "es_preferencial" => $tiene_columnas_vip
        ]
    ]);

    error_log("✅ Chat preferencial creado: chat_id=$chat_id, cliente_id=$cliente_id (APROBADO)");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("❌ start_chat_cliente.php ERROR: " . $e->getMessage());
    error_log($e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error interno del servidor"
    ]);
}