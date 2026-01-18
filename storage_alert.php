<?php
/**
 * =====================================================
 * STORAGE ALERT - Gmail SMTP (587 STARTTLS + 465 SSL fallback)
 * URL: /system/storage_alert.php
 * Prueba forzada: /system/storage_alert.php?force=1
 * =====================================================
 */

header('Content-Type: text/plain; charset=UTF-8');

// ===================== CONFIG ======================
define('ALERT_EMAIL', 'thomec86@gmail.com'); // a quién llega
define('SMTP_USER',   'thomec86@gmail.com'); // usuario gmail
define('SMTP_PASS',   'lazczoebcboumsnl');   // App Password (cambiar luego)
define('SERVER_NAME', gethostname());

// Umbral (en %). Para pruebas ponelo en 0 y llamá con ?force=1 si querés.
define('CRITICAL_LEVEL', 70);
define('ALERT_INTERVAL', 1); // horas entre alertas

// Archivo de estado (evita spam de alertas)
$stateFile = __DIR__ . '/.storage_state.json';

// ===================== helpers ======================
function out($msg) { echo $msg . PHP_EOL; }

function getStorage() {
    // En la mayoría de VPS Linux sirve '/', en algunos hosting chroot puede ser distinto.
    $path = '/';
    $total = @disk_total_space($path);
    $free  = @disk_free_space($path);
    if (!$total || !$free) return null;

    $used = $total - $free;
    $percent = round(($used / $total) * 100, 2);

    return [
        'path'    => $path,
        'percent' => $percent,
        'total'   => round($total / 1073741824, 2),
        'used'    => round($used  / 1073741824, 2),
        'free'    => round($free  / 1073741824, 2),
    ];
}

function shouldAlert($percent) {
    global $stateFile;

    $force = isset($_GET['force']) && $_GET['force'] == '1';
    if ($force) return true;

    if ($percent < CRITICAL_LEVEL) return false;

    if (!file_exists($stateFile)) return true;

    $state = json_decode(@file_get_contents($stateFile), true);
    if (!$state || !isset($state['last_alert'])) return true;

    $hours = (time() - (int)$state['last_alert']) / 3600;
    return $hours >= ALERT_INTERVAL;
}

function saveState($percent) {
    global $stateFile;
    @file_put_contents($stateFile, json_encode([
        'last_alert' => time(),
        'percent'    => $percent,
        'date'       => date('Y-m-d H:i:s')
    ]));
}

/**
 * Lee respuesta SMTP (maneja multilinea "250-... \n 250 ...")
 */
function smtpRead($fp) {
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) break;
        $data .= $line;
        // Si el 4to char es espacio, termina la respuesta multilinea.
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $data;
}

function smtpExpect($fp, array $codes, $stepName) {
    $resp = smtpRead($fp);
    if ($resp === '') return [false, "Sin respuesta del servidor en: $stepName"];

    $ok = false;
    foreach ($codes as $code) {
        if (strpos($resp, (string)$code) === 0) { $ok = true; break; }
    }
    if (!$ok) return [false, "Fallo en $stepName. Respuesta: " . trim($resp)];
    return [true, trim($resp)];
}

function smtpWrite($fp, $cmd) {
    fwrite($fp, $cmd . "\r\n");
}

/**
 * Envia por Gmail SMTP.
 * Intenta 587 STARTTLS y si falla, 465 SSL.
 */
function sendViaGmailSMTP($to, $subject, $htmlBody, $from, &$debug) {
    $debug = [];

    // 1) Intento 587 STARTTLS
    $attempts = [
        ['host' => 'smtp.gmail.com', 'port' => 587, 'mode' => 'starttls'],
        ['host' => 'smtp.gmail.com', 'port' => 465, 'mode' => 'ssl'],
    ];

    foreach ($attempts as $a) {
        $host = $a['host'];
        $port = $a['port'];
        $mode = $a['mode'];

        $debug[] = "== Intentando SMTP $host:$port ($mode) ==";

        $remote = ($mode === 'ssl') ? "ssl://$host:$port" : "$host:$port";

        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            $debug[] = "No conecta: $errstr ($errno)";
            continue;
        }

        stream_set_timeout($fp, 20);

        // Greeting
        [$ok, $resp] = smtpExpect($fp, [220], "Greeting 220");
        $debug[] = $resp;
        if (!$ok) { fclose($fp); continue; }

        // EHLO
        smtpWrite($fp, "EHLO " . SERVER_NAME);
        [$ok, $resp] = smtpExpect($fp, [250], "EHLO 250");
        $debug[] = $resp;
        if (!$ok) { fclose($fp); continue; }

        // STARTTLS si aplica
        if ($mode === 'starttls') {
            smtpWrite($fp, "STARTTLS");
            [$ok, $resp] = smtpExpect($fp, [220], "STARTTLS 220");
            $debug[] = $resp;
            if (!$ok) { fclose($fp); continue; }

            $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$cryptoOk) {
                $debug[] = "Fallo al habilitar TLS (stream_socket_enable_crypto).";
                fclose($fp);
                continue;
            }

            // EHLO de nuevo
            smtpWrite($fp, "EHLO " . SERVER_NAME);
            [$ok, $resp] = smtpExpect($fp, [250], "EHLO post-TLS 250");
            $debug[] = $resp;
            if (!$ok) { fclose($fp); continue; }
        }

        // AUTH LOGIN
        smtpWrite($fp, "AUTH LOGIN");
        [$ok, $resp] = smtpExpect($fp, [334], "AUTH LOGIN 334");
        $debug[] = $resp;
        if (!$ok) { fclose($fp); continue; }

        smtpWrite($fp, base64_encode(SMTP_USER));
        [$ok, $resp] = smtpExpect($fp, [334], "USER 334");
        $debug[] = $resp;
        if (!$ok) { fclose($fp); continue; }

        smtpWrite($fp, base64_encode(SMTP_PASS));
        [$ok, $resp] = smtpExpect($fp, [235], "PASS 235 (auth ok)");
        $debug[] = $resp;
        if (!$ok) { fclose($fp); continue; }

        // MAIL FROM
        smtpWrite($fp, "MAIL FROM:<$from>");
        [$ok, $resp] = smtpExpect($fp, [250], "MAIL FROM 250");
        $debug[] = $resp;
        if (!$ok) { fclose($fp); continue; }

        // RCPT TO
        smtpWrite($fp, "RCPT TO:<$to>");
        [$ok, $resp] = smtpExpect($fp, [250, 251], "RCPT TO 250/251");
        $debug[] = $resp;
        if (!$ok) { fclose($fp); continue; }

        // DATA
        smtpWrite($fp, "DATA");
        [$ok, $resp] = smtpExpect($fp, [354], "DATA 354");
        $debug[] = $resp;
        if (!$ok) { fclose($fp); continue; }

        // Cabeceras completas + CRLF
        $headers  = "From: $from\r\n";
        $headers .= "To: $to\r\n";
        $headers .= "Subject: $subject\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "\r\n";

        // Importante: terminar el DATA con <CRLF>.<CRLF>
        $data = $headers . $htmlBody . "\r\n.\r\n";
        fwrite($fp, $data);

        [$ok, $resp] = smtpExpect($fp, [250], "DATA accepted 250");
        $debug[] = $resp;

        smtpWrite($fp, "QUIT");
        smtpRead($fp);
        fclose($fp);

        if ($ok) return true;
    }

    return false;
}

