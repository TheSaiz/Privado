<?php
session_start();

if (!isset($_SESSION['cliente_id']) || !isset($_SESSION['cliente_email'])) {
    header('Location: login_clientes.php');
    exit;
}

require_once 'backend/connection.php';

$cliente_id = (int)$_SESSION['cliente_id'];

try {
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.nombre,
            u.apellido,
            u.email,
            cd.estado_validacion,
            cd.dni,
            cd.cuit,
            cd.banco,
            cd.cbu
        FROM usuarios u
        LEFT JOIN clientes_detalles cd ON cd.usuario_id = u.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$cliente_id]);
    $cliente_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente_info) {
        header('Location: dashboard_clientes.php');
        exit;
    }
    
    // PERMITIR ACCESO aunque no esté aprobado
    // El cliente puede chatear incluso si su documentación está pendiente

    $nombre_cliente = $cliente_info['nombre'] ?? 'Cliente';
    $cuotas_vencidas = 0;
    $noti_no_leidas  = 0;
    
    // Variables para el sidebar
    $docs_completos = ($cliente_info['estado_validacion'] === 'aprobado');

    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.estado,
            c.asesor_id,
            u.nombre AS asesor_nombre
        FROM chats c
        LEFT JOIN usuarios u ON u.id = c.asesor_id
        WHERE c.cliente_id = ?
        AND c.estado IN ('esperando_asesor', 'en_conversacion')
        ORDER BY c.id DESC
        LIMIT 1
    ");
    $stmt->execute([$cliente_id]);
    $chat_activo = $stmt->fetch(PDO::FETCH_ASSOC);

    $chat_id   = $chat_activo ? (int)$chat_activo['id'] : 0;
    $asesor_id = $chat_activo ? (int)($chat_activo['asesor_id'] ?? 0) : 0;

} catch (Exception $e) {
    error_log("Error verificando cliente: " . $e->getMessage());
    header('Location: dashboard_clientes.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mensaje'])) {
    $mensaje = trim($_POST['mensaje']);

    if ($mensaje !== '' && $chat_id > 0) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO mensajes (
                    chat_id,
                    emisor,
                    mensaje,
                    fecha
                ) VALUES (
                    ?, 'cliente', ?, NOW()
                )
            ");
            $stmt->execute([$chat_id, $mensaje]);

            $stmt = $pdo->prepare("
                UPDATE chats SET
                    ultimo_mensaje = ?,
                    fecha_ultimo_mensaje = NOW(),
                    mensajes_nuevos = mensajes_nuevos + 1
                WHERE id = ?
            ");
            $stmt->execute([$mensaje, $chat_id]);

            $pdo->commit();

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error enviando mensaje cliente: " . $e->getMessage());
        }
    }

    header("Location: hablar_con_asesor.php");
    exit;
}

