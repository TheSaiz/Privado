<?php
// =====================================================
// start_chat.php - CON SISTEMA DE REFERIDOS
// =====================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../../backend/connection.php";

// =====================================================
// LEER JSON
// =====================================================
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

// =====================================================
// INPUTS
// =====================================================
$nombre           = trim($data['nombre'] ?? '');
$departamento_id  = intval($data['departamento_id'] ?? 1);
$ip_cliente       = $data['ip_cliente'] ?? null;
$mac_dispositivo  = $data['mac_dispositivo'] ?? null;
$user_agent       = $data['user_agent'] ?? null;
$ciudad           = $data['ciudad'] ?? null;
$pais             = $data['pais'] ?? null;
$latitud          = $data['latitud'] ?? null;
$longitud         = $data['longitud'] ?? null;

// 🔥 NUEVO: Código de referido
$codigo_referido  = trim($data['codigo_referido'] ?? '');

if (empty($nombre)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Nombre requerido"
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // =====================================================
    // VERIFICAR SI VIENE DE REFERIDO
    // =====================================================
    $asesor_referidor_id = null;
    $es_referido = 0;

    if (!empty($codigo_referido)) {
        // Buscar asesor dueño del código
        $stmt = $pdo->prepare("
            SELECT id, nombre 
            FROM usuarios 
            WHERE codigo_referido = ? AND rol = 'asesor'
            LIMIT 1
        ");
        $stmt->execute([$codigo_referido]);
        $asesor = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($asesor) {
            $asesor_referidor_id = $asesor['id'];
            $es_referido = 1;
            error_log("✅ Chat viene de referido: código=$codigo_referido, asesor_id=$asesor_referidor_id ({$asesor['nombre']})");
        } else {
            error_log("⚠️ Código de referido no válido: $codigo_referido");
        }
    }

    // =====================================================
    // 1. CREAR O RECUPERAR USUARIO
    // =====================================================
    // Buscar si existe usuario temporal con este nombre
    $stmt = $pdo->prepare("
        SELECT id 
        FROM usuarios 
        WHERE nombre = ? AND rol = 'cliente' 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([$nombre]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario) {
        $usuario_id = $usuario['id'];
        error_log("Usuario existente encontrado: $usuario_id");
    } else {
        // Crear nuevo usuario
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (
                nombre,
                email,
                rol,
                estado,
                fecha_registro
            ) VALUES (?, ?, 'cliente', 'activo', NOW())
        ");
        
        $temp_email = "temp_" . time() . "_" . rand(1000, 9999) . "@temp.com";
        $stmt->execute([$nombre, $temp_email]);
        $usuario_id = $pdo->lastInsertId();
        
        error_log("✅ Nuevo usuario creado: $usuario_id");
    }

    // =====================================================
    // 2. ASEGURAR QUE EXISTE EN CLIENTES
    // =====================================================
    $stmt = $pdo->prepare("SELECT id FROM clientes WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("
            INSERT INTO clientes (usuario_id, email, telefono)
            VALUES (?, ?, '')
        ");
        $stmt->execute([
            $usuario_id,
            "temp_" . time() . "_" . rand(1000, 9999) . "@cliente.com"
        ]);
    }

    // =====================================================
    // 3. CREAR CHAT
    // =====================================================
    $stmt = $pdo->prepare("
        INSERT INTO chats (
            cliente_id,
            departamento_id,
            estado,
            fecha_inicio,
            ip_cliente,
            mac_dispositivo,
            user_agent,
            ciudad,
            pais,
            latitud,
            longitud,
            es_referido,
            codigo_referido_origen,
            asesor_id
        ) VALUES (?, ?, 'pendiente', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $usuario_id,
        $departamento_id,
        $ip_cliente,
        $mac_dispositivo,
        $user_agent,
        $ciudad,
        $pais,
        $latitud,
        $longitud,
        $es_referido,
        $es_referido ? $codigo_referido : null,
        $es_referido ? $asesor_referidor_id : null  // 🔥 ASIGNAR ASESOR SI ES REFERIDO
    ]);

    $chat_id = $pdo->lastInsertId();

    // =====================================================
    // 4. REGISTRAR CONVERSIÓN SI ES REFERIDO
    // =====================================================
    if ($es_referido && $asesor_referidor_id) {
        // Buscar si hay un click registrado para marcar conversión
        $stmt = $pdo->prepare("
            UPDATE referidos_clicks 
            SET convertido = 1,
                usuario_id = ?,
                fecha_conversion = NOW()
            WHERE asesor_id = ? 
              AND ip_address = ?
              AND convertido = 0
            ORDER BY fecha_click DESC
            LIMIT 1
        ");
        $stmt->execute([$usuario_id, $asesor_referidor_id, $ip_cliente]);
        
        error_log("✅ Conversión de referido registrada");
    }

    $pdo->commit();

    // =====================================================
    // RESPUESTA
    // =====================================================
    echo json_encode([
        "success" => true,
        "data" => [
            "chat_id" => $chat_id,
            "cliente_id" => $usuario_id,
            "es_referido" => $es_referido,
            "asesor_asignado" => $asesor_referidor_id
        ]
    ]);

    error_log("✅ Chat creado exitosamente: chat_id=$chat_id, usuario_id=$usuario_id, es_referido=$es_referido");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("❌ start_chat.php ERROR: " . $e->getMessage());
    error_log($e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al iniciar chat"
    ]);
}