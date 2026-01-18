<?php
/**
 * Servicio de Email Completo para Sistema de Préstamos
 * Versión Producción - Con todos los métodos necesarios
 */

class EmailService {
    private $from_email;
    private $from_name;
    private $smtp_enabled = false;
    private $base_url;
    private $pdo;
    
    public function __construct() {
        $this->from_email = 'no-reply@prestamolider.com';
        $this->from_name = 'Préstamo Líder';
        $this->base_url = 'https://prestamolider.com/system';
        
        // Conexión a BD para obtener datos
        try {
            require_once __DIR__ . '/connection.php';
            $this->pdo = $pdo;
        } catch (Exception $e) {
            $this->log("Error conectando BD en EmailService: " . $e->getMessage());
        }
    }
    
    /**
     * Envía un email
     */
    private function enviar($to, $subject, $body) {
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: {$this->from_name} <{$this->from_email}>" . "\r\n";
        
        $this->log("Enviando email a: $to | Asunto: $subject");
        
        if ($this->smtp_enabled) {
            return $this->enviarSMTP($to, $subject, $body, $headers);
        } else {
            return @mail($to, $subject, $body, $headers);
        }
    }
    
    /**
     * Log de emails
     */
    private function log($mensaje) {
        $logFile = __DIR__ . '/../logs/emails.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[$timestamp] $mensaje\n", FILE_APPEND);
    }
    
