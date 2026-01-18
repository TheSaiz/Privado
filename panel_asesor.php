<?php

date_default_timezone_set('America/Argentina/Buenos_Aires');
session_start();

if (!isset($_SESSION['asesor_id'])) {
    header('Location: login.php');
    exit;
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/panel_asesor_error.log');
error_reporting(E_ALL);
require_once 'backend/connection.php';

$asesor_id     = $_SESSION['asesor_id'];
$asesor_nombre = $_SESSION['asesor_nombre'];

// ======================================================
// LISTA DE CHATS AGRUPADOS POR DNI
// SOLO CHATS ACEPTADOS POR EL ASESOR
// ======================================================

$stmt = $pdo->prepare("
    SELECT
        x.chat_id_representativo,
        x.dni,
        x.cliente_id_real,
        x.cliente_nombre,
        x.cliente_telefono,
        x.departamento_nombre,
        x.estado,
        x.fecha_inicio,
        x.ultimo_mensaje,
        x.fecha_ultimo_mensaje,
        x.mensajes_nuevos,
        x.es_cliente_aprobado
    FROM (
        SELECT
            c.id AS chat_id_representativo,
            cd.dni,
            u.id AS cliente_id_real,
            u.nombre AS cliente_nombre,
            u.telefono AS cliente_telefono,
            d.nombre AS departamento_nombre,
            c.estado,
            c.fecha_inicio,

            (
                SELECT m.mensaje
                FROM mensajes m
                WHERE m.chat_id = c.id
                ORDER BY m.fecha DESC
                LIMIT 1
            ) AS ultimo_mensaje,

            (
                SELECT m.fecha
                FROM mensajes m
                WHERE m.chat_id = c.id
                ORDER BY m.fecha DESC
                LIMIT 1
            ) AS fecha_ultimo_mensaje,

            (
                SELECT COUNT(*)
                FROM mensajes m
                WHERE m.chat_id = c.id
                  AND m.emisor = 'cliente'
                  AND m.fecha > COALESCE(c.ultima_lectura_asesor, '1970-01-01')
            ) AS mensajes_nuevos,

            CASE
                WHEN cd.estado_validacion = 'aprobado' THEN 1
                ELSE 0
            END AS es_cliente_aprobado,

            ROW_NUMBER() OVER (
                PARTITION BY cd.dni
                ORDER BY c.fecha_inicio DESC
            ) AS rn

        FROM chats c
        INNER JOIN usuarios u ON u.id = c.cliente_id
        INNER JOIN clientes_detalles cd ON cd.usuario_id = u.id
        LEFT JOIN departamentos d ON d.id = c.departamento_id

        WHERE
            c.estado = 'en_conversacion'
            AND c.asesor_id = :asesor_id
    ) x
    WHERE x.rn = 1
    ORDER BY x.fecha_ultimo_mensaje DESC, x.fecha_inicio DESC
");

$stmt->execute(['asesor_id' => $asesor_id]);
$chats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CHAT ACTIVO
$chat_activo_id = isset($_GET['chat_id']) ? intval($_GET['chat_id']) : null;
$chat_activo    = null;
$mensajes       = [];

if ($chat_activo_id) {

    // Verificar que el chat pertenece al asesor
    $stmt = $pdo->prepare("
        SELECT 
            c.*, 
            u.nombre   AS cliente_nombre,
            u.telefono AS cliente_telefono,

            -- ✅ EMAIL REAL TOMADO POR DNI (usuario más reciente)
            (
                SELECT u2.email
                FROM usuarios u2
                INNER JOIN clientes_detalles cd2 ON cd2.usuario_id = u2.id
                WHERE cd2.dni = cd.dni
                ORDER BY u2.id DESC
                LIMIT 1
            ) AS cliente_email,

            d.nombre   AS departamento_nombre,
            cd.dni     AS cliente_dni,
            c.ciudad,
            c.pais,
            c.latitud,
            c.longitud

        FROM chats c
        INNER JOIN usuarios u            ON c.cliente_id = u.id
        INNER JOIN clientes_detalles cd  ON cd.usuario_id = u.id
        LEFT JOIN departamentos d        ON c.departamento_id = d.id

        WHERE c.id = ? AND c.asesor_id = ?
        LIMIT 1
    ");

    $stmt->execute([$chat_activo_id, $asesor_id]);
    $chat_activo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($chat_activo) {

        // TODOS LOS MENSAJES DEL MISMO DNI (HISTORIAL COMPLETO)
        // INCLUYE respuestas del cliente desde chatbot_respuestas
        $stmt = $pdo->prepare("
            SELECT 
                m.id,
                m.chat_id,
                m.emisor,
                m.mensaje,
                m.fecha,
                m.tiene_archivo,
                m.usuario_id,
                u.nombre AS usuario_nombre,
                c.fecha_inicio AS chat_fecha_inicio,
                a.id              AS archivo_id,
                a.nombre_original,
                a.nombre_guardado,
                a.tipo_mime,
                a.tamano,
                a.ruta
            FROM mensajes m
            LEFT JOIN usuarios u      ON m.usuario_id = u.id
            LEFT JOIN chats c         ON m.chat_id    = c.id
            LEFT JOIN chat_archivos a ON a.mensaje_id = m.id
            WHERE c.cliente_id IN (
                SELECT usuario_id 
                FROM clientes_detalles 
                WHERE dni = (
                    SELECT dni 
                    FROM clientes_detalles 
                    WHERE usuario_id = ? 
                    LIMIT 1
                )
            )
            
            UNION ALL
            
            SELECT 
                (cr.id + 100000) as id,
                cr.chat_id,
                'cliente' as emisor,
                cr.respuesta as mensaje,
                cr.fecha,
                0 as tiene_archivo,
                NULL as usuario_id,
                NULL as usuario_nombre,
                NULL as chat_fecha_inicio,
                NULL as archivo_id,
                NULL as nombre_original,
                NULL as nombre_guardado,
                NULL as tipo_mime,
                NULL as tamano,
                NULL as ruta
            FROM chatbot_respuestas cr
            INNER JOIN chats c ON c.id = cr.chat_id
            WHERE c.cliente_id IN (
                SELECT usuario_id 
                FROM clientes_detalles 
                WHERE dni = (
                    SELECT dni 
                    FROM clientes_detalles 
                    WHERE usuario_id = ? 
                    LIMIT 1
                )
            )
            
            ORDER BY fecha ASC
        ");
        $stmt->execute([$chat_activo['cliente_id'], $chat_activo['cliente_id']]);
        $mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // MARCAR COMO LEÍDO POR EL ASESOR
        $stmt = $pdo->prepare("
            UPDATE chats 
            SET ultima_lectura_asesor = NOW() 
            WHERE cliente_id IN (
                SELECT usuario_id 
                FROM clientes_detalles 
                WHERE dni = (
                    SELECT dni 
                    FROM clientes_detalles 
                    WHERE usuario_id = ? 
                    LIMIT 1
                )
            )
        ");
        $stmt->execute([$chat_activo['cliente_id']]);
    }
}


?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
     <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Panel de Asesor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
    
    <style>

        /* =============================== */
/* 🔥 CLIENTES PREFERENCIALES VIP */
/* =============================== */

/* Notificación de cliente preferencial */
.cliente-preferencial-notification {
    border-left: 4px solid #10b981 !important;
    background: linear-gradient(135deg, #d1fae5, #ffffff);
    box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3) !important;
}

/* Badge especial para VIP */
.vip-badge {
    display: inline-block;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    font-size: 10px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 999px;
    margin-left: 6px;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
    animation: pulse-glow 2s infinite;
}

/* Animación de brillo para VIP */
@keyframes pulse-glow {
    0%, 100% { 
        opacity: 1; 
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
    }
    50% { 
        opacity: 0.85; 
        box-shadow: 0 4px 16px rgba(16, 185, 129, 0.6);
    }
}

/* Item de chat preferencial en lista */
.chat-item.cliente-preferencial-item {
    border-left: 4px solid #10b981 !important;
    background: linear-gradient(90deg, #d1fae5 0%, #ffffff 100%);
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2);
}

.chat-item.cliente-preferencial-item:hover {
    background: linear-gradient(90deg, #a7f3d0 0%, #f9fafb 100%);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.chat-item.cliente-preferencial-item.active {
    background: linear-gradient(90deg, #6ee7b7 0%, #e0f2fe 100%);
    border-left: 4px solid #059669 !important;
    box-shadow: 0 4px 16px rgba(16, 185, 129, 0.4);
}

/* Icono especial para VIP */
.vip-icon {
    display: inline-block;
    animation: bounce-star 1s infinite;
}

@keyframes bounce-star {
    0%, 100% { 
        transform: translateY(0) scale(1); 
    }
    50% { 
        transform: translateY(-3px) scale(1.1); 
    }
}

/* Badge en lista de chats pendientes */
.pendiente-vip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    font-size: 11px;
    font-weight: 700;
    border-radius: 999px;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
}

/* Notificación referido (mantener original pero mejorado) */
.referido-notification {
    border-left: 4px solid #16a34a !important;
    background: linear-gradient(135deg, #ecfdf5, #ffffff);
    box-shadow: 0 6px 20px rgba(22, 163, 74, 0.25) !important;
}

.referido-badge {
    display: inline-block;
    background: #16a34a;
    color: white;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 999px;
    margin-left: 6px;
}

/* Botón VIP especial */
.btn-vip {
    background: linear-gradient(135deg, #10b981, #059669) !important;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    transition: all 0.3s ease;
}

.btn-vip:hover {
    background: linear-gradient(135deg, #059669, #047857) !important;
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.6);
    transform: translateY(-2px);
}

/* Indicador visual en notificaciones */
.priority-indicator {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    animation: pulse-dot 2s infinite;
}

@keyframes pulse-dot {
    0%, 100% { 
        transform: scale(1); 
        opacity: 1; 
    }
    50% { 
        transform: scale(1.3); 
        opacity: 0.7; 
    }
}

/* =============================== */
/* 🔥 NOTIFICACIÓN CHAT REFERIDO */
/* =============================== */

.referido-notification {
    border-left: 4px solid #16a34a !important;
    background: linear-gradient(135deg, #ecfdf5, #ffffff);
}

.referido-badge {
    display: inline-block;
    background: #16a34a;
    color: white;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 999px;
    margin-left: 6px;
}

    
/* Animaciones de notificaciones */
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.pulse-icon {
    animation: pulse 2s infinite;
}

.notification-highlight {
    animation: highlightPulse 1s ease-out;
}

@keyframes highlightPulse {
    0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.7); }
    50% { transform: scale(1.05); box-shadow: 0 0 20px 10px rgba(37, 99, 235, 0); }
    100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(37, 99, 235, 0); }
}

@keyframes slideOut {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(400px); opacity: 0; }
}

/* Grabador de audio */
.audio-recorder {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px;
    background: rgba(239, 68, 68, 0.1);
    border-radius: 10px;
    margin-bottom: 8px;
}

.audio-recorder-time {
    font-size: 14px;
    font-weight: 600;
    color: #ef4444;
    font-family: 'Courier New', monospace;
}

.audio-wave {
    display: flex;
    align-items: center;
    gap: 2px;
    flex: 1;
}

.audio-wave-bar {
    width: 3px;
    background: #ef4444;
    border-radius: 2px;
    animation: wave 1s ease-in-out infinite;
}

@keyframes wave {
    0%, 100% { height: 10px; }
    50% { height: 20px; }
}

.audio-recorder-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    transition: transform 0.2s, background 0.2s;
}

.audio-recorder-btn:hover {
    transform: scale(1.05);
}

.audio-recorder-stop {
    background: #ef4444;
    color: white;
}

.audio-recorder-cancel {
    background: #6b7280;
    color: white;
}

/* Reproductor de audio */
.audio-player {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 10px;
    margin-top: 6px;
    max-width: 300px;
}

.audio-player-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #3b82f6;
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    transition: background 0.2s;
    flex-shrink: 0;
}

.audio-player-btn:hover {
    background: #2563eb;
}

.audio-player-time {
    font-size: 11px;
    color: #1e40af;
    font-family: 'Courier New', monospace;
    min-width: 75px;
}

.audio-player-progress {
    flex: 1;
    height: 4px;
    background: rgba(59, 130, 246, 0.2);
    border-radius: 2px;
    position: relative;
    cursor: pointer;
    min-width: 80px;
}

.audio-player-progress-bar {
    height: 100%;
    background: #3b82f6;
    border-radius: 2px;
    transition: width 0.1s;
}

.record-audio-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #ef4444;
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    transition: transform 0.2s, background 0.2s;
}

.record-audio-btn:hover {
    background: #dc2626;
    transform: scale(1.05);
}

.record-audio-btn.recording {
    animation: pulse-red 1.5s infinite;
}

@keyframes pulse-red {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.8; transform: scale(1.05); }
}
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif; }
        .notification-dot { animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .chat-item:hover { background: #f3f4f6; }
        .chat-item.active { background: #e0f2fe; border-left: 3px solid #0284c7; }
        /* Cliente aprobado - destacado verde */
.chat-item.cliente-aprobado-item {
    border-left: 3px solid #10b981 !important;
    background: linear-gradient(90deg, #ecfdf5 0%, #ffffff 100%);
}

.chat-item.cliente-aprobado-item:hover {
    background: linear-gradient(90deg, #d1fae5 0%, #f9fafb 100%);
}

.chat-item.cliente-aprobado-item.active {
    background: linear-gradient(90deg, #a7f3d0 0%, #e0f2fe 100%);
    border-left: 3px solid #059669 !important;
}
        @keyframes slideIn {
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .toast-notification {
            animation: slideIn 0.3s ease-out;
        }

    .file-preview-box {
        border: 1px solid #d1d5db;
        border-radius: 12px;
        background: #fff;
        padding: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        width: fit-content;
        max-width: 240px;
    }
    .file-preview-image {
        width: 40px;
        height: 40px;
        border-radius: 6px;
        object-fit: cover;
    }
    .file-preview-info {
        display: flex;
        flex-direction: column;
        font-size: 12px;
    }
    /* Layout responsive */
    @media (max-width: 1024px) {
        .flex.h-full {
            flex-direction: column;
        }
        
        /* Lista de chats en mobile */
        .w-80 {
            width: 100%;
            max-height: 40vh;
        }
        
        /* Chat activo */
        .flex-1.flex.flex-col {
            height: 60vh;
        }
        
        /* Panel de perfil oculto en mobile */
        #profile-panel {
            display: none;
        }
        
        #profile-panel.show-mobile {
            display: block;
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            width: 90%;
            max-width: 400px;
            z-index: 9999;
            box-shadow: -4px 0 12px rgba(0,0,0,0.3);
        }
    }

    /* Top bar responsive */
    @media (max-width: 640px) {
        .bg-white.border-b .flex {
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        #pending-chats-button {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
        }
    }

    /* Chat messages area */
    #chat-messages {
        height: calc(100vh - 300px);
        min-height: 300px;
    }

    @media (max-width: 768px) {
        #chat-messages {
            height: calc(60vh - 150px);
            min-height: 200px;
        }
    }

    /* Input area responsive */
    @media (max-width: 640px) {
        #chat-input-area {
            padding: 0.5rem;
        }
        
        #message-input {
            font-size: 0.875rem;
            padding: 0.5rem;
        }
        
        #chat-send-btn, #chat-attach-btn, #chat-end-btn {
            width: 40px;
            height: 40px;
            padding: 0.5rem;
        }
    }

    /* Modales responsive */
    @media (max-width: 640px) {
        #pendingChatsModal > div,
        #transferChatModal > div {
            width: 95%;
            max-width: none;
            margin: 1rem;
        }
    }

    /* Search bar */
    @media (max-width: 640px) {
        #search-chats {
            font-size: 0.875rem;
        }
    }

    /* Chat items */
    .chat-item {
        min-height: 70px;
    }

    @media (max-width: 640px) {
        .chat-item h3 {
            font-size: 0.875rem;
        }
        
        .chat-item p {
            font-size: 0.75rem;
        }
    }

    /* Mapa responsive */
    #mapa-ubicacion {
        height: 200px;
    }

    @media (max-width: 768px) {
        #mapa-ubicacion {
            height: 150px;
        }
    }

    /* Botón para mostrar perfil en mobile */
    .show-profile-btn {
        display: none;
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 9997;
        background: #3b82f6;
        color: white;
        border-radius: 50%;
        width: 56px;
        height: 56px;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    }

    @media (max-width: 1024px) {
        .show-profile-btn {
            display: flex;
        }
    }

    /* Overlay para perfil mobile */
    .profile-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 9998;
    }

    .profile-overlay.active {
        display: block;
    }

    /* Fix para iOS safe area */
    @supports (padding: max(0px)) {
        body {
            padding-left: max(0px, env(safe-area-inset-left));
            padding-right: max(0px, env(safe-area-inset-right));
        }
    }

    /* Optimización scroll iOS */
    .chat-list, .chat-body {
        -webkit-overflow-scrolling: touch;
    }
    </style>