$mensajes = [];
if ($chat_id > 0) {
    $stmt = $pdo->prepare("
        SELECT 
            m.id,
            m.emisor,
            m.mensaje,
            m.fecha
        FROM mensajes m
        WHERE m.chat_id = ?
        ORDER BY m.id ASC
    ");
    $stmt->execute([$chat_id]);
    $mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Hablar con un Asesor - Préstamo Líder</title>
    
    <link rel="stylesheet" href="style_clientes.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-600: #4b5563;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }

        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 50%, #6b21a8 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideUp {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .animate-fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        .animate-slide-up {
            animation: slideUp 0.4s ease-out;
        }

        .animate-pulse {
            animation: pulse 2s infinite;
        }

        .animate-bounce {
            animation: bounce 1s infinite;
        }

        .animate-spin {
            animation: spin 1s linear infinite;
        }

        .chat-container {
            max-width: 900px;
            margin: 0 auto;
            margin-left: 280px;
            height: calc(100vh - 2rem);
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 768px) {
            .chat-container {
                height: 100vh;
                margin-left: 0;
            }

            .message-bubble {
                max-width: 85%;
            }

            .chat-messages {
                padding: 1rem;
            }
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            scroll-behavior: smooth;
            padding: 1.5rem;
        }

        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }

        .chat-messages::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
        }

        .chat-messages::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 10px;
        }

        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.5);
        }

        .message {
            margin-bottom: 1rem;
            animation: fadeIn 0.3s ease-out;
        }

        .message-cliente {
            display: flex;
            justify-content: flex-end;
        }

        .message-asesor {
            display: flex;
            justify-content: flex-start;
        }

        .message-sistema {
            display: flex;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .message-bubble {
            max-width: 70%;
            padding: 0.875rem 1.125rem;
            border-radius: 1.25rem;
            position: relative;
            word-wrap: break-word;
        }

        .message-cliente .message-bubble {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-bottom-right-radius: 0.25rem;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4);
        }

        .message-asesor .message-bubble {
            background: white;
            color: var(--gray-800);
            border-bottom-left-radius: 0.25rem;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
        }

        .message-sistema .message-bubble {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            text-align: center;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.2);
        }

        .message-time {
            font-size: 0.75rem;
            opacity: 0.7;
            margin-top: 0.25rem;
        }

        .chat-input-container {
            background: white;
            border-top: 1px solid var(--gray-200);
            padding: 1rem;
        }

        .chat-input {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: 1.5rem;
            font-size: 0.9375rem;
            transition: all 0.3s;
            resize: none;
            max-height: 120px;
        }

        .chat-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 1rem;
            font-weight: 600;
            transition: all 0.3s;
            cursor: pointer;
            border: none;
            font-size: 0.9375rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.6);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: white;
            color: var(--gray-700);
            border: 2px solid var(--gray-200);
        }

        .btn-secondary:hover {
            background: var(--gray-50);
            border-color: var(--gray-300);
        }

        .btn-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            padding: 0;
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .btn-icon:hover {
            background: var(--gray-200);
            transform: scale(1.05);
        }

        .waiting-state {
            text-align: center;
            padding: 3rem 1.5rem;
        }

        .waiting-animation {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            position: relative;
        }

        .waiting-animation::before,
        .waiting-animation::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            border: 4px solid transparent;
            border-top-color: #10b981;
            animation: spin 1s linear infinite;
        }

        .waiting-animation::before {
            width: 80px;
            height: 80px;
            top: 0;
            left: 0;
        }

        .waiting-animation::after {
            width: 60px;
            height: 60px;
            top: 10px;
            left: 10px;
            border-top-color: rgba(16, 185, 129, 0.5);
            animation-duration: 0.8s;
            animation-direction: reverse;
        }

        .file-preview {
            background: rgba(255,255,255,0.1);
            border-radius: 0.75rem;
            padding: 0.75rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .file-icon {
            width: 40px;
            height: 40px;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .audio-player {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(0,0,0,0.05);
            border-radius: 0.75rem;
            margin-top: 0.5rem;
        }

        .audio-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
        }

        .audio-btn:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
        }

        .audio-progress {
            flex: 1;
            height: 4px;
            background: rgba(0,0,0,0.1);
            border-radius: 2px;
            position: relative;
            cursor: pointer;
        }

        .audio-progress-bar {
            height: 100%;
            background: var(--primary);
            border-radius: 2px;
            transition: width 0.1s;
        }

        .audio-time {
            font-size: 0.75rem;
            font-family: 'Courier New', monospace;
            color: var(--gray-600);
            min-width: 70px;
            text-align: right;
        }

        .audio-recorder {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(239, 68, 68, 0.1);
            border-radius: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .recording-pulse {
            width: 12px;
            height: 12px;
            background: var(--danger);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }

        .chat-header {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 1rem 1.5rem;
        }

        .asesor-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.125rem;
        }

        .status-indicator {
            width: 10px;
            height: 10px;
            background: var(--success);
            border-radius: 50%;
            border: 2px solid white;
            position: absolute;
            bottom: 0;
            right: 0;
        }

        .welcome-screen {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 3rem 1.5rem;
        }

        .welcome-icon {
            width: 120px;
            height: 120px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }

        .welcome-icon span {
            font-size: 4rem;
            color: #10b981;
        }

        .glass-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 1.5rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
    </style>
