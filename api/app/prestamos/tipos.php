<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../../backend/connection.php';

auth_required();

$tipos = [
  ["id"=>"prestamo","titulo"=>"Préstamo Personal","subtitulo"=>"Hasta 24 cuotas","badge"=>"Según tu perfil"],
  ["id"=>"empeno","titulo"=>"Empeño","subtitulo"=>"Dinero rápido con garantía","badge"=>"Solo fotos del producto"],
  ["id"=>"prendario","titulo"=>"Crédito Prendario","subtitulo"=>"Usá tu vehículo como garantía","badge"=>"Solo fotos del vehículo"],
];

echo json_encode(["success"=>true,"data"=>$tipos]);
