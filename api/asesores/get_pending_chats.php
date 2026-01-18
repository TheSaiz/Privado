<?php
// =====================================================
// get_pending_chats.php - CON CLIENTES PREFERENCIALES
// =====================================================
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
require_once __DIR__ . "/../../backend/connection.php";

// =====================================================
// INPUT
// =====================================================
$asesor_id = intval($_GET['asesor_id'] ?? 0);

if ($asesor_id <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "asesor_id requerido"
    ]);
    exit;
}

try {
    // =====================================================
    // VERIFICAR SI EXISTEN COLUMNAS DE REFERIDOS
    // =====================================================
    $check_columns = $pdo->query("SHOW COLUMNS FROM chats LIKE 'es_referido'")->rowCount();
    $tiene_referidos = $check_columns > 0;
    
    // Verificar si existe columna de cliente aprobado
    $check_aprobado = $pdo->query("SHOW COLUMNS FROM chats LIKE 'es_cliente_aprobado'")->rowCount();
    $tiene_columna_aprobado = $check_aprobado > 0;

    if ($tiene_referidos) {
        // =====================================================
        // 🔥 QUERY MEJORADO - CON CLIENTES PREFERENCIALES
        // =====================================================
        
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.cliente_id,
                c.departamento_id,
                c.fecha_inicio,
                c.es_referido,
                c.asesor_id AS asesor_asignado,
                c.codigo_referido_origen,
                " . ($tiene_columna_aprobado ? "c.es_cliente_aprobado," : "0 AS es_cliente_aprobado,") . "
                u.nombre   AS cliente_nombre,
                u.telefono AS cliente_telefono,
                d.nombre   AS departamento_nombre,
                
                -- 🔥 VERIFICAR SI ES CLIENTE APROBADO (desde clientes_detalles)
                CASE 
                    WHEN cd.estado_validacion = 'aprobado' THEN 1
                    ELSE 0
                END AS cliente_validado_aprobado,
                
                -- 🔥 MARCAR SI ES REFERIDO PROPIO
                CASE 
                    WHEN c.es_referido = 1 AND c.asesor_id = ? THEN 1
                    ELSE 0
                END AS es_mi_referido
                
            FROM chats c
            INNER JOIN usuarios u ON u.id = c.cliente_id
            INNER JOIN departamentos d ON d.id = c.departamento_id
            LEFT JOIN clientes_detalles cd ON cd.usuario_id = c.cliente_id
            WHERE
                c.estado = 'esperando_asesor'
                -- 🔥 TODOS ven TODOS los chats pendientes
            ORDER BY 
                -- 🔥 PRIORIDAD: 1) Clientes aprobados, 2) Referidos, 3) Normales
                CASE 
                    WHEN cd.estado_validacion = 'aprobado' THEN 1
                    WHEN c.es_referido = 1 THEN 2
                    ELSE 3
                END ASC,
                c.fecha_inicio ASC
        ");
        
        $stmt->execute([$asesor_id]);
        
    } else {
        // =====================================================
        // QUERY LEGACY (sin referidos, pero con clientes aprobados)
        // =====================================================
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.cliente_id,
                c.departamento_id,
                c.fecha_inicio,
                u.nombre   AS cliente_nombre,
                u.telefono AS cliente_telefono,
                d.nombre   AS departamento_nombre,
                " . ($tiene_columna_aprobado ? "c.es_cliente_aprobado," : "0 AS es_cliente_aprobado,") . "
                
                -- 🔥 VERIFICAR SI ES CLIENTE APROBADO
                CASE 
                    WHEN cd.estado_validacion = 'aprobado' THEN 1
                    ELSE 0
                END AS cliente_validado_aprobado
                
            FROM chats c
            INNER JOIN usuarios u ON u.id = c.cliente_id
            INNER JOIN departamentos d ON d.id = c.departamento_id
            LEFT JOIN clientes_detalles cd ON cd.usuario_id = c.cliente_id
            WHERE
                c.estado = 'esperando_asesor'
            ORDER BY 
                -- 🔥 Clientes aprobados primero
                CASE 
                    WHEN cd.estado_validacion = 'aprobado' THEN 1
                    ELSE 2
                END ASC,
                c.fecha_inicio ASC
        ");
        
        $stmt->execute();
    }

    $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $chats_filtrados = $chats;

    // =====================================================
    // ENRIQUECER DATOS PARA EL FRONTEND
    // =====================================================
    foreach ($chats_filtrados as &$chat) {
        // 🔥 Determinar si es cliente aprobado (preferencial)
        $es_aprobado = ($chat['cliente_validado_aprobado'] == 1);
        
        // Marcar tipo de chat para UI especial
        if ($es_aprobado) {
            // 🔥 MÁXIMA PRIORIDAD: Cliente aprobado
            $chat['tipo_notificacion'] = 'cliente_preferencial';
            $chat['prioridad'] = 0; // Prioridad más alta
            $chat['puede_aceptar_siempre'] = true;
        } elseif (isset($chat['es_mi_referido']) && $chat['es_mi_referido'] == 1) {
            $chat['tipo_notificacion'] = 'referido_propio';
            $chat['prioridad'] = 1;
            $chat['puede_aceptar_siempre'] = true;
        } elseif (isset($chat['es_referido']) && $chat['es_referido'] == 1) {
            $chat['tipo_notificacion'] = 'referido_otro';
            $chat['prioridad'] = 2;
            $chat['puede_aceptar_siempre'] = false;
        } else {
            $chat['tipo_notificacion'] = 'normal';
            $chat['prioridad'] = 3;
            $chat['puede_aceptar_siempre'] = false;
        }
    }

    // =====================================================
    // LOG
    // =====================================================
    error_log("📋 Asesor $asesor_id - Chats disponibles: " . count($chats_filtrados));
    
    foreach ($chats_filtrados as $chat) {
        if ($chat['tipo_notificacion'] === 'cliente_preferencial') {
            $tipo = "CLIENTE PREFERENCIAL ⭐";
        } elseif (isset($chat['es_mi_referido']) && $chat['es_mi_referido']) {
            $tipo = "MI REFERIDO ⭐";
        } elseif (isset($chat['es_referido']) && $chat['es_referido']) {
            $tipo = "REFERIDO (otro)";
        } else {
            $tipo = "NORMAL";
        }
        error_log("  - Chat {$chat['id']}: $tipo - {$chat['cliente_nombre']}");
    }

    // =====================================================
    // RESPUESTA
    // =====================================================
    echo json_encode([
        "success" => true,
        "data" => [
            "chats" => $chats_filtrados,
            "total" => count($chats_filtrados),
            "tiene_sistema_referidos" => $tiene_referidos,
            "tiene_clientes_preferenciales" => true
        ]
    ]);

} catch (Throwable $e) {
    error_log("❌ get_pending_chats.php ERROR: " . $e->getMessage());
    error_log($e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al obtener chats pendientes"
    ]);
}