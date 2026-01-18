<?php
/**
 * docs_rechazados.php
 */

require_once __DIR__ . '/../EmailDispatcher.php';

function enviarMailDocsRechazados(array $cliente, string $motivo): void {
    
    if (empty($cliente['email'])) {
        throw new Exception("Email del cliente es requerido");
    }
    
    $mailer = new EmailDispatcher();
    
    $mailer->send(
        'docs_rechazados',
        $cliente['email'],
        [
            'nombre' => $cliente['nombre'] ?? $cliente['nombre_completo'] ?? 'Cliente',
            'mensaje' => $motivo
        ]
    );
}
