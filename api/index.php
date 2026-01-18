<?php
// /api/index.php
header("Content-Type: application/json");
echo json_encode([
    "service" => "API Chat - sistema_prestamos",
    "status" => "ok",
    "available_endpoints" => [
        "/chat/start_chat.php",
        "/chat/assign_department.php",
        "/chat/accept_chat.php",
        "/chat/get_chat.php",
        "/messages/send_message.php",
        "/messages/get_messages.php",
        "/chatbot/get_question.php",
        "/chatbot/save_answer.php",
        "/asesores/get_available.php",
        "/asesores/notify_asesores.php",
        "/asesores/accept_request.php",
        "/sessions/create_client.php",
        "/sessions/validate_client.php"
    ]
]);
?>