</head>
<body class="bg-gray-50 h-screen overflow-hidden">
    <!-- TOP BAR -->
<div class="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between">
    <div class="flex items-center gap-4">
        <div class="flex items-center gap-2">
            <span class="material-icons-outlined text-blue-600 text-2xl">support_agent</span>
            <h1 class="text-lg font-bold text-gray-800">Panel de Asesor</h1>
        </div>
    </div>

    <div class="flex items-center gap-4">
        <button id="pending-chats-button"
                class="px-3 py-1 bg-gray-300 text-gray-800 rounded-full text-sm font-semibold hover:bg-gray-400 transition"
                onclick="openPendingChatsModal()">
            Sin chats pendientes
        </button>

        <div class="flex items-center gap-2 text-sm">
            <div class="w-2 h-2 bg-green-500 rounded-full"></div>
            <span class="font-semibold text-gray-700">
                <?php echo htmlspecialchars($asesor_nombre); ?>
            </span>
        </div>

        <!-- Configuración + Logout -->
        <div class="flex items-center gap-3">
            <a href="perfil_asesor.php" class="text-gray-600 hover:text-gray-800">
                <span class="material-icons-outlined">settings</span>
            </a>

            <a href="logout.php" class="text-gray-600 hover:text-gray-800">
                <span class="material-icons-outlined">logout</span>
            </a>
        </div>
    </div>
</div>


    <!-- LAYOUT PRINCIPAL -->
    <div class="flex h-full min-h-0 overflow-hidden">
        <!-- COLUMNA IZQUIERDA: LISTA DE CHATS -->
        <div class="w-80 bg-white border-r border-gray-200 flex flex-col min-h-0">
            <div class="p-4 border-b border-gray-200">
                <div class="relative">
                    <span class="material-icons-outlined absolute left-3 top-2.5 text-gray-400 text-sm">search</span>
                    <input type="text" id="search-chats" placeholder="Buscar..." 
                           class="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>

            <div class="chat-list overflow-y-auto flex-1 min-h-0">
                <?php if (empty($chats)): ?>
                <div class="p-8 text-center">
                    <span class="material-icons-outlined text-gray-300 text-5xl mb-3">chat_bubble_outline</span>
                    <p class="text-sm text-gray-500">No hay conversaciones</p>
                </div>
                <?php else: ?>
                    <?php foreach ($chats as $chat): ?>
                        <?php $esClienteAprobado = isset($chat['es_cliente_aprobado']) && $chat['es_cliente_aprobado'] == 1; ?>
                            <a href="?chat_id=<?php echo $chat['chat_id_representativo']; ?>" 
                            class="chat-item block px-4 py-3 border-b border-gray-100 cursor-pointer transition <?php echo ($chat_activo_id == $chat['chat_id_representativo']) ? 'active' : ''; ?> <?php echo $esClienteAprobado ? 'cliente-aprobado-item' : ''; ?>">
                        <div class="flex items-start gap-3">
                            <div class="relative">
                                <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-semibold text-sm">
                                    <?php echo strtoupper(substr($chat['cliente_nombre'], 0, 2)); ?>
                                </div>
                                <?php if ($chat['estado'] === 'en_conversacion'): ?>
                                <div class="absolute bottom-0 right-0 w-3 h-3 bg-green-500 border-2 border-white rounded-full"></div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between mb-1">
                                    <h3 class="font-semibold text-sm text-gray-800 truncate flex items-center gap-2">
    <?php echo htmlspecialchars($chat['cliente_nombre']); ?>
    <?php if ($esClienteAprobado): ?>
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-green-100 text-green-700" title="Cliente con documentos aprobados">
            ✓ APROBADO
        </span>
    <?php endif; ?>
