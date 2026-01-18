<?php
// /api/config/database.php
$DB_HOST = "localhost";
$DB_USER = "u958859890_System";
$DB_PASS = "o34CM1wW!";
$DB_NAME = "u958859890_System";

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "DB connection error"]);
    exit;
}
$conn->set_charset("utf8mb4");
?>