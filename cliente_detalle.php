<?php
session_start();

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/connection.php';

$cliente_id = (int)($_GET['id'] ?? 0);

if ($cliente_id <= 0) {
    header("Location: clientes.php");
    exit;
}

// =====================================================
// OBTENER TODA LA INFORMACIÓN DEL CLIENTE
// =====================================================
try {
    // INFO COMPLETA DEL CLIENTE - TODOS LOS CAMPOS
    $stmt = $pdo->prepare("
        SELECT 
            u.*,
            cd.*
        FROM usuarios u
        LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$cliente_id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cliente) {
        die('Cliente no encontrado');
    }
    
    $nombre_cliente = $cliente['nombre_completo'] ?: $cliente['nombre'] ?: 'Cliente';
    $es_registrado = !empty($cliente['nombre_completo']);
    
    // PRÉSTAMOS DEL CLIENTE - QUERIES SEPARADAS
    $prestamos = [];
    
    // 1. Préstamos normales
    $stmt = $pdo->prepare("
        SELECT 
            id, estado, monto_solicitado, monto_ofrecido, monto,
            cuotas, fecha_solicitud, fecha_aprobacion,
            requiere_legajo, legajo_completo, legajo_validado,
            'prestamo' as tipo, NULL as info_adicional
        FROM prestamos
        WHERE cliente_id = ?
    ");
    $stmt->execute([$cliente_id]);
    $prestamos = array_merge($prestamos, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // 2. Créditos prendarios
    $stmt = $pdo->prepare("
        SELECT 
            id, estado, monto_solicitado, monto_ofrecido, monto_final as monto,
            cuotas_final as cuotas, fecha_solicitud, fecha_aprobacion,
            0 as requiere_legajo, 0 as legajo_completo, 0 as legajo_validado,
            'prendario' as tipo, dominio as info_adicional
        FROM creditos_prendarios
        WHERE cliente_id = ?
    ");
    $stmt->execute([$cliente_id]);
    $prestamos = array_merge($prestamos, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // 3. Empeños
    $stmt = $pdo->prepare("
        SELECT 
            id, estado, monto_solicitado, monto_ofrecido, monto_final as monto,
            1 as cuotas, fecha_solicitud, fecha_aprobacion,
            0 as requiere_legajo, 0 as legajo_completo, 0 as legajo_validado,
            'empeno' as tipo, descripcion_producto as info_adicional
        FROM empenos
        WHERE cliente_id = ?
    ");
    $stmt->execute([$cliente_id]);
    $prestamos = array_merge($prestamos, $stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // Ordenar por fecha
    usort($prestamos, function($a, $b) {
        return strtotime($b['fecha_solicitud']) - strtotime($a['fecha_solicitud']);
    });
    
    // CONVERSACIONES DEL CHATBOT
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.fecha_inicio,
            c.fecha_cierre as fecha_fin,
            c.estado_solicitud,
            c.prioridad,
            c.score,
            c.estado as estado_chat,
            d.nombre as departamento_nombre,
            asesor.nombre as asesor_nombre,
            asesor.apellido as asesor_apellido,
            (SELECT COUNT(*) FROM mensajes WHERE chat_id = c.id) as total_mensajes
        FROM chats c
        LEFT JOIN departamentos d ON c.departamento_id = d.id
        LEFT JOIN usuarios asesor ON c.asesor_id = asesor.id
        WHERE c.cliente_id = ? AND c.origen = 'chatbot'
        ORDER BY c.fecha_inicio DESC
        LIMIT 10
    ");
    $stmt->execute([$cliente_id]);
    $conversaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // LEGAJOS - DOCUMENTOS AGRUPADOS POR TIPO
    $stmt = $pdo->prepare("
        SELECT 
            cdl.*,
            p.id as prestamo_id,
            u.nombre as validador_nombre
        FROM cliente_documentos_legajo cdl
        INNER JOIN prestamos p ON cdl.prestamo_id = p.id
        LEFT JOIN usuarios u ON cdl.validado_por = u.id
        WHERE p.cliente_id = ?
        ORDER BY cdl.tipo_documento, cdl.fecha_creacion DESC
    ");
    $stmt->execute([$cliente_id]);
    $docs_legajo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar por tipo (mostrar solo el más reciente)
    $legajo_agrupado = [];
    foreach ($docs_legajo as $doc) {
        $tipo = $doc['tipo_documento'];
        if (!isset($legajo_agrupado[$tipo])) {
            $legajo_agrupado[$tipo] = $doc;
        }
    }
    
    // CONTRATOS DEL CLIENTE
    $stmt = $pdo->prepare("
        SELECT 
            pc.*,
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
            END as monto_operacion,
            CASE pc.tipo_operacion
                WHEN 'prestamo' THEN p.estado
                WHEN 'empeno' THEN e.estado
                WHEN 'prendario' THEN cp.estado
            END as estado_operacion
        FROM prestamos_contratos pc
        LEFT JOIN prestamos p ON pc.prestamo_id = p.id AND pc.tipo_operacion = 'prestamo'
        LEFT JOIN empenos e ON pc.prestamo_id = e.id AND pc.tipo_operacion = 'empeno'
        LEFT JOIN creditos_prendarios cp ON pc.prestamo_id = cp.id AND pc.tipo_operacion = 'prendario'
        WHERE pc.cliente_id = ?
        ORDER BY pc.created_at DESC
    ");
    $stmt->execute([$cliente_id]);
    $contratos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // MENSAJES DE LA ÚLTIMA CONVERSACIÓN
    $mensajes = [];
    if (!empty($conversaciones)) {
        $ultimo_chat_id = $conversaciones[0]['id'];
        $stmt = $pdo->prepare("
            SELECT 
                id,
                chat_id,
                emisor,
                mensaje as contenido,
                fecha as fecha_envio
            FROM mensajes
            WHERE chat_id = ?
            ORDER BY fecha ASC
            LIMIT 50
        ");
        $stmt->execute([$ultimo_chat_id]);
        $mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // CALCULAR EDAD
    $edad = null;
    if ($cliente['fecha_nacimiento']) {
        $fecha_nac = new DateTime($cliente['fecha_nacimiento']);
        $hoy = new DateTime();
        $edad = $hoy->diff($fecha_nac)->y;
    }
    
} catch (Exception $e) {
    die('Error al cargar datos: ' . $e->getMessage());
}

$tipos_doc_legajo = [
    'dni_frente' => 'DNI - Frente',
    'dni_dorso' => 'DNI - Dorso',
    'selfie_dni' => 'Selfie con DNI',
    'recibo_sueldo' => 'Recibo de Sueldo',
    'boleta_servicio' => 'Boleta de Servicio',
    'cbu' => 'Constancia CBU',
    'movimientos_bancarios' => 'Movimientos Bancarios',
    'otro' => 'Otro'
];

$estado_civil_texto = [
    'soltero' => 'Soltero/a',
    'casado' => 'Casado/a',
    'divorciado' => 'Divorciado/a',
    'viudo' => 'Viudo/a',
    'union_libre' => 'Unión Libre'
];

$tipo_ingreso_texto = [
    'dependencia' => 'Relación de Dependencia',
    'autonomo' => 'Autónomo',
    'monotributo' => 'Monotributista',
    'jubilacion' => 'Jubilado/Pensionado',
    'negro' => 'Trabajo Informal'
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($nombre_cliente); ?> - Perfil Completo</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    
    <style>
        .tab-button {
            padding: 12px 24px;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }
        .tab-button.active {
            border-bottom-color: #3b82f6;
            background: rgba(59, 130, 246, 0.1);
            font-weight: 600;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .mensaje-bot { background: #f3f4f6; }
        .mensaje-cliente { background: #dbeafe; }
        
        .info-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 24px;
            margin-bottom: 16px;
        }
        
        .info-card-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .info-item {
            margin-bottom: 12px;
        }
        
        .info-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .info-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: #1f2937;
        }
        
        .info-value.empty {
            color: #9ca3af;
            font-style: italic;
            font-weight: 400;
        }
    </style>
</head>
<body class="bg-gray-50">

<?php include 'sidebar.php'; ?>

<div class="ml-64">
    <nav class="bg-white shadow-md sticky top-0 z-40">
        <div class="max-w-full mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <a href="clientes.php" class="text-gray-600 hover:text-gray-800">
                        <span class="material-icons-outlined text-3xl">arrow_back</span>
                    </a>
                    <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-2xl">
                        <?php echo strtoupper(substr($nombre_cliente, 0, 2)); ?>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($nombre_cliente); ?></h1>
                        <p class="text-sm text-gray-600">ID: <?php echo $cliente_id; ?> • Cliente <?php echo $es_registrado ? 'Registrado' : 'de Chatbot'; ?></p>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <?php if ($cliente['telefono']): ?>
                    <a href="https://wa.me/<?php echo $cliente['cod_area'] . $cliente['telefono']; ?>"
                       target="_blank"
                       class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center gap-2">
                        <span class="material-icons-outlined">whatsapp</span>
                        WhatsApp
                    </a>
                    <?php endif; ?>
                    
                    <a href="editar_datos_cliente.php?cliente_id=<?= $cliente_id ?>"
                       class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center gap-2">
                        <span class="material-icons-outlined">edit</span>
                        Editar
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-full mx-auto px-6 py-6">
        
        <!-- TABS -->
        <div class="bg-white rounded-xl shadow-lg mb-6">
            <div class="flex border-b overflow-x-auto">
                <button class="tab-button active" onclick="switchTab('general')">
                    <span class="material-icons-outlined align-middle mr-2">person</span>
                    Información General
                </button>
                <button class="tab-button" onclick="switchTab('familiar')">
                    <span class="material-icons-outlined align-middle mr-2">family_restroom</span>
                    Información Familiar
                </button>
                <button class="tab-button" onclick="switchTab('direccion')">
                    <span class="material-icons-outlined align-middle mr-2">home</span>
                    Dirección
                </button>
                <button class="tab-button" onclick="switchTab('laboral')">
                    <span class="material-icons-outlined align-middle mr-2">work</span>
                    Información Laboral
                </button>
                <button class="tab-button" onclick="switchTab('prestamos')">
                    <span class="material-icons-outlined align-middle mr-2">account_balance_wallet</span>
                    Préstamos (<?php echo count($prestamos); ?>)
                </button>
                <button class="tab-button" onclick="switchTab('contratos')">
                    <span class="material-icons-outlined align-middle mr-2">description</span>
                    Contratos (<?php echo count($contratos); ?>)
                </button>
                <button class="tab-button" onclick="switchTab('legajos')">
                    <span class="material-icons-outlined align-middle mr-2">folder</span>
                    Legajos
                </button>
                <button class="tab-button" onclick="switchTab('conversaciones')">
                    <span class="material-icons-outlined align-middle mr-2">chat</span>
                    Conversaciones
                </button>
            </div>
        </div>

        <!-- TAB: INFORMACIÓN GENERAL -->
        <div id="tab-general" class="tab-content active">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- Datos Personales Básicos -->
                <div class="info-card">
                    <h3 class="info-card-title">
                        <span class="material-icons-outlined text-blue-600">badge</span>
                        Datos Personales Básicos
                    </h3>
                    
                    <div class="info-item">
                        <p class="info-label">Nombre Completo</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['nombre_completo'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">DNI</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['dni'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">CUIL / CUIT</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['cuil_cuit'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Fecha de Nacimiento</p>
                        <p class="info-value">
                            <?php 
                            if ($cliente['fecha_nacimiento']) {
                                echo date('d/m/Y', strtotime($cliente['fecha_nacimiento']));
                                if ($edad) echo ' <span class="text-sm text-gray-600">(' . $edad . ' años)</span>';
                            } else {
                                echo '<span class="empty">No registrada</span>';
                            }
                            ?>
                        </p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Estado de Documentación</p>
                        <?php if ((int)$cliente['docs_completos'] === 1): ?>
                            <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">
                                ✓ Completa
                            </span>
                        <?php else: ?>
                            <span class="px-3 py-1 bg-red-100 text-red-700 rounded-full text-xs font-semibold">
                                ⏳ Incompleta
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Contacto -->
                <div class="info-card">
                    <h3 class="info-card-title">
                        <span class="material-icons-outlined text-green-600">contact_phone</span>
                        Contacto
                    </h3>
                    
                    <div class="info-item">
                        <p class="info-label">Email</p>
                        <p class="info-value break-all">
                            <?php 
                            $email = $cliente['email'];
                            echo htmlspecialchars($email ?: 'No registrado');
                            if ((int)$cliente['email_verificado'] === 1): ?>
                                <span class="text-green-600 ml-1" title="Verificado">✓</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Código de Área</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['cod_area'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Teléfono</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['telefono'] ?: 'No registrado'); ?></p>
                    </div>
                </div>

                <!-- Referencias -->
                <div class="info-card">
                    <h3 class="info-card-title">
                        <span class="material-icons-outlined text-purple-600">group</span>
                        Contactos de Referencia
                    </h3>
                    
                    <?php if ($cliente['contacto1_nombre']): ?>
                    <div class="p-3 bg-gray-50 rounded-lg mb-3">
                        <p class="text-xs text-gray-500 mb-1">Contacto 1</p>
                        <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($cliente['contacto1_nombre']); ?></p>
                        <?php if ($cliente['contacto1_relacion']): ?>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($cliente['contacto1_relacion']); ?></p>
                        <?php endif; ?>
                        <?php if ($cliente['contacto1_telefono']): ?>
                        <p class="text-sm text-gray-700 mt-1">📞 <?php echo htmlspecialchars($cliente['contacto1_telefono']); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($cliente['contacto2_nombre']): ?>
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <p class="text-xs text-gray-500 mb-1">Contacto 2</p>
                        <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($cliente['contacto2_nombre']); ?></p>
                        <?php if ($cliente['contacto2_relacion']): ?>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($cliente['contacto2_relacion']); ?></p>
                        <?php endif; ?>
                        <?php if ($cliente['contacto2_telefono']): ?>
                        <p class="text-sm text-gray-700 mt-1">📞 <?php echo htmlspecialchars($cliente['contacto2_telefono']); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!$cliente['contacto1_nombre'] && !$cliente['contacto2_nombre']): ?>
                    <p class="text-gray-500 text-center py-4">Sin referencias registradas</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TAB: INFORMACIÓN FAMILIAR -->
        <div id="tab-familiar" class="tab-content">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <div class="info-card">
                    <h3 class="info-card-title">
                        <span class="material-icons-outlined text-purple-600">family_restroom</span>
                        Información Familiar
                    </h3>
                    
                    <div class="info-item">
                        <p class="info-label">Estado Civil</p>
                        <p class="info-value">
                            <?php 
                            $estado_civil = $cliente['estado_civil'] ?? '';
                            echo $estado_civil ? htmlspecialchars($estado_civil_texto[$estado_civil] ?? ucfirst($estado_civil)) : '<span class="empty">No registrado</span>';
                            ?>
                        </p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Hijos a Cargo</p>
                        <p class="info-value"><?php echo (int)($cliente['hijos_a_cargo'] ?? 0); ?></p>
                    </div>
                </div>
                
                <?php if ($cliente['estado_civil'] === 'casado'): ?>
                <div class="info-card">
                    <h3 class="info-card-title">
                        <span class="material-icons-outlined text-pink-600">favorite</span>
                        Datos del Cónyuge
                    </h3>
                    
                    <div class="info-item">
                        <p class="info-label">DNI del Cónyuge</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['dni_conyuge'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Nombre del Cónyuge</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['nombre_conyuge'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <?php if (empty($cliente['dni_conyuge']) || empty($cliente['nombre_conyuge'])): ?>
                    <div class="mt-4 p-3 bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 text-sm">
                        <strong>⚠️ Atención:</strong> Cliente casado pero faltan datos del cónyuge
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: DIRECCIÓN -->
        <div id="tab-direccion" class="tab-content">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <div class="info-card">
                    <h3 class="info-card-title">
                        <span class="material-icons-outlined text-green-600">home</span>
                        Dirección Completa
                    </h3>
                    
                    <div class="info-item">
                        <p class="info-label">Calle y Número</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['direccion_calle'] ?: 'No registrada'); ?></p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div class="info-item">
                            <p class="info-label">Piso</p>
                            <p class="info-value"><?php echo htmlspecialchars($cliente['direccion_piso'] ?: '-'); ?></p>
                        </div>
                        
                        <div class="info-item">
                            <p class="info-label">Departamento</p>
                            <p class="info-value"><?php echo htmlspecialchars($cliente['direccion_departamento'] ?: '-'); ?></p>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Barrio</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['direccion_barrio'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Código Postal</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['direccion_codigo_postal'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Localidad</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['direccion_localidad'] ?: 'No registrada'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Provincia</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['direccion_provincia'] ?: 'No registrada'); ?></p>
                    </div>
                </div>
                
                <div class="info-card">
                    <h3 class="info-card-title">
                        <span class="material-icons-outlined text-blue-600">map</span>
                        Ubicación GPS
                    </h3>
                    
                    <?php if (!empty($cliente['direccion_latitud']) && !empty($cliente['direccion_longitud'])): ?>
                    <div class="info-item">
                        <p class="info-label">Latitud</p>
                        <p class="info-value"><?php echo number_format($cliente['direccion_latitud'], 6); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Longitud</p>
                        <p class="info-value"><?php echo number_format($cliente['direccion_longitud'], 6); ?></p>
                    </div>
                    
                    <div class="mt-4">
                        <a href="https://www.google.com/maps?q=<?= $cliente['direccion_latitud'] ?>,<?= $cliente['direccion_longitud'] ?>" 
                           target="_blank"
                           class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <span class="material-icons-outlined">map</span>
                            Ver en Google Maps
                        </a>
                    </div>
                    <?php else: ?>
                    <p class="text-gray-500 text-center py-8">No se registró ubicación GPS</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TAB: INFORMACIÓN LABORAL -->
        <div id="tab-laboral" class="tab-content">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <div class="info-card">
                    <h3 class="info-card-title">
                        <span class="material-icons-outlined text-orange-600">work</span>
                        Información Laboral e Ingresos
                    </h3>
                    
                    <div class="info-item">
                        <p class="info-label">Tipo de Ingreso</p>
                        <p class="info-value">
                            <?php 
                            $tipo_ing = $cliente['tipo_ingreso'] ?? '';
                            echo $tipo_ing ? htmlspecialchars($tipo_ingreso_texto[$tipo_ing] ?? ucfirst($tipo_ing)) : '<span class="empty">No registrado</span>';
                            ?>
                        </p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Ingresos Mensuales</p>
                        <p class="info-value">
                            <?php 
                            if (!empty($cliente['monto_ingresos'])) {
                                echo '$' . number_format($cliente['monto_ingresos'], 0, ',', '.');
                            } else {
                                echo '<span class="empty">No registrado</span>';
                            }
                            ?>
                        </p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Banco</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['banco'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">CBU</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['cbu'] ?: 'No registrado'); ?></p>
                    </div>
                </div>
                
                <?php if (in_array($cliente['tipo_ingreso'] ?? '', ['dependencia', 'autonomo', 'monotributo'])): ?>
                <div class="info-card">
                    <h3 class="info-card-title">
                        <span class="material-icons-outlined text-indigo-600">business</span>
                        Datos del Empleador
                    </h3>
                    
                    <div class="info-item">
                        <p class="info-label">CUIT del Empleador</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['empleador_cuit'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Razón Social</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['empleador_razon_social'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Teléfono del Empleador</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['empleador_telefono'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Domicilio Laboral</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['empleador_direccion'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Sector</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['empleador_sector'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Cargo</p>
                        <p class="info-value"><?php echo htmlspecialchars($cliente['cargo'] ?: 'No registrado'); ?></p>
                    </div>
                    
                    <div class="info-item">
                        <p class="info-label">Antigüedad</p>
                        <p class="info-value">
                            <?php 
                            if (!empty($cliente['antiguedad_laboral'])) {
                                $antiguedad = (int)$cliente['antiguedad_laboral'];
                                $anos = floor($antiguedad / 12);
                                $meses = $antiguedad % 12;
                                echo $anos > 0 ? "$anos años" : '';
                                echo $anos > 0 && $meses > 0 ? ' y ' : '';
                                echo $meses > 0 ? "$meses meses" : '';
                                echo " ($antiguedad meses)";
                            } else {
                                echo '<span class="empty">No registrada</span>';
                            }
                            ?>
                        </p>
                    </div>
                    
                    <?php if (empty($cliente['empleador_razon_social']) || empty($cliente['cargo']) || empty($cliente['antiguedad_laboral'])): ?>
                    <div class="mt-4 p-3 bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 text-sm">
                        <strong>⚠️ Atención:</strong> Información laboral incompleta
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB: PRÉSTAMOS -->
        <div id="tab-prestamos" class="tab-content">
            <?php if (empty($prestamos)): ?>
                <div class="bg-white rounded-xl shadow-lg p-12 text-center">
                    <span class="material-icons-outlined text-gray-300 text-6xl mb-4">account_balance_wallet</span>
                    <p class="text-gray-600">No tiene préstamos registrados</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 gap-4">
                    <?php foreach ($prestamos as $p): ?>
                        <?php
                        $tipo_badge = [
                            'prestamo' => ['text' => 'Préstamo Personal', 'color' => 'bg-purple-100 text-purple-700'],
                            'prendario' => ['text' => 'Crédito Prendario', 'color' => 'bg-blue-100 text-blue-700'],
                            'empeno' => ['text' => 'Empeño', 'color' => 'bg-orange-100 text-orange-700']
                        ][$p['tipo']];
                        
                        $estado_badge = [
                            'pendiente' => ['text' => 'Pendiente', 'color' => 'bg-yellow-100 text-yellow-700'],
                            'aprobado' => ['text' => 'Aprobado', 'color' => 'bg-green-100 text-green-700'],
                            'rechazado' => ['text' => 'Rechazado', 'color' => 'bg-red-100 text-red-700'],
                            'activo' => ['text' => 'Activo', 'color' => 'bg-blue-100 text-blue-700'],
                            'finalizado' => ['text' => 'Finalizado', 'color' => 'bg-gray-100 text-gray-700']
                        ][$p['estado']] ?? ['text' => ucfirst($p['estado']), 'color' => 'bg-gray-100 text-gray-700'];
                        
                        $monto = $p['monto'] ?: $p['monto_ofrecido'] ?: $p['monto_solicitado'];
                        ?>
                        <div class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition">
                            <div class="flex items-start justify-between mb-4">
                                <div>
                                    <div class="flex items-center gap-2 mb-2">
                                        <h4 class="text-lg font-bold text-gray-800"><?php echo $tipo_badge['text']; ?> #<?php echo $p['id']; ?></h4>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $tipo_badge['color']; ?>">
                                            <?php echo strtoupper($p['tipo']); ?>
                                        </span>
                                    </div>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $estado_badge['color']; ?>">
                                        <?php echo $estado_badge['text']; ?>
                                    </span>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-gray-500">Monto</p>
                                    <p class="text-2xl font-bold text-green-600">$<?php echo number_format($monto, 0, ',', '.'); ?></p>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-3 gap-4 text-sm mb-4">
                                <div>
                                    <p class="text-xs text-gray-500">Cuotas</p>
                                    <p class="font-semibold text-gray-800"><?php echo (int)$p['cuotas']; ?></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Solicitud</p>
                                    <p class="font-semibold text-gray-800"><?php echo date('d/m/Y', strtotime($p['fecha_solicitud'])); ?></p>
                                </div>
                                <?php if ($p['fecha_aprobacion']): ?>
                                <div>
                                    <p class="text-xs text-gray-500">Aprobación</p>
                                    <p class="font-semibold text-gray-800"><?php echo date('d/m/Y', strtotime($p['fecha_aprobacion'])); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ((int)$p['requiere_legajo'] === 1): ?>
                            <div class="border-t pt-3 mb-3">
                                <p class="text-xs text-gray-500 mb-2">Estado del Legajo</p>
                                <?php if ((int)$p['legajo_validado'] === 1): ?>
                                    <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">
                                        ✅ Legajo Validado
                                    </span>
                                <?php elseif ((int)$p['legajo_completo'] === 1): ?>
                                    <span class="px-3 py-1 bg-orange-100 text-orange-700 rounded-full text-xs font-semibold">
                                        📋 Legajo Completo - Pendiente Validar
                                    </span>
                                <?php else: ?>
                                    <span class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-semibold">
                                        ⏳ Legajo Incompleto
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <a href="ver_prestamo.php?id=<?php echo $p['id']; ?>&tipo=<?php echo $p['tipo']; ?>"
                               class="block px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-center font-semibold">
                                Ver Detalle Completo
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB: CONTRATOS -->
        <div id="tab-contratos" class="tab-content">
            <?php if (empty($contratos)): ?>
                <div class="bg-white rounded-xl shadow-lg p-12 text-center">
                    <span class="material-icons-outlined text-gray-300 text-6xl mb-4">description</span>
                    <p class="text-gray-600">No tiene contratos firmados</p>
                </div>
            <?php else: ?>
                <!-- Resumen de contratos -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white rounded-xl shadow-lg p-6 border-t-4 border-blue-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600 font-semibold uppercase">Total Contratos</p>
                                <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo count($contratos); ?></p>
                            </div>
                            <span class="material-icons-outlined text-blue-500 text-5xl">description</span>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-lg p-6 border-t-4 border-green-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600 font-semibold uppercase">Firmados</p>
                                <p class="text-3xl font-bold text-gray-800 mt-2">
                                    <?php echo count(array_filter($contratos, fn($c) => $c['estado'] === 'firmado')); ?>
                                </p>
                            </div>
                            <span class="material-icons-outlined text-green-500 text-5xl">done</span>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-lg p-6 border-t-4 border-orange-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600 font-semibold uppercase">Pendientes</p>
                                <p class="text-3xl font-bold text-gray-800 mt-2">
                                    <?php echo count(array_filter($contratos, fn($c) => $c['estado'] === 'pendiente')); ?>
                                </p>
                            </div>
                            <span class="material-icons-outlined text-orange-500 text-5xl">pending</span>
                        </div>
                    </div>
                </div>
                
                <!-- Lista de contratos -->
                <div class="grid grid-cols-1 gap-4">
                    <?php foreach ($contratos as $contrato): ?>
                        <?php
                        $tipo_color = [
                            'prestamo' => 'bg-purple-100 text-purple-700 border-purple-300',
                            'empeno' => 'bg-orange-100 text-orange-700 border-orange-300',
                            'prendario' => 'bg-blue-100 text-blue-700 border-blue-300'
                        ][$contrato['tipo_operacion']] ?? 'bg-gray-100 text-gray-700 border-gray-300';
                        
                        $estado_badge = [
                            'pendiente' => ['text' => 'Pendiente Firma', 'color' => 'bg-yellow-100 text-yellow-800', 'icon' => 'pending'],
                            'firmado' => ['text' => 'Firmado', 'color' => 'bg-green-100 text-green-800', 'icon' => 'verified'],
                            'vencido' => ['text' => 'Vencido', 'color' => 'bg-red-100 text-red-800', 'icon' => 'error'],
                            'cancelado' => ['text' => 'Cancelado', 'color' => 'bg-gray-100 text-gray-800', 'icon' => 'cancel']
                        ][$contrato['estado']] ?? ['text' => ucfirst($contrato['estado']), 'color' => 'bg-gray-100 text-gray-700', 'icon' => 'info'];
                        ?>
                        
                        <div class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition border-l-4 <?php echo $tipo_color; ?>">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <h4 class="text-lg font-bold text-gray-800">
                                            <?php echo htmlspecialchars($contrato['tipo_nombre']); ?> 
                                            <span class="text-gray-500 font-normal text-sm">#<?php echo $contrato['id']; ?></span>
                                        </h4>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $tipo_color; ?> border">
                                            <?php echo strtoupper($contrato['tipo_operacion']); ?>
                                        </span>
                                    </div>
                                    
                                    <p class="text-sm text-gray-600 font-mono mb-2">
                                        📄 <?php echo htmlspecialchars($contrato['numero_contrato']); ?>
                                    </p>
                                    
                                    <div class="flex items-center gap-2">
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $estado_badge['color']; ?> flex items-center gap-1">
                                            <span class="material-icons-outlined text-sm"><?php echo $estado_badge['icon']; ?></span>
                                            <?php echo $estado_badge['text']; ?>
                                        </span>
                                        
                                        <?php if ($contrato['monto_operacion']): ?>
                                        <span class="px-3 py-1 bg-green-50 text-green-700 rounded-full text-xs font-semibold">
                                            💰 $<?php echo number_format($contrato['monto_operacion'], 0, ',', '.'); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="text-right">
                                    <?php if ($contrato['estado'] === 'firmado' && $contrato['fecha_firma']): ?>
                                        <div class="mb-2">
                                            <span class="material-icons-outlined text-green-600 text-3xl">check_circle</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Información adicional -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mb-4 pt-4 border-t">
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Creación</p>
                                    <p class="font-semibold text-gray-800">
                                        <?php echo date('d/m/Y', strtotime($contrato['created_at'])); ?>
                                    </p>
                                </div>
                                
                                <?php if ($contrato['fecha_firma']): ?>
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Firmado</p>
                                    <p class="font-semibold text-gray-800">
                                        <?php echo date('d/m/Y H:i', strtotime($contrato['fecha_firma'])); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($contrato['fecha_vencimiento']): ?>
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Vencimiento</p>
                                    <p class="font-semibold text-gray-800">
                                        <?php echo date('d/m/Y', strtotime($contrato['fecha_vencimiento'])); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Préstamo</p>
                                    <p class="font-semibold text-gray-800">
                                        #<?php echo $contrato['prestamo_id']; ?>
                                    </p>
                                </div>
                            </div>
                            
                            <?php if ($contrato['estado'] === 'firmado' && $contrato['firma_cliente']): ?>
                            <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                                <p class="text-xs text-gray-500 mb-2">Vista Previa de Firma</p>
                                <img src="<?php echo htmlspecialchars($contrato['firma_cliente']); ?>" 
                                     alt="Firma" 
                                     class="h-16 border border-gray-300 rounded bg-white">
                                <p class="text-xs text-gray-500 mt-2">
                                    IP: <?php echo htmlspecialchars($contrato['ip_firma'] ?? 'No registrada'); ?>
                                </p>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Botones de acción -->
                            <div class="flex gap-2">
                                <a href="ver_contrato.php?id=<?php echo $contrato['id']; ?>" 
                                   target="_blank"
                                   class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-center font-semibold flex items-center justify-center gap-2">
                                    <span class="material-icons-outlined text-sm">visibility</span>
                                    Ver Contrato
                                </a>
                                
                                <?php if ($contrato['estado'] === 'firmado'): ?>
                                <a href="descargar_contrato.php?id=<?php echo $contrato['id']; ?>" 
                                   class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-center font-semibold flex items-center justify-center gap-2">
                                    <span class="material-icons-outlined text-sm">download</span>
                                    Descargar PDF
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB: LEGAJOS -->
        <div id="tab-legajos" class="tab-content">
            <?php if (empty($legajo_agrupado)): ?>
                <div class="bg-white rounded-xl shadow-lg p-12 text-center">
                    <span class="material-icons-outlined text-gray-300 text-6xl mb-4">folder_off</span>
                    <p class="text-gray-600">No hay documentos de legajo subidos</p>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold text-gray-800">Progreso del Legajo</h3>
                        <span class="text-sm font-semibold text-gray-600"><?php echo count(array_filter($legajo_agrupado, fn($d) => $d['estado_validacion'] === 'aprobado')); ?> / 7</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-4">
                        <div class="bg-green-500 h-4 rounded-full" style="width: <?php echo round((count(array_filter($legajo_agrupado, fn($d) => $d['estado_validacion'] === 'aprobado')) / 7) * 100); ?>%"></div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($legajo_agrupado as $tipo => $doc): ?>
                        <div class="bg-white rounded-xl shadow-lg p-6">
                            <div class="flex justify-between items-start mb-3">
                                <h4 class="font-bold text-gray-800"><?php echo $tipos_doc_legajo[$tipo] ?? ucfirst($tipo); ?></h4>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?php
                                    echo match($doc['estado_validacion']) {
                                        'pendiente' => 'bg-yellow-100 text-yellow-800',
                                        'aprobado' => 'bg-green-100 text-green-800',
                                        'rechazado' => 'bg-red-100 text-red-800',
                                        default => 'bg-gray-100 text-gray-800'
                                    };
                                ?>">
                                    <?php echo ucfirst($doc['estado_validacion']); ?>
                                </span>
                            </div>
                            
                            <div class="mb-3">
                                <?php if (str_ends_with($doc['archivo_path'], '.pdf')): ?>
                                    <div class="bg-gray-100 p-6 rounded-lg text-center">
                                        <span class="material-icons-outlined text-red-600 text-4xl">picture_as_pdf</span>
                                    </div>
                                <?php else: ?>
                                    <img src="<?php echo htmlspecialchars($doc['archivo_path']); ?>" alt="Documento" class="w-full rounded-lg">
                                <?php endif; ?>
                            </div>
                            
                            <div class="text-xs text-gray-600 mb-3">
                                Subido: <?php echo date('d/m/Y H:i', strtotime($doc['fecha_creacion'])); ?>
                            </div>
                            
                            <?php if ($doc['motivo_rechazo']): ?>
                            <div class="text-sm text-red-700 mb-3 p-3 bg-red-50 rounded">
                                <strong>Motivo:</strong> <?php echo htmlspecialchars($doc['motivo_rechazo']); ?>
                            </div>
                            <?php endif; ?>
                            
                            <a href="<?php echo htmlspecialchars($doc['archivo_path']); ?>" target="_blank"
                               class="block px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-center font-semibold">
                                Ver Documento
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB: CONVERSACIONES -->
        <div id="tab-conversaciones" class="tab-content">
            <?php if (empty($conversaciones)): ?>
                <div class="bg-white rounded-xl shadow-lg p-12 text-center">
                    <span class="material-icons-outlined text-gray-300 text-6xl mb-4">chat_bubble_outline</span>
                    <p class="text-gray-600">No hay conversaciones del chatbot</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($conversaciones as $conv): ?>
                        <div class="bg-white rounded-xl shadow-lg p-6">
                            <div class="flex items-start justify-between mb-4">
                                <div>
                                    <h4 class="text-lg font-bold text-gray-800">Conversación #<?php echo $conv['id']; ?></h4>
                                    <p class="text-sm text-gray-600">
                                        <?php echo date('d/m/Y H:i', strtotime($conv['fecha_inicio'])); ?>
                                        <?php if ($conv['asesor_nombre']): ?>
                                            • Asesor: <?php echo htmlspecialchars($conv['asesor_nombre'] . ' ' . $conv['asesor_apellido']); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">
                                    <?php echo (int)$conv['total_mensajes']; ?> mensajes
                                </span>
                            </div>
                            
                            <div class="grid grid-cols-3 gap-4 text-sm">
                                <div>
                                    <p class="text-xs text-gray-500">Estado</p>
                                    <p class="font-semibold text-gray-800"><?php echo ucfirst($conv['estado_solicitud']); ?></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Prioridad</p>
                                    <p class="font-semibold text-gray-800"><?php echo ucfirst($conv['prioridad']); ?></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Score</p>
                                    <p class="font-semibold text-gray-800"><?php echo (int)$conv['score']; ?></p>
                                </div>
                            </div>
                            
                            <!-- Mostrar últimos mensajes de esta conversación -->
                            <div class="border-t mt-4 pt-4">
                                <button onclick="verMensajes(<?= $conv['id'] ?>)" 
                                        class="w-full px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-semibold transition">
                                    Ver Mensajes de Esta Conversación
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
function switchTab(tabName) {
    // Ocultar todos los tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Desactivar todos los botones
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Activar tab seleccionado
    document.getElementById('tab-' + tabName).classList.add('active');
    event.target.closest('.tab-button').classList.add('active');
}

function verMensajes(chatId) {
    // Redirigir a una página que muestre los mensajes o implementar un modal
    alert('Ver mensajes de conversación #' + chatId + ' - Por implementar si es necesario');
}
</script>

</body>
</html>