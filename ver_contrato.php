<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor', 'cliente'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/backend/connection.php';

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
            END as tipo_nombre,
            CASE pc.tipo_operacion
                WHEN 'prestamo' THEN COALESCE(p.monto_ofrecido, p.monto_solicitado, p.monto)
                WHEN 'empeno' THEN e.monto_final
                WHEN 'prendario' THEN cp.monto_final
            END as monto_operacion
        FROM prestamos_contratos pc
        INNER JOIN usuarios u ON pc.cliente_id = u.id
        LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
        LEFT JOIN prestamos p ON pc.prestamo_id = p.id AND pc.tipo_operacion = 'prestamo'
        LEFT JOIN empenos e ON pc.prestamo_id = e.id AND pc.tipo_operacion = 'empeno'
        LEFT JOIN creditos_prendarios cp ON pc.prestamo_id = cp.id AND pc.tipo_operacion = 'prendario'
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
        die('No tienes permiso para ver este contrato');
    }
    
    $nombre_cliente = $contrato['cliente_nombre_completo'] ?: ($contrato['cliente_nombre'] . ' ' . $contrato['cliente_apellido']);
    
} catch (Exception $e) {
    die('Error al cargar contrato: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Contrato <?php echo htmlspecialchars($contrato['numero_contrato']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .container { max-width: none; margin: 0; padding: 20px; }
        }
        
        .contrato-contenido {
            background: white;
            padding: 40px;
            line-height: 1.8;
        }
        
        .contrato-contenido h1 {
            text-align: center;
            color: #1f2937;
            margin-bottom: 30px;
        }
        
        .contrato-contenido h2, .contrato-contenido h3 {
            color: #1f2937;
            margin-top: 25px;
            margin-bottom: 15px;
        }
        
        .contrato-contenido p {
            text-align: justify;
            margin-bottom: 15px;
        }
        
        .contrato-contenido table {
            width: 100%;
            margin: 20px 0;
            border-collapse: collapse;
        }
        
        .contrato-contenido table td {
            padding: 10px;
            border: 1px solid #e5e7eb;
        }
    </style>
</head>
<body class="bg-gray-50">

<?php if ($usuario_rol !== 'cliente'): ?>
    <?php include ($usuario_rol === 'asesor' ? 'sidebar_asesores.php' : 'sidebar.php'); ?>
<?php endif; ?>

<div class="<?php echo $usuario_rol !== 'cliente' ? 'ml-64' : ''; ?>">
    
    <!-- Header (no imprimir) -->
    <div class="no-print bg-white shadow-lg sticky top-0 z-40">
        <div class="max-w-5xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                        <span class="material-icons-outlined text-blue-600">description</span>
                        Contrato <?php echo htmlspecialchars($contrato['numero_contrato']); ?>
                    </h1>
                    <p class="text-gray-600 mt-1">
                        <?php echo htmlspecialchars($contrato['tipo_nombre']); ?> - 
                        Cliente: <?php echo htmlspecialchars($nombre_cliente); ?>
                    </p>
                </div>
                
                <div class="flex gap-3">
                    <button onclick="window.print()" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center gap-2 transition duration-200">
                        <span class="material-icons-outlined">print</span>
                        Imprimir
                    </button>
                    
                    <?php if ($contrato['estado'] === 'firmado'): ?>
                    <a href="descargar_contrato.php?id=<?php echo $contrato_id; ?>" 
                       class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center gap-2 transition duration-200">
                        <span class="material-icons-outlined">download</span>
                        Descargar PDF
                    </a>
                    <?php endif; ?>
                    
                    <button onclick="window.close()" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 flex items-center gap-2 transition duration-200">
                        <span class="material-icons-outlined">close</span>
                        Cerrar
                    </button>
                </div>
            </div>
            
            <!-- Info adicional -->
            <div class="mt-4 pt-4 border-t grid grid-cols-4 gap-4 text-sm">
                <div>
                    <p class="text-xs text-gray-500">Estado</p>
                    <p class="font-semibold">
                        <?php 
                        echo match($contrato['estado']) {
                            'pendiente' => '⏳ Pendiente Firma',
                            'firmado' => '✅ Firmado',
                            'vencido' => '⚠️ Vencido',
                            'rechazado' => '❌ Rechazado',
                            default => ucfirst($contrato['estado'])
                        };
                        ?>
                    </p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Fecha Creación</p>
                    <p class="font-semibold text-gray-800"><?php echo date('d/m/Y H:i', strtotime($contrato['created_at'])); ?></p>
                </div>
                <?php if ($contrato['fecha_firma']): ?>
                <div>
                    <p class="text-xs text-gray-500">Fecha Firma</p>
                    <p class="font-semibold text-green-600"><?php echo date('d/m/Y H:i', strtotime($contrato['fecha_firma'])); ?></p>
                </div>
                <?php endif; ?>
                <?php if ($contrato['fecha_vencimiento']): ?>
                <div>
                    <p class="text-xs text-gray-500">Vencimiento</p>
                    <p class="font-semibold text-gray-800"><?php echo date('d/m/Y', strtotime($contrato['fecha_vencimiento'])); ?></p>
                </div>
                <?php endif; ?>
                <?php if ($contrato['monto_operacion']): ?>
                <div>
                    <p class="text-xs text-gray-500">Monto</p>
                    <p class="font-semibold text-green-600">$<?php echo number_format($contrato['monto_operacion'], 0, ',', '.'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Contenido del contrato -->
    <div class="max-w-5xl mx-auto px-6 py-8">
        <div class="contrato-contenido bg-white rounded-xl shadow-lg">
            <?php echo $contrato['contenido_contrato']; ?>
            
            <?php if ($contrato['estado'] === 'firmado' && $contrato['firma_cliente']): ?>
            <div style="margin-top: 60px; padding-top: 30px; border-top: 2px solid #e5e7eb;">
                <h2 style="color: #1f2937; font-size: 1.5rem; margin-bottom: 20px;">
                    Firma Digital del Cliente
                </h2>
                
                <div style="display: flex; gap: 30px; align-items: flex-start; flex-wrap: wrap;">
                    <div style="flex-shrink: 0;">
                        <img src="<?php echo htmlspecialchars($contrato['firma_cliente']); ?>" 
                             alt="Firma" 
                             style="border: 2px solid #d1d5db; border-radius: 8px; padding: 10px; background: white; max-height: 150px;">
                    </div>
                    <div style="flex: 1; min-width: 250px;">
                        <table style="width: 100%; border: none;">
                            <tr>
                                <td style="border: none; padding: 8px 0; color: #6b7280; font-weight: 600;">Firmado el:</td>
                                <td style="border: none; padding: 8px 0;"><?php echo date('d/m/Y H:i:s', strtotime($contrato['fecha_firma'])); ?></td>
                            </tr>
                            <tr>
                                <td style="border: none; padding: 8px 0; color: #6b7280; font-weight: 600;">Dirección IP:</td>
                                <td style="border: none; padding: 8px 0;"><?php echo htmlspecialchars($contrato['ip_firma'] ?? 'No registrada'); ?></td>
                            </tr>
                            <tr>
                                <td style="border: none; padding: 8px 0; color: #6b7280; font-weight: 600;">Dispositivo:</td>
                                <td style="border: none; padding: 8px 0; font-size: 0.85rem;"><?php echo htmlspecialchars(substr($contrato['user_agent_firma'] ?? 'No registrado', 0, 60)); ?></td>
                            </tr>
                        </table>
                        <div style="margin-top: 15px; padding: 12px; background: #d1fae5; border-left: 4px solid #059669; border-radius: 6px;">
                            <p style="color: #065f46; font-weight: 600; margin: 0;">
                                ✓ Firma electrónica válida
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Footer info (no imprimir) -->
        <div class="no-print mt-6 text-center text-gray-600 text-sm">
            <p>Este documento fue generado electrónicamente</p>
            <?php if ($contrato['estado'] === 'firmado'): ?>
            <p class="mt-2 text-green-600 font-semibold">
                ✓ Contrato firmado digitalmente el <?php echo date('d/m/Y H:i', strtotime($contrato['fecha_firma'])); ?>
            </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Cerrar con tecla ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        window.close();
    }
});

// Confirmación antes de imprimir
window.onbeforeprint = function() {
    console.log('Imprimiendo contrato...');
};
</script>

</body>
</html>