<?php
// =====================================================
// get_messages.php - CORREGIDO
// =====================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../../backend/connection.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método no permitido"]);
    exit;
}

$chat_id = intval($_GET["chat_id"] ?? 0);
$last_id = intval($_GET["last_id"] ?? 0);

if (!$chat_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "chat_id es obligatorio"]);
    exit;
}

try {
    // =====================================================
    // ✅ CORRECCIÓN 1: Mapear nombres correctamente
    // ✅ CORRECCIÓN 2: Traer TODOS los mensajes (no solo cliente)
    // =====================================================
    $stmt = $pdo->prepare("
        SELECT 
            m.id,
            m.chat_id,
            m.emisor,
            m.mensaje,
            m.fecha,
            m.tiene_archivo,
            a.id              AS archivo_id,
            a.nombre_original AS archivo_nombre,
            a.ruta            AS archivo_url,
            a.tamano          AS archivo_tamano
        FROM mensajes m
        LEFT JOIN chat_archivos a ON a.mensaje_id = m.id
        WHERE m.chat_id = ?
          AND m.id > ?
        ORDER BY m.id ASC
    ");
    $stmt->execute([$chat_id, $last_id]);
    $mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response_messages = [];

    foreach ($mensajes as $msg) {
        // =====================================================
        // ✅ CORRECCIÓN 3: Mapear correctamente emisor → sender
        // =====================================================
        $mensaje_data = [
            "id"            => (int)$msg["id"],
            "chat_id"       => (int)$msg["chat_id"],
            "sender"        => $msg["emisor"],        // ✅ Mapeo correcto
            "message"       => $msg["mensaje"],       // ✅ Mapeo correcto
            "fecha"         => $msg["fecha"],
            "tiene_archivo" => (bool)$msg["tiene_archivo"]
        ];

        // =====================================================
        // ✅ CORRECCIÓN 4: Incluir archivos si existen
        // =====================================================
        if ($msg["tiene_archivo"] && $msg["archivo_id"]) {
            $mensaje_data["archivo"] = [
                "id"     => (int)$msg["archivo_id"],
                "nombre" => $msg["archivo_nombre"],
                "url"    => $msg["archivo_url"],
                "tamano" => (int)$msg["archivo_tamano"]
            ];
        }

        $response_messages[] = $mensaje_data;
    }

    echo json_encode([
        "success" => true,
        "data" => [
            "messages" => $response_messages,
            "count"    => count($response_messages)
        ]
    ]);

} catch (PDOException $e) {
    error_log("❌ Error en get_messages: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al obtener mensajes"
    ]);
}