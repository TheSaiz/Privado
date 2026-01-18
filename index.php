<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');
$configFile  = 'config.json';
$metricsFile = 'metrics.json';

$defaultConfig = [
    'hero' => [
        'title'    => 'Tu préstamo en 24 horas',
        'subtitle' => 'Hasta $4.000.000 con la mejor tasa del mercado'
    ],
    'benefits' => [
        ['icon' => 'shield',     'title' => '100% Seguro',           'description' => 'Oficinas propias y transparencia total'],
        ['icon' => 'headphones', 'title' => 'Atención Personalizada','description' => 'Personas reales que te ayudan'],
        ['icon' => 'trending',   'title' => 'Sin Anticipos',         'description' => 'No pedimos pagos adelantados'],
        ['icon' => 'award',      'title' => 'Mejor Tasa',            'description' => 'Desde 100% TNA según tu perfil']
    ],
    'products' => [
        ['title' => 'Empleados Nacionales',   'maxAmount' => '4.000.000', 'minAge' => '1 mes',    'tna' => '100%', 'features' => ['Hasta $4M', '24-36 cuotas', 'Por recibo']],
        ['title' => 'Empleados Provinciales', 'maxAmount' => '1.000.000', 'minAge' => '1 mes',    'tna' => '165%', 'features' => ['Hasta $1M', '12-36 cuotas', 'Rápido']],
        ['title' => 'Empleados Privados',     'maxAmount' => '120.000',   'minAge' => '12 meses', 'tna' => '180%', 'features' => ['Hasta $120K', '12 cuotas', 'Con Veraz']],
        ['title' => 'Jubilados ANSES',        'maxAmount' => '90.000',    'minAge' => '1 mes',    'tna' => '195%', 'features' => ['Hasta $90K', '6-11 cuotas', '1er cobro']]
    ],
    'contact' => [
        'address'   => 'Córdoba 2454 Piso 6 Of. B, Posadas, Misiones',
        'phone'     => '0376-5431525',
        'whatsapp'  => '0376-4739033',
        'email'     => 'info@prestamolider.com',
        'hours'     => 'Lun-Vie 9-17hs | Sáb 7-15hs'
    ],
    'colors' => [
        'primary'   => '#2563eb',
        'secondary' => '#16a34a',
        'accent'    => '#f59e0b'
    ]
];

if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true);
    if (!is_array($config)) {
        $config = $defaultConfig;
    }
} else {
    $config = $defaultConfig;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
    
}

// =====================================================
// SISTEMA DE REFERIDOS - CAPTURA DE CÓDIGO
// =====================================================

// Capturar código de referido de la URL
$codigo_referido_url = isset($_GET['ref']) ? trim($_GET['ref']) : '';

// Variables para el sistema de referidos
$tiene_referido = false;
$nombre_referidor = null;
$asesor_referidor_id = null;

if (!empty($codigo_referido_url)) {
    try {
        require_once __DIR__ . '/backend/connection.php';
        
        $stmt = $pdo->prepare("
            SELECT id, nombre, foto_perfil
            FROM usuarios 
            WHERE codigo_referido = ? 
              AND rol = 'asesor' 
              AND estado = 'activo'
            LIMIT 1
        ");
        $stmt->execute([$codigo_referido_url]);
        $asesor_ref = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($asesor_ref) {
            $tiene_referido = true;
            $nombre_referidor = $asesor_ref['nombre'];
            $asesor_referidor_id = $asesor_ref['id'];

            // Registrar click
            $stmt = $pdo->prepare("
                INSERT INTO referidos_clicks (
                    asesor_id, ip_address, user_agent, referrer, fecha_click
                ) VALUES (?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $asesor_referidor_id,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $_SERVER['HTTP_REFERER'] ?? null
            ]);

            // Persistir en sesión
            $_SESSION['codigo_referido'] = $codigo_referido_url;
            $_SESSION['asesor_referidor_id'] = $asesor_referidor_id;
            $_SESSION['nombre_referidor'] = $nombre_referidor;
        }
    } catch (Exception $e) {
        error_log("Error referido: " . $e->getMessage());
    }
}

// Recuperar desde sesión si ya existía
if (!$tiene_referido && isset($_SESSION['codigo_referido'])) {
    $codigo_referido_url = $_SESSION['codigo_referido'];
    $asesor_referidor_id = $_SESSION['asesor_referidor_id'] ?? null;
    $nombre_referidor = $_SESSION['nombre_referidor'] ?? null;
    $tiene_referido = true;
}


$sessionId = session_id() ?: uniqid('sess_', true);
$visitTime = time();

$metrics = [];
if (file_exists($metricsFile)) {
    $metrics = json_decode(file_get_contents($metricsFile), true) ?: [];
}

$metrics[] = [
    'session_id' => $sessionId,
    'timestamp'  => $visitTime,
    'enter_time' => $visitTime
];

file_put_contents($metricsFile, json_encode($metrics));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Préstamo Líder - Tu préstamo en 24 horas</title>
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
    
/* =====================================================
   🎨 BOTONES DE SELECCIÓN CUIL - TEXTO BLANCO
   ===================================================== */

.chat-option-btn {
    background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); /* Fondo azul oscuro */
    border: 2px solid #3b82f6;
    border-radius: 8px;
    padding: 12px 16px;
    margin: 8px 0;
    width: 100%;
    text-align: left;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 14px;
    line-height: 1.4;
    color: #ffffff; /* ✅ Texto blanco por defecto */
}

.chat-option-btn:hover {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    border-color: #60a5fa;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}

.chat-option-btn:active {
    transform: translateY(0);
    box-shadow: 0 2px 6px rgba(59, 130, 246, 0.3);
}

.chat-option-btn strong {
    display: block;
    color: #ffffff; /* ✅ Nombre en blanco */
    font-size: 15px;
    margin-bottom: 4px;
    font-weight: 600;
}

.chat-option-btn small {
    display: block;
    color: #e0e7ff; /* ✅ CUIL en blanco suave */
    font-size: 12px;
    opacity: 0.9;
}

.chat-options-container {
    display: flex;
    flex-direction: column;
    margin: 12px 0;
    max-width: 500px;
}
    
/* Badge de referido */
.referral-badge {
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

    /* Grabador de audio */
.audio-recorder {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px;
    background: rgba(239, 68, 68, 0.1);
    border-radius: 10px;
    margin-top: 8px;3
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

.audio-recorder-send {
    background: #22c55e;
    color: white;
}

.audio-recorder-cancel {
    background: #6b7280;
    color: white;
}

/* Reproductor de audio */
.audio-player {
    display: flex;
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
}

.audio-player-btn:hover {
    background: #2563eb;
}

.audio-player-time {
    font-size: 12px;
    color: #1e40af;
    font-family: 'Courier New', monospace;
    min-width: 80px;
}

.audio-player-progress {
    flex: 1;
    height: 4px;
    background: rgba(59, 130, 246, 0.2);
    border-radius: 2px;
    position: relative;
    cursor: pointer;
}

.audio-player-progress-bar {
    height: 100%;
    background: #3b82f6;
    border-radius: 2px;
    transition: width 0.1s;
}

#chat-record-btn {
    width: 46px;
    height: 46px;
    border-radius: 999px;
    background: #ef4444;
    color: white;
    border: none;
    cursor: pointer;
    font-size: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform .15s, box-shadow .15s, filter .15s;
    box-shadow: 0 8px 20px rgba(239, 68, 68, 0.45);
    margin-left: 8px;
}

#chat-record-btn:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
    box-shadow: 0 12px 25px rgba(239, 68, 68, 0.55);
}

#chat-record-btn.recording {
    animation: pulse-red 1.5s infinite;
}

