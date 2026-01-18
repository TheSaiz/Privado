<?php
/**
 * ExternalProvider.php
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../mail/PHPMailer/Exception.php';
require_once __DIR__ . '/../mail/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../mail/PHPMailer/SMTP.php';

class ExternalProvider {

    private array $smtp;

    public function __construct(array $smtpConfig) {
        $this->smtp = $smtpConfig;
        $this->validateConfig();
    }

    /**
     * Validar configuración SMTP
     */
    private function validateConfig(): void {
        $required = ['host', 'port', 'username', 'password', 'from_email', 'from_name'];
        
        foreach ($required as $field) {
            if (empty($this->smtp[$field])) {
                throw new Exception("Configuración SMTP incompleta: falta '$field'");
            }
        }
    }

    /**
     * Enviar email
     * 
     * @param string $to Email destinatario
     * @param string $subject Asunto del email
     * @param string $html Contenido HTML del email
     * @return bool True si se envió correctamente
     */
    public function send(string $to, string $subject, string $html): bool {

        try {
            $mail = new PHPMailer(true);

            // Configuración del servidor SMTP
            $mail->isSMTP();
            $mail->Host = $this->smtp['host'];
            $mail->Port = (int)$this->smtp['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtp['username'];
            $mail->Password = $this->smtp['password'];
            $mail->Timeout = 30;

            // Encriptación
            $encryption = $this->smtp['encryption'] ?? $this->smtp['secure'] ?? 'tls';
            
            switch (strtolower($encryption)) {
                case 'ssl':
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    break;
                case 'tls':
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    break;
                case 'none':
                    $mail->SMTPSecure = false;
                    $mail->SMTPAutoTLS = false;
                    break;
                default:
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            // Configuración del mensaje
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($this->smtp['from_email'], $this->smtp['from_name'] ?? 'Sistema');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;

            // Enviar
            $mail->send();

            $this->log("✓ Email enviado a $to | Asunto: $subject");
            return true;

        } catch (Exception $e) {
            $this->log("✗ Error PHPMailer: " . $e->getMessage());
            return false;
        } catch (Throwable $e) {
            $this->log("✗ Error inesperado: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Registrar en log
     */
    private function log(string $msg): void {
        $dir = __DIR__ . '/logs';
        
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file = $dir . '/emails.log';
        $ts = date('Y-m-d H:i:s');
        @file_put_contents($file, "[$ts] [ExternalProvider] $msg\n", FILE_APPEND);
    }
}
