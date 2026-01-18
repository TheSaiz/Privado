<?php
session_start();

require_once 'backend/connection.php';

// Marcar como no disponible
if (isset($_SESSION['asesor_id'])) {
    $stmt = $pdo->prepare("
        UPDATE asesores_departamentos 
        SET disponible = 0 
        WHERE asesor_id = ?
    ");
    $stmt->execute([$_SESSION['asesor_id']]);
}

// Destruir sesión
session_destroy();

header('Location: index.php');
exit;
?>