@keyframes pulse-red {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.8; transform: scale(1.05); }
}
        .bg-hero-gradient {
            background: linear-gradient(
                135deg,
                <?php echo $config['colors']['primary']; ?> 0%,
                <?php echo $config['colors']['secondary']; ?> 100%
            );
        }
        .gradient-primary {
            background: linear-gradient(135deg, <?php echo $config['colors']['primary']; ?> 0%, <?php echo $config['colors']['secondary']; ?> 100%);
        }
        .hero-pattern {
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        #chat-end-btn {
            width: 46px;
            border-radius: 999px;
            background: #ef4444;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform .15s, box-shadow .15s, filter .15s;
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.55);
        }
        #chat-end-btn:hover {
            filter: brightness(1.06);
            transform: translateY(-1px);
            box-shadow: 0 12px 25px rgba(239, 68, 68, 0.65);
        }
        #chat-end-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }
        #chat-widget-button {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 68px;
            height: 68px;
            background: radial-gradient(circle at 30% 30%, #60a5fa, #1d4ed8);
            color: white;
            border-radius: 999px;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.35);
            z-index: 999999;
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
        }
        #chat-widget-button:hover {
            transform: translateY(-2px) scale(1.04);
            box-shadow: 0 14px 35px rgba(15, 23, 42, 0.45);
        }
        #chat-widget-button.has-new-message {
            animation: bounce 0.5s infinite;
        }
        @keyframes bounce {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-6px) scale(1.05); }
        }
        .chat-icon {
            font-size: 30px;
        }
        .notification-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: #ef4444;
            color: white;
            border-radius: 999px;
            min-width: 22px;
            height: 22px;
            padding: 0 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            box-shadow: 0 0 0 2px rgba(15,23,42,0.95);
            animation: pulse 1.8s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.85; transform: scale(1.15); }
        }
        #chat-attach-btn {
            width: 46px;
            height: 46px;
            border-radius: 999px;
            background: #6b7280;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform .15s, box-shadow .15s, filter .15s;
            box-shadow: 0 8px 20px rgba(107, 114, 128, 0.45);
            margin-left: 8px;
        }
        #chat-attach-btn:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
            box-shadow: 0 12px 25px rgba(107, 114, 128, 0.55);
        }
        .msg-file {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 10px;
            margin-top: 6px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .msg-file:hover {
            background: rgba(59, 130, 246, 0.2);
        }
        .file-icon {
            font-size: 24px;
        }
        .file-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .file-name {
            font-size: 13px;
            font-weight: 600;
            color: #1e40af;
        }
        .file-size {
            font-size: 11px;
            color: #6b7280;
        }
        .upload-progress {
            display: inline-block;
            padding: 8px 12px;
            background: rgba(59, 130, 246, 0.1);
            border-radius: 10px;
            margin-top: 6px;
            font-size: 12px;
            color: #1e40af;
        }
        #chat-widget-window {
            position: fixed;
            bottom: 100px;
            right: 20px;
            width: 380px;
            height: 520px;
            background: #0f172a;
            border-radius: 20px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.65);
            display: none;
            flex-direction: column;
            overflow: hidden;
            z-index: 999999;
            animation: fadeInUp .28s ease-out;
        }
        #chat-widget-window.minimized {
            height: 70px;
            overflow: hidden;
        }
        #chat-widget-window.minimized .chat-body,
        #chat-widget-window.minimized #chat-input-area {
            display: none !important;
        }
        @keyframes fadeInUp {
            from { transform: translateY(25px); opacity: 0; }
            to   { transform: translateY(0); opacity: 1; }
        }
        .chat-header {
            background: linear-gradient(135deg, #1d4ed8, #22c55e);
            color: white;
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chat-header-info {
            display: flex;
            flex-direction: column;
        }
        .chat-header-info strong {
            font-size: 15px;
        }
        .chat-status {
            font-size: 12px;
            opacity: 0.9;
        }
        .chat-header-actions {
            display: flex;
            gap: 6px;
        }
        .chat-minimize-btn,
        .chat-close-btn {
            background: rgba(15, 23, 42, 0.12);
            color: white;
            border: none;
            font-size: 18px;
            cursor: pointer;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            transition: background 0.2s, transform 0.15s;
        }
        .chat-minimize-btn:hover,
        .chat-close-btn:hover {
            background: rgba(15, 23, 42, 0.3);
            transform: scale(1.05);
        }
        .chat-body {
            flex: 1;
            overflow-y: auto;
            padding: 14px;
            background: radial-gradient(circle at top left, #1f2937 0, #020617 55%);
            scrollbar-width: thin;
            scrollbar-color: #4b5563 transparent;
        }
        .chat-body::-webkit-scrollbar {
            width: 6px;
        }
        .chat-body::-webkit-scrollbar-track {
            background: transparent;
        }
        .chat-body::-webkit-scrollbar-thumb {
            background: #4b5563;
            border-radius: 999px;
        }
        #chat-initial-form {
            background: rgba(15,23,42,0.85);
            padding: 18px 16px;
            border-radius: 14px;
            text-align: left;
            border: 1px solid rgba(148,163,184,0.35);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.5);
        }
        #chat-initial-form h3 {
            margin-bottom: 6px;
            font-size: 16px;
            font-weight: 600;
            color: #e5e7eb;
        }
        #chat-initial-form p {
            margin-bottom: 12px;
            font-size: 13px;
            color: #9ca3af;
        }
        #chat-initial-form input {
            width: 100%;
            padding: 9px 10px;
            margin-bottom: 10px;
            border-radius: 10px;
            border: 1px solid #4b5563;
            background: rgba(15,23,42,0.9);
            color: #e5e7eb;
            font-size: 13px;
            outline: none;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        #chat-initial-form input::placeholder {
            color: #6b7280;
        }
        #chat-initial-form input:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 1px rgba(37, 99, 235, 0.8);
            background: rgba(15,23,42,0.95);
        }
        #chat-initial-form button {
            width: 100%;
            padding: 10px;
            border-radius: 999px;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            margin-top: 4px;
            transition: transform .15s, box-shadow .15s, filter .15s;
            box-shadow: 0 10px 25px rgba(22,163,74,0.55);
        }
        #chat-initial-form button:hover {
            filter: brightness(1.03);
            transform: translateY(-1px);
            box-shadow: 0 14px 30px rgba(22,163,74,0.65);
        }
        #chat-messages {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 10px;
        }
        .msg-user,
        .msg-bot {
            padding: 8px 12px;
            border-radius: 14px;
            max-width: 80%;
            word-wrap: break-word;
            font-size: 13px;
            line-height: 1.4;
        }
        .msg-user {
            align-self: flex-end;
            background: linear-gradient(135deg, #2563eb, #22c55e);
            color: white;
            border-bottom-right-radius: 4px;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.5);
        }
        .msg-bot {
            align-self: flex-start;
            background: rgba(31, 41, 55, 0.95);
            color: #e5e7eb;
            border-bottom-left-radius: 4px;
            border: 1px solid rgba(55, 65, 81, 0.85);
        }
        .chat-options-container {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin: 6px 0 4px 0;
        }
        .chat-option-btn {
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid rgba(59,130,246,0.9);
            background: rgba(15,23,42,0.9);
            color: #dbeafe;
            font-size: 13px;
            text-align: left;
            cursor: pointer;
            transition: background .15s, transform .1s, box-shadow .15s;
        }
        .chat-option-btn:hover {
            background: rgba(37,99,235,0.25);
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(37, 99, 235, 0.4);
        }
        #chat-input-area {
            display: flex;
            padding: 10px 10px;
            background: #020617;
            border-top: 1px solid #111827;
            gap: 8px;
        }
        #chat-input {
            flex: 1;
            padding: 9px 10px;
            border-radius: 999px;
            border: 1px solid #4b5563;
            background: #020617;
            color: #e5e7eb;
            font-size: 13px;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        #chat-input::placeholder {
            color: #6b7280;
        }
        #chat-input:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 1px rgba(37, 99, 235, 0.8);
        }
        #chat-send-btn {
            width: 46px;
            border-radius: 999px;
            background: linear-gradient(135deg, #2563eb, #22c55e);
            color: white;
            border: none;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform .15s, box-shadow .15s, filter .15s;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.55);
        }
        #chat-send-btn:hover {
            filter: brightness(1.06);
            transform: translateY(-1px);
            box-shadow: 0 12px 25px rgba(37, 99, 235, 0.65);
        }
        #chat-send-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }
        @media (max-width: 600px) {
            #chat-widget-window {
                width: 94%;
                height: 78%;
                right: 3%;
                left: 3%;
                bottom: 90px;
                border-radius: 18px;
            }
            #chat-widget-button {
                right: 16px;
                bottom: 16px;
            }
        }
        /* Avatar del asesor en mensajes */
.msg-bot-with-avatar {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 16px;
}

.asesor-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    border: 2px solid #3b82f6;
    flex-shrink: 0;
}

.asesor-avatar:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}

.msg-bot-content {
    flex: 1;
}

/* Modal de perfil del asesor */
#asesor-profile-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999999;
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.profile-modal-content {
    background: white;
    border-radius: 16px;
    padding: 24px;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: slideUp 0.3s ease;
    position: relative;
}

@keyframes slideUp {
    from { 
        opacity: 0;
        transform: translateY(20px);
    }
    to { 
        opacity: 1;
        transform: translateY(0);
    }
}

