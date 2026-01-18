<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');
session_start();

// Verificar sesión
if (!isset($_SESSION['asesor_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/connection.php';

$asesor_id = $_SESSION['asesor_id'];
$asesor_nombre = $_SESSION['asesor_nombre'];

// ============================
// OBTENER O GENERAR CÓDIGO DE REFERIDO
// ============================

// Verificar si existe la columna codigo_referido
$check_column = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'codigo_referido'")->rowCount();

$codigo_referido = null;
$link_referido = null;

if ($check_column > 0) {
    // Obtener código existente
    $stmt = $pdo->prepare("SELECT codigo_referido, link_referido FROM usuarios WHERE id = ?");
    $stmt->execute([$asesor_id]);
    $datos = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($datos && !empty($datos['codigo_referido'])) {
    $codigo_referido = $datos['codigo_referido'];

    // 🔥 FORZAR LINK CORRECTO SIEMPRE
    $link_referido = 'https://prestamolider.com/system/?ref=' . $codigo_referido;

    // 🛠️ Si en la BD estaba mal (/registro), lo corregimos
    if (
        empty($datos['link_referido']) ||
        strpos($datos['link_referido'], '/system/') === false
    ) {
        $stmt = $pdo->prepare(
            "UPDATE usuarios SET link_referido = ? WHERE id = ?"
        );
        $stmt->execute([$link_referido, $asesor_id]);
    }
}
 else {
    // Generar nuevo código si no existe
    $codigo_referido = 'REF-' . strtoupper(substr(md5($asesor_id . time()), 0, 8));
    $link_referido = 'https://prestamolider.com/system/?ref=' . $codigo_referido;
        
        $stmt = $pdo->prepare("UPDATE usuarios SET codigo_referido = ?, link_referido = ? WHERE id = ?");
        $stmt->execute([$codigo_referido, $link_referido, $asesor_id]);
    }
} else {
    // Sistema de referidos no instalado
    $codigo_referido = 'SISTEMA_NO_INSTALADO';
    $link_referido = 'https://prestamolider.com/system/';
}

// ============================
// ESTADÍSTICAS DEL LINK
// ============================

$total_clicks = 0;
$total_conversiones = 0;
$tasa_conversion = 0;

if ($check_column > 0) {
    // Verificar si existe tabla de clicks
    $check_table = $pdo->query("SHOW TABLES LIKE 'referidos_clicks'")->rowCount();
    
    if ($check_table > 0) {
        // Total de clicks
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM referidos_clicks WHERE asesor_id = ?");
        $stmt->execute([$asesor_id]);
        $clicks_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_clicks = $clicks_data['total'] ?? 0;
        
        // Total de conversiones
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM referidos_clicks WHERE asesor_id = ? AND convertido = 1");
        $stmt->execute([$asesor_id]);
        $conv_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_conversiones = $conv_data['total'] ?? 0;
        
        // Calcular tasa de conversión
        if ($total_clicks > 0) {
            $tasa_conversion = round(($total_conversiones / $total_clicks) * 100, 2);
        }
    }
}

// ============================
// REFERIDOS ACTIVOS
// ============================

$total_referidos = 0;
$referidos_activos = 0;

if ($check_column > 0) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM usuarios WHERE referido_por = ? AND rol = 'asesor'");
    $stmt->execute([$asesor_id]);
    $ref_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_referidos = $ref_data['total'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM usuarios WHERE referido_por = ? AND rol = 'asesor' AND estado = 'activo'");
    $stmt->execute([$asesor_id]);
    $ref_activos = $stmt->fetch(PDO::FETCH_ASSOC);
    $referidos_activos = $ref_activos['total'] ?? 0;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mi Link de Referidos - Asesor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: #f8fafc;
            overflow-x: hidden;
        }

        .page-content {
            margin-left: 260px;
            min-width: 0;
            padding: 2rem;
        }

        @media (max-width: 768px) {
            .page-content {
                margin-left: 0 !important;
                padding: 1rem;
            }
        }

        .card {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .link-container {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 2px dashed #3b82f6;
            border-radius: 1rem;
            padding: 2rem;
            text-align: center;
            margin: 2rem 0;
        }

        .link-box {
            background: white;
            border: 2px solid #3b82f6;
            border-radius: 0.75rem;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 1.1rem;
            color: #1e40af;
            word-break: break-all;
            margin: 1rem 0;
            position: relative;
        }

        .copy-btn {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 0.5rem;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.5rem;
        }

        .copy-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }

        .copy-btn:active {
            transform: translateY(0);
        }

        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: #111827;
            margin: 0.5rem 0;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
        }

        .social-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            color: white;
            text-decoration: none;
            justify-content: center;
        }

        .social-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .whatsapp-btn {
            background: linear-gradient(135deg, #25D366, #128C7E);
        }

        .facebook-btn {
            background: linear-gradient(135deg, #1877F2, #0C63D4);
        }

        .twitter-btn {
            background: linear-gradient(135deg, #1DA1F2, #0C85D0);
        }

        .email-btn {
            background: linear-gradient(135deg, #EA4335, #C5221F);
        }

        .telegram-btn {
            background: linear-gradient(135deg, #0088cc, #006699);
        }

        .icon-large {
            font-size: 2rem;
        }

        .qr-container {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            text-align: center;
            border: 2px solid #e5e7eb;
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #10b981;
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            display: none;
            align-items: center;
            gap: 0.5rem;
            z-index: 9999;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .instructions {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 4px solid #f59e0b;
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin: 2rem 0;
        }

        .instructions h3 {
            color: #92400e;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .instructions ul {
            list-style: none;
            padding: 0;
            color: #78350f;
        }

        .instructions li {
            padding: 0.5rem 0;
            padding-left: 1.5rem;
            position: relative;
        }

        .instructions li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #f59e0b;
            font-weight: bold;
        }

        .code-badge {
            display: inline-block;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 1.25rem;
            letter-spacing: 0.1em;
        }
    </style>
</head>
<body>

<?php include 'sidebar_asesores.php'; ?>

<div class="page-content">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Mi Link de Referidos</h1>
        <p class="text-gray-600">Comparte tu link y construye tu red de asesores</p>
    </div>

    <?php if ($codigo_referido === 'SISTEMA_NO_INSTALADO'): ?>
        <!-- Sistema no instalado -->
        <div class="card">
            <div class="text-center py-12">
                <span class="material-icons-outlined text-gray-300" style="font-size: 5rem;">link_off</span>
                <h3 class="text-2xl font-bold text-gray-800 mt-4 mb-2">Sistema de Referidos No Disponible</h3>
                <p class="text-gray-600 mb-4">El sistema de referidos aún no está configurado en tu cuenta.</p>
                <p class="text-sm text-gray-500">Contacta al administrador para activar esta funcionalidad.</p>
            </div>
        </div>
    <?php else: ?>

    <!-- Estadísticas -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="stat-card">
            <div class="text-4xl mb-2">👥</div>
            <div class="stat-value"><?php echo $total_referidos; ?></div>
            <div class="stat-label">Total Referidos</div>
        </div>

        <div class="stat-card">
            <div class="text-4xl mb-2">✅</div>
            <div class="stat-value"><?php echo $referidos_activos; ?></div>
            <div class="stat-label">Referidos Activos</div>
        </div>

        <div class="stat-card">
            <div class="text-4xl mb-2">👆</div>
            <div class="stat-value"><?php echo $total_clicks; ?></div>
            <div class="stat-label">Clicks en tu Link</div>
        </div>

        <div class="stat-card">
            <div class="text-4xl mb-2">📈</div>
            <div class="stat-value"><?php echo $tasa_conversion; ?>%</div>
            <div class="stat-label">Tasa de Conversión</div>
        </div>
    </div>

    <!-- Tu Código de Referido -->
    <div class="card mb-6">
        <div class="text-center mb-4">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Tu Código de Referido</h2>
            <p class="text-gray-600">Este código identifica todos tus referidos</p>
        </div>
        <div class="flex justify-center">
            <div class="code-badge">
                <?php echo htmlspecialchars($codigo_referido); ?>
            </div>
        </div>
    </div>

    <!-- Link de Referidos -->
    <div class="card">
        <div class="link-container">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Tu Link de Referidos</h2>
            <p class="text-gray-600 mb-4">Comparte este link para invitar nuevos asesores</p>
            
            <div class="link-box" id="referral-link">
                <?php echo htmlspecialchars($link_referido); ?>
            </div>

            <div class="flex flex-wrap justify-center gap-3">
                <button onclick="copyToClipboard()" class="copy-btn">
                    <span class="material-icons-outlined">content_copy</span>
                    Copiar Link
                </button>
                <button onclick="copyCode()" class="copy-btn" style="background: linear-gradient(135deg, #6366f1, #4f46e5);">
                    <span class="material-icons-outlined">qr_code</span>
                    Copiar Código
                </button>
            </div>
        </div>

        <!-- Compartir en Redes Sociales -->
        <div class="mt-8">
            <h3 class="text-xl font-bold text-gray-800 mb-4 text-center">Compartir en Redes Sociales</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                <a href="#" onclick="shareWhatsApp(); return false;" class="social-btn whatsapp-btn">
                    <span style="font-size: 1.5rem;">📱</span>
                    WhatsApp
                </a>

                <a href="#" onclick="shareFacebook(); return false;" class="social-btn facebook-btn">
                    <span style="font-size: 1.5rem;">👤</span>
                    Facebook
                </a>

                <a href="#" onclick="shareTwitter(); return false;" class="social-btn twitter-btn">
                    <span style="font-size: 1.5rem;">🐦</span>
                    Twitter
                </a>

                <a href="#" onclick="shareTelegram(); return false;" class="social-btn telegram-btn">
                    <span style="font-size: 1.5rem;">✈️</span>
                    Telegram
                </a>

                <a href="#" onclick="shareEmail(); return false;" class="social-btn email-btn">
                    <span style="font-size: 1.5rem;">✉️</span>
                    Email
                </a>
            </div>
        </div>
    </div>

    <!-- Instrucciones -->
    <div class="instructions mt-8">
        <h3>📋 Cómo usar tu link de referidos:</h3>
        <ul>
            <li>Comparte tu link único con personas interesadas en ser asesores</li>
            <li>Cuando se registren usando tu link, automáticamente serán marcados como tus referidos</li>
            <li>Podrás ver el progreso y actividad de tus referidos desde el dashboard</li>
            <li>Puedes compartir tu link en redes sociales, WhatsApp, email o cualquier medio</li>
            <li>También puedes compartir solo tu código de referido: <strong><?php echo htmlspecialchars($codigo_referido); ?></strong></li>
        </ul>
    </div>

    <!-- QR Code -->
    <div class="qr-container mt-8">
        <h3 class="text-xl font-bold text-gray-800 mb-4">Código QR</h3>
        <div id="qr-code" class="flex justify-center mb-4">
            <!-- QR Code generado dinámicamente -->
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($link_referido); ?>" 
                 alt="QR Code" 
                 class="border-4 border-gray-200 rounded-lg">
        </div>
        <p class="text-sm text-gray-600 mb-4">Descarga o comparte este QR para que te escaneen</p>
        <a href="https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=<?php echo urlencode($link_referido); ?>" 
           download="mi-qr-referidos.png"
           class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
            <span class="material-icons-outlined">download</span>
            Descargar QR
        </a>
    </div>

    <?php endif; ?>
</div>

<!-- Toast de notificación -->
<div id="toast" class="toast">
    <span class="material-icons-outlined">check_circle</span>
    <span id="toast-message">¡Copiado!</span>
</div>

<script>
const referralLink = <?php echo json_encode($link_referido); ?>;
const referralCode = <?php echo json_encode($codigo_referido); ?>;

function copyToClipboard() {
    navigator.clipboard.writeText(referralLink).then(() => {
        showToast('¡Link copiado al portapapeles!');
    }).catch(() => {
        // Fallback para navegadores antiguos
        const textArea = document.createElement('textarea');
        textArea.value = referralLink;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showToast('¡Link copiado al portapapeles!');
    });
}

function copyCode() {
    navigator.clipboard.writeText(referralCode).then(() => {
        showToast('¡Código copiado al portapapeles!');
    }).catch(() => {
        const textArea = document.createElement('textarea');
        textArea.value = referralCode;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showToast('¡Código copiado al portapapeles!');
    });
}

function showToast(message) {
    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toast-message');
    toastMessage.textContent = message;
    toast.style.display = 'flex';
    
    setTimeout(() => {
        toast.style.display = 'none';
    }, 3000);
}

function shareWhatsApp() {
    const text = `¡Únete a Préstamo Líder como asesor! 💼

Usa mi código de referido: ${referralCode}

Regístrate aquí: ${referralLink}

¡Forma parte del equipo! 🚀`;
    
    const url = `https://wa.me/?text=${encodeURIComponent(text)}`;
    window.open(url, '_blank');
}

function shareFacebook() {
    const url = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(referralLink)}`;
    window.open(url, '_blank', 'width=600,height=400');
}

function shareTwitter() {
    const text = `¡Únete a Préstamo Líder como asesor! Usa mi código: ${referralCode} 💼🚀`;
    const url = `https://twitter.com/intent/tweet?text=${encodeURIComponent(text)}&url=${encodeURIComponent(referralLink)}`;
    window.open(url, '_blank', 'width=600,height=400');
}

function shareTelegram() {
    const text = `¡Únete a Préstamo Líder como asesor! Usa mi código: ${referralCode}`;
    const url = `https://t.me/share/url?url=${encodeURIComponent(referralLink)}&text=${encodeURIComponent(text)}`;
    window.open(url, '_blank');
}

function shareEmail() {
    const subject = 'Invitación para ser Asesor en Préstamo Líder';
    const body = `Hola!

Te invito a unirte a Préstamo Líder como asesor.

Usa mi código de referido: ${referralCode}

Regístrate aquí: ${referralLink}

¡Forma parte de un gran equipo!

Saludos,
${<?php echo json_encode($asesor_nombre); ?>}`;
    
    const url = `mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
    window.location.href = url;
}

// Tracking de clicks (opcional)
function trackShare(platform) {
    fetch('api/referidos/track_share.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            asesor_id: <?php echo $asesor_id; ?>,
            platform: platform
        })
    }).catch(console.error);
}
</script>

</body>
</html>