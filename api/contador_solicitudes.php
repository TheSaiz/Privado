<?php
session_start();
require_once '../backend/connection.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['usuario_id'], $_SESSION['usuario_rol'])) {
    echo json_encode([
        'success' => false,
        'total' => 0
    ]);
    exit;
}

$usuario_id  = (int)$_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];

$where = "
    cd.docs_completos = 1
    AND cd.estado_validacion = 'en_revision'
";

/* Si es asesor, solo asignadas o libres */
if ($usuario_rol === 'asesor') {
    $where .= " AND (c.asesor_id = $usuario_id OR c.asesor_id IS NULL)";
}

$sql = "
SELECT COUNT(DISTINCT cd.usuario_id) AS total
FROM clientes_detalles cd
LEFT JOIN chats c ON c.cliente_id = cd.usuario_id
WHERE $where
";

$stmt = $pdo->query($sql);
$total = (int)$stmt->fetchColumn();

echo json_encode([
    'success' => true,
    'total' => $total
]);