.profile-close-btn {
    position: absolute;
    top: 16px;
    right: 16px;
    background: #ef4444;
    color: white;
    border: none;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 18px;
    transition: background 0.2s;
}

.profile-close-btn:hover {
    background: #dc2626;
}

.profile-header {
    text-align: center;
    margin-bottom: 20px;
}

.profile-avatar-large {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid #3b82f6;
    margin: 0 auto 12px;
    display: block;
}

.profile-name {
    font-size: 20px;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.profile-role {
    font-size: 14px;
    color: #64748b;
    margin: 4px 0 0;
}

.profile-info {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.profile-info-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: #f8fafc;
    border-radius: 10px;
    transition: background 0.2s;
}

.profile-info-item:hover {
    background: #f1f5f9;
}

.profile-info-item a {
    color: #3b82f6;
    text-decoration: none;
    flex: 1;
}

.profile-info-item a:hover {
    text-decoration: underline;
}

.profile-icon {
    font-size: 20px;
    width: 24px;
    text-align: center;
}

.profile-label {
    font-size: 13px;
    color: #64748b;
    font-weight: 600;
    min-width: 80px;
}

.profile-value {
    font-size: 14px;
    color: #1e293b;
    flex: 1;
}
#typing-indicator {
    animation: fadeIn 0.3s ease;
}

    </style>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="text-xl font-bold text-gray-800">Préstamo Líder</span>
            </div>
        </div>
    </nav>

    <section class="relative min-h-screen flex items-center bg-hero-gradient bg-hero-pattern">
        <div class="relative max-w-7xl mx-auto px-4 py-20 text-center text-white z-10">
            <h1 class="text-5xl md:text-7xl font-bold mb-6">
                <?php echo htmlspecialchars($config['hero']['title']); ?>
            </h1>
            <p class="text-2xl md:text-3xl mb-12 opacity-90">
                <?php echo htmlspecialchars($config['hero']['subtitle']); ?>
            </p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-20">
                <div class="bg-white bg-opacity-10 backdrop-blur-md rounded-2xl p-6 border border-white border-opacity-20">
                    <div class="text-4xl font-bold mb-2">+10.000</div>
                    <div class="text-lg opacity-90">Clientes Satisfechos</div>
                </div>
                <div class="bg-white bg-opacity-10 backdrop-blur-md rounded-2xl p-6 border border-white border-opacity-20">
                    <div class="text-4xl font-bold mb-2">24-72hs</div>
                    <div class="text-lg opacity-90">Aprobación Rápida</div>
                </div>
                <div class="bg-white bg-opacity-10 backdrop-blur-md rounded-2xl p-6 border border-white border-opacity-20">
                    <div class="text-4xl font-bold mb-2">100%</div>
                    <div class="text-lg opacity-90">Seguro y Legal</div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4">
            <h2 class="text-4xl font-bold text-center mb-16 text-gray-800">¿Por qué elegirnos?</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                <?php foreach ($config['benefits'] as $benefit): ?>
                <div class="text-center p-8 rounded-2xl bg-gradient-to-br from-blue-50 to-green-50 hover:shadow-xl transition">
                    <div class="inline-flex p-4 rounded-full mb-4 gradient-primary">
                        <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-800">
                        <?php echo htmlspecialchars($benefit['title']); ?>
                    </h3>
                    <p class="text-gray-600">
                        <?php echo htmlspecialchars($benefit['description']); ?>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4">
            <h2 class="text-4xl font-bold text-center mb-16 text-gray-800">Contactanos</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                <div class="text-center p-6">
                    <svg class="w-12 h-12 text-blue-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <h3 class="font-bold mb-2">Dirección</h3>
                    <p class="text-gray-600 text-sm">
                        <?php echo htmlspecialchars($config['contact']['address']); ?>
                    </p>
                </div>
                <div class="text-center p-6">
                    <svg class="w-12 h-12 text-green-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                    </svg>
                    <h3 class="font-bold mb-2">Teléfono</h3>
                    <p class="text-gray-600">
                        <?php echo htmlspecialchars($config['contact']['phone']); ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-1">
                        WhatsApp: <?php echo htmlspecialchars($config['contact']['whatsapp']); ?>
                    </p>
                </div>
                <div class="text-center p-6">
                    <svg class="w-12 h-12 text-blue-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                    <h3 class="font-bold mb-2">Email</h3>
                    <p class="text-gray-600">
                        <?php echo htmlspecialchars($config['contact']['email']); ?>
                    </p>
                </div>
                <div class="text-center p-6">
                    <svg class="w-12 h-12 text-green-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <h3 class="font-bold mb-2">Horarios</h3>
                    <p class="text-gray-600 text-sm">
                        <?php echo htmlspecialchars($config['contact']['hours']); ?>
                    </p>
                </div>
            </div>
        </div>
    </section>
    <section class="py-20 bg-white">
    <div class="max-w-4xl mx-auto px-4">
        <h2 class="text-4xl font-bold text-center mb-12 text-gray-800">
            Preguntas Frecuentes
        </h2>

        <div class="space-y-4">
            <?php foreach ($config['faq'] as $i => $faq): ?>
            <div class="border rounded-xl overflow-hidden">
                
                <!-- Botón -->
                <button onclick="toggleFAQ(<?php echo $i; ?>)"
                    class="w-full px-6 py-4 flex justify-between items-center font-semibold text-gray-800
                           bg-gray-50 hover:bg-gray-100 transition text-left">

                    <?php echo htmlspecialchars($faq['pregunta']); ?>

                    <svg id="faq-arrow-<?php echo $i; ?>" class="w-5 h-5 transition-transform"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <!-- Respuesta -->
                <div id="faq-answer-<?php echo $i; ?>" class="hidden px-6 pb-4 pt-2 text-gray-600">
                    <?php echo nl2br(htmlspecialchars($faq['respuesta'])); ?>
                </div>

            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<script>
function toggleFAQ(i) {
    const answer = document.getElementById("faq-answer-" + i);
    const icon   = document.getElementById("faq-arrow-" + i);

    const open = !answer.classList.contains("hidden");

    document.querySelectorAll("[id^='faq-answer-']").forEach(e => e.classList.add("hidden"));
    document.querySelectorAll("[id^='faq-arrow-']").forEach(e => e.classList.remove("rotate-180"));

    if (!open) {
        answer.classList.remove("hidden");
        icon.classList.add("rotate-180");
    }
}
</script>


    <footer class="text-white py-12 gradient-primary">
        <div class="max-w-7xl mx-auto px-4 text-center">
            <div class="flex justify-center gap-6 mb-6">
                <a href="#" class="hover:scale-110 transition">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                    </svg>
                </a>
                <a href="#" class="hover:scale-110 transition">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                    </svg>
                </a>
                <a href="mailto:<?php echo htmlspecialchars($config['contact']['email']); ?>" class="hover:scale-110 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                </a>
            </div>
            <p class="text-sm opacity-90">© 2024 Préstamo Líder. Todos los derechos reservados.</p>
        </div>
    </footer>

    <div id="chat-widget-button">
    <div class="chat-icon">💬</div>
    <div id="notification-badge" class="notification-badge" style="display: none;">0</div>
</div>

<!-- CONTENEDOR PRINCIPAL DEL WIDGET (FIX) -->
<div id="chat-widget-window">

    <div class="chat-header">
        <div class="chat-header-info">
    <strong id="chat-asesor-nombre">Asistencia en Línea</strong>
    <span id="chat-asesor-status" class="chat-status">🟢 En línea</span>
</div>
        <div id="typing-indicator" class="hidden text-xs text-gray-400 px-4 py-1">
    ✍️ El asesor está escribiendo…
</div>

        <div class="chat-header-actions">
            <button id="btn-ver-perfil-asesor"
                    onclick="showAsesorProfile()"
                    class="chat-minimize-btn"
                    style="display:none;"
                    title="Ver perfil del asesor">
                👤
            </button>
            <button id="chat-widget-minimize" class="chat-minimize-btn">−</button>
            <button id="chat-widget-close" class="chat-close-btn">×</button>
        </div>
    </div>

    <div class="chat-body">
        <div id="chat-initial-form">
            <h3>Antes de comenzar</h3>
            <p>Completa tus datos para iniciar el chat</p>
            <input type="text" id="chat-name" placeholder="Nombre completo">
            <button type="button" id="start-chat-btn">Iniciar Chat</button>
        </div>

        <div id="chat-messages" style="display: none;"></div>
    </div>

    <div id="chat-input-area" style="display: none;">
        <button id="chat-end-btn" title="Terminar conversación">✖</button>
        <button id="chat-attach-btn" title="Adjuntar archivo">📎</button>
        <input type="file" id="chat-file-input"
       accept=".pdf,.png,.jpg,.jpeg,.webp,.xls,.xlsx,.mp3,.wav,.ogg,.m4a"
       style="display: none;">
               <button id="chat-record-btn" title="Grabar audio">🎤</button>
