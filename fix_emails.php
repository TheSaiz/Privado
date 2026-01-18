<?php
require_once 'backend/connection.php';

echo "<h2>Corrigiendo emails temporales...</h2>";

try {
    // Buscar todos los chats con emails válidos en las respuestas del chatbot
    $stmt = $pdo->query("
        SELECT 
            cr.chat_id,
            cr.respuesta as email,
            c.cliente_id
        FROM chatbot_respuestas cr
        INNER JOIN chats c ON c.id = cr.chat_id
        WHERE cr.pregunta_id = 8
        AND cr.respuesta LIKE '%@%'
        AND cr.respuesta NOT LIKE 'temp_%'
        ORDER BY cr.chat_id DESC
    ");
    
    $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Se encontraron " . count($updates) . " emails para actualizar</p>";
    
    foreach ($updates as $row) {
        $email = trim($row['email']);
        $cliente_id = $row['cliente_id'];
        $chat_id = $row['chat_id'];
        
        // Validar email
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Actualizar en usuarios
            $updateStmt = $pdo->prepare("
                UPDATE usuarios 
                SET email = ? 
                WHERE id = ?
            ");
            $updateStmt->execute([$email, $cliente_id]);
            
            echo "<p style='color: green;'>✓ Chat #{$chat_id} - Cliente #{$cliente_id}: Email actualizado a {$email}</p>";
        } else {
            echo "<p style='color: red;'>✗ Chat #{$chat_id}: Email inválido: {$email}</p>";
        }
    }
    
    echo "<h3>Proceso completado!</h3>";
    echo "<p><a href='panel_asesor.php'>Volver al panel</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>