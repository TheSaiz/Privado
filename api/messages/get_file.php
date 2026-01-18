<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../../backend/connection.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método no permitido"]);
    exit;
}

$mensaje_id = intval($_GET["mensaje_id"] ?? 0);

if (!$mensaje_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "mensaje_id es obligatorio"]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, nombre_original, nombre_guardado, tipo_mime, tamano, ruta, fecha_subida
        FROM chat_archivos
        WHERE mensaje_id = ?
    ");
    $stmt->execute([$mensaje_id]);
    $archivo = $stmt->fetch();

    if (!$archivo) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Archivo no encontrado"]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "data" => $archivo
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error al obtener archivo"]);
}
?>