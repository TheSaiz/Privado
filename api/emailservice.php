<?php
/**
 * Servicio de Email para el Sistema de Préstamos
 * Maneja todas las notificaciones por correo
 */

class EmailService {
    private $from_email;
    private $from_name;
    private $smtp_enabled = false;
    
    public function __construct() {
        $this->from_email = 'no-reply@prestamolider.com';
        $this->from_name = 'Préstamo Líder';
        
        // Puedes habilitar SMTP si lo configuras
        // $this->smtp_enabled = true;
    }
    
    /**
     * Envía un email
     */
    private function enviar($to, $subject, $body) {
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: {$this->from_name} <{$this->from_email}>" . "\r\n";
        
        // Registrar el intento de envío en log
        $this->log("Enviando email a: $to | Asunto: $subject");
        
        if ($this->smtp_enabled) {
            // Aquí puedes integrar PHPMailer o similar
            return $this->enviarSMTP($to, $subject, $body, $headers);
        } else {
            // Usar mail() de PHP (requiere servidor configurado)
            return mail($to, $subject, $body, $headers);
        }
    }
    
    /**
     * Log de emails
     */
    private function log($mensaje) {
        $logFile = __DIR__ . '/../../logs/emails.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[$timestamp] $mensaje\n", FILE_APPEND);
    }
    
