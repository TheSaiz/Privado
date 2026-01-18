<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../backend/connection.php';

$auth = auth_required();
$cliente_id = $auth["id"] ?? null;
if (!$cliente_id) { echo json_encode(["success"=>false,"error"=>"Cliente inválido"]); exit; }

$stmt = $pdo->prepare("
  SELECT 
    pp.fecha_pago,
    pp.monto,
    pp.metodo_pago,
    pp.estado
  FROM prestamos_pagos pp
  INNER JOIN prestamos p ON p.id = pp.prestamo_id
  WHERE p.cliente_id = ?
    AND pp.estado = 'pagado'
  ORDER BY pp.fecha_pago DESC
  LIMIT 20
");
$stmt->execute([$cliente_id]);

echo json_encode([
  "success" => true,
  "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
]);