<input type="file" id="chat-audio-file-input"
       accept="audio/*"
       style="display: none;">
        <input type="text" id="chat-input"
               placeholder="Escribe un mensaje..."
               style="margin-left:8px; margin-right:8px;">
        <button id="chat-send-btn">➤</button>
    </div>

</div>
<!-- FIN DEL WIDGET -->

<!-- Modal de Perfil del Asesor -->
<div id="asesor-profile-modal">
    <div class="profile-modal-content">
        <button class="profile-close-btn" onclick="closeAsesorProfile()">✕</button>
        <div class="profile-header">
            <img id="profile-avatar" class="profile-avatar-large" src="" alt="Asesor">
            <h3 id="profile-name" class="profile-name"></h3>
            <p id="profile-role" class="profile-role">Asesor de Préstamos</p>
        </div>
        <div id="profile-info" class="profile-info">
            <!-- Se llena dinámicamente -->
        </div>
    </div>
</div>

    <script>

/* ===================================================================
   CONFIGURACIÓN DE SESIÓN (TIEMPO EN PÁGINA)
=================================================================== */
let enterTime = <?php echo (int)$visitTime; ?>;
window.addEventListener('beforeunload', function () {
    let exitTime = Math.floor(Date.now() / 1000);
    let duration = exitTime - enterTime;
    navigator.sendBeacon('track_exit.php', JSON.stringify({
        session_id: '<?php echo $sessionId; ?>',
        duration: duration
    }));
});

/* ===================================================================
   VARIABLES GLOBALES DEL CHAT
=================================================================== */
const chatButton         = document.getElementById("chat-widget-button");
const chatWindow         = document.getElementById("chat-widget-window");
const chatClose          = document.getElementById("chat-widget-close");
const chatMinimize       = document.getElementById("chat-widget-minimize");
const initialForm        = document.getElementById("chat-initial-form");
const chatMessages       = document.getElementById("chat-messages");
const chatBody           = document.querySelector(".chat-body");
const chatInputArea      = document.getElementById("chat-input-area");
const chatInput          = document.getElementById("chat-input");
const chatSendBtn        = document.getElementById("chat-send-btn");
const notificationBadge  = document.getElementById("notification-badge");
const chatEndBtn         = document.getElementById("chat-end-btn");
const chatAttachBtn      = document.getElementById("chat-attach-btn");
const chatFileInput      = document.getElementById("chat-file-input");

let chatId = null;
let clienteId = null;
let unreadMessages = 0;
let isWindowOpen = false;
let isMinimized = false;
let clientName = null;
let lastMessageId = 0;
let asesorTyping = false;

/* ===================================================================
   ⏱️ CONTROL DE INACTIVIDAD DEL CLIENTE
=================================================================== */

let inactivityTimerWarning = null;
let inactivityTimerClose   = null;

const INACTIVITY_WARNING_TIME = 3 * 60 * 1000; // 3 minutos
const INACTIVITY_CLOSE_TIME   = 2 * 60 * 1000; // 2 minutos

function resetInactivityTimers() {
    // ⚠️ CRÍTICO: Solo activar si hay asesor asignado Y chat activo
    if (!currentAsesorData || !chatId || chatInput.disabled) {
        return;
    }

    // Limpiar timers previos
    clearInactivityTimers();

    // ⏳ 3 minutos → aviso
    inactivityTimerWarning = setTimeout(() => {
        enviarMensajeSistemaAutomatico("¿Seguís ahí? 😊");

        // ⏳ 2 minutos más → cerrar
        inactivityTimerClose = setTimeout(() => {
            finalizarChatPorInactividad();
        }, INACTIVITY_CLOSE_TIME);

    }, INACTIVITY_WARNING_TIME);
}

// ✅ Función para limpiar timers
function clearInactivityTimers() {
    if (inactivityTimerWarning) {
        clearTimeout(inactivityTimerWarning);
        inactivityTimerWarning = null;
    }
    if (inactivityTimerClose) {
        clearTimeout(inactivityTimerClose);
        inactivityTimerClose = null;
    }
}

async function enviarMensajeSistemaAutomatico(texto) {

    if (!chatId) return;

    const formData = new FormData();
    formData.append("chat_id", chatId);
    formData.append("sender", "sistema");
    formData.append("message", texto);

    try {
        await fetch("api/messages/send_message.php", {
            method: "POST",
            body: formData
        });

        // Mostrar en UI como bot/sistema
        addSystemMessage("🤖 " + texto);

    } catch (err) {
        console.error("Error enviando mensaje automático:", err);
    }
}


// ✅ Finalizar chat - INCLUIR LIMPIEZA
async function finalizarChatPorInactividad() {
    if (!chatId) return;

    try {
        await fetch("api/chat/end_chat.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                chat_id: chatId,
                motivo: "inactividad_cliente"
            })
        });
    } catch (err) {
        console.error("Error cerrando chat:", err);
    }

    addSystemMessage("⏳ La conversación fue finalizada por inactividad.");

    // Desactivar controles
    chatInput.disabled = true;
    chatSendBtn.disabled = true;
    chatAttachBtn.disabled = true;
    chatRecordBtn.disabled = true;

    // ✅ LIMPIAR TIMERS
    clearInactivityTimers();
    
    // ✅ LIMPIAR DATOS DEL ASESOR
    currentAsesorData = null;
    updateChatHeaderAsesor();
}

// ✅ Botón terminar chat - AGREGAR LIMPIEZA
chatEndBtn.addEventListener("click", async () => {
    if (!chatId) return;
    
    if (!confirm("¿Estás seguro que querés terminar la conversación?")) return;

    try {
        await fetch("api/chat/end_chat.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                chat_id: chatId,
                motivo: "cliente_finalizo"
            })
        });

        addSystemMessage("🔚 Conversación finalizada. ¡Gracias por contactarnos!");

        // Desactivar controles
        chatInput.disabled = true;
        chatSendBtn.disabled = true;
        chatAttachBtn.disabled = true;
        chatRecordBtn.disabled = true;
        chatEndBtn.disabled = true;

        // ✅ LIMPIAR TIMERS DE INACTIVIDAD
        clearInactivityTimers();
        
        // ✅ LIMPIAR DATOS
        currentAsesorData = null;
        chatId = null;

    } catch (err) {
        console.error("Error terminando chat:", err);
        alert("Hubo un error. Intenta nuevamente.");
    }
});


/* ===================================================================
   ADJUNTAR ARCHIVOS (CLIENTE → SERVIDOR)
=================================================================== */
chatAttachBtn.addEventListener("click", () => chatFileInput.click());

chatFileInput.addEventListener("change", async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    // ⛔ Tamaño máximo 10MB
    const maxSize = 10 * 1024 * 1024;
    if (file.size > maxSize) {
        alert("El archivo es demasiado grande. Máximo 10MB");
        chatFileInput.value = "";
        return;
    }

    // ✅ Tipos permitidos (incluye AUDIO)
    const allowedTypes = [
        'application/pdf',

        // Imágenes
        'image/png',
        'image/jpeg',
        'image/webp',

        // Excel
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

        // Audio
        'audio/mpeg',   // mp3
        'audio/wav',
        'audio/ogg',
        'audio/mp4',    // m4a
        'audio/webm'
    ];

    // Backup por extensión (algunos navegadores no envían bien el MIME)
    const audioExts = ['mp3', 'wav', 'ogg', 'm4a', 'webm'];
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    const isAllowedByExt = audioExts.includes(ext);

    if (!allowedTypes.includes(file.type) && !isAllowedByExt) {
        alert("Tipo de archivo no permitido.");
        chatFileInput.value = "";
        return;
    }

    await uploadFile(file);
    chatFileInput.value = "";
});