</head>
<body>

<?php 
$pagina_activa = 'chat';
include __DIR__ . '/sidebar_clientes.php'; 
?>

<div class="chat-container p-4">
    
    <?php if (!$chat_activo): ?>
    <div class="glass-card animate-fade-in">
        <div class="welcome-screen">
            <div class="welcome-icon animate-bounce">
                <span class="material-icons-outlined" style="font-size: 4rem;">support_agent</span>
            </div>
            
            <h1 class="text-4xl font-bold mb-4" style="font-family: 'Poppins', sans-serif; color: #1f2937;">
                ¡Hola, <?php echo htmlspecialchars($nombre_cliente); ?>! 👋
            </h1>
            
            <p class="text-lg mb-8 max-w-md mx-auto" style="color: #4b5563;">
                Estamos aquí para ayudarte. Conecta con uno de nuestros asesores especializados y resuelve todas tus dudas.
            </p>
            
            <button onclick="iniciarChat()" class="btn btn-primary text-lg px-8 py-4" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); box-shadow: 0 4px 20px rgba(16, 185, 129, 0.5);">
                <span class="material-icons-outlined">chat</span>
                <span>Iniciar Conversación</span>
            </button>
            
            <div class="mt-8 text-sm" style="color: #6b7280;">
                <p>✓ Respuesta inmediata</p>
                <p>✓ Asesores certificados</p>
                <p>✓ Atención personalizada</p>
            </div>
        </div>
    </div>

    <?php else: ?>
    <div class="glass-card flex flex-col animate-fade-in" style="height: 100%;">
        
        <div class="chat-header">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="relative">
                        <div class="asesor-avatar">
                            <?php if (!empty($chat_activo['asesor_nombre'])): ?>
                                <?php echo strtoupper(substr($chat_activo['asesor_nombre'], 0, 1)); ?>
                            <?php else: ?>
                                <span class="material-icons-outlined">support_agent</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($chat_activo['estado'] === 'en_conversacion'): ?>
                        <div class="status-indicator"></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2 class="font-bold text-gray-800">
                            <?php echo !empty($chat_activo['asesor_nombre']) ? htmlspecialchars($chat_activo['asesor_nombre']) : 'Asesor Préstamo Líder'; ?>
                        </h2>
                        <p class="text-sm text-gray-500">
                            <?php if ($chat_activo['estado'] === 'en_conversacion'): ?>
                                <span class="text-green-600">● En línea</span>
                            <?php else: ?>
                                <span class="text-yellow-600">● Esperando asesor...</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <?php if ($chat_activo['estado'] === 'en_conversacion'): ?>
                <button onclick="cerrarChat()" class="btn btn-secondary btn-icon">
                    <span class="material-icons-outlined">close</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div id="chat-messages" class="chat-messages">
            <?php if ($chat_activo['estado'] === 'esperando_asesor'): ?>
            <div class="waiting-state animate-slide-up">
                <div class="waiting-animation"></div>
                <h3 style="color: #1f2937;" class="text-xl font-bold mb-2">Buscando asesor disponible...</h3>
                <p style="color: #6b7280;">En breve un asesor se pondrá en contacto contigo</p>
            </div>
            <?php endif; ?>
        </div>

        <div class="chat-input-container">
            <div id="file-preview-container" class="hidden mb-3"></div>
            <div id="audio-recorder-container" class="hidden"></div>
            
            <div class="flex items-end gap-2">
                <textarea 
                    id="message-input" 
                    class="chat-input" 
                    placeholder="Escribe tu mensaje..."
                    rows="1"
                    <?php echo ($chat_activo['estado'] !== 'en_conversacion') ? 'disabled' : ''; ?>
                ></textarea>
                
                <input type="file" id="file-input" class="hidden" 
                       accept=".pdf,.png,.jpg,.jpeg,.webp,.xls,.xlsx,.mp3,.wav,.ogg,.m4a,.webm">
                
                <button id="attach-btn" class="btn btn-icon" 
                        <?php echo ($chat_activo['estado'] !== 'en_conversacion') ? 'disabled' : ''; ?>>
                    <span class="material-icons-outlined">attach_file</span>
                </button>
                
                <button id="audio-btn" class="btn btn-icon"
                        <?php echo ($chat_activo['estado'] !== 'en_conversacion') ? 'disabled' : ''; ?>>
                    <span class="material-icons-outlined">mic</span>
                </button>
                
                <button id="send-btn" class="btn btn-primary"
                        <?php echo ($chat_activo['estado'] !== 'en_conversacion') ? 'disabled' : ''; ?>>
                    <span class="material-icons-outlined">send</span>
                </button>
            </div>
        </div>

    </div>
    <?php endif; ?>

