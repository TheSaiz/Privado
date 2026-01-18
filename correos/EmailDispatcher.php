<?php
/**
 * EmailDispatcher.php
 */

require_once __DIR__ . '/TemplateEngine.php';
require_once __DIR__ . '/ExternalProvider.php';

class EmailDispatcher {

    private array $config;
    private array $templates;
    private ExternalProvider $provider;

    public function __construct() {
        $this->loadConfig();
        $this->loadTemplates();
        $this->provider = new ExternalProvider($this->config['smtp'] ?? []);
    }

    /**
     * Cargar configuración desde config.json
     */
    private function loadConfig(): void {
        $configFile = __DIR__ . '/../config.json';
        
        if (!file_exists($configFile)) {
            $this->log("ERROR: config.json no encontrado en: $configFile");
            throw new Exception("config.json no encontrado");
        }

        $this->config = json_decode(file_get_contents($configFile), true);

        if (!is_array($this->config)) {
            $this->log("ERROR: config.json inválido");
            throw new Exception("config.json inválido");
        }

        if (!isset($this->config['smtp'])) {
            $this->log("ERROR: Configuración SMTP no encontrada en config.json");
            throw new Exception("Configuración SMTP no encontrada");
        }

        $this->log("✓ Configuración cargada correctamente");
    }

    /**
     * Cargar plantillas desde templates.json
     */
    private function loadTemplates(): void {
        $templatesFile = __DIR__ . '/../templates.json';
        
        if (!file_exists($templatesFile)) {
            $this->log("ERROR: templates.json no encontrado en: $templatesFile");
            throw new Exception("templates.json no encontrado");
        }

        $this->templates = json_decode(file_get_contents($templatesFile), true);

        if (!is_array($this->templates)) {
            $this->log("ERROR: templates.json inválido");
            throw new Exception("templates.json inválido");
        }

        $this->log("✓ Plantillas cargadas correctamente (" . count($this->templates) . " plantillas)");
    }

    /**
     * Enviar email usando una plantilla
     * 
     * @param string $templateKey Nombre de la plantilla (ej: 'docs_aprobados')
     * @param string $toEmail Email del destinatario
     * @param array $data Variables para reemplazar (ej: ['nombre' => 'Juan'])
     * @return bool True si se envió correctamente
     */
    public function send(
        string $templateKey,
        string $toEmail,
        array $data = []
    ): bool {

        // Validar email
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $this->log("ERROR: Email inválido: $toEmail");
            return false;
        }

        // Validar que existe la plantilla
        if (!isset($this->templates[$templateKey])) {
            $this->log("ERROR: Plantilla inexistente: $templateKey");
            return false;
        }

        $tpl = $this->templates[$templateKey];

        // Validar que la plantilla tiene subject y body
        if (!isset($tpl['subject']) || !isset($tpl['body'])) {
            $this->log("ERROR: Plantilla '$templateKey' incompleta (falta subject o body)");
            return false;
        }

        try {
            // Renderizar contenido
            $subject = TemplateEngine::render($tpl['subject'], $data);
            $body = TemplateEngine::render($tpl['body'], $data);

            $this->log("→ Intentando enviar email a $toEmail con plantilla '$templateKey'");

            // Enviar email
            $result = $this->provider->send($toEmail, $subject, $body);

            if ($result) {
                $this->log("✓ Email enviado exitosamente a $toEmail");
            } else {
                $this->log("✗ Falló el envío a $toEmail");
            }

            return $result;

        } catch (Throwable $e) {
            $this->log("✗ EXCEPCIÓN al enviar email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Registrar en log
     */
    private function log(string $msg): void {
        $logDir = __DIR__ . '/logs';
        
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $file = $logDir . '/emails.log';
        $ts = date('Y-m-d H:i:s');
        @file_put_contents($file, "[$ts] $msg\n", FILE_APPEND);
    }
}
