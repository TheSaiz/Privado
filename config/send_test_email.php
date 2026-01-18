<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');

/* =========================
   AUTH
========================= */
if (
    !isset($_SESSION['usuario_id'], $_SESSION['usuario_rol']) ||
    $_SESSION['usuario_rol'] !== 'admin'
) {
    echo json_encode([
        'success' => false,
        'message' => 'No autorizado'
    ]);
    exit;
}

/* =========================
   INPUT
========================= */
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

$to = trim($data['email'] ?? '');

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'message' => 'Email inválido'
    ]);
    exit;
}

/* =========================
   SMTP
========================= */
try {
    // ✅ RUTA CORRECTA
    require_once __DIR__ . '/../mail/smtp_client.php';

    if (!function_exists('getMailer')) {
        throw new Exception('Función getMailer() no encontrada');
    }

    $mail = getMailer();

    if (!$mail) {
        throw new Exception('No se pudo inicializar el cliente SMTP');
    }

    $mail->clearAddresses();
    $mail->addAddress($to);

    $mail->isHTML(true);
    $mail->Subject = 'Prueba SMTP - Préstamo Líder';
    $mail->Body = '
        <h2>SMTP funcionando correctamente</h2>
        <p>Este es un correo de prueba enviado desde el panel de configuración.</p>
        <p><b>Fecha:</b> ' . date('d/m/Y H:i:s') . '</p>
    ';

    $mail->send();

    echo json_encode([
        'success' => true
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
