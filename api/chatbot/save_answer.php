<?php
// =====================================================
// save_answer.php - CON MAPEO DE BANCOS
// =====================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// =====================================================
// 🏦 MAPEO DE IDs DE BANCOS: chatbot → CRM
// =====================================================
$banco_map = [
    11 => 11,    // Nación OK
    12 => 14,    // Provincia → 14
    13 => 7,     // Galicia → 7
    14 => 72,    // Santander → 72
    15 => 285,   // Macro → 285
    16 => 17,    // BBVA → 17
    17 => 191,   // Credicoop → 191
    18 => 27,    // Supervielle → 27
    19 => 34,    // Patagonia → 34
    20 => 44,    // Hipotecario → 44
    21 => 29,    // Ciudad → 29
    22 => 299,   // Comafi → 299
    23 => 15,    // ICBC → 15
    24 => 143,   // Brubank → 143
    25 => 45030, // Naranja X → 45030
    26 => 2,     // Otro banco → 2 (NO COBRO POR BANCO)
];

// =====================================================
// LOGS
// =====================================================
$logFile = __DIR__ . "/../../logs/save_answer.log";
$logDir = dirname($logFile);

if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

function escribirLog($mensaje, $nivel = "INFO")
{
    global $logFile;
    $timestamp = date("Y-m-d H:i:s");
    $linea = "[{$timestamp}] [{$nivel}] {$mensaje}\n";
    @file_put_contents($logFile, $linea, FILE_APPEND | LOCK_EX);

    if ($nivel === "ERROR") {
        error_log($mensaje);
    }
}

// =====================================================
// VALIDAR MÉTODO
// =====================================================
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    escribirLog("Método no permitido: " . $_SERVER["REQUEST_METHOD"], "WARNING");
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método no permitido"]);
    exit;
}

// =====================================================
// CONECTAR BD
// =====================================================
try {
    require_once __DIR__ . "/../../backend/connection.php";
    escribirLog("Conexión a BD establecida");
} catch (Throwable $e) {
    escribirLog("Error conectando a BD: " . $e->getMessage(), "ERROR");
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error de conexión a BD"]);
    exit;
}

// =====================================================
// LEER INPUT (FormData o JSON)
// =====================================================
$data = $_POST;
if (empty($data)) {
    $json = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        $data = $json;
    }
}

escribirLog("Datos recibidos: " . json_encode($data));

// =====================================================
// INPUTS
// =====================================================
$chat_id     = intval($data["chat_id"] ?? 0);
$question_id = intval($data["question_id"] ?? 0);
$answer      = trim((string)($data["answer"] ?? ""));
$option_id   = intval($data["option_id"] ?? 0);

$cuil   = trim((string)($data["cuil"] ?? ""));
$nombre = trim((string)($data["nombre"] ?? ""));
$dni    = trim((string)($data["dni"] ?? ""));

$confirmado = !empty($data["confirmado"]);

if ($chat_id <= 0 || $question_id <= 0) {
    escribirLog("Parámetros incompletos: chat_id={$chat_id}, question_id={$question_id}", "WARNING");
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Parámetros incompletos"]);
    exit;
}

if ($answer === "" && $option_id <= 0) {
    escribirLog("Respuesta vacía: chat_id={$chat_id}, question_id={$question_id}", "WARNING");
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Respuesta vacía"]);
    exit;
}

// =====================================================
// VARIABLES PARA ENVÍO A API (FUERA TRANSACCIÓN)
// =====================================================
$debeEnviarApi = false;
$apiUrl = "https://prestamolider.com/prestamolider/enviarapi.php";
$apiPostData = null;

try {
    // =====================================================
    // TRANSACCIÓN BD
    // =====================================================
    $pdo->beginTransaction();
    escribirLog("Transacción iniciada para chat_id={$chat_id}");

    // 1) VALIDAR CHAT
    $stmt = $pdo->prepare("
        SELECT id, cliente_id, estado, cuil_validado, nombre_validado, banco, situacion_laboral
        FROM chats
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$chat_id]);
    $chat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chat) {
        escribirLog("Chat no encontrado: chat_id={$chat_id}", "ERROR");
        throw new Exception("Chat no encontrado");
    }

    escribirLog("Chat encontrado: estado={$chat['estado']}, cliente_id={$chat['cliente_id']}");

    // 2) VALIDAR ESTADO CHAT
    if (in_array($chat["estado"], ["esperando_asesor", "en_conversacion", "cerrado"], true)) {
        escribirLog("Chat en estado inválido para chatbot: {$chat['estado']}", "WARNING");
        $pdo->commit();
        echo json_encode(["success" => true, "ignored" => true, "reason" => "Chat ya no está en estado chatbot"]);
        exit;
    }

    $cliente_id = intval($chat["cliente_id"]);
    if ($cliente_id <= 0) {
        escribirLog("Chat sin cliente asociado: chat_id={$chat_id}", "ERROR");
        throw new Exception("Chat sin cliente asociado");
    }