</h3>
                                    <span class="text-xs text-gray-500">
                                        <?php 
                                        $fecha = $chat['fecha_ultimo_mensaje'] ?? $chat['fecha_inicio'];
                                        echo $fecha ? date('d/m H:i', strtotime($fecha)) : '';
                                        ?>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 truncate mb-1">
                                    <?php echo htmlspecialchars($chat['departamento_nombre']); ?>
                                </p>
                                <p class="text-xs text-gray-600 truncate">
                                    <?php echo htmlspecialchars($chat['ultimo_mensaje'] ?? 'Chat iniciado...'); ?>
                                </p>
                            </div>
                            <?php if ($chat['mensajes_nuevos'] > 0): ?>
                            <div class="flex-shrink-0 min-w-[20px] px-2 py-0.5 bg-blue-600 text-white text-xs font-bold rounded-full text-center">
                                <?php echo $chat['mensajes_nuevos']; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <!-- COLUMNA CENTRAL: CHAT ACTIVO -->
        <?php if ($chat_activo): ?>
        <div class="flex-1 flex flex-col bg-gray-50 min-h-0 overflow-hidden">
            <!-- Header del chat -->
            <div class="bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-semibold">
                        <?php echo strtoupper(substr($chat_activo['cliente_nombre'], 0, 2)); ?>
                    </div>
                    <div>
                        <h2 class="font-bold text-gray-800"><?php echo htmlspecialchars($chat_activo['cliente_nombre']); ?></h2>
                        <div class="flex items-center gap-2 text-xs text-gray-500">
                            <span><?php echo htmlspecialchars($chat_activo['departamento_nombre']); ?></span>
                            <?php if (!empty($chat_activo['estado'])): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold 
                                    <?php echo $chat_activo['estado'] === 'en_conversacion' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'; ?>">
                                    <?php echo htmlspecialchars($chat_activo['estado']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mensajes -->
<div id="chat-messages" class="flex-1 overflow-y-auto px-6 py-4 min-h-0 max-h-[calc(100vh-220px)]">
    <?php foreach ($mensajes as $msg): ?>
        <?php if ($msg['emisor'] === 'sistema'): ?>
        <!-- Mensaje del sistema (cierre de chat, etc) -->
        <div class="flex justify-center mb-4">
            <div class="px-4 py-2 bg-yellow-100 border border-yellow-300 rounded-lg shadow-sm">
                <p class="text-sm text-yellow-800 font-semibold text-center">
                    <?php echo htmlspecialchars($msg['mensaje']); ?>
                </p>
                <span class="text-xs text-yellow-600 mt-1 block text-center">
                    <?php echo date('d/m H:i', strtotime($msg['fecha'])); ?>
                </span>
            </div>
        </div>
        
        <?php elseif ($msg['emisor'] === 'cliente'): ?>
        <!-- Mensaje del cliente -->
        <div class="flex justify-start mb-4">
            <div class="flex gap-2 max-w-[60%]">
                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center flex-shrink-0">
                    <span class="text-white text-xs font-semibold">
                        <?php echo strtoupper(substr($chat_activo['cliente_nombre'], 0, 1)); ?>
                    </span>
                </div>
                <div>
                    <div class="bg-white rounded-2xl rounded-tl-none px-4 py-2.5 shadow-sm">
                        <p class="text-sm text-gray-800"><?php echo nl2br(htmlspecialchars($msg['mensaje'])); ?></p>
                        <?php if (!empty($msg['archivo_id']) && !empty($msg['ruta'])): ?>
                            <?php
                            $isAudio = !empty($msg['tipo_mime']) && strpos($msg['tipo_mime'], 'audio/') === 0;
                            ?>
                            <?php if ($isAudio): ?>
                                <div class="audio-player mt-2">
                                    <button class="audio-player-btn" onclick="toggleAudio(this)">▶</button>
                                    <audio src="<?php echo htmlspecialchars($msg['ruta']); ?>"></audio>
                                    <div class="audio-player-progress">
                                        <div class="audio-player-progress-bar"></div>
                                    </div>
                                    <span class="audio-player-time">00:00 / 00:00</span>
                                </div>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($msg['ruta']); ?>" target="_blank" 
                                   class="inline-flex items-center gap-2 mt-2 px-3 py-2 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 transition">
                                    <span class="material-icons-outlined text-blue-600">attach_file</span>
                                    <span class="text-sm text-blue-700 font-medium">Ver archivo</span>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <span class="text-xs text-gray-400 mt-1 ml-1 block">
                        <?php echo date('d/m H:i', strtotime($msg['fecha'])); ?>
                    </span>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Mensaje del asesor o bot -->
        <div class="flex justify-end mb-4">
            <div class="flex gap-2 max-w-[60%]">
                <div>
                    <div class="bg-blue-600 text-white rounded-2xl rounded-tr-none px-4 py-2.5 shadow-sm">
                        <p class="text-sm"><?php echo nl2br(htmlspecialchars($msg['mensaje'])); ?></p>
                        <?php if (!empty($msg['archivo_id']) && !empty($msg['ruta'])): ?>
                            <?php
                            $isAudio = (!empty($msg['tipo_mime']) && strpos($msg['tipo_mime'], 'audio/') === 0);
                            ?>
                            <?php if ($isAudio): ?>
                                <div class="audio-player mt-2">
                                    <button class="audio-player-btn" onclick="toggleAudio(this)">▶</button>
                                    <audio src="<?php echo htmlspecialchars($msg['ruta']); ?>"></audio>
                                    <div class="audio-player-progress">
                                        <div class="audio-player-progress-bar"></div>
                                    </div>
                                    <span class="audio-player-time">00:00 / 00:00</span>
                                </div>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($msg['ruta']); ?>" target="_blank" 
                                   class="inline-flex items-center gap-2 mt-2 px-3 py-2 bg-blue-700 rounded-lg hover:bg-blue-800 transition">
                                    <span class="material-icons-outlined text-white">attach_file</span>
                                    <span class="text-sm text-white font-medium">Ver archivo</span>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <span class="text-xs text-gray-400 mt-1 mr-1 block text-right">
                        <?php echo date('d/m H:i', strtotime($msg['fecha'])); ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

            <!-- INPUT MENSAJE + ADJUNTO -->
            <div class="bg-white border-t border-gray-200 px-6 py-3 flex-none">
                <div id="file-preview" class="hidden mb-3"></div>
                <div class="flex items-end gap-3 w-full">
                    <textarea id="message-input" rows="1" placeholder="Escribe tu mensaje..."
                        class="flex-1 px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl resize-none focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                        style="max-height: 120px;"></textarea>

                   <input type="file" id="file-input" class="hidden" 
       accept=".pdf,.png,.jpg,.jpeg,.webp,.xls,.xlsx,.mp3,.wav,.ogg,.m4a,.webm">

                    <button type="button" id="attach-button"
                            class="px-3 py-3 bg-gray-200 rounded-xl hover:bg-gray-300 transition flex items-center">
                        <span class="material-icons-outlined">attach_file</span>
                    </button>
                    <button type="button" id="record-audio-button" class="record-audio-btn" title="Grabar audio">
    🎤
</button>

                    <button type="button" onclick="sendMessage()" 
                            class="px-6 py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition flex items-center gap-2 font-semibold">
                        <span class="material-icons-outlined">send</span>
                        <span>Enviar</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- COLUMNA DERECHA: PERFIL DEL CLIENTE -->
        <!-- Panel de Información -->
<div id="profile-panel" class="w-80 bg-white border-l border-gray-200 overflow-y-auto flex-none min-h-0">
    <div class="p-6">
        <div class="flex items-center justify-between mb-6">
            <h3 class="font-bold text-gray-800">Perfil del Cliente</h3>
            <?php if ($chat_activo): ?>
            <button onclick="toggleEditMode()" id="edit-profile-btn"
                    class="text-blue-600 hover:text-blue-800 transition">
                <span class="material-icons-outlined">edit</span>
            </button>
            <?php endif; ?>
        </div>

        <div class="text-center mb-6">
            <div class="w-20 h-20 bg-gradient-to-br from-blue-500 to-blue-600 
                        rounded-full flex items-center justify-center 
                        text-white font-bold text-2xl mx-auto mb-3">
                <?php echo strtoupper(substr($chat_activo['cliente_nombre'], 0, 2)); ?>
            </div>
            
            <!-- Nombre editable -->
            <div id="view-nombre" class="font-bold text-gray-800 text-lg">
                <?php echo htmlspecialchars($chat_activo['cliente_nombre']); ?>
            </div>
            <div id="edit-nombre" class="hidden">
                <input type="text" id="input-nombre" 
                       value="<?php echo htmlspecialchars($chat_activo['cliente_nombre']); ?>"
                       class="w-full px-3 py-2 border rounded-lg text-center font-bold">
            </div>

            <!-- Email editable -->
            <div id="view-email" class="text-sm text-gray-500 mt-1">
                <?php echo htmlspecialchars($chat_activo['cliente_email']); ?>
            </div>
            <div id="edit-email" class="hidden mt-1">
                <input type="email" id="input-email" 
                       value="<?php echo htmlspecialchars($chat_activo['cliente_email']); ?>"
                       class="w-full px-3 py-2 border rounded-lg text-sm">
            </div>

            <?php if (!empty($chat_activo['fecha_inicio'])): ?>
            <p class="text-xs text-gray-400 mt-1">
                Chat iniciado: <?php echo date('d/m/Y H:i', strtotime($chat_activo['fecha_inicio'])); ?>
            </p>
            <?php endif; ?>
        </div>

        <div class="space-y-4 mb-6">
            <div class="pb-4 border-b border-gray-100">
                <label class="text-xs text-gray-500 uppercase font-semibold">DNI</label>
                <p class="text-sm text-gray-800 mt-1">
                    <?php echo htmlspecialchars($chat_activo['cliente_dni'] ?: 'N/A'); ?>
                </p>
            </div>

            <!-- Teléfono editable -->
            <div class="pb-4 border-b border-gray-100">
                <label class="text-xs text-gray-500 uppercase font-semibold">Teléfono</label>
                <div id="view-telefono" class="text-sm text-gray-800 mt-1">
                    <?php echo htmlspecialchars($chat_activo['cliente_telefono']); ?>
                </div>
                <div id="edit-telefono" class="hidden mt-1">
                    <input type="tel" id="input-telefono" 
                           value="<?php echo htmlspecialchars($chat_activo['cliente_telefono']); ?>"
                           class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>
            </div>

            <!-- Banco editable -->
            <div class="pb-4 border-b border-gray-100">
             <label class="text-xs text-gray-500 uppercase font-semibold">Banco</label>
                <div id="view-banco" class="text-sm text-gray-800 mt-1">
            <?php echo htmlspecialchars($chat_activo['banco'] ?: 'N/A'); ?>
            </div>
            <div id="edit-banco" class="hidden mt-1">
             <input type="text" id="input-banco" 
               value="<?php echo htmlspecialchars($chat_activo['banco']); ?>"
               class="w-full px-3 py-2 border rounded-lg text-sm">
                    </div>
            </div>


            <div class="pb-4 border-b border-gray-100">
                <label class="text-xs text-gray-500 uppercase font-semibold">Departamento</label>
                <p class="text-sm text-gray-800 mt-1">
                    <?php echo htmlspecialchars($chat_activo['departamento_nombre']); ?>
                </p>
            </div>
        </div>

        <!-- Botones de acción (solo visibles en modo edición) -->
        <div id="edit-actions" class="hidden space-y-3 mb-6">
            <button onclick="saveClientInfo()" 
                    class="w-full px-4 py-2.5 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-sm font-semibold flex items-center justify-center gap-2">
                <span class="material-icons-outlined text-sm">check</span>
                Guardar Cambios
            </button>
            <button onclick="cancelEdit()" 
                    class="w-full px-4 py-2.5 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition text-sm font-semibold">
                Cancelar
            </button>
        </div>

        <!-- Resto del panel (ubicación, transferir, cerrar) -->
        <div class="mb-6">
            <h4 class="text-xs text-gray-500 uppercase font-semibold mb-2">Ubicación aproximada</h4>
            <p class="text-xs text-gray-500 mb-2">
                Basado en IP o geolocalización (si está disponible).
            </p>
            <p class="text-sm text-gray-800 mb-2">
                <?php
                $ciudad = isset($chat_activo['ciudad']) ? $chat_activo['ciudad'] : null;
                $pais   = isset($chat_activo['pais'])   ? $chat_activo['pais']   : null;
                if ($ciudad || $pais) {
                    echo htmlspecialchars(trim($ciudad . ', ' . $pais, ', '));
                } else {
                    echo 'Ubicación no disponible';
                }
                ?>
            </p>
            <?php
            $tieneUbicacion = isset($chat_activo['latitud'], $chat_activo['longitud']) &&
                              $chat_activo['latitud']  !== null && $chat_activo['longitud']  !== null &&
                              $chat_activo['latitud']  !== ''   && $chat_activo['longitud']  !== '';
            ?>
            <?php if ($tieneUbicacion): ?>
                <div id="mapa-ubicacion" class="w-full h-40 rounded-lg border border-gray-200 overflow-hidden"></div>
            <?php else: ?>
                <div class="w-full h-24 rounded-lg border border-dashed border-gray-300 flex items-center justify-center text-xs text-gray-400">
                    Sin coordenadas almacenadas
                </div>
            <?php endif; ?>

            <button onclick="openTransferModal()" 
                    class="w-full px-4 py-2.5 mb-3 mt-4 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition text-sm font-semibold">
                Transferir Chat
            </button>

            <button onclick="cerrarChat()" 
        class="w-full px-4 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition text-sm font-semibold flex items-center justify-center gap-2">
    <span class="material-icons-outlined">close</span>
    Cerrar Chat
</button>
        </div>
    </div>
</div>
        <?php else: ?>
        <!-- Sin chat seleccionado -->
        <div class="flex-1 flex items-center justify-center bg-gray-50">
            <div class="text-center">
                <span class="material-icons-outlined text-gray-300 text-6xl mb-4">chat</span>
                <h3 class="text-lg font-semibold text-gray-600 mb-2">Selecciona un chat</h3>
                <p class="text-sm text-gray-500">O espera a que llegue un nuevo cliente</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- MODAL: Chats pendientes -->
    <div id="pendingChatsModal"
         class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 hidden">
        <div class="bg-white w-full max-w-md rounded-xl shadow-xl p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold text-gray-800">Chats pendientes</h2>
                <button onclick="closePendingChatsModal()" class="text-gray-500 hover:text-gray-700">
                    <span class="material-icons-outlined">close</span>
                </button>
            </div>
            <div id="pending-chats-list" class="space-y-3 max-h-96 overflow-y-auto">
                <p class="text-gray-500 text-sm">Buscando chats...</p>
            </div>
        </div>
    </div>

    <!-- MODAL: Transferir chat -->
    <div id="transferChatModal"
         class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 hidden">
        <div class="bg-white w-full max-w-md rounded-xl shadow-xl p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold text-gray-800">Transferir chat</h2>
                <button onclick="closeTransferModal()" class="text-gray-500 hover:text-gray-700">
                    <span class="material-icons-outlined">close</span>
                </button>
            </div>
            <p class="text-gray-600 text-sm mb-3">Selecciona el asesor al que deseas transferir este chat:</p>
            <div id="asesores-transfer-list" class="space-y-3 max-h-96 overflow-y-auto">
                <p class="text-gray-500 text-sm">Cargando asesores...</p>
            </div>
        </div>
    </div>

    <!-- Contenedor de notificaciones -->
    <div id="notificaciones-container" class="fixed top-20 right-6 z-50 space-y-3"></div>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script>
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(400px); opacity: 0; }
        }
    `;
    document.head.appendChild(style);

    const chatId   = <?php echo $chat_activo_id ?? 'null'; ?>;

// ================================
// ESTADO GLOBAL DE NOTIFICACIONES
// ================================
let activeNotifications = new Map();
let knownPendingChats = new Set();
let knownTransferRequests = new Set();


    const asesorId = <?php echo $asesor_id; ?>;
    let lastMessageId = <?php

    $lastId = 0;

    if (!empty($mensajes) && $chat_activo_id) {
        foreach ($mensajes as $m) {
            if ((int)$m['chat_id'] === (int)$chat_activo_id) {
                $lastId = max($lastId, (int)$m['id']);
            }
        }
    }

    echo $lastId;
?>;

    const clientLat = <?php 
        echo ($chat_activo && isset($chat_activo['latitud']) && $chat_activo['latitud'] !== '' && $chat_activo['latitud'] !== null)
            ? floatval($chat_activo['latitud'])
            : 'null';
    ?>;
    const clientLng = <?php 
        echo ($chat_activo && isset($chat_activo['longitud']) && $chat_activo['longitud'] !== '' && $chat_activo['longitud'] !== null)
            ? floatval($chat_activo['longitud'])
            : 'null';
    ?>;
    const clientCity    = <?php echo json_encode($chat_activo['ciudad'] ?? null); ?>;
    const clientCountry = <?php echo json_encode($chat_activo['pais'] ?? null); ?>;

    let clientMapInitialized = false;
    let clientMap = null;

    function initClientMap() {
        if (clientMapInitialized) return;
        if (!window.L || !clientLat || !clientLng) return;

        const mapDiv = document.getElementById('mapa-ubicacion');
        if (!mapDiv) return;

        clientMap = L.map(mapDiv).setView([clientLat, clientLng], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(clientMap);

        const label = (clientCity || clientCountry) 
            ? `${clientCity ? clientCity + ', ' : ''}${clientCountry ? clientCountry : ''}`
            : 'Ubicación aproximada';

        L.marker([clientLat, clientLng]).addTo(clientMap)
            .bindPopup(label)
            .openPopup();

        clientMapInitialized = true;
    }

    async function sendMessage() {
    if (!chatId) return;

    const input     = document.getElementById('message-input');
    const fileInput = document.getElementById('file-input');

    const message = input.value.trim();
    const file    = fileInput?.files[0];

    // --- SI HAY ARCHIVO --- //
    if (file) {
        const formData = new FormData();
        formData.append("chat_id", chatId);
        formData.append("sender", "asesor");
        formData.append("message", message || "");
        formData.append("archivo", file);

        const response = await fetch('api/messages/upload_file.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // NO llamar a addFileToChat aquí - el polling lo hará
            clearFilePreview();
            input.value = "";
            fileInput.value = "";
        } else {
            alert("Error al enviar archivo");
        }
        return;
    }

    // --- SI SOLO HAY TEXTO --- //
    if (message.length > 0) {
        const formData = new FormData();
        formData.append("chat_id", chatId);
        formData.append("sender", "asesor");
        formData.append("message", message);

        const response = await fetch('api/messages/send_message.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // NO llamar a addMessageToChat aquí - el polling lo hará
            input.value = "";
        } else {
            alert("Error al enviar mensaje");
        }
    }
}


// --------- AUTOSIZE + ENTER PARA ENVIAR (CORREGIDO) ---------

const textarea = document.getElementById('message-input');

// ✅ VALIDAR QUE EXISTA EL TEXTAREA
if (textarea) {
    // Auto-resize del textarea
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });

    // Enter para enviar (Shift+Enter para nueva línea)
    textarea.addEventListener('keypress', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
} else {
    console.warn('⚠️ No se encontró el textarea #message-input (probablemente no hay chat activo)');
}


// Función para convertir URLs en links clickeables
function linkify(text) {
    const urlRegex = /(https?:\/\/[^\s]+)/g;
    return text.replace(urlRegex, (url) => {
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800 underline">${url}</a>`;
    });
}

