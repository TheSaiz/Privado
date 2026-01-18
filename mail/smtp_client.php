<?php

require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function getMailer(): PHPMailer
{
    $configFile = __DIR__ . '/../config.json';

    if (!file_exists($configFile)) {
        throw new Exception('config.json no encontrado');
    }

    $config = json_decode(file_get_contents($configFile), true);
    $smtp   = $config['smtp'] ?? null;

    if (!$smtp) {
        throw new Exception('Configuración SMTP inexistente');
    }

    $required = ['host','port','username','password','from_email','from_name','encryption'];
    foreach ($required as $k) {
        if (empty($smtp[$k])) {
            throw new Exception("SMTP incompleto: falta {$k}");
        }
    }

    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';

    $mail->isSMTP();
    $mail->Host       = $smtp['host'];
    $mail->Port       = (int)$smtp['port'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtp['username'];
    $mail->Password   = $smtp['password'];
    $mail->Timeout    = 15;

    switch ($smtp['encryption']) {
        case 'ssl':
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            break;

        case 'tls':
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            break;

        case 'none':
            $mail->SMTPSecure  = false;
            $mail->SMTPAutoTLS = false;
            break;

        default:
            throw new Exception('Tipo de encriptación SMTP inválido');
    }

    $mail->setFrom($smtp['from_email'], $smtp['from_name']);

    return $mail;
}