// 3) ASEGURAR CLIENTE EXISTE
$stmt = $pdo->prepare("
    INSERT IGNORE INTO clientes (usuario_id, email, telefono)
    VALUES (?, ?, '')
");
$stmt->execute([
    $cliente_id,
    null
]);
    // 4) GUARDAR RESPUESTA chatbot_respuestas (update si existe)
    $stmt = $pdo->prepare("
        SELECT id FROM chatbot_respuestas
        WHERE chat_id = ? AND pregunta_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$chat_id, $question_id]);
    $existente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existente) {
        escribirLog("Actualizando respuesta existente: id={$existente['id']}");
        $stmt = $pdo->prepare("
            UPDATE chatbot_respuestas
            SET respuesta = ?, opcion_id = ?, fecha = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $answer,
            $option_id > 0 ? $option_id : null,
            $existente["id"]
        ]);
    } else {
        escribirLog("Insertando nueva respuesta: chat_id={$chat_id}, pregunta_id={$question_id}");
        $stmt = $pdo->prepare("
            INSERT INTO chatbot_respuestas (chat_id, pregunta_id, respuesta, opcion_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $chat_id,
            $question_id,
            $answer,
            $option_id > 0 ? $option_id : null
        ]);
    }

    // =====================================================
    // 5) PROCESAR SEGÚN pregunta_id
    // =====================================================

    // DNI / CUIL / NOMBRE (2)
    if ($question_id === 2) {
        escribirLog("Procesando DNI/CUIL/NOMBRE: dni={$dni}, cuil={$cuil}, nombre={$nombre}");

        if ($dni !== "") {
            $stmt = $pdo->prepare("
                INSERT INTO clientes_detalles (usuario_id, dni)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE dni = VALUES(dni)
            ");
            $stmt->execute([$cliente_id, $dni]);
            escribirLog("DNI guardado: {$dni}");
        }

        if ($cuil || $nombre) {
            $stmt = $pdo->prepare("
                UPDATE chats
                SET cuil_validado = COALESCE(NULLIF(?, ''), cuil_validado),
                    nombre_validado = COALESCE(NULLIF(?, ''), nombre_validado)
                WHERE id = ?
            ");
            $stmt->execute([$cuil, $nombre, $chat_id]);
            escribirLog("CUIL/Nombre guardado en chat: cuil={$cuil}, nombre={$nombre}");
        }

        if ($nombre) {
            $pdo->prepare("UPDATE usuarios SET nombre = ? WHERE id = ?")
                ->execute([$nombre, $cliente_id]);
            escribirLog("Nombre actualizado en usuarios");
        }
    }

    // Código de área (4)
    if ($question_id === 4 && $answer !== "") {
        escribirLog("Guardando código de área: {$answer}");
        $pdo->prepare("UPDATE usuarios SET codigo_area = ? WHERE id = ?")
            ->execute([$answer, $cliente_id]);
    }

    // Teléfono (5) - UNIFICADO / COMPATIBLE
    if ($question_id === 5 && $answer !== "") {

        $raw = preg_replace('/\D+/', '', $answer);

        if (strlen($raw) === 8) {
            // flujo viejo (ya llega sin área)
            $stmt = $pdo->prepare("SELECT respuesta FROM chatbot_respuestas WHERE chat_id=? AND pregunta_id=4 ORDER BY id DESC LIMIT 1");
            $stmt->execute([$chat_id]);
            $area = preg_replace('/\D+/', '', (string)$stmt->fetchColumn());
            $phone = $raw;
        } elseif (strlen($raw) === 10) {
            // flujo nuevo unificado
            $area = substr($raw, 0, 2);
            $phone = substr($raw, 2);
        } else {
            escribirLog("Teléfono inválido: {$raw}", "WARNING");
            throw new Exception("Teléfono inválido");
        }

        $tel = $area . $phone;

        $pdo->prepare("UPDATE usuarios SET codigo_area=?, telefono=? WHERE id=?")
            ->execute([$area, $tel, $cliente_id]);

        $pdo->prepare("UPDATE clientes SET telefono=? WHERE usuario_id=?")
            ->execute([$tel, $cliente_id]);

        $pdo->prepare("UPDATE chats SET areacode=?, phone=? WHERE id=?")
            ->execute([$area, $phone, $chat_id]);

        escribirLog("Teléfono guardado OK: {$tel}");
    }

    // Situación laboral (3)
    if ($question_id === 3) {
        $a = mb_strtolower($answer);
        $map = [
            "auh" => "COBRA_AUH",
            "suaf" => "COBRA_SUAF",
            "jubil" => "JUBILADO",
            "depend" => "RELACION_DEPENDENCIA",
            "recibo" => "RELACION_DEPENDENCIA",
            "sueldo" => "RELACION_DEPENDENCIA",
            "mono" => "MONO_RESPONSABLE",
            "negro" => "COBRA_NEGRO",
        ];

        foreach ($map as $k => $v) {
            if (strpos($a, $k) !== false) {
                $pdo->prepare("UPDATE chats SET situacion_laboral = ? WHERE id = ?")
                    ->execute([$v, $chat_id]);
                escribirLog("Situación laboral guardada: {$v} (detectado: '{$k}' en '{$answer}')");
                break;
            }
        }
    }

    // 🏦 Banco (7) - CON MAPEO A IDs DEL CRM
    if ($question_id === 7 && $option_id > 0) {
        // Convertir ID del chatbot al ID del CRM
        $banco_crm = $banco_map[$option_id] ?? $option_id;
        
        escribirLog("Banco seleccionado: option_id={$option_id} → banco_crm={$banco_crm}");
        
        // Guardar el ID correcto del CRM
        $pdo->prepare("UPDATE chats SET banco = ? WHERE id = ?")
            ->execute([$banco_crm, $chat_id]);
        
        escribirLog("Banco guardado: option_id={$option_id}, banco_crm={$banco_crm}");
    }

    // Email (8)
    if ($question_id === 8 && filter_var($answer, FILTER_VALIDATE_EMAIL)) {
        escribirLog("Procesando email: {$answer}");

        // Email único (usuarios.email es UNIQUE)
        $stmt = $pdo->prepare("
            SELECT id FROM usuarios
            WHERE email = ? AND id <> ?
            LIMIT 1
        ");
        $stmt->execute([$answer, $cliente_id]);

        if ($stmt->fetch()) {
            // Email duplicado: NO cortar flujo, se continúa para CRM
            escribirLog("Email duplicado (se continúa flujo): {$answer}", "WARNING");
        } else {
            // Guardar email REAL en usuarios y clientes (solo si es nuevo)
            $pdo->prepare("UPDATE usuarios SET email = ? WHERE id = ?")
                ->execute([$answer, $cliente_id]);
            $pdo->prepare("UPDATE clientes SET email = ? WHERE usuario_id = ?")
                ->execute([$answer, $cliente_id]);

            escribirLog("Email guardado correctamente en usuarios/clientes");
        }

        // Preparar envío a API (pero NO enviar aquí)
        if ($confirmado) {
            escribirLog("Email confirmado, preparando payload para API (se enviará fuera de transacción)");

            // Recuperar datos desde BD (ya dentro de tx, consistentes)
            $stmt = $pdo->prepare("
                SELECT
                    c.banco,
                    c.situacion_laboral,
                    c.cuil_validado,
                    c.nombre_validado,
                    u.email,
                    u.codigo_area,
                    u.telefono
                FROM chats c
                JOIN usuarios u ON u.id = c.cliente_id
                WHERE c.id = ?
                LIMIT 1
            ");
            $stmt->execute([$chat_id]);
            $d = $stmt->fetch(PDO::FETCH_ASSOC);

            // Area code fallback desde respuestas
            $areacode = trim((string)($d["codigo_area"] ?? ""));
            if ($areacode === "") {
                $stmt = $pdo->prepare("
                    SELECT respuesta
                    FROM chatbot_respuestas
                    WHERE chat_id = ? AND pregunta_id = 4
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$chat_id]);
                $areacode = trim((string)$stmt->fetchColumn());
            }

            // Phone: pregunta 5 ahora puede venir unificada (10 dígitos) o vieja (solo número)
            $stmt = $pdo->prepare("
                SELECT respuesta
                FROM chatbot_respuestas
                WHERE chat_id = ? AND pregunta_id = 5
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$chat_id]);
            $raw_phone = trim((string)$stmt->fetchColumn());

            // Normalizar
            $areacode  = preg_replace('/\D+/', '', $areacode);
            $raw_phone = preg_replace('/\D+/', '', $raw_phone);

            // Si viene unificado (10), split fijo 2+8
            if (strlen($raw_phone) === 10) {
                $areacode = substr($raw_phone, 0, 2);
                $phone    = substr($raw_phone, 2);
            } else {
                $phone = $raw_phone;
            }

            // Validar completos
            $faltantes = [];
            foreach (["banco", "situacion_laboral", "cuil_validado", "nombre_validado", "email"] as $k) {
                if (empty($d[$k])) $faltantes[] = $k;
            }
            if ($areacode === "") $faltantes[] = "areacode";
            if ($phone === "")    $faltantes[] = "phone";

            if (!empty($faltantes)) {
                escribirLog("Datos incompletos para API: " . implode(", ", $faltantes), "WARNING");
            } else {
                $post_data = [
                    "cuil" => $d["cuil_validado"],
                    "name" => $d["nombre_validado"],
                    "email" => $d["email"],
                    "areacode" => $areacode,
                    "phone" => $phone,
                    "bank" => $d["banco"], // Ya contiene el ID correcto del CRM
                    "auh" => "",
                    "suaf" => "",
                    "jubilado" => "",
                    "relacion" => "",
                    "mono" => "",
                    "negro" => ""
                ];

                switch ($d["situacion_laboral"]) {
                    case "COBRA_AUH": $post_data["auh"] = "si"; break;
                    case "COBRA_SUAF": $post_data["suaf"] = "si"; break;
                    case "JUBILADO": $post_data["jubilado"] = "si"; break;
                    case "RELACION_DEPENDENCIA": $post_data["relacion"] = "si"; break;
                    case "MONO_RESPONSABLE": $post_data["mono"] = "si"; break;
                    case "COBRA_NEGRO": $post_data["negro"] = "si"; break;
                }

                $debeEnviarApi = true;
                $apiPostData = $post_data;
                escribirLog("Payload API listo (se enviará luego del COMMIT): " . json_encode($apiPostData));
            }
        }
    }

    // COMMIT BD
    $pdo->commit();
    escribirLog("✅ COMMIT OK - chat_id={$chat_id} question_id={$question_id}");

    // Responder OK al frontend SIEMPRE (si guardó BD)
    echo json_encode(["success" => true, "chat_id" => $chat_id, "question_id" => $question_id]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
        escribirLog("Transacción revertida", "WARNING");
    }

    escribirLog("ERROR: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine(), "ERROR");
    escribirLog("Stack trace: " . $e->getTraceAsString(), "ERROR");

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error interno del servidor",
        "error" => $e->getMessage()
    ]);
    exit;
}

