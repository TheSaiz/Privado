<?php
/**
 * TemplateEngine.php
 */

class TemplateEngine {

    /**
     * Renderizar contenido reemplazando placeholders
     * 
     * @param string $content Contenido con placeholders {{VARIABLE}}
     * @param array $data Array con las variables a reemplazar ['variable' => 'valor']
     * @return string Contenido renderizado
     */
    public static function render(string $content, array $data): string {
        foreach ($data as $key => $value) {
            $placeholder = '{{' . strtoupper($key) . '}}';
            $content = str_replace($placeholder, (string)$value, $content);
        }
        return $content;
    }
}
