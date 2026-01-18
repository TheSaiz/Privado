<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['cliente_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/../connection.php';

// Leer datos JSON
$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Email inválido']);
    exit;
}

try {
    // Generar código de 6 dígitos
    $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $usuario_id = (int)$_SESSION['cliente_id'];
    
    // Guardar código en la base de datos (válido por 15 minutos)
    $expira = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    
    // Primero verificar si existe el registro
    $stmt = $pdo->prepare("SELECT id FROM clientes_detalles WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    
    if ($stmt->fetch()) {
        // Actualizar registro existente
        $stmt = $pdo->prepare("
            UPDATE clientes_detalles 
            SET email = ?, 
                email_codigo = ?, 
                email_codigo_expira = ?,
                email_verificado = 0
            WHERE usuario_id = ?
        ");
        $stmt->execute([$email, $codigo, $expira, $usuario_id]);
    } else {
        // Crear nuevo registro
        $stmt = $pdo->prepare("
            INSERT INTO clientes_detalles 
            (usuario_id, email, email_codigo, email_codigo_expira, email_verificado) 
            VALUES (?, ?, ?, ?, 0)
        ");
        $stmt->execute([$usuario_id, $email, $codigo, $expira]);
    }
    
    // Enviar email con SMTP
    $subject = 'Código de verificación - Préstamo Líder';
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #f8f9fa; border-radius: 10px;'>
            <div style='background: linear-gradient(135deg, #7c5cff, #5a3fd6); color: white; padding: 30px; border-radius: 10px 10px 0 0; text-align: center;'>
                <h1 style='margin: 0; font-size: 28px;'>Préstamo Líder</h1>
            </div>
            
            <div style='background: white; padding: 30px; border-radius: 0 0 10px 10px;'>
                <h2 style='color: #7c5cff; margin-top: 0;'>Código de Verificación</h2>
                <p style='font-size: 16px;'>Hola,</p>
                <p style='font-size: 16px;'>Tu código de verificación es:</p>
                
                <div style='background: #f0edff; border: 2px solid #7c5cff; border-radius: 8px; padding: 20px; text-align: center; margin: 25px 0;'>
                    <span style='font-size: 36px; font-weight: bold; color: #7c5cff; letter-spacing: 8px;'>{$codigo}</span>
                </div>
                
                <p style='font-size: 14px; color: #666;'>
                    ⏰ Este código expira en <strong>15 minutos</strong>.
                </p>
                <p style='font-size: 14px; color: #666;'>
                    Si no solicitaste este código, podés ignorar este email.
                </p>
                
                <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center; font-size: 12px; color: #999;'>
                    <p>© 2026 Préstamo Líder - Todos los derechos reservados</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Headers para SMTP
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Seguridad Préstamo Líder <seguridad@prestamolider.com>\r\n";
    $headers .= "Reply-To: seguridad@prestamolider.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    
    // Parámetros adicionales para mail()
    $params = "-f seguridad@prestamolider.com";
    
    $emailEnviado = mail($email, $subject, $message, $headers, $params);
    
    echo json_encode([
        'success' => $emailEnviado, 
        'message' => $emailEnviado ? 'Código enviado correctamente' : 'Error al enviar email'
    ]);
    
} catch (Exception $e) {
    error_log("Error enviando código: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error al enviar el código']);
}