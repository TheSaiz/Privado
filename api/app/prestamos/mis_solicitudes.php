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

try {
  // Normalizamos resultados a un formato común
  $stmt = $pdo->prepare("
    (SELECT
      'prestamo' AS tipo,
      id,
      monto_solicitado AS monto,
      estado,
      fecha_solicitud AS fecha,
      cuotas_solicitadas AS extra_1,
      frecuencia_solicitada AS extra_2
    FROM prestamos
    WHERE cliente_id = ?)

    UNION ALL

    (SELECT
      'empeno' AS tipo,
      id,
      monto_solicitado AS monto,
      estado,
      fecha_solicitud AS fecha,
      descripcion_producto AS extra_1,
      NULL AS extra_2
    FROM empenos
    WHERE cliente_id = ?)

    UNION ALL

    (SELECT
      'prendario' AS tipo,
      id,
      monto_solicitado AS monto,
      estado,
      fecha_solicitud AS fecha,
      dominio AS extra_1,
      NULL AS extra_2
    FROM creditos_prendarios
    WHERE cliente_id = ?)

    ORDER BY fecha DESC
    LIMIT 200
  ");

  $stmt->execute([$cliente_id, $cliente_id, $cliente_id]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(["success"=>true,"data"=>$rows]);
} catch (Exception $e) {
  echo json_encode(["success"=>false,"error"=>$e->getMessage()]);
}