async function uploadFile(file) {
    if (!chatId) {
        alert("Debes iniciar el chat primero");
        return;
    }

    // ⏳ Mensaje temporal de subida
    const temp = document.createElement("div");
    temp.className = "flex justify-end mb-2";
    temp.innerHTML = `
        <div class="upload-progress">
            Subiendo ${escapeHtml(file.name)}… ⏳
        </div>
    `;
    chatMessages.appendChild(temp);
    scrollToBottom();

    try {
        const formData = new FormData();
        formData.append("chat_id", chatId);
        formData.append("sender", "cliente");
        formData.append("message", `Archivo adjunto: ${file.name}`);
        formData.append("archivo", file);

        const response = await fetch("api/messages/upload_file.php", {
            method: "POST",
            body: formData
        });

        const data = await response.json();
        temp.remove();

        if (!data.success) {
            alert(data.message || "Error al subir archivo");
            return;
        }

        // 🔊 DETECTAR AUDIO
        const audioExtensions = ['mp3', 'wav', 'ogg', 'm4a', 'webm'];
        const isAudio = audioExtensions.some(ext =>
            file.name.toLowerCase().endsWith('.' + ext)
        );

        // 🎧 MOSTRAR REPRODUCTOR DE AUDIO
        if (isAudio) {
            addAudioMessage(data.data.archivo_url, 0, "user");
            return;
        }

        // 📎 ARCHIVO NORMAL
        addFileMessage(
            file.name,
            formatFileSize(file.size),
            data.data.archivo_url,
            "user"
        );

    } catch (err) {
        console.error("Error subiendo archivo:", err);
        alert("Error de conexión");
        temp.remove();
    }
}

function formatFileSize(bytes) {
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    if (!bytes) return '0 Bytes';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return Math.round(bytes / Math.pow(1024, i)) + ' ' + sizes[i];
}


/* ===================================================================
   MOSTRAR ARCHIVO EN EL CHAT
=================================================================== */
function addFileMessage(fileName, fileSize, fileUrl, sender = "bot", text = "") {
    const msg = document.createElement("div");
    msg.className = sender === "user" ? "flex justify-end mb-4" : "flex justify-start mb-4";

    const icon =
        fileName.endsWith(".pdf")  ? "📕" :
        fileName.endsWith(".png")  ? "🖼️" :
        fileName.endsWith(".jpg")  ? "🖼️" :
        fileName.endsWith(".jpeg") ? "🖼️" :
        fileName.endsWith(".webp") ? "🖼️" :
        fileName.endsWith(".xls")  ? "📊" :
        fileName.endsWith(".xlsx") ? "📊" : "📄";

    msg.innerHTML = `
        <div class="max-w-[80%]">
            <div class="${sender === "user"
                ? "bg-gradient-to-r from-blue-600 to-green-600 text-white"
                : "bg-gray-800 text-white"} rounded-2xl px-4 py-2.5 inline-block">
                <a href="${fileUrl}" target="_blank" class="msg-file">
                    <span class="file-icon">${icon}</span>
                    <div class="file-info">
                        <span class="file-name">${escapeHtml(fileName)}</span>
                        <span class="file-size">${fileSize}</span>
                    </div>
                </a>
            </div>
        </div>
    `;

    chatMessages.appendChild(msg);
    scrollToBottom();
}

/* ===================================================================
   ESCAPE HTML
=================================================================== */
function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
}

/* ===================================================================
   SCROLL AUTOMÁTICO
=================================================================== */
function scrollToBottom() {
    setTimeout(() => {
        chatBody.scrollTop = chatBody.scrollHeight;
    }, 50);
}

/* ===================================================================
   BOTÓN ABRIR/CERRAR WIDGET
=================================================================== */
chatButton.addEventListener("click", () => {
    if (!isWindowOpen || isMinimized) openChat();
    else minimizeChat();
});

chatWindow.addEventListener("click", () => {
    if (isMinimized) openChat();
});

function openChat() {
    chatWindow.style.display = "flex";
    chatWindow.classList.remove("minimized");
    isWindowOpen = true;
    isMinimized = false;
    clearNotifications();
}

function minimizeChat() {
    chatWindow.classList.add("minimized");
    isMinimized = true;
}

chatMinimize.addEventListener("click", (e) => {
    e.stopPropagation();
    minimizeChat();
});

chatClose.addEventListener("click", (e) => {
    e.stopPropagation();
    chatWindow.style.display = "none";
    isWindowOpen = false;
    isMinimized = false;
});

/* ===================================================================
   NOTIFICACIONES
=================================================================== */
function showNotification() {
    unreadMessages++;
    notificationBadge.textContent = unreadMessages;
    notificationBadge.style.display = "flex";
    chatButton.classList.add("has-new-message");
}

function clearNotifications() {
    unreadMessages = 0;
    notificationBadge.style.display = "none";
    chatButton.classList.remove("has-new-message");
}

/* ===================================================================
   GEOLOCALIZACIÓN + IP + AGENTE
=================================================================== */
let client_ip = null;
let client_city = null;
let client_country = null;
let client_lat = null;
let client_lng = null;
let client_user_agent = navigator.userAgent;
let client_mac = null;

const codigoReferido = <?php echo json_encode($codigo_referido_url); ?>;
const tieneReferido  = <?php echo $tiene_referido ? 'true' : 'false'; ?>;
const nombreReferidor = <?php echo json_encode($nombre_referidor); ?>;

console.log('Referido:', { codigoReferido, tieneReferido, nombreReferidor });



fetch("https://api64.ipify.org?format=json")
    .then(r => r.json())
    .then(d => client_ip = d.ip)
    .catch(() => client_ip = null);

fetch("https://ipapi.co/json/")
    .then(r => r.json())
    .then(d => {
        client_city = d.city || null;
        client_country = d.country_name || null;
        client_lat = d.latitude || null;
        client_lng = d.longitude || null;
    })
    .catch(() => {});

    // ====================================================================
// 🔥 MODIFICACIÓN DEL CHAT PARA REFERIDOS
// ====================================================================

// Modificar UI del formulario inicial si hay referido
if (tieneReferido && nombreReferidor) {
    document.addEventListener('DOMContentLoaded', () => {
        const initialForm = document.getElementById('chat-initial-form');
        
        if (initialForm) {
            // Agregar badge de referido
            const badge = document.createElement('div');
            badge.className = 'referral-badge';
            badge.style.cssText = `
                background: linear-gradient(135deg, #3b82f6, #22c55e);
                color: white;
                padding: 12px;
                border-radius: 10px;
                margin-bottom: 15px;
                text-align: center;
                font-size: 13px;
            `;
            badge.innerHTML = `
                <div style="margin-bottom: 4px; opacity: 0.9;">
                    Fuiste invitado por:
                </div>
                <div style="font-weight: 700; font-size: 16px;">
                    👤 ${nombreReferidor}
                </div>
            `;
            
            // Insertar al inicio del formulario
            initialForm.insertBefore(badge, initialForm.firstChild);
            
            // Modificar el título
            const h3 = initialForm.querySelector('h3');
            if (h3) {
                h3.textContent = 'Chat Directo con tu Asesor';
            }
            
            // Modificar el párrafo
            const p = initialForm.querySelector('p');
            if (p) {
                p.textContent = 'Serás atendido directamente por tu asesor referidor';
            }
        }
    });
}



/* ===================================================================
   INICIAR CHAT (FORMULARIO INICIAL)
=================================================================== */
document.getElementById("start-chat-btn").addEventListener("click", async () => {

    const nombre = document.getElementById("chat-name").value.trim();

    // 🔐 Validación mínima (solo nombre)
    if (!nombre) {
        alert("Por favor ingresa tu nombre");
        return;
    }

    try {
        // ---------------------------------------------
        // INICIAR CHAT (SIN DNI NI TELÉFONO)
        // ---------------------------------------------
        const response = await fetch("api/chat/start_chat.php", {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "Accept": "application/json"
            },
            body: JSON.stringify({
                nombre,
                departamento_id: 1,
                ip_cliente: client_ip,
                mac_dispositivo: client_mac,
                user_agent: client_user_agent,
                ciudad: client_city,
                pais: client_country,
                latitud: client_lat,
                longitud: client_lng,
                codigo_referido: codigoReferido || ''
            })
        });

        const data = await response.json();

        if (!data.success) {
            alert("Error al iniciar el chat: " + (data.message || "Error desconocido"));
            return;
        }

        // ---------------------------------------------
        // GUARDAR DATOS DE SESIÓN
        // ---------------------------------------------
        chatId     = data.data.chat_id;
        clienteId  = data.data.cliente_id;
        clientName = nombre;

        // ✅ ASIGNAR A window para que chatbot_flow.js lo vea
        window.chatId = chatId;

        console.log("✅ Chat iniciado:", { chatId, clienteId, clientName });

        // ---------------------------------------------
        // ACTUALIZAR UI
        // ---------------------------------------------
        initialForm.style.display = "none";
        chatMessages.style.display = "flex";
        chatInputArea.style.display = "flex";

        addBotMessage(`¡Hola ${nombre}! 😊 Vamos a comenzar.`);

        // ---------------------------------------------
        // ✅ INICIAR FLUJO DEL CHATBOT (DESPUÉS DE CREAR chatId)
        // ---------------------------------------------
        setTimeout(() => {
            if (typeof window.iniciarFlujoChatbot === 'function') {
                window.iniciarFlujoChatbot();
            } else {
                console.error("❌ La función iniciarFlujoChatbot no está disponible");
            }
        }, 800);

    } catch (error) {
        console.error("Error al iniciar el chat:", error);
        alert("Error de conexión. Revisa tu internet e intenta nuevamente.");
    }
});



