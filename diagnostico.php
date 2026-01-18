<?php
// Script de diagnóstico para verificar solicitudes de información

session_start();

if (!isset($_SESSION['cliente_id'])) {
    die('No hay sesión activa');
}

$cliente_id = (int)$_SESSION['cliente_id'];
$prestamo_id = (int)($_GET['id'] ?? 4);
$tipo_operacion = strtolower($_GET['tipo'] ?? 'empeno');

require_once __DIR__ . '/backend/connection.php';

echo "<h2>🔍 Diagnóstico de Solicitudes de Información</h2>";
echo "<hr>";

echo "<h3>Parámetros:</h3>";
echo "<ul>";
echo "<li><strong>Cliente ID:</strong> {$cliente_id}</li>";
echo "<li><strong>Operación ID:</strong> {$prestamo_id}</li>";
echo "<li><strong>Tipo Operación:</strong> {$tipo_operacion}</li>";
echo "</ul>";
echo "<hr>";

// Verificar si existe la operación
echo "<h3>1. Verificando la operación...</h3>";
$tabla = match($tipo_operacion) {
    'prestamo' => 'prestamos',
    'empeno' => 'empenos',
    'prendario' => 'creditos_prendarios',
    default => null
};

if ($tabla) {
    $stmt = $pdo->prepare("SELECT id, cliente_id, estado FROM {$tabla} WHERE id = ? LIMIT 1");
    $stmt->execute([$prestamo_id]);
    $operacion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($operacion) {
        echo "✅ Operación encontrada:<br>";
        echo "<pre>" . print_r($operacion, true) . "</pre>";
    } else {
        echo "❌ Operación NO encontrada<br>";
    }
} else {
    echo "❌ Tipo de operación inválido<br>";
}

echo "<hr>";

// Verificar solicitudes de información
echo "<h3>2. Buscando solicitudes de información...</h3>";
try {
    $stmt = $pdo->prepare("
        SELECT 
            si.*,
            (SELECT COUNT(*) FROM solicitudes_info_archivos WHERE solicitud_id = si.id) as num_archivos
        FROM solicitudes_info si
        WHERE si.cliente_id = ? 
        AND si.operacion_id = ? 
        AND si.tipo_operacion = ?
        ORDER BY si.fecha DESC
    ");
    $stmt->execute([$cliente_id, $prestamo_id, $tipo_operacion]);
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($solicitudes)) {
        echo "❌ <strong>NO se encontraron solicitudes de información para esta operación</strong><br>";
        echo "<p style='color: orange;'>Esto significa que el asesor NO creó la solicitud correctamente desde gestionar_operaciones.php</p>";
    } else {
        echo "✅ Se encontraron " . count($solicitudes) . " solicitud(es):<br><br>";
        foreach ($solicitudes as $sol) {
            echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0; background: #f9f9f9;'>";
            echo "<strong>ID:</strong> {$sol['id']}<br>";
            echo "<strong>Mensaje:</strong> {$sol['mensaje']}<br>";
            echo "<strong>Fecha:</strong> {$sol['fecha']}<br>";
            echo "<strong>Respondida:</strong> " . ($sol['respondida'] ? 'SÍ' : 'NO') . "<br>";
            echo "<strong>Archivos adjuntos:</strong> {$sol['num_archivos']}<br>";
            if ($sol['respondida']) {
                echo "<strong>Respuesta cliente:</strong> {$sol['respuesta_cliente']}<br>";
                echo "<strong>Fecha respuesta:</strong> {$sol['fecha_respuesta']}<br>";
            }
            echo "</div>";
        }
    }
} catch (Exception $e) {
    echo "❌ Error al buscar: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// Verificar tabla solicitudes_info existe
echo "<h3>3. Verificando estructura de tablas...</h3>";
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'solicitudes_info'");
    if ($stmt->rowCount() > 0) {
        echo "✅ Tabla 'solicitudes_info' existe<br>";
        
        $stmt = $pdo->query("DESCRIBE solicitudes_info");
        $columnas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<strong>Columnas:</strong><br>";
        echo "<pre>" . print_r($columnas, true) . "</pre>";
    } else {
        echo "❌ Tabla 'solicitudes_info' NO existe - DEBES EJECUTAR EL SQL<br>";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<p><a href='detalle_prestamo.php?id={$prestamo_id}&tipo={$tipo_operacion}'>← Volver al detalle</a></p>";
?>