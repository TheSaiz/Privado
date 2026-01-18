<?php
// Registrar tiempo de salida del usuario
header('Content-Type: application/json');

$metricsFile = 'metrics.json';

// Leer datos del POST
$data = json_decode(file_get_contents('php://input'), true);

if ($data && isset($data['session_id']) && isset($data['duration'])) {
    // Cargar métricas existentes
    $metrics = [];
    if (file_exists($metricsFile)) {
        $metrics = json_decode(file_get_contents($metricsFile), true) ?: [];
    }
    
    // Actualizar la sesión con la duración
    foreach ($metrics as &$metric) {
        if ($metric['session_id'] === $data['session_id']) {
            $metric['duration'] = $data['duration'];
            $metric['exit_time'] = time();
            break;
        }
    }
    
    // Guardar métricas actualizadas
    file_put_contents($metricsFile, json_encode($metrics));
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
?>