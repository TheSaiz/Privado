<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../backend/connection.php';
require_once __DIR__ . '/../middleware/auth.php';

$auth = auth_required();
$usuario_id = (int)$auth['user_id'];

// GET - Obtener datos del perfil
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Obtener datos de usuarios
        $stmt = $pdo->prepare("
            SELECT 
                id, nombre, apellido, email, telefono, 
                codigo_area, fecha_registro, estado
            FROM usuarios
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            exit;
        }
        
        // Obtener datos de clientes_detalles
        $stmt = $pdo->prepare("
            SELECT 
                dni, cuit, nombre_completo, fecha_nacimiento,
                direccion, calle, numero, localidad, ciudad, provincia, codigo_postal,
                banco, cbu,
                tipo_ingreso, monto_ingresos,
                contacto1_nombre, contacto1_relacion, contacto1_telefono,
                contacto2_nombre, contacto2_relacion, contacto2_telefono,
                doc_dni_frente, doc_dni_dorso, doc_selfie_dni,
                doc_comprobante_ingresos, doc_cbu,
                docs_completos, estado_validacion, docs_updated_at
            FROM clientes_detalles
            WHERE usuario_id = ?
            LIMIT 1
        ");
        $stmt->execute([$usuario_id]);
        $detalles = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Si no existe, crear registro vacío
        if (!$detalles) {
            $pdo->prepare("INSERT INTO clientes_detalles (usuario_id) VALUES (?)")->execute([$usuario_id]);
            $stmt->execute([$usuario_id]);
            $detalles = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'personal' => [
                    'nombre' => $usuario['nombre'],
                    'apellido' => $usuario['apellido'],
                    'email' => $usuario['email'],
                    'telefono' => $usuario['telefono'],
                    'codigo_area' => $usuario['codigo_area'],
                    'dni' => $detalles['dni'] ?? '',
                    'cuit' => $detalles['cuit'] ?? '',
                    'nombre_completo' => $detalles['nombre_completo'] ?? '',
                    'fecha_nacimiento' => $detalles['fecha_nacimiento'] ?? '',
                    'fecha_registro' => $usuario['fecha_registro']
                ],
                'direccion' => [
                    'calle' => $detalles['calle'] ?? '',
                    'numero' => $detalles['numero'] ?? '',
                    'localidad' => $detalles['localidad'] ?? '',
                    'ciudad' => $detalles['ciudad'] ?? '',
                    'provincia' => $detalles['provincia'] ?? '',
                    'codigo_postal' => $detalles['codigo_postal'] ?? ''
                ],
                'bancario' => [
                    'banco' => $detalles['banco'] ?? '',
                    'cbu' => $detalles['cbu'] ?? ''
                ],
                'laboral' => [
                    'tipo_ingreso' => $detalles['tipo_ingreso'] ?? '',
                    'monto_ingresos' => $detalles['monto_ingresos'] ?? ''
                ],
                'contactos' => [
                    'contacto1' => [
                        'nombre' => $detalles['contacto1_nombre'] ?? '',
                        'relacion' => $detalles['contacto1_relacion'] ?? '',
                        'telefono' => $detalles['contacto1_telefono'] ?? ''
                    ],
                    'contacto2' => [
                        'nombre' => $detalles['contacto2_nombre'] ?? '',
                        'relacion' => $detalles['contacto2_relacion'] ?? '',
                        'telefono' => $detalles['contacto2_telefono'] ?? ''
                    ]
                ],
                'documentos' => [
                    'doc_dni_frente' => $detalles['doc_dni_frente'] ?? null,
                    'doc_dni_dorso' => $detalles['doc_dni_dorso'] ?? null,
                    'doc_selfie_dni' => $detalles['doc_selfie_dni'] ?? null,
                    'doc_comprobante_ingresos' => $detalles['doc_comprobante_ingresos'] ?? null,
                    'doc_cbu' => $detalles['doc_cbu'] ?? null,
                    'docs_completos' => (bool)($detalles['docs_completos'] ?? false),
                    'estado_validacion' => $detalles['estado_validacion'] ?? 'pendiente',
                    'docs_updated_at' => $detalles['docs_updated_at'] ?? null
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Throwable $e) {
        error_log('Profile GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al obtener perfil']);
    }
}

// POST - Actualizar datos del perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'JSON inválido']);
            exit;
        }
        
        $seccion = $data['seccion'] ?? '';
        
        switch ($seccion) {
            case 'personal':
                $stmt = $pdo->prepare("
                    UPDATE usuarios
                    SET nombre = ?, apellido = ?, email = ?, telefono = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['nombre'] ?? '',
                    $data['apellido'] ?? '',
                    $data['email'] ?? '',
                    $data['telefono'] ?? '',
                    $usuario_id
                ]);
                
                $stmt = $pdo->prepare("
                    UPDATE clientes_detalles
                    SET dni = ?, cuit = ?, nombre_completo = ?, fecha_nacimiento = ?
                    WHERE usuario_id = ?
                ");
                $stmt->execute([
                    $data['dni'] ?? '',
                    $data['cuit'] ?? '',
                    $data['nombre_completo'] ?? '',
                    $data['fecha_nacimiento'] ?? null,
                    $usuario_id
                ]);
                break;
                
            case 'direccion':
                $stmt = $pdo->prepare("
                    UPDATE clientes_detalles
                    SET calle = ?, numero = ?, localidad = ?, ciudad = ?, provincia = ?, codigo_postal = ?,
                        direccion = CONCAT_WS(' ', ?, ?)
                    WHERE usuario_id = ?
                ");
                $stmt->execute([
                    $data['calle'] ?? '',
                    $data['numero'] ?? '',
                    $data['localidad'] ?? '',
                    $data['ciudad'] ?? '',
                    $data['provincia'] ?? '',
                    $data['codigo_postal'] ?? '',
                    $data['calle'] ?? '',
                    $data['numero'] ?? '',
                    $usuario_id
                ]);
                break;
                
            case 'bancario':
                $stmt = $pdo->prepare("
                    UPDATE clientes_detalles
                    SET banco = ?, cbu = ?
                    WHERE usuario_id = ?
                ");
                $stmt->execute([
                    $data['banco'] ?? '',
                    $data['cbu'] ?? '',
                    $usuario_id
                ]);
                break;
                
            case 'laboral':
                $stmt = $pdo->prepare("
                    UPDATE clientes_detalles
                    SET tipo_ingreso = ?, monto_ingresos = ?
                    WHERE usuario_id = ?
                ");
                $stmt->execute([
                    $data['tipo_ingreso'] ?? null,
                    $data['monto_ingresos'] ?? null,
                    $usuario_id
                ]);
                break;
                
            case 'contactos':
                $stmt = $pdo->prepare("
                    UPDATE clientes_detalles
                    SET contacto1_nombre = ?, contacto1_relacion = ?, contacto1_telefono = ?,
                        contacto2_nombre = ?, contacto2_relacion = ?, contacto2_telefono = ?
                    WHERE usuario_id = ?
                ");
                $stmt->execute([
                    $data['contacto1_nombre'] ?? '',
                    $data['contacto1_relacion'] ?? '',
                    $data['contacto1_telefono'] ?? '',
                    $data['contacto2_nombre'] ?? '',
                    $data['contacto2_relacion'] ?? '',
                    $data['contacto2_telefono'] ?? '',
                    $usuario_id
                ]);
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Sección inválida']);
                exit;
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Perfil actualizado correctamente'
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Throwable $e) {
        error_log('Profile POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al actualizar perfil']);
    }
}