// Función para formatear hora en zona horaria de Buenos Aires
function getBuenosAiresTime(timestamp = null) {
    const date = timestamp ? new Date(timestamp) : new Date();
    const options = {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'America/Argentina/Buenos_Aires',
        hour12: false
    };
    return date.toLocaleString('es-AR', options);
}


// --------- BOTÓN ADJUNTAR (CORREGIDO) ---------

const attachButton = document.getElementById('attach-button');
const fileInput = document.getElementById('file-input');

if (attachButton && fileInput) {
    attachButton.addEventListener('click', () => {
        fileInput.click();
    });
} else {
    console.warn('⚠️ Botones de adjunto no disponibles (sin chat activo)');
}


// --------- AGREGAR MENSAJE DE TEXTO AL CHAT ---------

function addMessageToChat(message, sender) {
    const messagesDiv = document.getElementById('chat-messages');
    if (!messagesDiv) return;
    
    const messageDiv = document.createElement('div');
    const time = getBuenosAiresTime(); // ✅ Usar hora de Buenos Aires
    
    // Escapar HTML pero mantener links
    const escapedMessage = escapeHtml(message);
    const linkedMessage = linkify(escapedMessage);
    
    // 🔥 MENSAJE DEL SISTEMA
    if (sender === 'sistema') {
        messageDiv.className = 'flex justify-center mb-4';
        messageDiv.innerHTML = `
            <div class="px-4 py-2 bg-yellow-100 border border-yellow-300 rounded-lg shadow-sm">
                <p class="text-sm text-yellow-800 font-semibold text-center">${linkedMessage}</p>
                <span class="text-xs text-yellow-600 mt-1 block text-center">${time}</span>
            </div>
        `;
        messagesDiv.appendChild(messageDiv);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
        return;
    }
    
    // MENSAJE DEL ASESOR
    if (sender === 'asesor') {
        messageDiv.className = 'flex justify-end mb-4';
        messageDiv.innerHTML = `
            <div class="flex gap-2 max-w-[60%]">
                <div>
                    <div class="bg-blue-600 text-white rounded-2xl rounded-tr-none px-4 py-2.5 shadow-sm">
                        <p class="text-sm">${linkedMessage}</p>
                    </div>
                    <span class="text-xs text-gray-400 mt-1 mr-1 block text-right">${time}</span>
                </div>
            </div>
        `;
    } else {
        // MENSAJE DEL CLIENTE
        messageDiv.className = 'flex justify-start mb-4';
        messageDiv.innerHTML = `
            <div class="flex gap-2 max-w-[60%]">
                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center flex-shrink-0">
                    <span class="text-white text-xs font-semibold">C</span>
                </div>
                <div>
                    <div class="bg-white rounded-2xl rounded-tl-none px-4 py-2.5 shadow-sm">
                        <p class="text-sm text-gray-800">${linkedMessage}</p>
                    </div>
                    <span class="text-xs text-gray-400 mt-1 ml-1 block">${time}</span>
                </div>
            </div>
        `;
    }
    
    messagesDiv.appendChild(messageDiv);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
}


