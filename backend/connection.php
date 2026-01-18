<?php
// backend/connection.php
$host = "localhost";
$dbname = "u958859890_System";
$user = "u958859890_System";
$pass = "o34CM1wW!";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode([
        "success" => false, 
        "message" => "Error de conexión a la base de datos"
    ]);
    exit;
}
?>