<?php
/**
 * TRACK_REFERRAL.PHP
 * Registra clicks en links de referidos y gestiona redirecciones
 */

date_default_timezone_set('America/Argentina/Buenos_Aires');
session_start();

require_once 'backend/connection.php';

// ====================================================================
// CAPTURAR CÓDIGO DE REFERIDO Y DATOS DEL CLICK
// ====================================================================
$codigo_referido = isset($_GET['ref']) ? trim($_GET['ref']) : null;

if (!$codigo_referido) {
    // No hay código de referido, redirigir al registro normal
    header("Location: registro.php");
    exit;
}

// ====================================================================
// VALIDAR QUE EL CÓDIGO EXISTE
// ====================================================================
$stmt = $pdo->prepare("
    SELECT id, nombre, estado 
    FROM usuarios 
    WHERE codigo_referido = ? AND rol = 'asesor'
    LIMIT 1
");
$stmt->execute([$codigo_referido]);
$asesor_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$asesor_data) {
    // Código inválido, redirigir al registro normal
    header("Location: registro.php");
    exit;
}

$asesor_referidor_id = $asesor_data['id'];
$asesor_referidor_nombre = $asesor_data['nombre'];

// ====================================================================
// GUARDAR EN SESIÓN
// ====================================================================
$_SESSION['asesor_referidor_id'] = $asesor_referidor_id;
$_SESSION['asesor_referidor_nombre'] = $asesor_referidor_nombre;
$_SESSION['codigo_referido'] = $codigo_referido;
$_SESSION['referral_tracked'] = time(); // Para evitar duplicados

// ====================================================================
// REGISTRAR CLICK (Solo si es nuevo)
// ====================================================================
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$referrer = $_SERVER['HTTP_REFERER'] ?? null;

// Capturar parámetros UTM si existen
$utm_source = isset($_GET['utm_source']) ? trim($_GET['utm_source']) : null;
$utm_medium = isset($_GET['utm_medium']) ? trim($_GET['utm_medium']) : null;
$utm_campaign = isset($_GET['utm_campaign']) ? trim($_GET['utm_campaign']) : null;

try {
    // Verificar si ya registramos este click recientemente (últimos 30 minutos)
    $check_recent = $pdo->prepare("
        SELECT id FROM referidos_clicks 
        WHERE asesor_id = ? 
          AND ip_address = ? 
          AND fecha_click > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        LIMIT 1
    ");
    $check_recent->execute([$asesor_referidor_id, $ip]);
    
    if (!$check_recent->fetch()) {
        // Es un click nuevo, registrarlo
        $stmt = $pdo->prepare("
            INSERT INTO referidos_clicks 
            (asesor_id, ip_address, user_agent, referrer, utm_source, utm_medium, utm_campaign)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $asesor_referidor_id,
            $ip,
            $user_agent,
            $referrer,
            $utm_source,
            $utm_medium,
            $utm_campaign
        ]);
        
        // Actualizar contador de clicks en estadísticas
        $pdo->prepare("
            UPDATE estadisticas_referidos 
            SET clicks_link = clicks_link + 1
            WHERE asesor_id = ?
        ")->execute([$asesor_referidor_id]);
    }
} catch (Exception $e) {
    // Si hay error en el registro del click, continuar igual
    error_log("Error registrando click de referido: " . $e->getMessage());
}

// ====================================================================
// REDIRIGIR AL REGISTRO
// ====================================================================
header("Location: registro.php");
exit;