// --------- AGREGAR ARCHIVO / AUDIO AL CHAT ---------

function addFileToChat(file) {
    if (!file || !file.url || !file.name) return;

    const messagesDiv = document.getElementById('chat-messages');
    if (!messagesDiv) return;

    const time = getBuenosAiresTime(); // ⏰ Hora Buenos Aires
    const ext  = file.name.split('.').pop().toLowerCase();

    const isAudio = /\.(mp3|wav|ogg|m4a|webm)$/i.test(file.name);

    let icon = '📄';
    if (['png','jpg','jpeg','webp'].includes(ext)) icon = '🖼️';
    else if (ext === 'pdf') icon = '📕';
    else if (['xls','xlsx'].includes(ext)) icon = '📊';

    const sizeStr = formatFileSize(file.size);
    const linkedText = file.text ? linkify(escapeHtml(file.text)) : '';

    function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
}

    const div = document.createElement('div');

    // 👉 Determinar emisor
    const isAsesor = file.sender === 'asesor' || !file.sender;
    div.className = isAsesor ? 'flex justify-end mb-4' : 'flex justify-start mb-4';

    // =====================================================
    // 🎧 AUDIO INLINE
    // =====================================================
    if (isAudio) {
        div.innerHTML = `
            <div class="flex gap-2 max-w-[60%]">
                ${!isAsesor ? `
                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center flex-shrink-0">
                    <span class="text-white text-xs font-semibold">C</span>
                </div>` : ``}

                <div>
                    <div class="${isAsesor
                        ? 'bg-blue-600 text-white rounded-2xl rounded-tr-none'
                        : 'bg-white text-gray-800 rounded-2xl rounded-tl-none'
                    } px-4 py-2.5 shadow-sm">

                        ${linkedText ? `<p class="text-sm mb-2">${linkedText}</p>` : ''}

                        <div class="audio-player">
                            <button class="audio-player-btn" onclick="toggleAudio(this)">▶</button>
                            <audio src="${file.url}"></audio>
                            <div class="audio-player-progress">
                                <div class="audio-player-progress-bar"></div>
                            </div>
                            <span class="audio-player-time">00:00 / 00:00</span>
                        </div>
                    </div>

                    <span class="text-xs text-gray-400 mt-1 block ${isAsesor ? 'text-right mr-1' : 'ml-1'}">
                        ${time}
                    </span>
                </div>
            </div>
        `;

        messagesDiv.appendChild(div);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
        return;
    }

    // =====================================================
    // 📎 ARCHIVO NORMAL
    // =====================================================
    if (isAsesor) {
        div.innerHTML = `
            <div class="flex gap-2 max-w-[60%]">
                <div>
                    <div class="bg-blue-600 text-white rounded-2xl rounded-tr-none px-4 py-2.5 shadow-sm">
                        ${linkedText ? `<p class="text-sm mb-2">${linkedText}</p>` : ""}
                        <a href="${file.url}" target="_blank"
                           class="inline-flex items-center gap-2 px-3 py-2 bg-blue-700 rounded-lg hover:bg-blue-800 transition">
                            <span style="font-size:22px">${icon}</span>
                            <div class="text-left">
                                <div class="text-xs font-semibold text-white">${escapeHtml(file.name)}</div>
                                <div class="text-xs text-blue-200">${sizeStr}</div>
                            </div>
                        </a>
                    </div>
                    <span class="text-xs text-gray-400 mt-1 mr-1 block text-right">${time}</span>
                </div>
            </div>
        `;
    } else {
        div.innerHTML = `
            <div class="flex gap-2 max-w-[60%]">
                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center flex-shrink-0">
                    <span class="text-white text-xs font-semibold">C</span>
                </div>
                <div>
                    <div class="bg-white rounded-2xl rounded-tl-none px-4 py-2.5 shadow-sm">
                        ${linkedText ? `<p class="text-sm text-gray-800 mb-2">${linkedText}</p>` : ""}
                        <a href="${file.url}" target="_blank"
                           class="inline-flex items-center gap-2 px-3 py-2 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 transition">
                            <span style="font-size:22px">${icon}</span>
                            <div class="text-left">
                                <div class="text-xs font-semibold text-blue-700">${escapeHtml(file.name)}</div>
                                <div class="text-xs text-gray-500">${sizeStr}</div>
                            </div>
                        </a>
                    </div>
                    <span class="text-xs text-gray-400 mt-1 ml-1 block">${time}</span>
                </div>
            </div>
        `;
    }

    messagesDiv.appendChild(div);
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
}

// --------- ESCAPAR HTML ---------
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// --------- POLLING MENSAJES NUEVOS ---------
if (chatId) {
    setInterval(async () => {
        try {
            const response = await fetch(
                `api/messages/get_messages.php?chat_id=${chatId}&last_id=${lastMessageId}`
            );
            const data = await response.json();

            if (!data.success || data.data.messages.length === 0) return;

            data.data.messages.forEach(msg => {

                if (
                    msg.sender === 'cliente' &&
                    msg.message &&
                    msg.message.trim().toLowerCase() === 'termino la conversacion'
                ) {
                    bloquearChat();
                    mostrarMensajeFinalizado();
                }

                if (msg.tiene_archivo && msg.archivo) {
                    addFileToChat({
                        url: msg.archivo.url,
                        name: msg.archivo.nombre,
                        size: msg.archivo.tamano,
                        text: msg.message || "",
                        sender: msg.sender
                    });
                } else {
                    // 🔥 MANEJO DE TODOS LOS TIPOS DE MENSAJES
                    if (msg.sender === 'sistema') {
                        addMessageToChat(msg.message, 'sistema');
                    } else if (msg.sender === 'cliente') {
                        addMessageToChat(msg.message, 'cliente');
                    } else if (msg.sender === 'asesor') {
                        addMessageToChat(msg.message, 'asesor');
                    } else if (msg.sender === 'bot') {
                        addMessageToChat(msg.message, 'asesor');  // ← BOT a la DERECHA como asesor
                    }
                }

                lastMessageId = Math.max(lastMessageId, msg.id);
            });

        } catch (error) {
            console.error('Error polling mensajes:', error);
        }
    }, 2000);
}

// Solo inicializar funcionalidades si hay chat activo
const hasChatActivo = chatId !== null;

if (!hasChatActivo) {
    console.info('ℹ️ Sin chat activo - modo solo lectura');
}