</div>

<script>
const CLIENTE_ID = <?php echo $cliente_id; ?>;
const CHAT_ACTIVO = <?php echo $chat_activo ? 'true' : 'false'; ?>;
const CHAT_ID = <?php echo $chat_activo ? $chat_activo['id'] : 'null'; ?>;
const CHAT_ESTADO = <?php echo $chat_activo ? '"' . $chat_activo['estado'] . '"' : 'null'; ?>;

let lastMessageId = 0;
let mediaRecorder = null;
let recordedChunks = [];
let recordingTimer = null;
let recordingSeconds = 0;

async function iniciarChat() {
    try {
        const btn = event.target.closest('button');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-icons-outlined animate-spin">hourglass_empty</span><span>Conectando...</span>';

        const response = await fetch('api/chat/start_chat_cliente.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                cliente_id: CLIENTE_ID
            })
        });

        const data = await response.json();

        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Error al iniciar el chat');
            btn.disabled = false;
            btn.innerHTML = '<span class="material-icons-outlined">chat</span><span>Iniciar Conversación</span>';
        }

    } catch (error) {
        console.error('Error:', error);
        alert('Error de conexión');
        event.target.closest('button').disabled = false;
        event.target.closest('button').innerHTML = '<span class="material-icons-outlined">chat</span><span>Iniciar Conversación</span>';
    }
}

if (CHAT_ACTIVO && CHAT_ESTADO === 'en_conversacion') {
    cargarMensajes();
    setInterval(cargarMensajes, 3000);
    
    const textarea = document.getElementById('message-input');
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
    
    textarea.addEventListener('keypress', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            enviarMensaje();
        }
    });
    
    document.getElementById('send-btn').addEventListener('click', enviarMensaje);
    document.getElementById('attach-btn').addEventListener('click', () => {
        document.getElementById('file-input').click();
    });
    document.getElementById('file-input').addEventListener('change', handleFileSelect);
    document.getElementById('audio-btn').addEventListener('click', toggleAudioRecording);
}

async function cargarMensajes() {
    try {
        const response = await fetch(`api/messages/get_messages.php?chat_id=${CHAT_ID}&last_id=${lastMessageId}`);
        const data = await response.json();

        if (data.success && data.data.messages.length > 0) {
            data.data.messages.forEach(msg => {
                agregarMensaje(msg);
                lastMessageId = Math.max(lastMessageId, msg.id);
            });
        }
    } catch (error) {
        console.error('Error cargando mensajes:', error);
    }
}