// =====================================================
// ✅ API EXTERNA FUERA DE TRANSACCIÓN (NO ROMPE AL USUARIO)
// =====================================================
if ($debeEnviarApi && is_array($apiPostData)) {
    try {
        escribirLog("➡️ Enviando a API FUERA de transacción... URL={$apiUrl}");

        $maxReintentos = 3;
        $response = false;
        $httpCode = 0;
        $curlError = "";

        for ($intento = 1; $intento <= $maxReintentos; $intento++) {
            escribirLog("Intento {$intento}/{$maxReintentos} de envío a API...");

            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($apiPostData),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/x-www-form-urlencoded",
                    "User-Agent: ChatbotPrestamolider/1.0"
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = (string)curl_error($ch);
            curl_close($ch);

            escribirLog("Respuesta API (intento {$intento}): HTTP {$httpCode}, response=" . substr((string)$response, 0, 500));

            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                escribirLog("✅ Envío exitoso a API en intento {$intento}");
                break;
            }

            escribirLog("⚠️ Error intento {$intento}: HTTP {$httpCode}, curl_error={$curlError}", "WARNING");

            if ($intento < $maxReintentos) {
                usleep(500000);
            }
        }

        // Guardar respuesta CRM (best-effort)
        try {
            $api_response = json_decode((string)$response, true);

            $status = null;
            $message = "";

            if (is_array($api_response) && isset($api_response["status"])) {
                $status = (string)$api_response["status"];
                $message = (string)($api_response["message"] ?? "");
            } else {
                $status = "NOJSON_HTTP_" . $httpCode;
                $message = substr((string)$response, 0, 200);
            }

            $enviado = ($response !== false && $httpCode >= 200 && $httpCode < 300) ? 1 : 0;

            $stmt = $pdo->prepare("
                UPDATE chats
                SET enviado_crm = ?,
                    respuesta_crm = ?
                WHERE id = ?
            ");
            $stmt->execute([$enviado, $status, $chat_id]);

            escribirLog("✅ Update chats CRM: enviado_crm={$enviado}, respuesta_crm={$status}, msg=" . substr($message, 0, 120));

        } catch (Throwable $e2) {
            escribirLog("⚠️ No se pudo guardar respuesta CRM en chats: " . $e2->getMessage(), "WARNING");
        }

    } catch (Throwable $e) {
        escribirLog("❌ Error enviando a API (fuera tx): " . $e->getMessage(), "ERROR");
    }
}