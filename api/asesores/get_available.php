<?php
// /api/asesores/get_available.php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

$department_id = intval($_GET['department_id'] ?? 0);
if (!$department_id) response(false, "department_id requerido", null, 400);

$stmt = $conn->prepare("
    SELECT u.id, u.nombre, u.apellido
    FROM asesores_departamentos ad
    JOIN usuarios u ON ad.asesor_id = u.id
    WHERE ad.departamento_id = ? AND ad.disponible = 1
");
$stmt->bind_param("i", $department_id);
$stmt->execute();
$res = $stmt->get_result();
$items = [];
while ($r = $res->fetch_assoc()) $items[] = $r;

response(true, "Asesores disponibles", $items);
?>