async function enviarMensaje() {
    const textarea = document.getElementById('message-input');
    const mensaje = textarea.value.trim();
    const fileInput = document.getElementById('file-input');
    const file = fileInput.files[0];

    if (!mensaje && !file) return;

    try {
        const formData = new FormData();
        formData.append('chat_id', CHAT_ID);
        formData.append('sender', 'cliente');
        formData.append('message', mensaje);
        
        if (file) {
            formData.append('archivo', file);
        }

        const endpoint = file ? 'api/messages/upload_file.php' : 'api/messages/send_message.php';
        
        const response = await fetch(endpoint, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            textarea.value = '';
            textarea.style.height = 'auto';
            fileInput.value = '';
            document.getElementById('file-preview-container').classList.add('hidden');
            document.getElementById('file-preview-container').innerHTML = '';
        } else {
            alert('Error al enviar mensaje');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error de conexión');
    }
}

function agregarMensaje(msg) {
    const container = document.getElementById('chat-messages');
    const existente = document.getElementById(`msg-${msg.id}`);
    
    if (existente) return; 

    if (msg.sender === 'sistema') {
        const div = document.createElement('div');
        div.id = `msg-${msg.id}`;
        div.className = 'message';
        div.innerHTML = `
            <div style="display: flex; justify-content: center; margin-bottom: 1rem;">
                <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 0.75rem; padding: 0.75rem 1rem; max-width: 70%; text-align: center; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.2);">
                    <p style="color: #92400e; font-weight: 600; font-size: 0.875rem; margin: 0;">${escapeHtml(msg.message)}</p>
                    <div style="color: #d97706; font-size: 0.75rem; opacity: 0.8; margin-top: 0.25rem;">${new Date(msg.fecha).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })}</div>
                </div>
            </div>
        `;
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
        return;
    }

    const div = document.createElement('div');
    div.id = `msg-${msg.id}`;
    div.className = `message message-${msg.sender}`;

    const time = new Date(msg.fecha).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
    
    let contenidoExtra = '';
    
    if (msg.tiene_archivo && msg.archivo) {
        const isAudio = /\.(mp3|wav|ogg|m4a|webm)$/i.test(msg.archivo.nombre);
        
        if (isAudio) {
            contenidoExtra = `
                <div class="audio-player">
                    <button class="audio-btn" onclick="toggleAudio(this)">▶</button>
                    <audio src="${escapeHtml(msg.archivo.url)}"></audio>
                    <div class="audio-progress">
                        <div class="audio-progress-bar"></div>
                    </div>
                    <span class="audio-time">00:00 / 00:00</span>
                </div>
            `;
        } else {
            const icon = getFileIcon(msg.archivo.nombre);
            const size = formatFileSize(msg.archivo.tamano);
            
            contenidoExtra = `
                <div class="file-preview">
                    <div class="file-icon">${icon}</div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-semibold truncate">${escapeHtml(msg.archivo.nombre)}</div>
                        <div class="text-xs opacity-70">${size}</div>
                    </div>
                    <a href="${escapeHtml(msg.archivo.url)}" download class="material-icons-outlined text-sm hover:scale-110 transition">download</a>
                </div>
            `;
        }
    }

    div.innerHTML = `
        <div class="message-bubble">
            ${msg.message ? `<p>${linkify(escapeHtml(msg.message))}</p>` : ''}
            ${contenidoExtra}
            <div class="message-time">${time}</div>
        </div>
    `;

    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function handleFileSelect(e) {
    const file = e.target.files[0];
    if (!file) return;

    const container = document.getElementById('file-preview-container');
    const icon = getFileIcon(file.name);
    const size = formatFileSize(file.size);

    container.innerHTML = `
        <div class="flex items-center gap-3 p-3 bg-gray-100 rounded-xl">
            <div class="text-3xl">${icon}</div>
            <div class="flex-1 min-w-0">
                <div class="text-sm font-semibold truncate">${escapeHtml(file.name)}</div>
                <div class="text-xs text-gray-500">${size}</div>
            </div>
            <button onclick="clearFilePreview()" class="material-icons-outlined text-gray-500 hover:text-red-600">close</button>
        </div>
    `;
    
    container.classList.remove('hidden');
}

function clearFilePreview() {
    document.getElementById('file-input').value = '';
    document.getElementById('file-preview-container').classList.add('hidden');
    document.getElementById('file-preview-container').innerHTML = '';
}

async function toggleAudioRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
        return;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        
        recordedChunks = [];
        recordingSeconds = 0;
        
        mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });
        
        mediaRecorder.ondataavailable = e => {
            if (e.data.size > 0) recordedChunks.push(e.data);
        };
        
        mediaRecorder.onstop = async () => {
            stream.getTracks().forEach(t => t.stop());
            
            const audioBlob = new Blob(recordedChunks, { type: 'audio/webm' });
            const audioFile = new File([audioBlob], `audio_${Date.now()}.webm`, { type: 'audio/webm' });
            
            await enviarAudio(audioFile);
            hideRecordingUI();
        };
        
        mediaRecorder.start();
        showRecordingUI();
        
        recordingTimer = setInterval(() => {
            recordingSeconds++;
            updateRecordingTime();
        }, 1000);
        
    } catch (error) {
        console.error('Error:', error);
        alert('No se pudo acceder al micrófono');
    }
}