/* ===================================================================
   ENVÍO DE MENSAJE (TEXTO) – FIX ANTI-DUPLICADO
=================================================================== */

// Bloqueo para evitar doble envío
let sending = false;

chatSendBtn.addEventListener("click", (e) => {
    e.preventDefault();
    enviarMensajeTexto();
});

chatInput.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
        e.preventDefault();
        e.stopPropagation();
        enviarMensajeTexto();
    }
});

// ===================================================================
// MENSAJE HUMANO (CLIENTE ↔ ASESOR)
// ===================================================================
async function enviarMensajeLibre(text) {

    if (!chatId) {
        console.warn("Chat no iniciado");
        return;
    }

    const formData = new FormData();
    formData.append("chat_id", chatId);
    formData.append("sender", "cliente");
    formData.append("message", text);

    try {
        const response = await fetch("api/messages/send_message.php", {
            method: "POST",
            body: formData
        });

        const data = await response.json();

        if (!data.success) {
            console.error("Error enviando mensaje libre:", data.message);
            return;
        }

        // Mostrar mensaje del cliente
        addUserMessage(text);

        // ✅ Reset inactividad (solo aplica si ya hay asesor y chatId)
        resetInactivityTimers();

        // (opcional) actualizar último ID
        if (data.data && data.data.mensaje_id) {
            lastMessageId = data.data.mensaje_id;
        }

    } catch (error) {
        console.error("Error de conexión enviando mensaje libre:", error);
    }
}


// ===================================================================
// FUNCIÓN CENTRAL DE ENVÍO DE MENSAJES
// ===================================================================
async function enviarMensajeTexto() {

    if (sending) return;
    sending = true;

    const text = chatInput.value.trim();
    if (!text) {
        sending = false;
        return;
    }

    chatInput.value = "";

    try {
        // 🔥 SI EL CHATBOT SIGUE ACTIVO → FLUJO
        if (window.chatbotActivo) {
            // ✅ GUARDAR MENSAJE DEL CLIENTE EN LA BASE DE DATOS
            await guardarMensajeCliente(text);
            
            await handleUserInput(text);

            // (opcional) si querés que el chatbot también reinicie timers cuando ya hay asesor
            // resetInactivityTimers();
        }
        // 🔥 CHAT HUMANO
        else {
            await enviarMensajeLibre(text); // acá ya resetea timers
        }
    } catch (err) {
        console.error("Error enviando mensaje:", err);
    }

    sending = false;
}

/* ===================================================================
   💾 GUARDAR MENSAJE DEL CLIENTE EN LA BASE DE DATOS
=================================================================== */
async function guardarMensajeCliente(mensaje) {
    if (!chatId) return;
    
    try {
        const response = await fetch('/system/api/chat/save_client_message.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                chat_id: chatId,
                mensaje: mensaje
            })
        });
        
        const data = await response.json();
        
        if (!data.success) {
            console.warn('No se pudo guardar mensaje del cliente:', data.message);
        }
    } catch (error) {
        console.error('Error guardando mensaje del cliente:', error);
    }
}



/* ===================================================================
   FUNCIONES DEL CHATBOT FLOW (VIENEN DE chatbot_flow.js)
=================================================================== */

// Variable global para el asesor
// ===============================
// DATOS DEL ASESOR ACTUAL
// ===============================
let currentAsesorData = null;
// ===============================
// ACTUALIZAR HEADER CON ASESOR
// ===============================
function updateChatHeaderAsesor() {
    const nombreEl = document.getElementById("chat-asesor-nombre");
    const statusEl = document.getElementById("chat-asesor-status");
    const btnPerfil = document.getElementById("btn-ver-perfil-asesor");

    if (!nombreEl || !statusEl) return;

    if (!currentAsesorData) {
        nombreEl.textContent = "Asistencia en Línea";
        statusEl.textContent = "🟢 En línea";
        if (btnPerfil) btnPerfil.style.display = "none";
        return;
    }

    nombreEl.textContent = currentAsesorData.nombre || "Asesor";
    statusEl.textContent = "🟢 En línea";

    if (btnPerfil) btnPerfil.style.display = "flex";
}


/* ===================================================================
   FUNCIÓN PARA HORA DE BUENOS AIRES
=================================================================== */
function getBuenosAiresTime(timestamp = null) {
    const date = timestamp ? new Date(timestamp) : new Date();
    
    const options = {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'America/Argentina/Buenos_Aires',
        hour12: false
    };
    
    return date.toLocaleTimeString('es-AR', options);
}