function buildEmailHtml($storage) {
    $percent = $storage['percent'];
    $color = $percent >= 80 ? '#dc2626' : '#f59e0b';
    $icon  = $percent >= 80 ? '🚨' : '⚠️';

    return "<!doctype html><html><body style='font-family:Arial;background:#f3f4f6;padding:30px'>
        <div style='max-width:640px;margin:auto;background:#fff;border-radius:12px;overflow:hidden'>
            <div style='background:$color;color:white;padding:28px;text-align:center'>
                <div style='font-size:56px;line-height:1'>$icon</div>
                <h2 style='margin:10px 0 0'>ALERTA DE ALMACENAMIENTO</h2>
                <div style='opacity:.95;margin-top:8px'>".htmlspecialchars(SERVER_NAME)."</div>
            </div>
            <div style='padding:28px;text-align:center'>
                <div style='font-size:54px;font-weight:700;color:$color'>{$percent}%</div>
                <div style='color:#6b7280;margin-top:6px'>Uso de almacenamiento</div>

                <div style='height:22px;border-radius:999px;background:#e5e7eb;overflow:hidden;margin:22px 0'>
                    <div style='height:100%;width:{$percent}%;background:$color'></div>
                </div>

                <div style='color:#111827'>
                    <div><b>Total:</b> {$storage['total']} GB</div>
                    <div><b>Usado:</b> {$storage['used']} GB</div>
                    <div><b>Libre:</b> {$storage['free']} GB</div>
                </div>

                <hr style='margin:22px 0;border:none;border-top:1px solid #e5e7eb'>

                <div style='font-size:12px;color:#6b7280'>
                    Fecha: ".date('d/m/Y H:i:s')."<br>
                    PHP: ".PHP_VERSION."<br>
                    Path: ".htmlspecialchars($storage['path'])."
                </div>
            </div>
        </div>
    </body></html>";
}

// ===================== RUN ======================
out("▶ Storage monitor (Gmail SMTP)");
out("Servidor: " . SERVER_NAME);
out("Modo force: " . ((isset($_GET['force']) && $_GET['force']=='1') ? 'SI' : 'NO'));
out("------------------------------------------------");

$storage = getStorage();
if (!$storage) {
    out("❌ No se pudo leer el disco con disk_total_space('/').");
    exit;
}

out("📊 Uso actual: {$storage['percent']}%  (Usado {$storage['used']} GB / Total {$storage['total']} GB)");

if (!shouldAlert($storage['percent'])) {
    out("ℹ️ No corresponde enviar alerta (umbral/intervalo).");
    out("   - Umbral: " . CRITICAL_LEVEL . "%");
    out("   - Intervalo: " . ALERT_INTERVAL . " hs");
    out("   Tip: probá ahora con: /system/storage_alert.php?force=1");
    exit;
}

$to      = ALERT_EMAIL;
$from    = SMTP_USER;
$subject = "🚨 ALERTA: Almacenamiento {$storage['percent']}% - " . SERVER_NAME;
$html    = buildEmailHtml($storage);

out("📧 Enviando a: $to");
$debug = [];
$ok = sendViaGmailSMTP($to, $subject, $html, $from, $debug);

if ($ok) {
    saveState($storage['percent']);
    out("✅ EMAIL ENVIADO (SMTP OK)");
} else {
    out("❌ NO SE PUDO ENVIAR (SMTP FAIL)");
    out("---- DEBUG SMTP ----");
    foreach ($debug as $line) out($line);
    out("--------------------");
    out("Sugerencia: probá también revisar firewall/salida a puertos 587/465 desde el servidor.");
}
