<?php
session_start();

if (!isset($_SESSION['asesor_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'backend/connection.php';

$asesor_id = $_SESSION['asesor_id'];
$chat_id = intval($_GET['chat_id'] ?? 0);

if (!$chat_id) {
    header('Location: panel_asesor.php');
    exit;
}

// Obtener información del chat
$stmt = $pdo->prepare("
    SELECT c.*, 
           u.nombre as cliente_nombre, 
           u.email as cliente_email, 
           u.telefono as cliente_telefono,
           d.nombre as departamento_nombre,
           cd.dni as cliente_dni
    FROM chats c
    INNER JOIN usuarios u ON c.cliente_id = u.id
    LEFT JOIN departamentos d ON c.departamento_id = d.id
    LEFT JOIN clientes_detalles cd ON cd.usuario_id = u.id
    WHERE c.id = ? AND c.asesor_id = ?
");
$stmt->execute([$chat_id, $asesor_id]);
$chat = $stmt->fetch();

if (!$chat) {
    header('Location: panel_asesor.php');
    exit;
}

// Obtener historial de mensajes
$stmt = $pdo->prepare("
    SELECT m.*, u.nombre as usuario_nombre
    FROM mensajes m
    LEFT JOIN usuarios u ON m.usuario_id = u.id
    WHERE m.chat_id = ?
    ORDER BY m.fecha ASC
");
$stmt->execute([$chat_id]);
$mensajes = $stmt->fetchAll();

// Marcar como leído
$stmt = $pdo->prepare("UPDATE chats SET ultima_lectura_asesor = NOW() WHERE id = ?");
$stmt->execute([$chat_id]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat con <?php echo htmlspecialchars($chat['cliente_nombre']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <style>
        #chat-messages {
            height: calc(100vh - 400px);
            min-height: 400px;
        }
    </style>
</head>
<body class="bg-gray-50">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <a href="panel_asesor.php" class="flex items-center gap-2 px-3 py-2 bg-gray-100 rounded-lg hover:bg-gray-200 transition">
                    <span class="material-icons-outlined">arrow_back</span>
                    <span>Volver</span>
                </a>
                <div class="h-8 w-px bg-gray-300"></div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                        <span class="material-icons-outlined text-blue-600">person</span>
                    </div>
                    <div>
                        <h1 class="font-bold text-gray-800"><?php echo htmlspecialchars($chat['cliente_nombre']); ?></h1>
                        <p class="text-sm text-gray-500"><?php echo htmlspecialchars($chat['departamento_nombre']); ?></p>
                    </div>
                </div>
            </div>
            <button onclick="cerrarChat()" class="flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                <span class="material-icons-outlined">close</span>
                <span>Cerrar Chat</span>
            </button>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Panel de Chat -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-lg overflow-hidden flex flex-col" style="height: calc(100vh - 200px);">
                    
                    <!-- Mensajes -->
                    <div id="chat-messages" class="flex-1 overflow-y-auto p-6 bg-gray-50">
                        <?php foreach ($mensajes as $msg): ?>
                            <?php if ($msg['emisor'] === 'cliente'): ?>
                            <div class="flex justify-start mb-4">
                                <div class="flex gap-2 max-w-[70%]">
                                    <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center flex-shrink-0">
                                        <span class="material-icons-outlined text-white text-sm">person</span>
                                    </div>
                                    <div>
                                        <div class="bg-white rounded-lg p-3 shadow">
                                            <p class="text-gray-800"><?php echo nl2br(htmlspecialchars($msg['mensaje'])); ?></p>
                                        </div>
                                        <span class="text-xs text-gray-500 mt-1 block">
                                            <?php echo date('H:i', strtotime($msg['fecha'])); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="flex justify-end mb-4">
                                <div class="flex gap-2 max-w-[70%]">
                                    <div>
                                        <div class="bg-blue-600 text-white rounded-lg p-3 shadow">
                                            <p><?php echo nl2br(htmlspecialchars($msg['mensaje'])); ?></p>
                                        </div>
                                        <span class="text-xs text-gray-500 mt-1 block text-right">
                                            <?php echo date('H:i', strtotime($msg['fecha'])); ?>
                                        </span>
                                    </div>
                                    <div class="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center flex-shrink-0">
                                        <span class="material-icons-outlined text-white text-sm">support_agent</span>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <!-- Input de mensaje -->
                    <div class="border-t bg-white p-4">
                        <div class="flex gap-2">
                            <input type="text" id="message-input" 
                                   placeholder="Escribe tu mensaje..." 
                                   class="flex-1 px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <button onclick="sendMessage()" 
                                    class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition flex items-center gap-2">
                                <span class="material-icons-outlined">send</span>
                                <span>Enviar</span>
                            </button>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Panel de Información -->
            <div class="space-y-6">
                
                <!-- Info del Cliente -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <span class="material-icons-outlined text-blue-600">account_circle</span>
                        Información del Cliente
                    </h2>
                    <div class="space-y-3">
                        <div>
                            <label class="text-sm text-gray-500">Nombre</label>
                            <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($chat['cliente_nombre']); ?></p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-500">DNI</label>
                            <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($chat['cliente_dni'] ?? 'N/A'); ?></p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-500">Teléfono</label>
                            <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($chat['cliente_telefono']); ?></p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-500">Email</label>
                            <p class="font-semibold text-gray-800 text-sm break-all"><?php echo htmlspecialchars($chat['cliente_email']); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Info del Chat -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <span class="material-icons-outlined text-green-600">info</span>
                        Detalles del Chat
                    </h2>
                    <div class="space-y-3">
                        <div>
                            <label class="text-sm text-gray-500">Departamento</label>
                            <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($chat['departamento_nombre']); ?></p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-500">Fecha de Inicio</label>
                            <p class="font-semibold text-gray-800"><?php echo date('d/m/Y H:i', strtotime($chat['fecha_inicio'])); ?></p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-500">Origen</label>
                            <p class="font-semibold text-gray-800 capitalize"><?php echo htmlspecialchars($chat['origen']); ?></p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-500">Estado</label>
                            <span class="inline-block px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-semibold">
                                Activo
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Respuestas rápidas -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <span class="material-icons-outlined text-purple-600">flash_on</span>
                        Respuestas Rápidas
                    </h2>
                    <div class="space-y-2">
                        <button onclick="quickReply('Hola, ¿en qué puedo ayudarte?')" 
                                class="w-full text-left px-3 py-2 bg-gray-50 hover:bg-gray-100 rounded-lg text-sm transition">
                            Saludo inicial
                        </button>
                        <button onclick="quickReply('¿Podrías brindarme más información?')" 
                                class="w-full text-left px-3 py-2 bg-gray-50 hover:bg-gray-100 rounded-lg text-sm transition">
                            Solicitar información
                        </button>
                        <button onclick="quickReply('Estoy revisando tu solicitud...')" 
                                class="w-full text-left px-3 py-2 bg-gray-50 hover:bg-gray-100 rounded-lg text-sm transition">
                            Revisando solicitud
                        </button>
                        <button onclick="quickReply('Gracias por comunicarte con nosotros.')" 
                                class="w-full text-left px-3 py-2 bg-gray-50 hover:bg-gray-100 rounded-lg text-sm transition">
                            Agradecimiento
                        </button>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <script>
        const chatId = <?php echo $chat_id; ?>;
        const asesorId = <?php echo $asesor_id; ?>;
        let lastMessageId = <?php echo empty($mensajes) ? 0 : end($mensajes)['id']; ?>;

        // Enviar mensaje
        async function sendMessage() {
            const input = document.getElementById('message-input');
            const message = input.value.trim();
            
            if (!message) return;

            try {
                const formData = new FormData();
                formData.append('chat_id', chatId);
                formData.append('sender', 'asesor');
                formData.append('message', message);

                const response = await fetch('api/messages/send_message.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    input.value = '';
                    addMessageToChat(message, 'asesor');
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Respuesta rápida
        function quickReply(text) {
            document.getElementById('message-input').value = text;
            document.getElementById('message-input').focus();
        }

        // Agregar mensaje al chat
        function addMessageToChat(message, sender) {
            const messagesDiv = document.getElementById('chat-messages');
            const messageDiv = document.createElement('div');
            messageDiv.className = sender === 'asesor' ? 'flex justify-end mb-4' : 'flex justify-start mb-4';
            
            const time = new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
            
            if (sender === 'asesor') {
                messageDiv.innerHTML = `
                    <div class="flex gap-2 max-w-[70%]">
                        <div>
                            <div class="bg-blue-600 text-white rounded-lg p-3 shadow">
                                <p>${message}</p>
                            </div>
                            <span class="text-xs text-gray-500 mt-1 block text-right">${time}</span>
                        </div>
                        <div class="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-icons-outlined text-white text-sm">support_agent</span>
                        </div>
                    </div>
                `;
            } else {
                messageDiv.innerHTML = `
                    <div class="flex gap-2 max-w-[70%]">
                        <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-icons-outlined text-white text-sm">person</span>
                        </div>
                        <div>
                            <div class="bg-white rounded-lg p-3 shadow">
                                <p class="text-gray-800">${message}</p>
                            </div>
                            <span class="text-xs text-gray-500 mt-1 block">${time}</span>
                        </div>
                    </div>
                `;
            }
            
            messagesDiv.appendChild(messageDiv);
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }

        // Polling para nuevos mensajes
        setInterval(async () => {
            try {
                const response = await fetch(`api/messages/get_messages.php?chat_id=${chatId}&last_id=${lastMessageId}`);
                const data = await response.json();

                if (data.success && data.data.messages.length > 0) {
                    data.data.messages.forEach(msg => {
                        if (msg.sender === 'client' || msg.sender === 'cliente') {
                            addMessageToChat(msg.message, 'cliente');
                        }
                        lastMessageId = msg.id;
                    });
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }, 2000);

        // Enter para enviar
        document.getElementById('message-input').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') sendMessage();
        });

        // Cerrar chat
        async function cerrarChat() {
            if (!confirm('¿Estás seguro de cerrar este chat?')) return;

            try {
                const formData = new FormData();
                formData.append('chat_id', chatId);

                const response = await fetch('api/chat/close_chat.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    window.location.href = 'panel_asesor.php';
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Scroll al final al cargar
        document.getElementById('chat-messages').scrollTop = document.getElementById('chat-messages').scrollHeight;
    </script>

</body>
</html>