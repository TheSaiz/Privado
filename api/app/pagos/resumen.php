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

// Pendientes
$stmt = $pdo->prepare("
  SELECT COUNT(*) as cant, COALESCE(SUM(pp.monto),0) as total
  FROM prestamos_pagos pp
  INNER JOIN prestamos p ON p.id = pp.prestamo_id
  WHERE p.cliente_id = ? AND pp.estado = 'pendiente'
");
$stmt->execute([$cliente_id]);
$pendientes = $stmt->fetch(PDO::FETCH_ASSOC);

// Vencidas
$stmt = $pdo->prepare("
  SELECT COUNT(*) as cant
  FROM prestamos_pagos pp
  INNER JOIN prestamos p ON p.id = pp.prestamo_id
  WHERE p.cliente_id = ? AND pp.estado = 'pendiente' AND pp.fecha_vencimiento < CURDATE()
");
$stmt->execute([$cliente_id]);
$vencidas = $stmt->fetch(PDO::FETCH_ASSOC);

// Pagadas
$stmt = $pdo->prepare("
  SELECT COUNT(*) as cant, COALESCE(SUM(pp.monto),0) as total
  FROM prestamos_pagos pp
  INNER JOIN prestamos p ON p.id = pp.prestamo_id
  WHERE p.cliente_id = ? AND pp.estado = 'pagado'
");
$stmt->execute([$cliente_id]);
$pagadas = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
  "success" => true,
  "data" => [
    "pendientes" => $pendientes,
    "vencidas"   => $vencidas,
    "pagadas"    => $pagadas,
  ]
]);
