<?php
// /api/helpers/response.php
function response($success, $message, $data = null, $code = 200) {
    http_response_code($code);
    $payload = ["success" => $success, "message" => $message];
    if (!is_null($data)) $payload["data"] = $data;
    echo json_encode($payload);
    exit;
}
?>
