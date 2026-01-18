<?php
session_start();
require_once __DIR__ . '/../connection.php';

// Validar sesión
if (!isset($_SESSION['cliente_id'])) {
    $_SESSION['error_msg'] = 'Sesión no válida';
    header('Location: ../../prestamos_clientes.php');
    exit;
}

$usuario_id = (int)$_SESSION['cliente_id'];

// Validar que la documentación esté completa
$stmt = $pdo->prepare("SELECT docs_completos FROM clientes_detalles WHERE usuario_id = ? LIMIT 1");
$stmt->execute([$usuario_id]);
$cliente = $stmt->fetch();

if (!$cliente || (int)($cliente['docs_completos'] ?? 0) !== 1) {
    $_SESSION['error_msg'] = 'Debés completar tu documentación antes de solicitar un préstamo';
    header('Location: ../../prestamos_clientes.php');
    exit;
}

// Validar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_msg'] = 'Método no permitido';
    header('Location: ../../prestamos_clientes.php');
    exit;
}

// Obtener y validar datos del formulario
$monto_solicitado = (float)($_POST['monto_solicitado'] ?? 0);
$cuotas_solicitadas = (int)($_POST['cuotas_solicitadas'] ?? 0);
$frecuencia_solicitada = $_POST['frecuencia_solicitada'] ?? '';
$destino_credito = trim($_POST['destino_credito'] ?? '');
$comentarios_cliente = trim($_POST['comentarios_cliente'] ?? '');

// Validaciones
if ($monto_solicitado < 5000 || $monto_solicitado > 500000) {
    $_SESSION['error_msg'] = 'El monto debe estar entre $5.000 y $500.000';
    header('Location: ../../prestamos_clientes.php');
    exit;
}

if ($cuotas_solicitadas < 1 || $cuotas_solicitadas > 24) {
    $_SESSION['error_msg'] = 'Las cuotas deben estar entre 1 y 24';
    header('Location: ../../prestamos_clientes.php');
    exit;
}

if (!in_array($frecuencia_solicitada, ['diario', 'semanal', 'quincenal', 'mensual'])) {
    $_SESSION['error_msg'] = 'Frecuencia de pago no válida';
    header('Location: ../../prestamos_clientes.php');
    exit;
}

try {
    // Obtener configuración de tasa de interés
    $stmt = $pdo->prepare("SELECT valor FROM prestamos_config WHERE clave = 'tasa_interes_default' LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch();
    $tasa_interes = (float)($config['valor'] ?? 50);

    // Calcular monto total estimado
    $monto_cuota = round($monto_solicitado / $cuotas_solicitadas * (1 + $tasa_interes / 100), 2);
    $monto_total = round($monto_cuota * $cuotas_solicitadas, 2);

    // Insertar préstamo en estado pendiente
    $stmt = $pdo->prepare("
        INSERT INTO prestamos (
            cliente_id,
            monto_solicitado,
            cuotas_solicitadas,
            frecuencia_solicitada,
            tasa_interes,
            monto_total,
            destino_credito,
            comentarios_cliente,
            estado,
            fecha_solicitud,
            tipo_solicitud,
            requiere_legajo,
            legajo_completo,
            legajo_validado
        ) VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'pendiente',
            NOW(),
            'manual',
            1,
            0,
            0
        )
    ");

    $stmt->execute([
        $usuario_id,
        $monto_solicitado,
        $cuotas_solicitadas,
        $frecuencia_solicitada,
        $tasa_interes,
        $monto_total,
        $destino_credito,
        $comentarios_cliente
    ]);

    $prestamo_id = $pdo->lastInsertId();

    // Registrar log
    $logFile = __DIR__ . '/../../logs/prestamos.log';
    if (!is_dir(dirname($logFile))) {
        @mkdir(dirname($logFile), 0755, true);
    }
    $logMsg = sprintf(
        "[%s] Préstamo creado - ID: %d, Cliente: %d, Monto: $%s, Cuotas: %d\n",
        date('Y-m-d H:i:s'),
        $prestamo_id,
        $usuario_id,
        number_format($monto_solicitado, 2),
        $cuotas_solicitadas
    );
    @file_put_contents($logFile, $logMsg, FILE_APPEND);

    $_SESSION['success_msg'] = '✅ ¡Solicitud enviada correctamente! Un asesor la revisará pronto y te contactará.';
    header('Location: ../../prestamos_clientes.php');
    exit;

} catch (PDOException $e) {
    error_log("Error SQL creando préstamo: " . $e->getMessage());
    $_SESSION['error_msg'] = 'Error al enviar la solicitud. Por favor, intentá de nuevo.';
    header('Location: ../../prestamos_clientes.php');
    exit;
} catch (Exception $e) {
    error_log("Error general creando préstamo: " . $e->getMessage());
    $_SESSION['error_msg'] = 'Error al procesar la solicitud. Por favor, intentá de nuevo.';
    header('Location: ../../prestamos_clientes.php');
    exit;
}