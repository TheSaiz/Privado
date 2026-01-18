<?php
/**
 * get_new_messages.php
 * -----------------------------------------
 */

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../../backend/connection.php";

$chat_id = intval($_GET["chat_id"] ?? 0);
$last_id = intval($_GET["last_id"] ?? 0);

try {

    /* =========================================================
       MODO B — NOTIFICACIONES (SIN CHAT ACTIVO)
    ========================================================= */
    if ($chat_id <= 0) {

        /*
          🔔 Chats nuevos:
          - estado = pendiente
          - no aceptados aún
          - podés filtrar por departamento si querés
        */

        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM chats
            WHERE estado = 'pendiente'
        ");
        $stmt->execute();

        $total = (int)$stmt->fetchColumn();

        echo json_encode([
            "success" => true,
            "modo"    => "notificaciones",
            "nuevos_chats" => $total
        ]);
        exit;
    }

    /* =========================================================
       MODO A — MENSAJES NUEVOS DEL CHAT
    ========================================================= */

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
          AND m.emisor = 'cliente'
        ORDER BY m.id ASC
    ");

    $stmt->execute([$chat_id, $last_id]);
    $mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mensajes_formateados = [];

    foreach ($mensajes as $msg) {

        $item = [
            "id"             => (int)$msg["id"],
            "emisor"         => $msg["emisor"],
            "mensaje"        => $msg["mensaje"],
            "fecha"          => $msg["fecha"],
            "tiene_archivo"  => (bool)$msg["tiene_archivo"]
        ];

        if ($msg["tiene_archivo"] && $msg["archivo_id"]) {
            $item["archivo"] = [
                "id"     => (int)$msg["archivo_id"],
                "nombre" => $msg["archivo_nombre"],
                "url"    => $msg["archivo_url"],
                "tamano" => (int)$msg["archivo_tamano"]
            ];
        }

        $mensajes_formateados[] = $item;
    }

    echo json_encode([
        "success" => true,
        "modo"    => "mensajes",
        "data"    => $mensajes_formateados
    ]);

} catch (PDOException $e) {

    error_log("❌ get_new_messages error: " . $e->getMessage());

    echo json_encode([
        "success" => false,
        "error"   => "Error interno"
    ]);
}
