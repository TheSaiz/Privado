<?php
/**
 * notificar_documentacion.php
 * Envía notificación SOLO cuando una decisión (aprobado / rechazado)
 * corresponde a una NUEVA carga de documentación.
 *
 * Reglas:
 * - estado_validacion debe ser 'aprobado' o 'rechazado'
 * - docs_updated_at debe existir
 * - docs_updated_at_notificado debe ser NULL o menor a docs_updated_at
 */

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../../backend/connection.php"; // ajustar si cambia tu path

// =========================
// INPUT
// =========================
$usuario_id = (int)($_POST['usuario_id'] ?? $_GET['usuario_id'] ?? 0);

if ($usuario_id <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "usuario_id inválido"
    ]);
    exit;
}

// =========================
// OBTENER DATOS CLIENTE
// =========================
$stmt = $pdo->prepare("
    SELECT
        estado_validacion,
        docs_updated_at,
        docs_updated_at_notificado
    FROM clientes_detalles
    WHERE usuario_id = ?
    LIMIT 1
");
$stmt->execute([$usuario_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "Cliente no encontrado"
    ]);
    exit;
}

$estado = $cliente['estado_validacion'];
$docsUpdatedAt = $cliente['docs_updated_at'];
$docsNotificadoAt = $cliente['docs_updated_at_notificado'];

// =========================
// VALIDACIONES CLAVE
// =========================

// Solo aprobado o rechazado
if (!in_array($estado, ['aprobado', 'rechazado'], true)) {
    echo json_encode([
        "success" => true,
        "notificado" => false,
        "reason" => "estado_no_final"
    ]);
    exit;
}

// Debe existir una carga de docs
if (!$docsUpdatedAt) {
    echo json_encode([
        "success" => true,
        "notificado" => false,
        "reason" => "sin_docs_updated_at"
    ]);
    exit;
}

// Si ya se notificó este mismo ciclo → NO enviar
if ($docsNotificadoAt && strtotime($docsNotificadoAt) >= strtotime($docsUpdatedAt)) {
    echo json_encode([
        "success" => true,
        "notificado" => false,
        "reason" => "ciclo_ya_notificado"
    ]);
    exit;
}

// =========================
// MENSAJE A ENVIAR
// =========================
if ($estado === 'aprobado') {
    $titulo = "Documentación aprobada";
    $mensaje = "Tu documentación fue aprobada. Ya podés continuar con tu solicitud.";
} else {
    $titulo = "Documentación rechazada";
    $mensaje = "Tu documentación fue rechazada. Revisá los datos y volvé a intentarlo.";
}

// =====================================================
// 🔔 ACA VA EL ENVÍO REAL DE LA NOTIFICACIÓN
// (Firebase / OneSignal / lo que uses después)
// Por ahora solo queda el hook listo.
// =====================================================

// =====================================================
// MARCAR CICLO COMO NOTIFICADO
// =====================================================
$upd = $pdo->prepare("
    UPDATE clientes_detalles
    SET docs_updated_at_notificado = ?
    WHERE usuario_id = ?
");
$upd->execute([$docsUpdatedAt, $usuario_id]);

echo json_encode([
    "success" => true,
    "notificado" => true,
    "estado" => $estado
]);