// Scroll inicial
const messagesDiv = document.getElementById('chat-messages');
if (messagesDiv) {
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
}

  async function cerrarChat() {
    if (!chatId) {
        alert('No hay chat activo');
        return;
    }

    if (!confirm('¿Estás seguro de cerrar este chat?')) return;

    try {
        // Cerrar el chat (el mensaje del sistema se crea en el backend)
        const response = await fetch('api/chat/close_chat_asesor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                chat_id: chatId,
                asesor_id: asesorId
            })
        });

        const data = await response.json();
        
        if (data.success) {
            alert('Chat cerrado correctamente');
            window.location.href = 'panel_asesor.php';
        } else {
            alert('Error al cerrar el chat: ' + (data.message || 'Error desconocido'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error de conexión al cerrar el chat');
    }
}




// POLLING TRANSFERENCIAS DE CHAT (recibidas)
setInterval(async () => {
    try {
        const resp = await fetch(
            `api/asesores/get_transfer_requests.php?asesor_id=${asesorId}`
        );
        const data = await resp.json();

        if (!data.success) return;

        data.data.forEach(t => showTransferNotification(t));

    } catch (e) {
        console.error("Error polling transferencias", e);
    }
}, 3000);

function showTransferNotification(t) {
    if (document.getElementById("transfer-" + t.id)) return;

    const notif = document.createElement("div");
    notif.id = "transfer-" + t.id;
    notif.className = "toast-notification bg-yellow-50 border-l-4 border-yellow-500 p-4 rounded-xl shadow";

    notif.innerHTML = `
        <div class="flex items-start gap-3">
            <span class="material-icons-outlined text-yellow-600">swap_horiz</span>
            <div class="flex-1">
                <h4 class="font-bold text-sm text-gray-800">Transferencia de chat</h4>
                <p class="text-xs text-gray-600 mt-1">
                    ${escapeHtml(t.asesor_origen_nombre)} te transfiere el chat con
                    <b>${escapeHtml(t.cliente_nombre)}</b>
                </p>
                <div class="flex gap-2 mt-2">
                    <button onclick="acceptTransfer(${t.id})"
                        class="px-3 py-1 bg-green-600 text-white rounded text-xs font-semibold">
                        Aceptar
                    </button>
                    <button onclick="rejectTransfer(${t.id})"
                        class="px-3 py-1 bg-red-600 text-white rounded text-xs font-semibold">
                        Rechazar
                    </button>
                </div>
            </div>
        </div>
    `;

    document.getElementById("notificaciones-container").appendChild(notif);
}

// =====================================================
// 🔔 SISTEMA DE NOTIFICACIONES - SOLO UNA A LA VEZ
// =====================================================

function showNewChatNotification(chat, isFirst) {
    const container = document.getElementById('notificaciones-container');
    const esReferido = chat.es_referido == 1;
    const esClienteAprobado = chat.cliente_validado_aprobado == 1 || chat.tipo_notificacion === 'cliente_preferencial';
    
    // ✅ ELIMINAR TODAS LAS NOTIFICACIONES ANTERIORES DE CHATS
    const existingChatNotifications = container.querySelectorAll('[id^="notif-"]');
    existingChatNotifications.forEach(notif => {
        notif.style.animation = 'slideOut 0.3s forwards';
        setTimeout(() => notif.remove(), 300);
    });
    
    // Limpiar registros de chats pendientes previos (pero mantener la marca del actual)
    const currentChatId = chat.id;
    knownPendingChats.forEach(id => {
        if (id !== currentChatId) {
            activeNotifications.delete(id);
        }
    });
    
    // Si ya existe esta notificación específica después de la limpieza, no duplicar
    if (document.getElementById('notif-' + chat.id)) return;

    // ✅ CORREGIDO: Cliente aprobado siempre puede aceptar
    const puedeAceptar = chat.puede_aceptar_siempre || isFirst;
    const isLocked = !puedeAceptar;

    // 🔥 Determinar color según tipo
    let borderColor, bgColor, iconColor, icon, titulo, badge;
    
    if (esClienteAprobado) {
        // 🔥 CLIENTE PREFERENCIAL - VERDE
        borderColor = 'border-green-600';
        bgColor = 'bg-gradient-to-r from-green-50 to-white';
        iconColor = 'text-green-600';
        icon = 'verified_user';
        titulo = '⭐ Cliente Preferencial';
        badge = '<span class="referido-badge" style="background:#10b981; animation: pulse 2s infinite;">APROBADO</span>';
    } else if (esReferido) {
        // REFERIDO - VERDE ORIGINAL
        borderColor = 'border-green-500';
        bgColor = 'referido-notification';
        iconColor = 'text-green-600';
        icon = 'verified';
        titulo = '💚 Chat Referido Directo';
        badge = '<span class="referido-badge">REFERIDO</span>';
    } else {
        // NORMAL - AZUL
        borderColor = 'border-blue-600';
        bgColor = 'bg-white';
        iconColor = 'text-blue-600';
        icon = 'notifications_active';
        titulo = '🔔 Nuevo chat disponible';
        badge = '';
    }

    const notif = document.createElement('div');
    notif.id = 'notif-' + chat.id;
    notif.className = `
        toast-notification 
        shadow-2xl rounded-xl p-4 
        max-w-sm
        border-l-4 ${borderColor} ${bgColor}
    `;

    notif.innerHTML = `
        <div class="flex items-start gap-3">
            <span class="material-icons-outlined ${iconColor} pulse-icon">
                ${icon}
            </span>

            <div class="flex-1">
                <h4 class="font-bold text-sm text-gray-800 flex items-center gap-2">
                    ${titulo}
                    ${badge}
                </h4>

                <p class="text-xs text-gray-600 mt-1">
                    ${escapeHtml(chat.cliente_nombre)} - ${escapeHtml(chat.departamento_nombre)}
                </p>

                <p class="text-xs text-gray-500 mt-1">
                    📅 ${new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })}
                </p>

                <div id="notif-actions-${chat.id}" class="mt-3">
                    ${
                        !isLocked
                        ? `<button onclick="acceptChat(${chat.id})"
                                class="w-full px-3 py-2 
                                ${esClienteAprobado 
                                    ? 'bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 shadow-lg' 
                                    : esReferido 
                                    ? 'bg-green-600 hover:bg-green-700' 
                                    : 'bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700'}
                                text-white rounded-lg text-sm font-semibold transition shadow-md flex items-center justify-center gap-2">
                            <span class="material-icons-outlined text-sm">check_circle</span>
                            ${esClienteAprobado ? 'Aceptar Cliente VIP' : 'Aceptar Chat'}
                           </button>`
                        : `<button disabled
                                class="w-full px-3 py-2 bg-gray-300 text-gray-500 rounded-lg text-sm font-semibold cursor-not-allowed">
                            En espera
                           </button>`
                    }
                </div>
            </div>

            <button onclick="dismissNotification(${chat.id})"
                    class="text-gray-400 hover:text-gray-600 transition">
                <span class="material-icons-outlined text-sm">close</span>
            </button>
        </div>
    `;

    // ✅ SIEMPRE agregar al principio (arriba de todo)
    container.prepend(notif);
    
    activeNotifications.set(chat.id, notif.id);

    // 🔊 Sonido para clientes preferenciales, referidos o primer chat
    if (esClienteAprobado || esReferido || isFirst) {
        playNotificationSound();
    }
}


// ========================================
// ❌ CERRAR / DESCARTAR NOTIFICACIÓN
// ========================================
function dismissNotification(chatId) {
    const notifId = activeNotifications.get(chatId);
    const notif = document.getElementById(notifId || ('notif-' + chatId));

    if (notif) {
        notif.style.animation = 'slideOut 0.3s forwards';
        setTimeout(() => notif.remove(), 300);
    }

    activeNotifications.delete(chatId);
    knownPendingChats.delete(chatId);
}


// =====================================================
// 🔁 POLLING DE CHATS PENDIENTES (NOTIFICACIONES)
// =====================================================
setInterval(async () => {
    try {
        const resp = await fetch(
            `api/asesores/get_pending_chats.php?asesor_id=${asesorId}`
        );
        const data = await resp.json();

        if (!data.success) return;

        const chats = data.data.chats;

        // ✅ ACTUALIZAR BOTÓN DE CHATS PENDIENTES
        const button = document.getElementById('pending-chats-button');
        if (button) {
            if (chats.length > 0) {
                // 🔥 Contar clientes preferenciales
                const preferenciales = chats.filter(c => 
                    c.cliente_validado_aprobado == 1 || c.tipo_notificacion === 'cliente_preferencial'
                ).length;
                
                if (preferenciales > 0) {
                    button.textContent = `⭐ ${preferenciales} VIP de ${chats.length} pendientes`;
                    button.className = 'px-3 py-1 bg-green-600 text-white rounded-full text-sm font-semibold hover:bg-green-700 transition shadow-lg animate-pulse';
                } else {
                    button.textContent = `${chats.length} chat${chats.length > 1 ? 's' : ''} pendiente${chats.length > 1 ? 's' : ''}`;
                    button.className = 'px-3 py-1 bg-blue-600 text-white rounded-full text-sm font-semibold hover:bg-blue-700 transition';
                }
            } else {
                button.textContent = 'Sin chats pendientes';
                button.className = 'px-3 py-1 bg-gray-300 text-gray-800 rounded-full text-sm font-semibold hover:bg-gray-400 transition';
            }
        }

        // ✅ MOSTRAR SOLO EL CHAT CON MAYOR PRIORIDAD
        if (chats.length > 0) {
            // El primer chat ya viene ordenado por prioridad desde el backend
            const chat = chats[0];
            
            // Si no lo conocíamos antes, mostrar notificación
            if (!knownPendingChats.has(chat.id)) {
                knownPendingChats.add(chat.id);
                showNewChatNotification(chat, true);
            }
        }

    } catch (e) {
        console.error("❌ Error polling chats pendientes:", e);
    }
}, 3000);

// ===================================================================
// ACEPTAR CHAT - CON VALIDACIÓN DE DISPONIBILIDAD
// ===================================================================

async function acceptChat(chatIdToAccept) {
    // Deshabilitar temporalmente el botón
    const notif = document.getElementById('notif-' + chatIdToAccept);
    if (notif) {
        const button = notif.querySelector('button:not([onclick*="dismiss"])');
        if (button) {
            button.disabled = true;
            button.innerHTML = '<span class="material-icons-outlined text-sm animate-spin">hourglass_empty</span> Aceptando...';
        }
    }

    try {
        const response = await fetch('api/asesores/accept_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                chat_id: chatIdToAccept,
                asesor_id: asesorId
            })
        });

        const data = await response.json();

        if (data.success) {
            // ✅ Chat aceptado exitosamente
            dismissNotification(chatIdToAccept);
            activeNotifications.delete(chatIdToAccept);
            knownPendingChats.delete(chatIdToAccept);
            
            // Mostrar mensaje de éxito
            showSuccessToast('Chat aceptado correctamente');
            
            // Redirigir al chat
            window.location.href = `panel_asesor.php?chat_id=${chatIdToAccept}`;
        } else {
            // ❌ El chat ya fue tomado por otro asesor
            dismissNotification(chatIdToAccept);
            activeNotifications.delete(chatIdToAccept);
            knownPendingChats.delete(chatIdToAccept);
            
            // Mostrar mensaje informativo
            showInfoToast(data.message || 'El chat ya fue aceptado por otro asesor');
        }

    } catch (error) {
        console.error('Error:', error);
        
        // Restaurar botón en caso de error
        if (notif) {
            const button = notif.querySelector('button:not([onclick*="dismiss"])');
            if (button) {
                button.disabled = false;
                button.innerHTML = '<span class="material-icons-outlined text-sm">check_circle</span> Aceptar Chat';
            }
        }
        
        alert('Error al aceptar el chat');
    }
}

// ===================================================================
// REPRODUCIR SONIDO DE NOTIFICACIÓN
// ===================================================================

function playNotificationSound() {
    try {
        // Sonido simple de notificación
        const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBSuCzvLbjzgIG2S59+qSP');
        audio.volume = 0.5;
        audio.play().catch(e => console.warn('No se pudo reproducir sonido:', e));
    } catch (e) {
        console.warn('Error al reproducir notificación:', e);
    }
}

async function acceptTransfer(transferId) {
    const r = await fetch("api/asesores/accept_transfer.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ transfer_id: transferId })
    });

    const d = await r.json();

    if (d.success) {
        location.reload(); // el chat aparece al nuevo asesor
    }
}

// ===================================================================
// TOASTS INFORMATIVOS
// ===================================================================

function showSuccessToast(message) {
    showToast(message, 'success');
}

function showInfoToast(message) {
    showToast(message, 'info');
}

