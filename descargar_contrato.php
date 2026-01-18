<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor', 'cliente'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/connection.php';

$contrato_id = (int)($_GET['id'] ?? 0);
$usuario_id = $_SESSION['usuario_id'];
$usuario_rol = $_SESSION['usuario_rol'];

if ($contrato_id <= 0) {
    die('ID de contrato inválido');
}

try {
    // Obtener información del contrato
    $stmt = $pdo->prepare("
        SELECT 
            pc.*,
            u.nombre as cliente_nombre,
            u.apellido as cliente_apellido,
            cd.nombre_completo as cliente_nombre_completo,
            CASE 
                WHEN pc.tipo_operacion = 'prestamo' THEN 'Préstamo Personal'
                WHEN pc.tipo_operacion = 'empeno' THEN 'Empeño'
                WHEN pc.tipo_operacion = 'prendario' THEN 'Crédito Prendario'
                ELSE 'Otro'
            END as tipo_nombre
        FROM prestamos_contratos pc
        INNER JOIN usuarios u ON pc.cliente_id = u.id
        LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
        WHERE pc.id = ?
        LIMIT 1
    ");
    $stmt->execute([$contrato_id]);
    $contrato = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contrato) {
        die('Contrato no encontrado');
    }
    
    // Si es cliente, verificar que sea su propio contrato
    if ($usuario_rol === 'cliente' && $contrato['cliente_id'] != $usuario_id) {
        die('No tienes permiso para descargar este contrato');
    }
    
    $nombre_cliente = $contrato['cliente_nombre_completo'] ?: ($contrato['cliente_nombre'] . ' ' . $contrato['cliente_apellido']);
    
    // Registrar descarga
    try {
        $stmt = $pdo->prepare("
            INSERT INTO contratos_historial (contrato_id, cliente_id, accion, ip_address, user_agent)
            VALUES (?, ?, 'descarga_pdf', ?, ?)
        ");
        $stmt->execute([
            $contrato_id,
            $contrato['cliente_id'],
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log("Error registrando descarga: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    die('Error al cargar contrato: ' . $e->getMessage());
}

// =====================================================
// GENERAR PDF CON HTML (sin librerías externas)
// El navegador convertirá esto a PDF con Ctrl+P
// =====================================================

$filename = 'Contrato_' . $contrato['numero_contrato'] . '_' . date('Ymd') . '.pdf';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($filename); ?></title>
    <style>
        @page {
            margin: 2cm;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        
        h1 {
            text-align: center;
            color: #1f2937;
            font-size: 20pt;
            margin-bottom: 10px;
            border-bottom: 3px solid #7c5cff;
            padding-bottom: 10px;
        }
        
        h2 {
            color: #1f2937;
            font-size: 16pt;
            margin-top: 25px;
            margin-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 8px;
        }
        
        h3 {
            color: #4b5563;
            font-size: 13pt;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        
        p {
            text-align: justify;
            margin-bottom: 10px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        table td {
            padding: 8px;
            border: 1px solid #e5e7eb;
        }
        
        table td:first-child {
            background: #f9fafb;
            font-weight: bold;
            width: 35%;
        }
        
        .header-info {
            text-align: center;
            margin-bottom: 30px;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }
        
        .numero-contrato {
            font-size: 14pt;
            font-weight: bold;
            color: #7c5cff;
            margin: 10px 0;
        }
        
        .firma-section {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
        }
        
        .firma-img {
            max-height: 100px;
            border: 2px solid #d1d5db;
            padding: 10px;
            background: white;
        }
        
        ul {
            margin-left: 20px;
            line-height: 2;
        }
        
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 9pt;
            color: #6b7280;
            border-top: 1px solid #e5e7eb;
            padding-top: 15px;
            page-break-inside: avoid;
        }
        
        .no-print {
            position: fixed;
            top: 10px;
            right: 10px;
            background: #7c5cff;
            color: white;
            padding: 15px 30px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            cursor: pointer;
            z-index: 9999;
            font-size: 14pt;
            font-weight: bold;
        }
        
        .no-print:hover {
            background: #6b4fd9;
        }
        
        @media print {
            .no-print {
                display: none;
            }
            
            body {
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>

<button class="no-print" onclick="window.print()">🖨️ IMPRIMIR / GUARDAR PDF</button>

<div class="header-info">
    <h1><?php echo htmlspecialchars($contrato['tipo_nombre']); ?></h1>
    <p class="numero-contrato">Contrato N° <?php echo htmlspecialchars($contrato['numero_contrato']); ?></p>
    <p><strong>Cliente:</strong> <?php echo htmlspecialchars($nombre_cliente); ?></p>
    <p><strong>Fecha de emisión:</strong> <?php echo date('d/m/Y', strtotime($contrato['created_at'])); ?></p>
    <?php if ($contrato['fecha_firma']): ?>
    <p><strong>Fecha de firma:</strong> <?php echo date('d/m/Y H:i', strtotime($contrato['fecha_firma'])); ?></p>
    <?php endif; ?>
</div>

<?php echo $contrato['contenido_contrato']; ?>

<?php if ($contrato['estado'] === 'firmado' && $contrato['firma_cliente']): ?>
<div class="firma-section">
    <h2>Firma Digital del Cliente</h2>
    <table>
        <tr>
            <td>Fecha y hora de firma:</td>
            <td><?php echo date('d/m/Y H:i:s', strtotime($contrato['fecha_firma'])); ?></td>
        </tr>
        <tr>
            <td>Dirección IP:</td>
            <td><?php echo htmlspecialchars($contrato['ip_firma'] ?? 'No registrada'); ?></td>
        </tr>
        <tr>
            <td>Estado:</td>
            <td><strong style="color: #059669;">✓ Contrato Firmado Electrónicamente</strong></td>
        </tr>
    </table>
    <div style="margin-top: 20px;">
        <img src="<?php echo htmlspecialchars($contrato['firma_cliente']); ?>" alt="Firma" class="firma-img">
    </div>
</div>
<?php endif; ?>

<div class="footer">
    <p>Este documento fue generado electrónicamente el <?php echo date('d/m/Y H:i:s'); ?></p>
    <p>PRÉSTAMO LÍDER S.A. - Todos los derechos reservados</p>
</div>

<script>
// Auto-abrir diálogo de impresión después de 500ms
setTimeout(function() {
    window.print();
}, 500);

// Cerrar ventana después de imprimir o cancelar
window.onafterprint = function() {
    window.close();
};
</script>

</body>
</html>