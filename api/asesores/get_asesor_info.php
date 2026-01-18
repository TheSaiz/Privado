<?php
header('Content-Type: application/json');
require_once '../../backend/connection.php';

$chat_id = $_GET['chat_id'] ?? null;

if (!$chat_id) {
    echo json_encode(['success' => false, 'message' => 'Chat ID requerido']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.nombre,
            u.apellido,
            ap.foto_perfil,
            ap.celular,
            ap.whatsapp,
            ap.telegram,
            ap.instagram,
            ap.facebook,
            ap.tiktok
        FROM chats c
        JOIN usuarios u ON c.asesor_id = u.id
        LEFT JOIN asesores_perfil ap ON u.id = ap.usuario_id
        WHERE c.id = ? AND c.asesor_id IS NOT NULL
    ");
    
    $stmt->execute([$chat_id]);
    $asesor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($asesor) {
        echo json_encode([
            'success' => true,
            'data' => [
                'nombre' => $asesor['nombre'] . ' ' . ($asesor['apellido'] ?? ''),
                'foto_perfil' => $asesor['foto_perfil'] ?? 'default-avatar.png',
                'celular' => $asesor['celular'],
                'whatsapp' => $asesor['whatsapp'],
                'telegram' => $asesor['telegram'],
                'instagram' => $asesor['instagram'],
                'facebook' => $asesor['facebook'],
                'tiktok' => $asesor['tiktok']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No hay asesor asignado']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}