    /**
     * Template base para todos los emails
     */
    private function template($titulo, $contenido, $boton_texto = null, $boton_url = null) {
        $boton_html = '';
        if ($boton_texto && $boton_url) {
            $boton_html = "
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$boton_url}' style='background: linear-gradient(135deg, #2563eb 0%, #16a34a 100%); color: white; padding: 12px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>
                    {$boton_texto}
                </a>
            </div>";
        }
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; background: #f5f7fb; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; background: white; }
                .header { background: linear-gradient(135deg, #2563eb 0%, #16a34a 100%); padding: 30px; text-align: center; }
                .header h1 { color: white; margin: 0; font-size: 28px; }
                .content { padding: 40px 30px; }
                .footer { background: #f8fafc; padding: 20px; text-align: center; font-size: 12px; color: #64748b; }
                .alert { padding: 15px; border-radius: 8px; margin: 20px 0; }
                .alert-info { background: #dbeafe; color: #1e40af; border-left: 4px solid #3b82f6; }
                .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
                .alert-warning { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }
                .alert-danger { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
                .detail-box { background: #f8fafc; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .detail-item { margin: 10px 0; }
                .detail-label { color: #64748b; font-size: 13px; font-weight: 600; }
                .detail-value { color: #0f172a; font-size: 18px; font-weight: 700; margin-top: 4px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>💰 Préstamo Líder</h1>
                </div>
                <div class='content'>
                    <h2 style='color: #0f172a; margin-top: 0;'>{$titulo}</h2>
                    {$contenido}
                    {$boton_html}
                </div>
                <div class='footer'>
                    <p>Este es un email automático de Préstamo Líder. Por favor no responder.</p>
                    <p>© " . date('Y') . " Préstamo Líder. Todos los derechos reservados.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Email: Nueva solicitud de préstamo (para asesor)
     */
    public function nuevaSolicitud($asesor_email, $cliente_nombre, $monto, $cuotas, $frecuencia, $prestamo_id) {
        $contenido = "
        <p>Hola,</p>
        <p>Tienes una <strong>nueva solicitud de préstamo</strong> para evaluar:</p>
        
        <div class='detail-box'>
            <div class='detail-item'>
                <div class='detail-label'>Cliente</div>
                <div class='detail-value'>{$cliente_nombre}</div>
            </div>
            <div class='detail-item'>
                <div class='detail-label'>Monto Solicitado</div>
                <div class='detail-value'>$" . number_format($monto, 0, ',', '.') . "</div>
            </div>
            <div class='detail-item'>
                <div class='detail-label'>Cuotas</div>
                <div class='detail-value'>{$cuotas} {$frecuencia}s</div>
            </div>
        </div>
        
        <div class='alert alert-info'>
            <strong>💼 Acción requerida:</strong> Ingresa al panel de administración para evaluar esta solicitud.
        </div>
        ";
        
        return $this->enviar(
            $asesor_email,
            "Nueva Solicitud de Préstamo #$prestamo_id",
            $this->template(
                "Nueva Solicitud de Préstamo",
                $contenido,
                "Ver Solicitud",
                "https://prestamolider.com/system/prestamos_admin.php"
            )
        );
    }
    
    /**
     * Email: Contraoferta enviada (para cliente)
     */
    public function contraofertaEnviada($cliente_email, $cliente_nombre, $prestamo_id, $monto_original, $monto_ofrecido, $cuotas_ofrecidas, $frecuencia_ofrecida, $tasa_interes, $comentarios = '') {
        $contenido = "
        <p>Hola <strong>{$cliente_nombre}</strong>,</p>
        <p>Tu asesor ha evaluado tu solicitud de préstamo y te ha enviado una <strong>contraoferta</strong>.</p>
        
        <div class='detail-box'>
            <h3 style='margin-top: 0; color: #64748b; font-size: 14px;'>Solicitud Original</h3>
            <div class='detail-item'>
                <div class='detail-label'>Monto Solicitado</div>
                <div class='detail-value'>$" . number_format($monto_original, 0, ',', '.') . "</div>
            </div>
        </div>
        
        <div class='detail-box' style='border: 2px solid #3b82f6;'>
            <h3 style='margin-top: 0; color: #1e40af; font-size: 16px;'>💼 Contraoferta del Asesor</h3>
            <div class='detail-item'>
                <div class='detail-label'>Monto Ofrecido</div>
                <div class='detail-value' style='color: #2563eb;'>$" . number_format($monto_ofrecido, 0, ',', '.') . "</div>
            </div>
            <div class='detail-item'>
                <div class='detail-label'>Cuotas</div>
                <div class='detail-value'>{$cuotas_ofrecidas} {$frecuencia_ofrecida}s</div>
            </div>
            <div class='detail-item'>
                <div class='detail-label'>Tasa de Interés</div>
                <div class='detail-value'>{$tasa_interes}%</div>
            </div>
        </div>
        ";
        
        if ($comentarios) {
            $contenido .= "
            <div class='alert alert-info'>
                <strong>Comentario del asesor:</strong><br>
                " . nl2br(htmlspecialchars($comentarios)) . "
            </div>";
        }
        
        $contenido .= "
        <div class='alert alert-warning'>
            <strong>⏰ Acción requerida:</strong> Ingresa a tu portal para aceptar o rechazar esta contraoferta.
        </div>
        ";
        
        return $this->enviar(
            $cliente_email,
            "Contraoferta de Préstamo #$prestamo_id - Préstamo Líder",
            $this->template(
                "Tienes una Contraoferta",
                $contenido,
                "Ver Contraoferta",
                "https://prestamolider.com/system/prestamos_clientes.php"
            )
        );
    }
    
    /**
     * Email: Préstamo aprobado directamente (para cliente)
     */
    public function prestamoAprobado($cliente_email, $cliente_nombre, $prestamo_id, $monto, $cuotas, $frecuencia, $tasa_interes) {
        $monto_total = $monto * (1 + ($tasa_interes / 100));
        
        $contenido = "
        <p>Hola <strong>{$cliente_nombre}</strong>,</p>
        <p>¡Excelentes noticias! Tu solicitud de préstamo ha sido <strong style='color: #10b981;'>APROBADA</strong>. ✅</p>
        
        <div class='detail-box' style='border: 2px solid #10b981;'>
            <h3 style='margin-top: 0; color: #065f46;'>💰 Detalles del Préstamo Aprobado</h3>
            <div class='detail-item'>
                <div class='detail-label'>Monto del Préstamo</div>
                <div class='detail-value' style='color: #10b981;'>$" . number_format($monto, 0, ',', '.') . "</div>
            </div>
            <div class='detail-item'>
                <div class='detail-label'>Total a Pagar</div>
                <div class='detail-value'>$" . number_format($monto_total, 0, ',', '.') . "</div>
            </div>
            <div class='detail-item'>
                <div class='detail-label'>Cuotas</div>
                <div class='detail-value'>{$cuotas} {$frecuencia}s</div>
            </div>
            <div class='detail-item'>
                <div class='detail-label'>Valor de cada cuota</div>
                <div class='detail-value'>$" . number_format($monto_total / $cuotas, 0, ',', '.') . "</div>
            </div>
            <div class='detail-item'>
                <div class='detail-label'>Tasa de Interés</div>
                <div class='detail-value'>{$tasa_interes}%</div>
            </div>
        </div>
        
        <div class='alert alert-success'>
            <strong>✅ Préstamo Activo:</strong> Tu préstamo ya está activo y el cronograma de pagos ha sido generado. Puedes verlo en tu portal de clientes.
        </div>
        ";
        
        return $this->enviar(
            $cliente_email,
            "¡Préstamo Aprobado! #$prestamo_id - Préstamo Líder",
            $this->template(
                "¡Tu Préstamo fue Aprobado!",
                $contenido,
                "Ver Mi Préstamo",
                "https://prestamolider.com/system/prestamos_clientes.php"
            )
        );
    }
    
    /**
     * Email: Préstamo rechazado (para cliente)
     */
    public function prestamoRechazado($cliente_email, $cliente_nombre, $prestamo_id, $motivo) {
        $contenido = "
        <p>Hola <strong>{$cliente_nombre}</strong>,</p>
        <p>Lamentablemente, tu solicitud de préstamo <strong>#$prestamo_id</strong> no ha podido ser aprobada en este momento.</p>
        
        <div class='alert alert-danger'>
            <strong>Motivo del rechazo:</strong><br>
            " . nl2br(htmlspecialchars($motivo)) . "
        </div>
        
        <p>Te invitamos a:</p>
        <ul>
            <li>Revisar tu situación financiera</li>
            <li>Completar toda tu documentación</li>
            <li>Realizar una nueva solicitud cuando consideres oportuno</li>
        </ul>
        
        <p>Nuestro equipo está disponible para asesorarte.</p>
        ";
        
        return $this->enviar(
            $cliente_email,
            "Solicitud de Préstamo #$prestamo_id - Préstamo Líder",
            $this->template(
                "Actualización sobre tu Solicitud",
                $contenido,
                "Ir al Portal",
                "https://prestamolider.com/system/prestamos_clientes.php"
            )
        );
    }
    
    /**
     * Email: Cliente aceptó contraoferta (para asesor)
     */
    public function contraofertaAceptada($asesor_email, $cliente_nombre, $prestamo_id, $monto_ofrecido) {
        $contenido = "
        <p>Hola,</p>
        <p>El cliente <strong>{$cliente_nombre}</strong> ha <strong style='color: #10b981;'>ACEPTADO</strong> tu contraoferta. ✅</p>
        
        <div class='detail-box'>
            <div class='detail-item'>
                <div class='detail-label'>Préstamo</div>
                <div class='detail-value'>#$prestamo_id</div>
            </div>
            <div class='detail-item'>
                <div class='detail-label'>Monto</div>
                <div class='detail-value'>$" . number_format($monto_ofrecido, 0, ',', '.') . "</div>
            </div>
        </div>
        
        <div class='alert alert-success'>
            <strong>✅ Préstamo Activado:</strong> El préstamo ha sido activado automáticamente y el cronograma de pagos ha sido generado.
        </div>
        ";
        
        return $this->enviar(
            $asesor_email,
            "Contraoferta Aceptada - Préstamo #$prestamo_id",
            $this->template(
                "Contraoferta Aceptada",
                $contenido,
                "Ver Préstamo",
                "https://prestamolider.com/system/prestamos_admin.php"
            )
        );
    }
    
    /**
     * Email: Cliente rechazó contraoferta (para asesor)
     */
    public function contraofertaRechazada($asesor_email, $cliente_nombre, $prestamo_id, $motivo = '') {
        $contenido = "
        <p>Hola,</p>
        <p>El cliente <strong>{$cliente_nombre}</strong> ha <strong>rechazado</strong> tu contraoferta del préstamo <strong>#$prestamo_id</strong>.</p>
        ";
        
        if ($motivo) {
            $contenido .= "
            <div class='alert alert-warning'>
                <strong>Motivo del cliente:</strong><br>
                " . nl2br(htmlspecialchars($motivo)) . "
            </div>";
        }
        
        $contenido .= "
        <div class='alert alert-info'>
            <strong>💼 Siguiente paso:</strong> La solicitud ha vuelto a estado pendiente. Puedes hacer una nueva contraoferta o aprobar/rechazar el préstamo.
        </div>
        ";
        
        return $this->enviar(
            $asesor_email,
            "Contraoferta Rechazada - Préstamo #$prestamo_id",
            $this->template(
                "Contraoferta Rechazada",
                $contenido,
                "Ver Solicitud",
                "https://prestamolider.com/system/prestamos_admin.php"
            )
        );
    }
}
?>