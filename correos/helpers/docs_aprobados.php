<?php
/**
 * docs_aprobados.php
 */

require_once __DIR__ . '/../EmailDispatcher.php';

function enviarMailDocsAprobados(array $cliente): void {
    
    if (empty($cliente['email'])) {
        throw new Exception("Email del cliente es requerido");
    }
    
    $mailer = new EmailDispatcher();
    
    $mailer->send(
        'docs_aprobados',
        $cliente['email'],
        [
            'nombre' => $cliente['nombre'] ?? $cliente['nombre_completo'] ?? 'Cliente'
        ]
    );
}
