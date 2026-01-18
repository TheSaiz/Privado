<?php
require_once __DIR__ . '/../EmailDispatcher.php';

function enviarMailDocsRechazados(array $cliente, string $motivo): void {

    $mailer = new EmailDispatcher();

    $mailer->send(
        'docs_rechazados',
        $cliente['email'],
        [
            'nombre'  => $cliente['nombre'],
            'mensaje' => $motivo
        ]
    );
}