function showToast(message, type = 'info') {
    const container = document.getElementById('notificaciones-container');
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'success' ? 'bg-green-100 border-green-500' : 'bg-blue-100 border-blue-500'} border-l-4 p-4 rounded-xl shadow-lg max-w-sm`;
    
    const icon = type === 'success' ? 'check_circle' : 'info';
    const iconColor = type === 'success' ? 'text-green-600' : 'text-blue-600';
    
    toast.innerHTML = `
        <div class="flex items-center gap-3">
            <span class="material-icons-outlined ${iconColor}">${icon}</span>
            <span class="text-sm font-semibold">${escapeHtml(message)}</span>
        </div>
    `;
    
    container.appendChild(toast);
    
    // Auto-eliminar después de 4 segundos
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s forwards';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

async function rejectTransfer(transferId) {
    await fetch("api/asesores/reject_transfer.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ transfer_id: transferId })
    });

    document.getElementById("transfer-" + transferId)?.remove();
}


// ========================================
// EDICIÓN DE DATOS DEL CLIENTE
// ========================================

let editMode = false;
let originalData = {};

function toggleEditMode() {
    if (!chatId) {
        alert('No hay chat activo');
        return;
    }

    editMode = !editMode;
    
    if (editMode) {
        // Guardar datos originales
        originalData = {
            nombre: document.getElementById('input-nombre')?.value || '',
            email: document.getElementById('input-email')?.value || '',
            telefono: document.getElementById('input-telefono')?.value || ''
        };
        
        // Mostrar campos de edición
        const viewNombre = document.getElementById('view-nombre');
        const editNombre = document.getElementById('edit-nombre');
        const viewEmail = document.getElementById('view-email');
        const editEmail = document.getElementById('edit-email');
        const viewTelefono = document.getElementById('view-telefono');
        const editTelefono = document.getElementById('edit-telefono');
        const editActions = document.getElementById('edit-actions');
        const editBtn = document.getElementById('edit-profile-btn');
        
        if (viewNombre) viewNombre.classList.add('hidden');
        if (editNombre) editNombre.classList.remove('hidden');
        
        if (viewEmail) viewEmail.classList.add('hidden');
        if (editEmail) editEmail.classList.remove('hidden');
        
        if (viewTelefono) viewTelefono.classList.add('hidden');
        if (editTelefono) editTelefono.classList.remove('hidden');
        
        if (editActions) editActions.classList.remove('hidden');
        
        // Cambiar ícono del botón
        if (editBtn) editBtn.innerHTML = '<span class="material-icons-outlined">close</span>';
    } else {
        cancelEdit();
    }
}

function cancelEdit() {
    editMode = false;
    
    // Restaurar valores originales
    const inputNombre = document.getElementById('input-nombre');
    const inputEmail = document.getElementById('input-email');
    const inputTelefono = document.getElementById('input-telefono');
    
    if (inputNombre && originalData.nombre) inputNombre.value = originalData.nombre;
    if (inputEmail && originalData.email) inputEmail.value = originalData.email;
    if (inputTelefono && originalData.telefono) inputTelefono.value = originalData.telefono;
    
    // Ocultar campos de edición
    const viewNombre = document.getElementById('view-nombre');
    const editNombre = document.getElementById('edit-nombre');
    const viewEmail = document.getElementById('view-email');
    const editEmail = document.getElementById('edit-email');
    const viewTelefono = document.getElementById('view-telefono');
    const editTelefono = document.getElementById('edit-telefono');
    const editActions = document.getElementById('edit-actions');
    const editBtn = document.getElementById('edit-profile-btn');
    
    if (viewNombre) viewNombre.classList.remove('hidden');
    if (editNombre) editNombre.classList.add('hidden');
    
    if (viewEmail) viewEmail.classList.remove('hidden');
    if (editEmail) editEmail.classList.add('hidden');
    
    if (viewTelefono) viewTelefono.classList.remove('hidden');
    if (editTelefono) editTelefono.classList.add('hidden');
    
    if (editActions) editActions.classList.add('hidden');
    
    // Restaurar ícono
    if (editBtn) editBtn.innerHTML = '<span class="material-icons-outlined">edit</span>';
}

async function saveClientInfo() {
    const nombre = document.getElementById('input-nombre')?.value.trim() || '';
    const email = document.getElementById('input-email')?.value.trim() || '';
    const telefono = document.getElementById('input-telefono')?.value.trim() || '';
    
    if (!nombre || !email || !telefono) {
        alert('Todos los campos son obligatorios');
        return;
    }
    
    // Validar email
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        alert('Email inválido');
        return;
    }
    
    try {
        const response = await fetch('api/chat/update_client_info.php', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                chat_id: chatId,
                nombre: nombre,
                email: email,
                telefono: telefono
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Actualizar vista
            const viewNombre = document.getElementById('view-nombre');
            const viewEmail = document.getElementById('view-email');
            const viewTelefono = document.getElementById('view-telefono');
            
            if (viewNombre) viewNombre.textContent = nombre;
            if (viewEmail) viewEmail.textContent = email;
            if (viewTelefono) viewTelefono.textContent = telefono;
            
            // Salir del modo edición
            cancelEdit();
            
            alert('Datos actualizados correctamente');
        } else {
            alert(data.message || 'Error al guardar los cambios');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error de conexión al guardar los datos');
    }
}
    // Tecla ESC para salir de un chat
    (function() {
        document.addEventListener("keydown", function(event) {
            if (event.key === "Escape") {
                const url = new URL(window.location.href);
                if (url.searchParams.has("chat_id")) {
                    url.searchParams.delete("chat_id");
                    window.location.href = url.pathname;
                }
            }
        });
    })();

    // =====================================================
// 📋 MODAL: CHATS PENDIENTES (SIN DUPLICADOS)
// =====================================================

function openPendingChatsModal() {
    document.getElementById("pendingChatsModal").classList.remove("hidden");
    loadPendingChatsModal();
}

function closePendingChatsModal() {
    document.getElementById("pendingChatsModal").classList.add("hidden");
}

async function loadPendingChatsModal() {
    const container = document.getElementById("pending-chats-list");
    container.innerHTML = `<p class="text-gray-500 text-sm">Cargando...</p>`;

    try {
        const response = await fetch(`api/asesores/get_pending_chats.php?asesor_id=${asesorId}`);
        const data = await response.json();

        if (!data.success || data.data.chats.length === 0) {
            container.innerHTML = `<p class="text-gray-500 text-sm">No hay chats pendientes.</p>`;
            return;
        }

        container.innerHTML = "";

        // ✅ FILTRAR DUPLICADOS POR CLIENTE (nombre + departamento)
        const chatsFiltrados = [];
        const clientesVistos = new Set();

        data.data.chats.forEach(chat => {
            // Crear clave única: nombre + departamento
            const claveCliente = `${chat.cliente_nombre}_${chat.departamento_nombre}`;
            
            // Si no lo hemos visto antes, agregarlo
            if (!clientesVistos.has(claveCliente)) {
                clientesVistos.add(claveCliente);
                chatsFiltrados.push(chat);
            }
        });

        // ✅ Mostrar chats únicos con prioridad a clientes aprobados
        chatsFiltrados.forEach((chat, index) => {
            const item = document.createElement("div");
            
            // 🔥 Determinar si es cliente aprobado
            const esClienteAprobado = chat.cliente_validado_aprobado == 1 || chat.tipo_notificacion === 'cliente_preferencial';
            const esReferido = chat.es_referido == 1;
            
            // Estilo especial para clientes aprobados
            let itemClass = 'border p-3 rounded-lg flex justify-between items-center';
            if (esClienteAprobado) {
                itemClass = 'border-2 border-green-400 p-3 rounded-lg flex justify-between items-center bg-gradient-to-r from-green-50 to-white shadow-md';
            } else if (esReferido) {
                itemClass = 'border-2 border-green-300 p-3 rounded-lg flex justify-between items-center bg-green-50';
            }
            
            item.className = itemClass;

            // Cliente aprobado siempre puede ser aceptado
            const puedeAceptar = chat.puede_aceptar_siempre || index === 0;
            const isLocked = !puedeAceptar;

            item.innerHTML = `
                <div>
                    <p class="font-semibold text-gray-800 flex items-center gap-2">
                        ${esClienteAprobado ? '⭐ ' : ''}${escapeHtml(chat.cliente_nombre)}
                        ${esClienteAprobado ? '<span class="text-[10px] bg-green-600 text-white px-2 py-0.5 rounded-full font-bold animate-pulse">✓ VIP</span>' : ''}
                        ${esReferido && !esClienteAprobado ? '<span class="text-[10px] bg-green-500 text-white px-2 py-0.5 rounded-full font-bold">REFERIDO</span>' : ''}
                    </p>
                    <p class="text-xs text-gray-500">${escapeHtml(chat.departamento_nombre)}</p>
                    ${esClienteAprobado ? '<p class="text-xs text-green-600 font-semibold mt-1">🎯 Cliente aprobado - Prioridad máxima</p>' : ''}
                </div>

                ${
                    isLocked
                    ? `<button disabled
                             class="px-3 py-1.5 bg-gray-300 text-gray-500 rounded-lg text-xs font-semibold cursor-not-allowed">
                           En espera
                       </button>`
                    : `<button onclick="acceptChat(${chat.id})"
                               class="px-3 py-1.5 ${esClienteAprobado ? 'bg-green-600 hover:bg-green-700' : 'bg-blue-600 hover:bg-blue-700'} text-white rounded-lg text-xs font-semibold transition shadow-md">
                           ${esClienteAprobado ? 'Aceptar VIP' : 'Aceptar'}
                       </button>`
                }
            `;

            container.appendChild(item);
        });

    } catch (error) {
        container.innerHTML = `<p class='text-red-500 text-sm'>Error al cargar chats</p>`;
    }
}

    // POLLING ESTADO DE CHATS (contadores de no leídos)
    setInterval(async () => {
        try {
            const response = await fetch("api/asesores/get_chats_status.php?asesor_id=" + asesorId);
            const data = await response.json();

            if (!data.success) return;

            const chatsStatus = data.data;

            chatsStatus.forEach(status => {
                const chatItem = document.querySelector(`.chat-item[href='?chat_id=${status.id}']`);
                if (!chatItem) return;

                let badge = chatItem.querySelector(".unread-badge");

                if (!badge) {
                    badge = document.createElement("div");
                    badge.className = "unread-badge flex-shrink-0 min-w-[20px] px-2 py-0.5 bg-blue-600 text-white text-xs font-bold rounded-full text-center ml-2";
                    const flexContainer = chatItem.querySelector(".flex.items-start");
                    if (flexContainer) {
                        flexContainer.appendChild(badge);
                    }
                }

                if (status.mensajes_nuevos > 0) {
                    badge.textContent = status.mensajes_nuevos;
                    badge.classList.remove("hidden");
                } else {
                    badge.classList.add("hidden");
                }
            });

        } catch (err) {
            console.error("Error actualizando contadores:", err);
        }

    }, 1000);

    // MODAL: Transferir chat
    function openTransferModal() {
        document.getElementById("transferChatModal").classList.remove("hidden");
        loadAsesoresForTransfer();
    }

    function closeTransferModal() {
        document.getElementById("transferChatModal").classList.add("hidden");
    }

    async function loadAsesoresForTransfer() {
        const container = document.getElementById("asesores-transfer-list");
        container.innerHTML = `<p class="text-gray-500 text-sm">Cargando asesores...</p>`;

        try {
            const response = await fetch(`api/asesores/list_asesores.php?asesor_id=${asesorId}`);
            const data = await response.json();

            if (!data.success) {
                container.innerHTML = `<p class="text-red-500">No hay asesores disponibles</p>`;
                return;
            }

            const asesores = data.data;
            container.innerHTML = "";

            if (asesores.length === 0) {
                container.innerHTML = `<p class="text-gray-500">No hay asesores disponibles.</p>`;
                return;
            }

            asesores.forEach(a => {
                const item = document.createElement("div");
                item.className = "border p-3 rounded-lg flex justify-between items-center";

                item.innerHTML = `
                    <div>
                        <p class="font-semibold text-gray-800">${escapeHtml(a.nombre)}</p>
                        <p class="text-xs text-gray-500">${escapeHtml(a.departamentos)}</p>
                    </div>
                    <button onclick="transferChat(${a.id})"
                            class="px-3 py-1.5 bg-yellow-500 text-white rounded-lg text-xs font-semibold hover:bg-yellow-600 transition">
                        Transferir
                    </button>
                `;

                container.appendChild(item);
            });

        } catch (error) {
            container.innerHTML = `<p class="text-red-500">Error al cargar asesores</p>`;
        }
    }

    async function transferChat(asesorDestino) {
    if (!confirm("¿Enviar solicitud de transferencia?")) return;

    const response = await fetch("api/asesores/request_transfer.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            chat_id: chatId,
            asesor_destino: asesorDestino
        })
    });

    const data = await response.json();

    if (data.success) {
        alert("Solicitud enviada. Esperando aceptación del asesor.");
        closeTransferModal();
    } else {
        alert(data.message || "Error al enviar solicitud");
    }
}


    // Bloquear input si cliente terminó conversación
    function bloquearChat() {
        const textarea = document.getElementById("message-input");
        const btn = document.querySelector("button[onclick='sendMessage()']");

        if (textarea) {
            textarea.disabled = true;
            textarea.classList.add("bg-gray-200", "cursor-not-allowed");
            textarea.placeholder = "El cliente finalizó la conversación";
        }

        if (btn) {
            btn.disabled = true;
            btn.classList.add("opacity-50", "cursor-not-allowed");
        }
    }

    function mostrarMensajeFinalizado() {
        const messagesDiv = document.getElementById("chat-messages");

        const aviso = document.createElement("div");
        aviso.className = "text-center my-4";
        aviso.innerHTML = `
            <span class="px-4 py-2 bg-yellow-200 text-yellow-800 rounded-lg text-sm font-semibold">
                El usuario finalizó la conversación
            </span>
        `;

        messagesDiv.appendChild(aviso);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }


// --- VISTA PREVIA DE ARCHIVOS --- //
const fileInputPreview = document.getElementById("file-input");
const previewBox = document.getElementById("file-preview");

if (attachButton && fileInputPreview) {
    attachButton.addEventListener("click", () => {
        fileInputPreview.click();
    });
}


if (fileInputPreview) {
    fileInputPreview.addEventListener("change", function () {
        const file = this.files[0];

        if (!file) {
            previewBox.classList.add("hidden");
            previewBox.innerHTML = "";
            return;
        }

        const ext = file.name.split(".").pop().toLowerCase();
        const sizeKB = (file.size / 1024).toFixed(1) + " KB";

        let previewHTML = "";

        if (["png", "jpg", "jpeg", "webp"].includes(ext)) {
            const imgURL = URL.createObjectURL(file);

            previewHTML = `
                <div class="file-preview-box shadow-sm">
                    <img src="${imgURL}" class="file-preview-image">
                    <div class="file-preview-info">
                        <strong>${file.name}</strong>
                        <span>${sizeKB}</span>
                    </div>
                    <button onclick="clearFilePreview()" class="ml-2 text-red-600 font-bold">×</button>
                </div>
            `;
        } else {
            const icon = ext === "pdf" ? "📕" :
                         ["xls", "xlsx"].includes(ext) ? "📊" :
                         "📄";

            previewHTML = `
                <div class="file-preview-box shadow-sm">
                    <span style="font-size:28px">${icon}</span>
                    <div class="file-preview-info">
                        <strong>${file.name}</strong>
                        <span>${sizeKB}</span>
                    </div>
                    <button onclick="clearFilePreview()" class="ml-2 text-red-600 font-bold">×</button>
                </div>
            `;
        }

        previewBox.innerHTML = previewHTML;
        previewBox.classList.remove("hidden");
    });
}


function clearFilePreview() {
    if (!fileInputPreview || !previewBox) return;

    fileInputPreview.value = "";
    previewBox.innerHTML = "";
    previewBox.classList.add("hidden");
}


    // Botón para mostrar perfil en mobile
    const showProfileBtn = document.createElement('button');
    showProfileBtn.className = 'show-profile-btn';
    showProfileBtn.innerHTML = '<span class="material-icons-outlined">person</span>';
    document.body.appendChild(showProfileBtn);

    const profileOverlay = document.createElement('div');
    profileOverlay.className = 'profile-overlay';
    document.body.appendChild(profileOverlay);

    const profilePanel = document.getElementById('profile-panel');

    showProfileBtn.addEventListener('click', () => {
        profilePanel.classList.add('show-mobile');
        profileOverlay.classList.add('active');
    });

    profileOverlay.addEventListener('click', () => {
        profilePanel.classList.remove('show-mobile');
        profileOverlay.classList.remove('active');
    });

    // Auto-resize textarea en mobile
    if (textarea && window.innerWidth <= 640) {
        textarea.style.maxHeight = '80px';
    }

    // Optimización para teclado virtual en mobile
    if (/iPhone|iPad|iPod|Android/i.test(navigator.userAgent)) {
        window.addEventListener('resize', () => {
            const vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--vh', `${vh}px`);
        });
    }

    // Fix scroll automático al abrir teclado (iOS)
    if (textarea) {
        textarea.addEventListener('focus', () => {
            setTimeout(() => {
                textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 300);
        });
    }

    function toggleAudio(button) {
    const container = button.closest('.audio-player');
    const audio = container.querySelector('audio');
    const bar = container.querySelector('.audio-player-progress-bar');
    const timeLabel = container.querySelector('.audio-player-time');

    // Pausar otros audios
    document.querySelectorAll('audio').forEach(a => {
        if (a !== audio) a.pause();
    });

    if (audio.paused) {
        audio.play();
        button.textContent = '⏸';
    } else {
        audio.pause();
        button.textContent = '▶';
    }

    audio.ontimeupdate = () => {
        const percent = (audio.currentTime / audio.duration) * 100 || 0;
        bar.style.width = percent + '%';

        timeLabel.textContent =
            formatTime(audio.currentTime) + ' / ' + formatTime(audio.duration);
    };

    audio.onended = () => {
        button.textContent = '▶';
        bar.style.width = '0%';
    };
}

function formatTime(seconds) {
    if (isNaN(seconds)) return '00:00';
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return `${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}`;
}


// =====================================================
// 🎤 GRABAR / DETENER / ENVIAR AUDIO (CORREGIDO)
// =====================================================

let mediaRecorder = null;
let recordedChunks = [];
let recordingTimer = null;
let recordingSeconds = 0;

const recordBtn = document.getElementById("record-audio-button");

// ✅ VALIDAR QUE EXISTA EL BOTÓN
if (recordBtn) {
    recordBtn.addEventListener("click", async () => {
        if (!chatId) {
            alert('No hay chat activo');
            return;
        }

        // ⏹ SI ESTÁ GRABANDO → DETENER
        if (mediaRecorder && mediaRecorder.state === "recording") {
            mediaRecorder.stop();
            recordBtn.classList.remove("recording");
            recordBtn.textContent = "🎤";
            clearInterval(recordingTimer);
            return;
        }

        // 🎤 INICIAR GRABACIÓN
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });

            recordedChunks = [];
            recordingSeconds = 0;

            mediaRecorder = new MediaRecorder(stream, {
                mimeType: "audio/webm"
            });

            mediaRecorder.ondataavailable = e => {
                if (e.data.size > 0) recordedChunks.push(e.data);
            };

            mediaRecorder.onstop = async () => {
                stream.getTracks().forEach(t => t.stop());

                const audioBlob = new Blob(recordedChunks, { type: "audio/webm" });

                const audioFile = new File(
                    [audioBlob],
                    `audio_${Date.now()}.webm`,
                    { type: "audio/webm" }
                );

                await enviarAudioGrabado(audioFile);
            };

            mediaRecorder.start();

            recordBtn.classList.add("recording");
            recordBtn.textContent = "⏹";

            recordingTimer = setInterval(() => {
                recordingSeconds++;
            }, 1000);

        } catch (error) {
            alert("No se pudo acceder al micrófono");
            console.error(error);
        }
    });
} else {
    console.warn('⚠️ Botón de grabación no disponible (sin chat activo)');
}

// =====================================================
// 📤 ENVIAR AUDIO AL BACKEND (USA upload_file.php)
// =====================================================

async function enviarAudioGrabado(audioFile) {
    if (!chatId || !audioFile) return;

    const formData = new FormData();
    formData.append("chat_id", chatId);
    formData.append("sender", "asesor");
    formData.append("message", "");
    formData.append("archivo", audioFile);

    try {
        const response = await fetch("api/messages/upload_file.php", {
            method: "POST",
            body: formData
        });

        const data = await response.json();

        if (!data.success) {
            alert("Error al enviar el audio");
        }
        // ✅ NO agregar al chat acá
        // El polling lo mostrará automáticamente

    } catch (err) {
        console.error("Error enviando audio:", err);
        alert("Error de conexión al enviar audio");
    }
}

// =====================================================
// 🚀 ACTUALIZAR BOTÓN AL CARGAR LA PÁGINA
// =====================================================
async function updatePendingChatsButton() {
    try {
        const response = await fetch(`api/asesores/get_pending_chats.php?asesor_id=${asesorId}`);
        const data = await response.json();

        const button = document.getElementById('pending-chats-button');
        if (!button) return;

        if (data.success && data.data.chats && data.data.chats.length > 0) {
            const count = data.data.chats.length;
            button.textContent = `${count} chat${count > 1 ? 's' : ''} pendiente${count > 1 ? 's' : ''}`;
            button.className = 'px-3 py-1 bg-blue-600 text-white rounded-full text-sm font-semibold hover:bg-blue-700 transition';
        } else {
            button.textContent = 'Sin chats pendientes';
            button.className = 'px-3 py-1 bg-gray-300 text-gray-800 rounded-full text-sm font-semibold hover:bg-gray-400 transition';
        }
    } catch (error) {
        console.error('Error actualizando botón:', error);
    }
}

// Ejecutar al cargar
updatePendingChatsButton();

    </script>
</body>
</html>