function showRecordingUI() {
    const container = document.getElementById('audio-recorder-container');
    container.innerHTML = `
        <div class="audio-recorder">
            <div class="recording-pulse"></div>
            <span class="flex-1 text-sm font-semibold text-red-600" id="recording-time">00:00</span>
            <button onclick="toggleAudioRecording()" class="btn btn-icon bg-red-600 text-white hover:bg-red-700">
                <span class="material-icons-outlined">stop</span>
            </button>
        </div>
    `;
    container.classList.remove('hidden');
}

function hideRecordingUI() {
    document.getElementById('audio-recorder-container').classList.add('hidden');
    clearInterval(recordingTimer);
}

function updateRecordingTime() {
    const mins = Math.floor(recordingSeconds / 60).toString().padStart(2, '0');
    const secs = (recordingSeconds % 60).toString().padStart(2, '0');
    const el = document.getElementById('recording-time');
    if (el) el.textContent = `${mins}:${secs}`;
}

async function enviarAudio(audioFile) {
    try {
        const formData = new FormData();
        formData.append('chat_id', CHAT_ID);
        formData.append('sender', 'cliente');
        formData.append('message', '');
        formData.append('archivo', audioFile);
        
        const response = await fetch('api/messages/upload_file.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        
        if (!data.success) {
            alert('Error al enviar audio');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error de conexión');
    }
}

function toggleAudio(button) {
    const container = button.closest('.audio-player');
    const audio = container.querySelector('audio');
    const bar = container.querySelector('.audio-progress-bar');
    const timeLabel = container.querySelector('.audio-time');

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
        timeLabel.textContent = formatTime(audio.currentTime) + ' / ' + formatTime(audio.duration);
    };

    audio.onended = () => {
        button.textContent = '▶';
        bar.style.width = '0%';
    };
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function linkify(text) {
    const urlRegex = /(https?:\/\/[^\s]+)/g;
    return text.replace(urlRegex, (url) => {
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="underline hover:opacity-80">${url}</a>`;
    });
}

function formatTime(seconds) {
    if (isNaN(seconds)) return '00:00';
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return `${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}`;
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
}

function getFileIcon(filename) {
    const ext = filename.split('.').pop().toLowerCase();
    if (['png','jpg','jpeg','webp'].includes(ext)) return '🖼️';
    if (ext === 'pdf') return '📕';
    if (['xls','xlsx'].includes(ext)) return '📊';
    if (['mp3','wav','ogg','m4a','webm'].includes(ext)) return '🎵';
    return '📄';
}

async function cerrarChat() {
    if (!CHAT_ID || CHAT_ID === null) {
        window.location.href = 'dashboard_clientes.php';
        return;
    }

    if (!confirm('¿Estás seguro de cerrar este chat?')) return;

    try {
        const response = await fetch('api/chat/close_chat_cliente.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                chat_id: CHAT_ID,
                cliente_id: CLIENTE_ID
            })
        });

        const data = await response.json();

        if (data.success) {
            alert('Chat cerrado correctamente');
            window.location.href = 'dashboard_clientes.php';
        } else {
            alert('Error al cerrar el chat: ' + (data.message || 'Error desconocido'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error de conexión al cerrar el chat');
    }
}
</script>

</body>
</html>