    /**
     * Obtener email del cliente
     */
    private function getClienteEmail($cliente_id) {
        if (!$this->pdo) return null;
        
        $stmt = $this->pdo->prepare("SELECT email FROM usuarios WHERE id = ?");
        $stmt->execute([$cliente_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['email'] : null;
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
                    <p>Este es un email automático del sistema de Préstamo Líder</p>
                    <p>Si tienes dudas, contacta con nuestro equipo de soporte</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    // ===== NOTIFICACIONES DE SOLICITUD =====
    
    public function notificarSolicitudCreada($cliente_id, $prestamo_id, $monto, $cuotas, $frecuencia) {
        $email = $this->getClienteEmail($cliente_id);
        if (!$email) return false;
        
        $titulo = "✅ Solicitud de Préstamo Recibida";
        $contenido = "
            <p>Tu solicitud de préstamo ha sido recibida y está siendo evaluada por nuestro equipo.</p>
            
            <div class='detail-box'>
                <div class='detail-item'>
                    <div class='detail-label'>Monto Solicitado</div>
                    <div class='detail-value'>$" . number_format($monto, 0, ',', '.') . "</div>
                </div>
                <div class='detail-item'>
                    <div class='detail-label'>Plan de Pago</div>
                    <div class='detail-value'>{$cuotas} cuotas {$frecuencia}es</div>
                </div>
                <div class='detail-item'>
                    <div class='detail-label'>Número de Solicitud</div>
                    <div class='detail-value'>#{$prestamo_id}</div>
                </div>
            </div>
            
            <div class='alert alert-info'>
                <strong>📋 Próximos Pasos:</strong><br>
                • Nuestro equipo evaluará tu solicitud en las próximas 24-48 horas<br>
                • Recibirás una notificación cuando tengamos una respuesta<br>
                • Puedes revisar el estado en tu panel de cliente
            </div>
        ";
        
        $boton_url = $this->base_url . "/dashboard_clientes.php";
        
        return $this->enviar(
            $email,
            "Solicitud de Préstamo #{$prestamo_id} Recibida",
            $this->template($titulo, $contenido, "Ver Mi Panel", $boton_url)
        );
    }
    
    // ===== NOTIFICACIONES DE CONTRAOFERTA =====
    
    public function notificarContraoferta($cliente_id, $prestamo_id, $monto, $cuotas, $frecuencia, $comentarios = '') {
        $email = $this->getClienteEmail($cliente_id);
        if (!$email) return false;
        
        $titulo = "💬 Nueva Contraoferta de Préstamo";
        $contenido = "
            <p>Tenemos una contraoferta para tu solicitud de préstamo.</p>
            
            <div class='detail-box'>
                <div class='detail-item'>
                    <div class='detail-label'>Monto Ofrecido</div>
                    <div class='detail-value'>$" . number_format($monto, 0, ',', '.') . "</div>
                </div>
                <div class='detail-item'>
                    <div class='detail-label'>Plan de Pago</div>
                    <div class='detail-value'>{$cuotas} cuotas {$frecuencia}es</div>
                </div>
            </div>
            
            " . ($comentarios ? "<div class='alert alert-info'><strong>Comentario del Evaluador:</strong><br>{$comentarios}</div>" : "") . "
            
            <div class='alert alert-warning'>
                <strong>⏳ Acción Requerida:</strong><br>
                Por favor revisa la contraoferta en tu panel y decide si deseas aceptarla o rechazarla.
            </div>
        ";
        
        $boton_url = $this->base_url . "/prestamos_clientes.php";
        
        return $this->enviar(
            $email,
            "Contraoferta para tu Préstamo #{$prestamo_id}",
            $this->template($titulo, $contenido, "Ver Contraoferta", $boton_url)
        );
    }
    
    public function notificarContraofertaAceptada($cliente_id, $prestamo_id, $monto, $cuotas, $frecuencia) {
        $email = $this->getClienteEmail($cliente_id);
        if (!$email) return false;
        
        $titulo = "🎉 ¡Préstamo Aprobado!";
        $contenido = "
            <p>¡Excelente noticia! Has aceptado la contraoferta y tu préstamo está ahora <strong>ACTIVO</strong>.</p>
            
            <div class='detail-box'>
                <div class='detail-item'>
                    <div class='detail-label'>Monto del Préstamo</div>
                    <div class='detail-value'>$" . number_format($monto, 0, ',', '.') . "</div>
                </div>
                <div class='detail-item'>
                    <div class='detail-label'>Plan de Pago</div>
                    <div class='detail-value'>{$cuotas} cuotas {$frecuencia}es</div>
                </div>
            </div>
            
            <div class='alert alert-success'>
                <strong>✅ Próximos Pasos:</strong><br>
                • El dinero será depositado en tu cuenta en las próximas 24-48 horas<br>
                • Recibirás recordatorios antes de cada vencimiento<br>
                • Puedes ver tu plan de pagos en el panel
            </div>
        ";
        
        $boton_url = $this->base_url . "/dashboard_clientes.php";
        
        return $this->enviar(
            $email,
            "¡Tu Préstamo #{$prestamo_id} está Activo!",
            $this->template($titulo, $contenido, "Ver Plan de Pagos", $boton_url)
        );
    }
    
    public function notificarAdminContraofertaAceptada($prestamo_id) {
        // Email a admins cuando un cliente acepta contraoferta
        $admin_email = 'admin@prestamolider.com';
        
        $titulo = "✅ Contraoferta Aceptada - Préstamo #{$prestamo_id}";
        $contenido = "
            <p>El cliente ha aceptado la contraoferta del préstamo #{$prestamo_id}.</p>
            <p>El préstamo está ahora en estado <strong>ACTIVO</strong> y requiere que se procese el desembolso.</p>
        ";
        
        return $this->enviar(
            $admin_email,
            "Contraoferta Aceptada - Préstamo #{$prestamo_id}",
            $this->template($titulo, $contenido)
        );
    }
    
    public function notificarAdminContraofertaRechazada($prestamo_id, $motivo) {
        $admin_email = 'admin@prestamolider.com';
        
        $titulo = "❌ Contraoferta Rechazada - Préstamo #{$prestamo_id}";
        $contenido = "
            <p>El cliente ha rechazado la contraoferta del préstamo #{$prestamo_id}.</p>
            <p><strong>Motivo:</strong> {$motivo}</p>
        ";
        
        return $this->enviar(
            $admin_email,
            "Contraoferta Rechazada - Préstamo #{$prestamo_id}",
            $this->template($titulo, $contenido)
        );
    }
    
    // ===== NOTIFICACIONES DE APROBACIÓN/RECHAZO =====
    
    public function notificarPrestamoAprobado($cliente_id, $prestamo_id, $monto, $cuotas, $frecuencia) {
        $email = $this->getClienteEmail($cliente_id);
        if (!$email) return false;
        
        $titulo = "🎉 ¡Préstamo Aprobado!";
        $contenido = "
            <p>¡Felicitaciones! Tu préstamo ha sido <strong>APROBADO</strong> con las condiciones que solicitaste.</p>
            
            <div class='detail-box'>
                <div class='detail-item'>
                    <div class='detail-label'>Monto Aprobado</div>
                    <div class='detail-value'>$" . number_format($monto, 0, ',', '.') . "</div>
                </div>
                <div class='detail-item'>
                    <div class='detail-label'>Plan de Pago</div>
                    <div class='detail-value'>{$cuotas} cuotas {$frecuencia}es</div>
                </div>
            </div>
            
            <div class='alert alert-success'>
                <strong>✅ Próximos Pasos:</strong><br>
                • El dinero será depositado en tu cuenta en 24-48 horas<br>
                • Recibirás recordatorios antes de cada vencimiento<br>
                • Tu primer pago vence según el plan establecido
            </div>
        ";
        
        $boton_url = $this->base_url . "/dashboard_clientes.php";
        
        return $this->enviar(
            $email,
            "¡Préstamo Aprobado! - Solicitud #{$prestamo_id}",
            $this->template($titulo, $contenido, "Ver Plan de Pagos", $boton_url)
        );
    }
    
    public function notificarPrestamoRechazado($cliente_id, $prestamo_id, $motivo) {
        $email = $this->getClienteEmail($cliente_id);
        if (!$email) return false;
        
        $titulo = "Información sobre tu Solicitud de Préstamo";
        $contenido = "
            <p>Lamentamos informarte que tu solicitud de préstamo #{$prestamo_id} no ha podido ser aprobada en este momento.</p>
            
            <div class='alert alert-warning'>
                <strong>Motivo:</strong><br>
                {$motivo}
            </div>
            
            <p>No te desanimes, puedes:</p>
            <ul>
                <li>Mejorar tu perfil crediticio</li>
                <li>Actualizar tu información financiera</li>
                <li>Intentar con un monto menor</li>
                <li>Contactar con nuestro equipo para más información</li>
            </ul>
        ";
        
        return $this->enviar(
            $email,
            "Actualización sobre tu Solicitud #{$prestamo_id}",
            $this->template($titulo, $contenido)
        );
    }
    
    // ===== RECORDATORIOS DE VENCIMIENTO =====
    
    public function recordatorioProximoVencimiento($cliente_id, $prestamo_id, $cuota_num, $monto, $fecha_vencimiento) {
        $email = $this->getClienteEmail($cliente_id);
        if (!$email) return false;
        
        $fecha_formateada = date('d/m/Y', strtotime($fecha_vencimiento));
        
        $titulo = "⏰ Recordatorio: Próximo Vencimiento";
        $contenido = "
            <p>Te recordamos que en <strong>3 días</strong> vence una cuota de tu préstamo.</p>
            
            <div class='detail-box'>
                <div class='detail-item'>
                    <div class='detail-label'>Cuota #</div>
                    <div class='detail-value'>{$cuota_num}</div>
                </div>
                <div class='detail-item'>
                    <div class='detail-label'>Monto a Pagar</div>
                    <div class='detail-value'>$" . number_format($monto, 2, ',', '.') . "</div>
                </div>
                <div class='detail-item'>
                    <div class='detail-label'>Fecha de Vencimiento</div>
                    <div class='detail-value'>{$fecha_formateada}</div>
                </div>
            </div>
            
            <div class='alert alert-info'>
                <strong>💡 Tip:</strong> Programa tu pago con anticipación para evitar recargos por mora.
            </div>
        ";
        
        $boton_url = $this->base_url . "/dashboard_clientes.php";
        
        return $this->enviar(
            $email,
            "Recordatorio: Cuota #{$cuota_num} vence en 3 días",
            $this->template($titulo, $contenido, "Ver Mis Cuotas", $boton_url)
        );
    }
    
    public function recordatorioVenceHoy($cliente_id, $prestamo_id, $cuota_num, $monto, $fecha_vencimiento) {
        $email = $this->getClienteEmail($cliente_id);
        if (!$email) return false;
        
        $titulo = "🔔 ¡Cuota Vence HOY!";
        $contenido = "
            <p><strong>Importante:</strong> Una cuota de tu préstamo vence el día de HOY.</p>
            
            <div class='detail-box'>
                <div class='detail-item'>
                    <div class='detail-label'>Cuota #</div>
                    <div class='detail-value'>{$cuota_num}</div>
                </div>
                <div class='detail-item'>
                    <div class='detail-label'>Monto a Pagar</div>
                    <div class='detail-value'>$" . number_format($monto, 2, ',', '.') . "</div>
                </div>
            </div>
            
            <div class='alert alert-warning'>
                <strong>⚠️ Atención:</strong> Para evitar recargos por mora, realiza tu pago antes de las 23:59 hs.
            </div>
        ";
        
        $boton_url = $this->base_url . "/dashboard_clientes.php";
        
        return $this->enviar(
            $email,
            "¡URGENTE! Cuota #{$cuota_num} vence HOY",
            $this->template($titulo, $contenido, "Pagar Ahora", $boton_url)
        );
    }
    
    public function notificarCuotaVencida($cliente_id, $prestamo_id, $cuota_num, $monto, $dias_mora, $monto_mora) {
        $email = $this->getClienteEmail($cliente_id);
        if (!$email) return false;
        
        $monto_total = $monto + $monto_mora;
        
        $titulo = "⚠️ Cuota Vencida - Acción Requerida";
        $contenido = "
            <p>Tu cuota #{$cuota_num} del préstamo #{$prestamo_id} está <strong>VENCIDA</strong>.</p>
            
            <div class='detail-box'>
                <div class='detail-item'>
                    <div class='detail-label'>Monto Original</div>
                    <div class='detail-value'>$" . number_format($monto, 2, ',', '.') . "</div>
                </div>
                <div class='detail-item'>
                    <div class='detail-label'>Recargo por Mora ({$dias_mora} días)</div>
                    <div class='detail-value' style='color: #dc2626;'>+$" . number_format($monto_mora, 2, ',', '.') . "</div>
                </div>
                <div class='detail-item' style='border-top: 2px solid #e5e7eb; padding-top: 10px; margin-top: 10px;'>
                    <div class='detail-label'>TOTAL A PAGAR</div>
                    <div class='detail-value' style='font-size: 24px;'>$" . number_format($monto_total, 2, ',', '.') . "</div>
                </div>
            </div>
            
            <div class='alert alert-danger'>
                <strong>⚠️ Importante:</strong><br>
                • Cada día que pase se acumulan más cargos por mora<br>
                • Regulariza tu situación lo antes posible<br>
                • Contacta con nosotros si tienes dificultades para pagar
            </div>
        ";
        
        $boton_url = $this->base_url . "/dashboard_clientes.php";
        
        return $this->enviar(
            $email,
            "Cuota Vencida - Préstamo #{$prestamo_id}",
            $this->template($titulo, $contenido, "Regularizar Ahora", $boton_url)
        );
    }
    
    // ===== NOTIFICACIÓN DE PAGO =====
    
    public function notificarPagoRecibido($cliente_id, $prestamo_id, $cuota_num, $monto) {
        $email = $this->getClienteEmail($cliente_id);
        if (!$email) return false;
        
        $titulo = "✅ Pago Recibido";
        $contenido = "
            <p>Hemos recibido tu pago correspondiente a la cuota #{$cuota_num} de tu préstamo #{$prestamo_id}.</p>
            
            <div class='detail-box'>
                <div class='detail-item'>
                    <div class='detail-label'>Monto Pagado</div>
                    <div class='detail-value'>$" . number_format($monto, 2, ',', '.') . "</div>
                </div>
                <div class='detail-item'>
                    <div class='detail-label'>Fecha de Pago</div>
                    <div class='detail-value'>" . date('d/m/Y H:i') . "</div>
                </div>
            </div>
            
            <div class='alert alert-success'>
                <strong>🎉 ¡Gracias por tu pago puntual!</strong><br>
                Tu comprobante está disponible en tu panel de cliente.
            </div>
        ";
        
        $boton_url = $this->base_url . "/dashboard_clientes.php";
        
        return $this->enviar(
            $email,
            "Pago Recibido - Cuota #{$cuota_num}",
            $this->template($titulo, $contenido, "Ver Mi Panel", $boton_url)
        );
    }
}