/* ===================================================================
   FUNCIÓN PARA CONVERTIR URLs EN LINKS CLICKEABLES
=================================================================== */
function linkify(text) {
    const urlRegex = /(https?:\/\/[^\s]+)/g;
    return text.replace(urlRegex, (url) => {
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="text-blue-400 hover:text-blue-300 underline">${url}</a>`;
    });
}

// ===============================
// MENSAJE DEL ASESOR / BOT
// ===============================
function addBotMessage(text, timestamp = Date.now()) {
    const msg = document.createElement("div");

    // Helper para hora en Buenos Aires
    const time = getBuenosAiresTime(timestamp);

    // Procesar texto con links clickeables
    const escapedText = escapeHtml(text);
    const linkedText = linkify(escapedText);

    // ===============================
    // MENSAJE DE ASESOR (HUMANO)
    // ===============================
    if (currentAsesorData && currentAsesorData.foto_perfil) {
        msg.className = "msg-bot-with-avatar";
        msg.innerHTML = `
            <img 
                src="${currentAsesorData.foto_perfil}" 
                alt="${currentAsesorData.nombre || 'Asesor'}"
                class="asesor-avatar"
                onclick="showAsesorProfile()"
                title="Ver perfil de ${currentAsesorData.nombre || 'Asesor'}"
            >
            <div class="msg-bot-content">
                <div class="msg-bot">
                    <div class="text-xs text-gray-400 mb-1 font-semibold">
                        ${currentAsesorData.nombre || 'Asesor'}
                    </div>
                    ${linkedText.replace(/\n/g, "<br>")}
                    <div class="text-[10px] text-gray-400 text-right mt-1">
                        ${time}
                    </div>
                </div>
            </div>
        `;
    } 
    // ===============================
    // MENSAJE DE BOT / SISTEMA
    // ===============================
    else {
        msg.className = "msg-bot";
        msg.innerHTML = `
            ${linkedText.replace(/\n/g, "<br>")}
            <div class="text-[10px] text-gray-400 text-right mt-1">
                ${time}
            </div>
        `;
    }

    chatMessages.appendChild(msg);
    scrollToBottom();
}

// ===============================
// MENSAJE DEL CLIENTE
// ===============================
function addUserMessage(text) {
    const msg = document.createElement("div");
    msg.className = "msg-user";
    msg.innerHTML = escapeHtml(text);
    chatMessages.appendChild(msg);
    scrollToBottom();
}

// ===============================
// MENSAJE DEL ASESOR CON ARCHIVO
// ===============================
function addBotFileMessage(fileData) {
    const msg = document.createElement("div");
    msg.className = "flex justify-start mb-4";

    const icon =
        fileData.name.endsWith(".pdf")  ? "📕" :
        fileData.name.endsWith(".png")  ? "🖼️" :
        fileData.name.endsWith(".jpg")  ? "🖼️" :
        fileData.name.endsWith(".jpeg") ? "🖼️" :
        fileData.name.endsWith(".webp") ? "🖼️" :
        fileData.name.endsWith(".xls")  ? "📊" :
        fileData.name.endsWith(".xlsx") ? "📊" : "📄";

    // Procesar texto con links clickeables
    const linkedText = fileData.text ? linkify(escapeHtml(fileData.text)) : "";

    msg.innerHTML = `
        <div class="max-w-[80%] flex gap-2">
            ${currentAsesorData && currentAsesorData.foto_perfil ? `
                <img 
                    src="${currentAsesorData.foto_perfil}"
                    class="asesor-avatar"
                    onclick="showAsesorProfile()"
                    title="Ver perfil de ${currentAsesorData.nombre || 'Asesor'}"
                >
            ` : ``}

            <div class="bg-gray-800 text-white rounded-2xl px-4 py-2.5 inline-block">
                ${linkedText ? `<p class="text-sm mb-2">${linkedText}</p>` : ""}
                <a href="${fileData.url}" target="_blank" class="msg-file">
                    <span class="file-icon">${icon}</span>
                    <div class="file-info">
                        <span class="file-name">${escapeHtml(fileData.name)}</span>
                        <span class="file-size">${formatFileSize(fileData.size)}</span>
                    </div>
                </a>
            </div>
        </div>
    `;

    chatMessages.appendChild(msg);
    scrollToBottom();
}

// ===============================
// MENSAJE DEL SISTEMA (TRANSFERENCIAS, AVISOS)
// ===============================
function addSystemMessage(text) {
    const msg = document.createElement("div");
    msg.className = "text-center text-xs text-gray-400 my-3";
    msg.innerHTML = text;
    chatMessages.appendChild(msg);
    scrollToBottom();
}

/* ===================================================================
   📨 ESCUCHAR MENSAJES DEL ASESOR (POLLING) - TEXTO, ARCHIVOS, MULTI ASESOR
=================================================================== */
setInterval(async () => {

    if (!chatId) return;

    try {
        const r = await fetch(
            `api/messages/get_new_messages.php?chat_id=${chatId}&last_id=${lastMessageId}`
        );

        const d = await r.json();

        if (!d.success) return;

        /* ===============================
           INDICADOR "ESCRIBIENDO..."
        =============================== */
        const typingIndicator = document.getElementById("typing-indicator");
        if (typingIndicator) {
            if (d.typing) typingIndicator.classList.remove("hidden");
            else typingIndicator.classList.add("hidden");
        }

        if (!d.data || d.data.length === 0) return;

        d.data.forEach(msg => {

            let asesorHablo = false;

            /* ===============================
               MULTI ASESOR / TRANSFERENCIA
            =============================== */
            if (msg.asesor && (!currentAsesorData || msg.asesor.id !== currentAsesorData.id)) {

                if (currentAsesorData) {
                    addSystemMessage("🔄 Tu chat está siendo transferido a otro asesor…");
                }

                currentAsesorData = msg.asesor;
                updateChatHeaderAsesor();


                addSystemMessage(
                    `👤 Ahora te atiende ${currentAsesorData.nombre}`
                );
                    lastMessageId = 0;

                // 🔥 Iniciar / resetear inactividad al asignar asesor
                resetInactivityTimers();
                
            }

            /* ===============================
               MENSAJES CON ARCHIVO
            =============================== */
            if (msg.tiene_archivo && msg.archivo) {

                const audioExtensions = ['mp3', 'wav', 'ogg', 'm4a', 'webm'];
                const isAudio = audioExtensions.some(ext =>
                    msg.archivo.nombre.toLowerCase().endsWith('.' + ext)
                );

                if (isAudio) {
                    addAudioMessage(msg.archivo.url, 0, "bot");
                } else {
                    addBotFileMessage({
                        url: msg.archivo.url,
                        name: msg.archivo.nombre,
                        size: msg.archivo.tamano,
                        text: msg.mensaje || ""
                    });
                }

                asesorHablo = true;
            }

            /* ===============================
               MENSAJES DE TEXTO
            =============================== */
            else if (msg.mensaje) {
                addBotMessage(
                    msg.mensaje,
                    msg.created_at ? msg.created_at * 1000 : Date.now()
                );

                asesorHablo = true;
            }

            lastMessageId = msg.id;

            /* ===============================
               🔥 RESET INACTIVIDAD SI HABLÓ EL ASESOR
            =============================== */
            if (asesorHablo) {
                resetInactivityTimers();
            }

            /* ===============================
               NOTIFICACIÓN SI EL CHAT ESTÁ CERRADO
            =============================== */
            if (!isWindowOpen || isMinimized) {
                showNotification();
            }

        });

    } catch (e) {
        console.error("Error polling mensajes:", e);
    }

}, 3000);



/* ===================================================================
   CARGAR DATOS DEL ASESOR
=================================================================== */
async function loadAsesorData(chatId) {
    try {
        const response = await fetch(`/system/api/asesores/get_asesor_info.php?chat_id=${chatId}`);
        const data = await response.json();
        
        if (data.success && data.data) {
            currentAsesorData = data.data;
            updateChatHeaderAsesor();
            console.log("Datos del asesor cargados:", currentAsesorData);
            
            // Mostrar botón de perfil
            const btnPerfil = document.getElementById('btn-ver-perfil-asesor');
            if (btnPerfil) btnPerfil.style.display = 'flex';
        }
    } catch (error) {
        console.error("Error cargando datos del asesor:", error);
    }
}

function showAsesorProfile() {
    if (!currentAsesorData) return;
    
    const modal = document.getElementById("asesor-profile-modal");
    const avatar = document.getElementById("profile-avatar");
    const name = document.getElementById("profile-name");
    const infoContainer = document.getElementById("profile-info");
    
    avatar.src = currentAsesorData.foto_perfil || "default-avatar.png";
    name.textContent = currentAsesorData.nombre || "Asesor";
    
    let infoHTML = "";
    
    if (currentAsesorData.celular) {
        infoHTML += `
            <div class="profile-info-item">
                <span class="profile-icon">📱</span>
                <span class="profile-label">Celular:</span>
                <span class="profile-value">${escapeHtml(currentAsesorData.celular)}</span>
            </div>
        `;
    }
    
    if (currentAsesorData.whatsapp) {
        infoHTML += `
            <div class="profile-info-item">
                <span class="profile-icon">💬</span>
                <span class="profile-label">WhatsApp:</span>
                <a href="https://wa.me/${currentAsesorData.whatsapp}" target="_blank">${escapeHtml(currentAsesorData.whatsapp)}</a>
            </div>
        `;
    }
    
    if (currentAsesorData.telegram) {
        infoHTML += `
            <div class="profile-info-item">
                <span class="profile-icon">✈️</span>
                <span class="profile-label">Telegram:</span>
                <a href="${currentAsesorData.telegram}" target="_blank">Ver perfil</a>
            </div>
        `;
    }
    
    if (currentAsesorData.instagram) {
        infoHTML += `
            <div class="profile-info-item">
                <span class="profile-icon">📷</span>
                <span class="profile-label">Instagram:</span>
                <a href="${currentAsesorData.instagram}" target="_blank">Ver perfil</a>
            </div>
        `;
    }
    
    if (currentAsesorData.facebook) {
        infoHTML += `
            <div class="profile-info-item">
                <span class="profile-icon">👤</span>
                <span class="profile-label">Facebook:</span>
                <a href="${currentAsesorData.facebook}" target="_blank">Ver perfil</a>
            </div>
        `;
    }
    
    if (currentAsesorData.tiktok) {
        infoHTML += `
            <div class="profile-info-item">
                <span class="profile-icon">🎵</span>
                <span class="profile-label">TikTok:</span>
                <a href="${currentAsesorData.tiktok}" target="_blank">Ver perfil</a>
            </div>
        `;
    }
    
    infoContainer.innerHTML = infoHTML || '<p style="text-align:center;color:#64748b;">No hay información adicional disponible</p>';
    
    modal.style.display = "flex";
}

function closeAsesorProfile() {
    document.getElementById("asesor-profile-modal").style.display = "none";
}

// Cerrar modal al hacer clic fuera
document.addEventListener("click", (e) => {
    const modal = document.getElementById("asesor-profile-modal");
    if (e.target === modal) {
        closeAsesorProfile();
    }
});

// Intentar cargar datos del asesor periódicamente
setInterval(() => {
    if (chatId && !currentAsesorData) {
        loadAsesorData(chatId);
    }
}, 5000);

/* ===================================================================
   🎤 GRABACIÓN DE AUDIO
=================================================================== */

let mediaRecorder = null;
let audioChunks = [];
let recordingStartTime = 0;
let recordingInterval = null;

const chatRecordBtn = document.getElementById("chat-record-btn");
const chatAudioFileInput = document.getElementById("chat-audio-file-input");

// Botón para seleccionar archivo de audio
chatRecordBtn.addEventListener("contextmenu", (e) => {
    e.preventDefault();
    chatAudioFileInput.click();
});

// Manejar archivo de audio seleccionado
chatAudioFileInput.addEventListener("change", async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const maxSize = 10 * 1024 * 1024; // 10MB
    if (file.size > maxSize) {
        alert("El archivo es demasiado grande. Máximo 10MB");
        chatAudioFileInput.value = "";
        return;
    }

    await uploadFile(file);
    chatAudioFileInput.value = "";
});

// Botón para grabar audio (click normal)
chatRecordBtn.addEventListener("click", async () => {
    if (!chatId) {
        alert("Debes iniciar el chat primero");
        return;
    }

    if (!mediaRecorder || mediaRecorder.state === "inactive") {
        // Iniciar grabación
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(stream);
            audioChunks = [];

            mediaRecorder.ondataavailable = (event) => {
                audioChunks.push(event.data);
            };

            mediaRecorder.onstop = async () => {
                const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                const audioFile = new File([audioBlob], `audio_${Date.now()}.webm`, { type: 'audio/webm' });
                
                // Detener stream
                stream.getTracks().forEach(track => track.stop());
                
                // Subir audio
                await uploadFile(audioFile);
                
                // Reset UI
                chatRecordBtn.classList.remove("recording");
                chatRecordBtn.innerHTML = "🎤";
                if (recordingInterval) {
                    clearInterval(recordingInterval);
                    recordingInterval = null;
                }
                removeRecordingUI();
            };

            mediaRecorder.start();
            recordingStartTime = Date.now();
            chatRecordBtn.classList.add("recording");
            chatRecordBtn.innerHTML = "⏹";
            
            showRecordingUI();
            
            // Actualizar tiempo
            recordingInterval = setInterval(updateRecordingTime, 100);

        } catch (error) {
            console.error("Error al acceder al micrófono:", error);
            alert("No se pudo acceder al micrófono. Verifica los permisos.");
        }
    } else {
        // Detener grabación
        mediaRecorder.stop();
    }
});

function showRecordingUI() {
    const inputArea = document.getElementById("chat-input-area");
    const existingRecorder = document.getElementById("audio-recorder-ui");
    if (existingRecorder) return;

    const recorderUI = document.createElement("div");
    recorderUI.id = "audio-recorder-ui";
    recorderUI.className = "audio-recorder";
    recorderUI.innerHTML = `
        <div class="audio-recorder-time" id="recording-time">00:00</div>
        <div class="audio-wave">
            <div class="audio-wave-bar" style="animation-delay: 0s;"></div>
            <div class="audio-wave-bar" style="animation-delay: 0.1s;"></div>
            <div class="audio-wave-bar" style="animation-delay: 0.2s;"></div>
            <div class="audio-wave-bar" style="animation-delay: 0.3s;"></div>
            <div class="audio-wave-bar" style="animation-delay: 0.4s;"></div>
        </div>
        <button class="audio-recorder-btn audio-recorder-cancel" onclick="cancelRecording()" title="Cancelar">✖</button>
    `;
    
    inputArea.insertBefore(recorderUI, inputArea.firstChild);
}

function removeRecordingUI() {
    const recorderUI = document.getElementById("audio-recorder-ui");
    if (recorderUI) recorderUI.remove();
}

function updateRecordingTime() {
    const elapsed = Date.now() - recordingStartTime;
    const seconds = Math.floor(elapsed / 1000);
    const minutes = Math.floor(seconds / 60);
    const secs = seconds % 60;
    
    const timeDisplay = document.getElementById("recording-time");
    if (timeDisplay) {
        timeDisplay.textContent = `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }
}

// ✅ VERSIÓN CORREGIDA
function cancelRecording() {
    if (mediaRecorder && mediaRecorder.state !== "inactive") {
        // Detener grabación
        mediaRecorder.stop();
        
        // ✅ Detener stream (libera micrófono)
        if (mediaRecorder.stream) {
            mediaRecorder.stream.getTracks().forEach(track => track.stop());
        }
        
        // Reset UI
        chatRecordBtn.classList.remove("recording");
        chatRecordBtn.innerHTML = "🎤";
        
        if (recordingInterval) {
            clearInterval(recordingInterval);
            recordingInterval = null;
        }
        
        removeRecordingUI();
        audioChunks = [];
    }
}

/* ===================================================================
   🔊 REPRODUCCIÓN DE AUDIO
=================================================================== */

function addAudioMessage(audioUrl, duration, sender = "bot") {
    const msg = document.createElement("div");
    msg.className = sender === "user" ? "flex justify-end mb-4" : "flex justify-start mb-4";

    const audioId = "audio-" + Date.now() + Math.random();
    
    msg.innerHTML = `
        <div class="max-w-[80%]">
            <div class="${sender === "user"
                ? "bg-gradient-to-r from-blue-600 to-green-600 text-white"
                : "bg-gray-800 text-white"} rounded-2xl px-4 py-2.5 inline-block">
                
                <div class="audio-player">
                    <button class="audio-player-btn" onclick="toggleAudioPlayback('${audioId}')" id="play-btn-${audioId}">
                        ▶
                    </button>
                    <div class="audio-player-time" id="time-${audioId}">00:00 / 00:00</div>
                    <div class="audio-player-progress" onclick="seekAudio(event, '${audioId}')">
                        <div class="audio-player-progress-bar" id="progress-${audioId}"></div>
                    </div>
                </div>
                
               <audio id="${audioId}"
       src="/system/audio.php?f=${encodeURIComponent(audioUrl.split('/').pop())}"
       preload="metadata"></audio>

            </div>
        </div>
    `;

    chatMessages.appendChild(msg);
    scrollToBottom();
    
    // ======================================================
    // CONFIGURACIÓN Y CONTROL DEL REPRODUCTOR DE AUDIO
    // ======================================================

    // Configurar audio (se llama desde addAudioMessage)
    const audio = document.getElementById(audioId);

    audio.addEventListener('loadedmetadata', () => {
        updateAudioTime(audioId);
    });

    audio.addEventListener('timeupdate', () => {
        updateAudioProgress(audioId);
    });

    audio.addEventListener('ended', () => {
        const playBtn = document.getElementById(`play-btn-${audioId}`);
        if (playBtn) playBtn.textContent = "▶";
    });
}

// ======================================================
// PLAY / PAUSE
// ======================================================
function toggleAudioPlayback(audioId) {
    const audio = document.getElementById(audioId);
    const playBtn = document.getElementById(`play-btn-${audioId}`);

    if (!audio || !playBtn) return;

    // ▶️ PLAY
    if (audio.paused) {
        audio.play()
            .then(() => {
                playBtn.textContent = "⏸";
                updateAudioTime(audioId);
            })
            .catch(err => {
                console.error("No se pudo reproducir el audio:", err);
                alert("No se pudo reproducir el audio. Verificá el formato o permisos del navegador.");
            });
    }
    // ⏸ PAUSE
    else {
        audio.pause();
        playBtn.textContent = "▶";
    }
}

// ======================================================
// ACTUALIZAR TIEMPO
// ======================================================
function updateAudioTime(audioId) {
    const audio = document.getElementById(audioId);
    const timeDisplay = document.getElementById(`time-${audioId}`);

    if (!audio || !timeDisplay) return;

    const current = formatAudioTime(audio.currentTime);
    const total = formatAudioTime(audio.duration);
    timeDisplay.textContent = `${current} / ${total}`;
}

// ======================================================
// BARRA DE PROGRESO
// ======================================================
function updateAudioProgress(audioId) {
    const audio = document.getElementById(audioId);
    const progressBar = document.getElementById(`progress-${audioId}`);

    if (!audio || !progressBar || !audio.duration) return;

    const percent = (audio.currentTime / audio.duration) * 100;
    progressBar.style.width = percent + "%";

    updateAudioTime(audioId);
}

// ======================================================
// SEEK (CLICK EN BARRA)
// ======================================================
function seekAudio(event, audioId) {
    const audio = document.getElementById(audioId);
    const progressContainer = event.currentTarget;

    if (!audio || !progressContainer || !audio.duration) return;

    const rect = progressContainer.getBoundingClientRect();
    const clickX = event.clientX - rect.left;
    const percent = clickX / rect.width;

    audio.currentTime = percent * audio.duration;
}

// ======================================================
// FORMATO DE TIEMPO
// ======================================================
function formatAudioTime(seconds) {
    if (isNaN(seconds) || !isFinite(seconds)) return "00:00";

    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}


</script>

<script src="/system/js/chatbot_flow.js?v=<?php echo time(); ?>"></script>

</body>
</html>