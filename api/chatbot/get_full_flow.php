<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../../backend/connection.php";

try {

    // Obtener todo el flujo del chatbot
    $stmt = $pdo->query("
        SELECT id, pregunta, tipo
        FROM chatbot_flujo
        ORDER BY id ASC
    ");
    $preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($preguntas as &$p) {

        if ($p['tipo'] === 'opcion') {
            $stmt2 = $pdo->prepare("
                SELECT id, texto, departamento_id 
                FROM chatbot_opciones
                WHERE flujo_id = ?
                ORDER BY id ASC
            ");
            $stmt2->execute([$p['id']]);
            $p['opciones'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $p['opciones'] = [];
        }
    }

    echo json_encode([
        "success" => true,
        "data" => $preguntas
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al obtener el flujo",
        "error" => $e->getMessage()
    ]);
}
?>
