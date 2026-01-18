<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../../backend/connection.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Método no permitido"]);
    exit;
}

$last_id = intval($_GET["last_id"] ?? 0);

try {
    if ($last_id === 0) {
        $stmt = $pdo->query("SELECT * FROM chatbot_flujo ORDER BY id ASC LIMIT 1");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM chatbot_flujo WHERE id > ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$last_id]);
    }

    $question = $stmt->fetch();

    if (!$question) {
        echo json_encode([
            "success" => true,
            "data" => [
                "finished" => true,
                "message" => "Fin del cuestionario"
            ]
        ]);
        exit;
    }

    $options = [];

    if ($question["tipo"] === "opcion") {
        $stmt = $pdo->prepare("
            SELECT id, texto, departamento_id 
            FROM chatbot_opciones 
            WHERE flujo_id = ?
        ");
        $stmt->execute([$question["id"]]);
        $options = $stmt->fetchAll();
    }

    echo json_encode([
        "success" => true,
        "data" => [
            "finished" => false,
            "question" => [
                "id" => intval($question["id"]),
                "pregunta" => $question["pregunta"],
                "tipo" => $question["tipo"],
                "options" => $options
            ]
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al obtener pregunta"
    ]);
}
?>