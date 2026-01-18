<?php
require_once __DIR__ . '/../EmailDispatcher.php';

function enviarMailDocsAprobados(array $cliente): void {

    $mailer = new EmailDispatcher();

    $mailer->send(
        'docs_aprobados',
        $cliente['email'],
        [
            'nombre' => $cliente['nombre']
        ]
    );
}
