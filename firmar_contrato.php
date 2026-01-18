<?php
session_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Validar sesión
if (!isset($_SESSION['cliente_id']) || !isset($_SESSION['cliente_email'])) {
    header('Location: login_clientes.php');
    exit;
}

$cliente_id = (int)($_SESSION['cliente_id'] ?? 0);

if ($cliente_id <= 0 || !isset($_GET['id']) || !isset($_GET['tipo'])) {
    header('Location: dashboard_clientes.php');
    exit;
}

$prestamo_id = (int)$_GET['id'];
$tipo_operacion = strtolower($_GET['tipo']);

if (!in_array($tipo_operacion, ['prestamo', 'empeno', 'prendario'])) {
    header('Location: dashboard_clientes.php');
    exit;
}

// Conexión
try {
    require_once __DIR__ . '/backend/connection.php';
} catch (Throwable $e) {
    die('Error de conexión');
}

// ============================================
// VERIFICAR INFORMACIÓN COMPLEMENTARIA
// ============================================
try {
    $stmt = $pdo->prepare("
        SELECT 
            cuil_cuit,
            estado_civil,
            dni_conyuge,
            nombre_conyuge,
            direccion_calle,
            direccion_barrio,
            direccion_localidad,
            direccion_provincia,
            tipo_ingreso,
            empleador_razon_social,
            cargo,
            antiguedad_laboral
        FROM clientes_detalles
        WHERE usuario_id = ?
    ");
    $stmt->execute([$cliente_id]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);

    // Validar campos obligatorios
    $falta_info = false;
    $campos_faltantes = [];
    
    if (empty($info['cuil_cuit'])) {
        $falta_info = true;
        $campos_faltantes[] = 'CUIL/CUIT';
    }
    
    if (empty($info['estado_civil'])) {
        $falta_info = true;
        $campos_faltantes[] = 'Estado civil';
    }
    
    if (empty($info['direccion_calle'])) {
        $falta_info = true;
        $campos_faltantes[] = 'Dirección';
    }
    
    if (empty($info['direccion_barrio'])) {
        $falta_info = true;
        $campos_faltantes[] = 'Barrio';
    }
    
    if (empty($info['direccion_localidad'])) {
        $falta_info = true;
        $campos_faltantes[] = 'Localidad';
    }
    
    if (empty($info['direccion_provincia'])) {
        $falta_info = true;
        $campos_faltantes[] = 'Provincia';
    }
    
    if (empty($info['tipo_ingreso'])) {
        $falta_info = true;
        $campos_faltantes[] = 'Tipo de ingreso';
    }
    
    // Si está casado, verificar datos del cónyuge
    if ($info['estado_civil'] === 'casado') {
        if (empty($info['dni_conyuge'])) {
            $falta_info = true;
            $campos_faltantes[] = 'DNI del cónyuge';
        }
        if (empty($info['nombre_conyuge'])) {
            $falta_info = true;
            $campos_faltantes[] = 'Nombre del cónyuge';
        }
    }
    
    // Si es empleado/autónomo/monotributista, verificar datos laborales
    if (in_array($info['tipo_ingreso'], ['dependencia', 'autonomo', 'monotributo'])) {
        if (empty($info['empleador_razon_social'])) {
            $falta_info = true;
            $campos_faltantes[] = 'Razón social del empleador';
        }
        if (empty($info['cargo'])) {
            $falta_info = true;
            $campos_faltantes[] = 'Cargo';
        }
        if (empty($info['antiguedad_laboral']) || $info['antiguedad_laboral'] <= 0) {
            $falta_info = true;
            $campos_faltantes[] = 'Antigüedad laboral';
        }
    }

    // Si falta información, redirigir al formulario
    if ($falta_info) {
        $msg = urlencode('Antes de firmar el contrato, necesitamos que completes tu información personal y laboral.');
        header('Location: completar_informacion.php?redirect=firmar_contrato&prestamo_id=' . $prestamo_id . '&tipo=' . $tipo_operacion . '&msg=' . $msg);
        exit;
    }
    
} catch (Exception $e) {
    error_log("Error verificando información: " . $e->getMessage());
}
// ============================================

$mensaje = '';
$error = '';

// Procesar firma del contrato
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'firmar') {
    try {
        $firma_cliente = $_POST['firma_data'] ?? '';
        $acepta_terminos = isset($_POST['acepta_terminos']);
        
        if (empty($firma_cliente)) {
            throw new Exception('Debe firmar el contrato');
        }
        
        if (!$acepta_terminos) {
            throw new Exception('Debe aceptar los términos y condiciones');
        }
        
        $pdo->beginTransaction();
        
        // Verificar que el contrato existe y está pendiente
        $stmt = $pdo->prepare("
            SELECT * FROM prestamos_contratos 
            WHERE prestamo_id = ? AND tipo_operacion = ? AND cliente_id = ? AND estado = 'pendiente'
            LIMIT 1
        ");
        $stmt->execute([$prestamo_id, $tipo_operacion, $cliente_id]);
        $contrato = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$contrato) {
            throw new Exception('Contrato no encontrado o ya firmado');
        }
        
        // Guardar firma
        $stmt = $pdo->prepare("
            UPDATE prestamos_contratos SET
                firma_cliente = ?,
                ip_firma = ?,
                user_agent_firma = ?,
                fecha_firma = NOW(),
                estado = 'firmado',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $firma_cliente,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $contrato['id']
        ]);
        
        // Actualizar estado del préstamo
        $tabla = match($tipo_operacion) {
            'prestamo' => 'prestamos',
            'empeno' => 'empenos',
            'prendario' => 'creditos_prendarios'
        };
        
        $stmt = $pdo->prepare("
            UPDATE {$tabla} SET
                estado = 'aprobado',
                estado_contrato = 'firmado',
                contrato_id = ?
            WHERE id = ? AND cliente_id = ?
        ");
        $stmt->execute([$contrato['id'], $prestamo_id, $cliente_id]);
        
        // Registrar en historial
        $stmt = $pdo->prepare("
            INSERT INTO contratos_historial (contrato_id, cliente_id, accion, ip_address, user_agent)
            VALUES (?, ?, 'firma_contrato', ?, ?)
        ");
        $stmt->execute([
            $contrato['id'],
            $cliente_id,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        // Crear notificación
        $stmt = $pdo->prepare("
            INSERT INTO clientes_notificaciones (cliente_id, tipo, titulo, mensaje, url_accion, texto_accion)
            VALUES (?, 'success', '✅ Contrato Firmado', 'Tu contrato ha sido firmado exitosamente. Ahora debes solicitar el desembolso para recibir el dinero en 24-48hs hábiles.', 'solicitar_desembolso.php?id={$prestamo_id}&tipo={$tipo_operacion}', 'Solicitar Desembolso')
        ");
        $stmt->execute([$cliente_id]);
        
        $pdo->commit();
        
        // Redirigir al dashboard
        header('Location: dashboard_clientes.php?msg=' . urlencode('✅ Contrato firmado exitosamente. Ahora podés solicitar el desembolso.'));
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Obtener datos del préstamo
$prestamo = null;
$cliente_info = null;
$contrato = null;

try {
    // Obtener información completa del cliente
    $stmt = $pdo->prepare("
        SELECT 
            cd.*,
            u.nombre,
            u.apellido,
            u.email
        FROM clientes_detalles cd
        INNER JOIN usuarios u ON cd.usuario_id = u.id
        WHERE cd.usuario_id = ? 
        LIMIT 1
    ");
    $stmt->execute([$cliente_id]);
    $cliente_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cliente_info) {
        throw new Exception('Información del cliente no encontrada');
    }
    
    // Agregar nombre completo
    $cliente_info['nombre_completo'] = trim($cliente_info['nombre'] . ' ' . $cliente_info['apellido']);
    
    // Obtener datos del préstamo según tipo
    if ($tipo_operacion === 'prestamo') {
        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                COALESCE(p.monto_ofrecido, p.monto_solicitado, p.monto) as monto_final,
                COALESCE(p.cuotas_ofrecidas, p.cuotas_solicitadas, p.cuotas) as cuotas_final,
                COALESCE(p.frecuencia_ofrecida, p.frecuencia_solicitada, p.frecuencia_pago) as frecuencia_final,
                COALESCE(p.tasa_interes, p.tasa_interes_ofrecida, 15) as tasa_final,
                COALESCE(p.monto_total, p.monto_total_ofrecido) as total_final
            FROM prestamos p
            WHERE p.id = ? AND p.cliente_id = ? AND p.estado = 'aprobado'
            LIMIT 1
        ");
    } elseif ($tipo_operacion === 'empeno') {
        $stmt = $pdo->prepare("
            SELECT 
                e.*,
                e.monto_final,
                e.cuotas_final,
                e.frecuencia_final,
                e.tasa_interes_final as tasa_final,
                e.monto_total_final as total_final
            FROM empenos e
            WHERE e.id = ? AND e.cliente_id = ? AND e.estado = 'aprobado'
            LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                cp.*,
                cp.monto_final,
                cp.cuotas_final,
                cp.frecuencia_final,
                cp.tasa_interes_final as tasa_final,
                cp.monto_total_final as total_final
            FROM creditos_prendarios cp
            WHERE cp.id = ? AND cp.cliente_id = ? AND cp.estado = 'aprobado'
            LIMIT 1
        ");
    }
    
    $stmt->execute([$prestamo_id, $cliente_id]);
    $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$prestamo) {
        header('Location: dashboard_clientes.php');
        exit;
    }
    
    // Verificar o crear contrato
    $stmt = $pdo->prepare("
        SELECT * FROM prestamos_contratos 
        WHERE prestamo_id = ? AND tipo_operacion = ? AND cliente_id = ?
        LIMIT 1
    ");
    $stmt->execute([$prestamo_id, $tipo_operacion, $cliente_id]);
    $contrato = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si no existe contrato, crearlo
    if (!$contrato) {
        // Generar número de contrato
        $prefijo = ['prestamo' => 'PRE', 'empeno' => 'EMP', 'prendario' => 'PRN'][$tipo_operacion];
        $numero_contrato = $prefijo . '-' . date('Y') . '-' . str_pad($prestamo_id, 6, '0', STR_PAD_LEFT);
        
        // Generar contenido del contrato según tipo
        $contenido = generarContratoSegunTipo($tipo_operacion, $prestamo, $cliente_info);
        
        $stmt = $pdo->prepare("
            INSERT INTO prestamos_contratos (
                prestamo_id, tipo_operacion, cliente_id, numero_contrato,
                contenido_contrato, estado, fecha_vencimiento
            ) VALUES (?, ?, ?, ?, ?, 'pendiente', DATE_ADD(NOW(), INTERVAL 7 DAY))
        ");
        $stmt->execute([
            $prestamo_id,
            $tipo_operacion,
            $cliente_id,
            $numero_contrato,
            $contenido
        ]);
        
        $contrato_id = $pdo->lastInsertId();
        
        // Actualizar préstamo con referencia al contrato
        $tabla = match($tipo_operacion) {
            'prestamo' => 'prestamos',
            'empeno' => 'empenos',
            'prendario' => 'creditos_prendarios'
        };
        
        $stmt = $pdo->prepare("
            UPDATE {$tabla} SET estado_contrato = 'pendiente_firma', contrato_id = ? WHERE id = ?
        ");
        $stmt->execute([$contrato_id, $prestamo_id]);
        
        // Recargar contrato
        $stmt = $pdo->prepare("SELECT * FROM prestamos_contratos WHERE id = ?");
        $stmt->execute([$contrato_id]);
        $contrato = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
} catch (Throwable $e) {
    die('Error al cargar información: ' . $e->getMessage());
}

// Variables para sidebar
$pagina_activa = 'prestamos';
$docs_completos = (int)($cliente_info['docs_completos'] ?? 0) === 1;
$noti_no_leidas = 0;

$tipo_nombre = ['prestamo' => 'Préstamo Personal', 'empeno' => 'Empeño', 'prendario' => 'Crédito Prendario'][$tipo_operacion];

// ============================================
// FUNCIONES DE GENERACIÓN DE CONTRATOS
// ============================================

/**
 * Genera el contrato según el tipo de operación
 */
function generarContratoSegunTipo($tipo, $prestamo, $cliente) {
    switch ($tipo) {
        case 'prestamo':
            return generarContratoPrestamo($prestamo, $cliente);
        case 'empeno':
            return generarContratoEmpeno($prestamo, $cliente);
        case 'prendario':
            return generarContratoPrendario($prestamo, $cliente);
        default:
            return '';
    }
}

/**
 * Genera contrato de préstamo personal
 */
function generarContratoPrestamo($prestamo, $cliente) {
    $monto = number_format((float)($prestamo['monto_final'] ?? 0), 2, ',', '.');
    $monto_letras = numeroALetras((float)($prestamo['monto_final'] ?? 0));
    $cuotas = (int)($prestamo['cuotas_final'] ?? 0);
    $cuota_monto = number_format((float)($prestamo['total_final'] ?? 0) / $cuotas, 2, ',', '.');
    $cuota_monto_letras = numeroALetras((float)($prestamo['total_final'] ?? 0) / $cuotas);
    $frecuencia = ucfirst($prestamo['frecuencia_final'] ?? 'mensual');
    $tasa_nominal = number_format((float)($prestamo['tasa_final'] ?? 15), 2);
    $total = number_format((float)($prestamo['total_final'] ?? 0), 2, ',', '.');
    $total_letras = numeroALetras((float)($prestamo['total_final'] ?? 0));
    
    // Calcular fecha primer vencimiento
    $fecha_primer_vencimiento = date('d/m/Y', strtotime('+30 days'));
    
    // Datos del cliente
    $nombre_completo = $cliente['nombre_completo'];
    $dni = $cliente['dni'];
    $cuil = $cliente['cuil_cuit'];
    $domicilio = $cliente['direccion_calle'];
    $localidad = $cliente['direccion_localidad'];
    $provincia = $cliente['direccion_provincia'];
    $telefono = $cliente['cod_area'] . '-' . $cliente['telefono'];
    $email = $cliente['email'];
    
    // Fecha actual
    $dia = date('d');
    $mes = strftime('%B');
    $anio = date('Y');
    $fecha_completa = "$dia de $mes de $anio";
    
    $contenido = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 40px; line-height: 1.6;">

<h1 style="text-align: center; color: #1f2937; margin-bottom: 10px; font-size: 24px;">SOLICITUD DE CRÉDITO</h1>
<p style="text-align: center; color: #6b7280; margin-bottom: 30px;">Contrato de Préstamo Personal</p>

<div style="background: #f9fafb; padding: 20px; border-radius: 10px; margin-bottom: 30px; border-left: 4px solid #7c5cff;">
    <p style="margin: 0 0 15px 0; line-height: 1.8;">
        Solicito a <strong>PRÉSTAMO LÍDER S.A.</strong>, con domicilio en Buenos Aires, Argentina, 
        un <strong>CRÉDITO</strong> por la suma de <strong>\$$monto</strong> (Pesos $monto_letras), 
        obligándome a restituirlo en <strong>$cuotas cuotas</strong> iguales, {$frecuencia}es y consecutivas, 
        en adelante las "CUOTAS", de <strong>\$$cuota_monto</strong> (Pesos $cuota_monto_letras) cada una, 
        con vencimiento la primera de ellas el <strong>$fecha_primer_vencimiento</strong> y las restantes 
        el mismo día de los meses subsiguientes.
    </p>
    <p style="margin: 0; line-height: 1.8;">
        Todas y cada una de las cuotas se calcularán bajo el <strong>régimen de amortización francés</strong>. 
        Los intereses se calcularán sobre el saldo de capital adeudado, a una tasa de interés fija del 
        <strong>$tasa_nominal% nominal anual</strong>.
    </p>
</div>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    DATOS PERSONALES Y DE CONTACTO
</h2>

<table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; width: 40%; font-weight: bold;">APELLIDO Y NOMBRE:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$nombre_completo</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">DNI:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$dni</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">CUIL/CUIT:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$cuil</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">DOMICILIO:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$domicilio, $localidad, $provincia</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">TELÉFONO:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$telefono</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">EMAIL:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$email</td>
    </tr>
</table>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    CONDICIONES GENERALES DEL CRÉDITO
</h2>

<p style="text-align: justify; line-height: 1.8; margin-bottom: 20px;">
    A fin de documentar mi obligación, entregaré a <strong>PRÉSTAMO LÍDER S.A.</strong> un pagaré a la vista sin protesto 
    (de conformidad con el art. 50 Decreto Ley 5965/63), por el importe total del crédito solicitado con más sus intereses 
    calculados a la tasa pactada.
</p>

<h3 style="color: #4b5563; font-size: 16px; margin-top: 25px;">PRIMERA - MORA Y VENCIMIENTO</h3>
<p style="text-align: justify; line-height: 1.8;">
    No será necesaria interpelación previa judicial o extrajudicial para la constitución de la Mora; la misma se producirá 
    en forma automática de pleno derecho por el mero transcurso del plazo indicado para el pago de cada una de las cuotas 
    o por incumplimiento del solicitante de cualquier obligación a su cargo que surja del presente. Producida la Mora, 
    PRÉSTAMO LÍDER S.A. podrá considerar todo el crédito como de plazo vencido y exigir el inmediato pago del saldo adeudado 
    con más los intereses compensatorios pactados y un <strong>interés punitorio equivalente al 50% del compensatorio</strong>, 
    y demás accesorios que la situación de mora hubiere generado. Los intereses compensatorios y moratorios se capitalizarán 
    cada 30 días (art. 623 del Código Civil).
</p>

<h3 style="color: #4b5563; font-size: 16px; margin-top: 25px;">SEGUNDA - CANCELACIÓN ANTICIPADA</h3>
<p style="text-align: justify; line-height: 1.8;">
    En caso de desear cancelar el crédito en forma anticipada, me notifico que lo podré realizar solamente cancelando 
    el 100% del monto total de las cuotas pendientes, sin quita de los intereses previstos.
</p>

<h3 style="color: #4b5563; font-size: 16px; margin-top: 25px;">TERCERA - PROTECCIÓN DE DATOS PERSONALES</h3>
<p style="text-align: justify; line-height: 1.8;">
    Declaro bajo juramento que PRÉSTAMO LÍDER S.A. me ha informado previamente que, en cumplimiento con la Ley de Protección 
    de Datos Personales y demás normas reglamentarias, mis datos personales y patrimoniales relacionados con la operación 
    crediticia que solicito se me otorgue podrán ser inmediatamente informados y registrados en la base de datos de las 
    organizaciones de información crediticia, públicas y/o privadas.
</p>

<h3 style="color: #4b5563; font-size: 16px; margin-top: 25px;">CUARTA - AUTORIZACIÓN PARA DÉBITO AUTOMÁTICO</h3>
<p style="text-align: justify; line-height: 1.8;">
    Autorizo en forma expresa e irrevocable a las entidades financieras, mutuales, cooperativas y/o las empresas de cobranzas 
    por cuenta de terceros con las que PRÉSTAMO LÍDER S.A. convenga, a descontar los importes correspondientes a las cuotas 
    de mi cuenta bancaria o tarjeta de débito hasta la cancelación total del préstamo y de los intereses, moratorios, 
    compensatorios y punitorios pactados.
</p>

<h3 style="color: #4b5563; font-size: 16px; margin-top: 25px;">QUINTA - DERECHO DE REVOCACIÓN</h3>
<p style="text-align: justify; line-height: 1.8;">
    Tomo conocimiento que tengo derecho a revocar la contratación del Crédito dentro del plazo de diez (10) días corridos 
    contados a partir de que el mismo fue puesto a disposición, mediante notificación fehaciente. Dicha revocación será sin 
    costo ni responsabilidad alguna, en la medida que no haya hecho uso del Crédito.
</p>

<h3 style="color: #4b5563; font-size: 16px; margin-top: 25px;">SEXTA - JURISDICCIÓN</h3>
<p style="text-align: justify; line-height: 1.8;">
    Para cualquier diferencia derivada del presente contrato, acepto la jurisdicción de los tribunales ordinarios de la 
    Ciudad de Buenos Aires, renunciando a cualquier otro fuero que pudiera corresponderme.
</p>

<div style="margin-top: 50px; padding-top: 30px; border-top: 2px solid #e5e7eb;">
    <p style="text-align: center; line-height: 1.8; color: #6b7280;">
        En prueba de conformidad, se firma el presente contrato en la Ciudad de Buenos Aires, 
        a los <strong>$fecha_completa</strong>.
    </p>
</div>

<div style="margin-top: 50px; text-align: center;">
    <div style="display: inline-block; width: 300px; border-top: 2px solid #1f2937; padding-top: 10px;">
        <p style="margin: 0; font-weight: bold;">FIRMA DEL PRESTATARIO</p>
        <p style="margin: 5px 0 0 0; font-size: 14px;">$nombre_completo</p>
        <p style="margin: 5px 0 0 0; font-size: 14px;">DNI: $dni</p>
    </div>
</div>

</div>
HTML;
    
    return $contenido;
}

/**
 * Genera contrato de empeño
 */
function generarContratoEmpeno($prestamo, $cliente) {
    $monto = number_format((float)($prestamo['monto_final'] ?? 0), 2, ',', '.');
    $monto_letras = numeroALetras((float)($prestamo['monto_final'] ?? 0));
    $plazo_dias = (int)($prestamo['plazo_dias'] ?? 90);
    $fecha_vencimiento = date('d/m/Y', strtotime("+$plazo_dias days"));
    $tasa_nominal = number_format((float)($prestamo['tasa_final'] ?? 15), 2);
    $interes_punitorio = number_format((float)($prestamo['tasa_final'] ?? 15) * 0.5, 2);
    
    // Datos del bien
    $tipo_bien = $prestamo['tipo_bien'] ?? 'No especificado';
    $marca = $prestamo['marca'] ?? 'No especificada';
    $modelo = $prestamo['modelo'] ?? 'No especificado';
    $numero_serie = $prestamo['numero_serie'] ?? 'No especificado';
    $estado_bien = $prestamo['estado_bien'] ?? 'Bueno';
    $descripcion = $prestamo['descripcion_producto'] ?? 'Sin descripción';
    
    // Datos del cliente
    $nombre_completo = $cliente['nombre_completo'];
    $dni = $cliente['dni'];
    $cuil = $cliente['cuil_cuit'];
    $domicilio = $cliente['direccion_calle'];
    $localidad = $cliente['direccion_localidad'];
    $provincia = $cliente['direccion_provincia'];
    
    // Fecha actual
    $dia = date('d');
    $mes = strftime('%B');
    $anio = date('Y');
    
    $contenido = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 40px; line-height: 1.6;">

<h1 style="text-align: center; color: #1f2937; margin-bottom: 10px; font-size: 24px;">CONTRATO DE EMPEÑO DE BIEN MUEBLE</h1>

<p style="text-align: justify; line-height: 1.8; margin-bottom: 30px;">
    Yo, <strong>$nombre_completo</strong>, DNI N° <strong>$dni</strong>, con domicilio en 
    <strong>$domicilio, $localidad, $provincia</strong>, en adelante "el suscripto", declaro haber 
    recibido en este acto de <strong>PRÉSTAMO LÍDER S.A.</strong>, CUIT N° <strong>30-12345678-9</strong>, 
    con domicilio legal en Buenos Aires, Argentina, en adelante "EL ACREEDOR", la suma que se detalla a 
    continuación, entregando en garantía el bien descrito. A continuación, manifiesto los términos del empeño:
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    PRIMERA – OBJETO
</h2>

<p style="text-align: justify; line-height: 1.8; margin-bottom: 15px;">
    1. Entrego en este acto a EL ACREEDOR, en carácter de garantía, el siguiente bien de mi propiedad:
</p>

<table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; width: 40%; font-weight: bold;">Tipo de bien:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$tipo_bien</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">Marca:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$marca</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">Modelo:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$modelo</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">Número de serie/IMEI:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$numero_serie</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">Estado general del bien:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$estado_bien</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">Descripción adicional:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$descripcion</td>
    </tr>
</table>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    SEGUNDA – SUMA ENTREGADA
</h2>

<p style="text-align: justify; line-height: 1.8; background: #fef3c7; padding: 15px; border-left: 4px solid #fbbf24; margin-bottom: 30px;">
    2. Declaro haber recibido de EL ACREEDOR la suma de PESOS <strong>\$$monto</strong> 
    (Pesos <strong>$monto_letras</strong>), en calidad de mutuo con garantía.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    TERCERA – PLAZO
</h2>

<p style="text-align: justify; line-height: 1.8;">
    3. El plazo del presente contrato es de <strong>$plazo_dias días corridos</strong> desde la fecha de firma. 
    El vencimiento opera el día <strong>$fecha_vencimiento</strong>.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    CUARTA – INTERESES
</h2>

<p style="text-align: justify; line-height: 1.8;">
    4. Este préstamo devengará un interés fijo del <strong>$tasa_nominal% nominal anual</strong>. 
    En caso de mora, se aplicará un interés punitorio adicional del <strong>$interes_punitorio%</strong>.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    QUINTA – GARANTÍA
</h2>

<p style="text-align: justify; line-height: 1.8;">
    5. El bien queda en poder de EL ACREEDOR en carácter de depósito. Me comprometo a no reclamar su uso 
    durante el plazo del contrato.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    SEXTA – MORA Y EJECUCIÓN DE LA GARANTÍA
</h2>

<p style="text-align: justify; line-height: 1.8; background: #fee2e2; padding: 15px; border-left: 4px solid #ef4444;">
    6. En caso de incumplimiento y vencido el plazo, declaro aceptar que el bien entregado quedará en 
    propiedad de EL ACREEDOR para cubrir la deuda total, sin derecho a reclamo alguno.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    SÉPTIMA – DECLARACIÓN DE PROPIEDAD
</h2>

<p style="text-align: justify; line-height: 1.8;">
    7. Declaro bajo juramento que el bien es de mi exclusiva propiedad, sin gravámenes ni denuncias, 
    y asumo toda responsabilidad ante eventuales reclamos de terceros.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    OCTAVA – JURISDICCIÓN
</h2>

<p style="text-align: justify; line-height: 1.8;">
    8. Para cualquier diferencia derivada del presente contrato, acepto la jurisdicción de los tribunales 
    ordinarios de la Ciudad de Buenos Aires, Provincia de Buenos Aires.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    NOVENA – DOMICILIOS
</h2>

<p style="text-align: justify; line-height: 1.8;">
    9. Constituyo domicilio en el indicado, donde se tendrán por válidas todas las notificaciones.
</p>

<div style="margin-top: 50px; padding-top: 30px; border-top: 2px solid #e5e7eb;">
    <p style="line-height: 1.8;">
        <strong>LUGAR:</strong> Buenos Aires, Argentina<br>
        <strong>FECHA:</strong> $dia de $mes de $anio
    </p>
</div>

<div style="margin-top: 50px; text-align: center;">
    <div style="display: inline-block; width: 300px; border-top: 2px solid #1f2937; padding-top: 10px;">
        <p style="margin: 0; font-weight: bold;">FIRMA</p>
        <p style="margin: 5px 0 0 0; font-size: 14px;">ACLARACIÓN: $nombre_completo</p>
        <p style="margin: 5px 0 0 0; font-size: 14px;">DNI: $dni</p>
    </div>
</div>

</div>
HTML;
    
    return $contenido;
}

/**
 * Genera contrato de crédito prendario
 */
function generarContratoPrendario($prestamo, $cliente) {
    $monto = number_format((float)($prestamo['monto_final'] ?? 0), 2, ',', '.');
    $monto_letras = numeroALetras((float)($prestamo['monto_final'] ?? 0));
    $cuotas = (int)($prestamo['cuotas_final'] ?? 0);
    $cuota_monto = number_format((float)($prestamo['total_final'] ?? 0) / $cuotas, 2, ',', '.');
    $cuota_monto_letras = numeroALetras((float)($prestamo['total_final'] ?? 0) / $cuotas);
    $tasa_mensual = number_format((float)($prestamo['tasa_final'] ?? 15) / 12, 2);
    $tasa_punitoria = number_format((float)($prestamo['tasa_final'] ?? 15) / 12 * 0.5, 2);
    
    // Calcular fecha primer vencimiento
    $fecha_primer_vencimiento = date('d/m/Y', strtotime('+30 days'));
    
    // Datos del vehículo
    $dominio = $prestamo['dominio'] ?? 'No especificado';
    $marca_modelo = ($prestamo['marca_vehiculo'] ?? 'No especificado') . ' ' . ($prestamo['modelo_vehiculo'] ?? '');
    $anio_vehiculo = $prestamo['anio_vehiculo'] ?? 'No especificado';
    $motor = $prestamo['numero_motor'] ?? 'No especificado';
    $chasis = $prestamo['numero_chasis'] ?? 'No especificado';
    $color = $prestamo['color_vehiculo'] ?? 'No especificado';
    $valor_estimado = number_format((float)($prestamo['valor_vehiculo'] ?? 0), 2, ',', '.');
    
    // Datos del cliente
    $nombre_completo = $cliente['nombre_completo'];
    $dni = $cliente['dni'];
    $cuil = $cliente['cuil_cuit'];
    $domicilio = $cliente['direccion_calle'];
    $localidad = $cliente['direccion_localidad'];
    $provincia = $cliente['direccion_provincia'];
    
    // Fecha actual
    $dia = date('d');
    $mes = strftime('%B');
    $anio = date('Y');
    
    $contenido = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 40px; line-height: 1.6;">

<h1 style="text-align: center; color: #1f2937; margin-bottom: 10px; font-size: 24px;">CONTRATO DE PRENDA/AUTOPRENDA</h1>

<p style="text-align: justify; line-height: 1.8; margin-bottom: 30px;">
    En la ciudad de <strong>Buenos Aires</strong>, a los <strong>$dia</strong> días del mes de 
    <strong>$mes</strong> del año <strong>$anio</strong>, entre <strong>PRÉSTAMO LÍDER S.A.</strong>, 
    con domicilio en Buenos Aires, Argentina, CUIT N° <strong>30-12345678-9</strong>, en adelante 
    denominado EL ACREEDOR, y <strong>$nombre_completo</strong>, con domicilio en <strong>$domicilio, 
    $localidad, $provincia</strong>, DNI/CUIT N° <strong>$dni / $cuil</strong>, en adelante denominado 
    EL DEUDOR, se celebra el presente Contrato de Crédito con Prenda o Autoprenda.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    PRIMERA – OBJETO
</h2>

<p style="text-align: justify; line-height: 1.8; background: #fef3c7; padding: 15px; border-left: 4px solid #fbbf24; margin-bottom: 20px;">
    Por el presente acto, EL ACREEDOR otorga a EL DEUDOR un préstamo por la suma de pesos 
    <strong>$monto_letras (\$$monto)</strong>, monto que EL DEUDOR declara recibir en este acto 
    a su entera satisfacción, en efectivo o mediante transferencia bancaria.
</p>

<p style="text-align: justify; line-height: 1.8;">
    El préstamo tiene por finalidad financiar la adquisición, conservación o uso del bien prendado 
    que se detalla más adelante.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    SEGUNDA – GARANTÍA PRENDARIA (Prenda o Autoprenda)
</h2>

<p style="text-align: justify; line-height: 1.8; margin-bottom: 15px;">
    A fin de asegurar el pago del crédito otorgado, EL DEUDOR constituye a favor de EL ACREEDOR 
    un derecho real de prenda con registro, sobre el bien mueble automotor de su propiedad que 
    se detalla a continuación:
</p>

<table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; width: 40%; font-weight: bold;">Tipo de bien:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">Automotor</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">Marca / Modelo:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$marca_modelo</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">Dominio:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;"><strong style="color: #7c5cff;">$dominio</strong></td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">Año:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$anio_vehiculo</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">N° de motor:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$motor</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">N° de chasis:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$chasis</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">Color:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">$color</td>
    </tr>
    <tr>
        <td style="padding: 10px; border: 1px solid #e5e7eb; background: #f9fafb; font-weight: bold;">Valor estimado:</td>
        <td style="padding: 10px; border: 1px solid #e5e7eb;">\$$valor_estimado</td>
    </tr>
</table>

<div style="background: #fee2e2; padding: 15px; border-left: 4px solid #ef4444; margin-bottom: 30px;">
    <p style="margin: 0; line-height: 1.8;">
        <strong>EL DEUDOR declara que:</strong><br>
        • El bien prendado se encuentra libre de gravámenes y a su nombre<br>
        • Se compromete a NO venderlo, transferirlo, permutarlo ni gravarlo nuevamente sin autorización escrita del ACREEDOR<br>
        • Se obliga a mantenerlo en perfecto estado de conservación<br>
        • Responderá por cualquier daño, pérdida o deterioro<br>
        • Conservará la documentación legal del vehículo
    </p>
</div>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    TERCERA – PLAZO, CUOTAS Y FORMA DE PAGO
</h2>

<p style="text-align: justify; line-height: 1.8;">
    EL DEUDOR se compromete a abonar el crédito en <strong>$cuotas cuotas mensuales consecutivas</strong> 
    de pesos <strong>$cuota_monto_letras (\$$cuota_monto)</strong> cada una, venciendo la primera el 
    día <strong>$fecha_primer_vencimiento</strong> y las siguientes en igual fecha de los meses sucesivos.
</p>

<p style="text-align: justify; line-height: 1.8; background: #f0fdf4; padding: 15px; border-left: 4px solid #10b981; margin-top: 15px;">
    Los pagos deberán realizarse en el domicilio del ACREEDOR o mediante transferencia bancaria. 
    <strong>El atraso superior a 30 días facultará al ACREEDOR a declarar vencido de pleno derecho el 
    plazo total del crédito</strong>, pudiendo exigir el pago inmediato del saldo total adeudado.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    CUARTA – INTERESES
</h2>

<p style="text-align: justify; line-height: 1.8;">
    El préstamo devengará un interés compensatorio del <strong>$tasa_mensual% mensual</strong>, calculado 
    sobre el saldo deudor, y en caso de mora se aplicará un interés punitorio adicional del 
    <strong>$tasa_punitoria% mensual</strong> desde el vencimiento y hasta el pago efectivo.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    QUINTA – SEGURO DEL BIEN
</h2>

<p style="text-align: justify; line-height: 1.8;">
    EL DEUDOR se obliga a mantener el vehículo asegurado durante toda la vigencia del crédito, 
    contra robo total, incendio y destrucción. El ACREEDOR será designado como beneficiario preferente del seguro.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    SEXTA – INCUMPLIMIENTO Y EJECUCIÓN
</h2>

<p style="text-align: justify; line-height: 1.8; margin-bottom: 15px;">
    Ante el incumplimiento de cualquiera de las cuotas o condiciones de este contrato, el ACREEDOR podrá:
</p>

<ul style="line-height: 2; margin-left: 20px;">
    <li>Exigir el pago total del crédito, intereses y gastos</li>
    <li>Solicitar judicialmente o por vía ejecutiva la venta del bien prendado</li>
    <li>En caso de autoprenda, tomar posesión del vehículo con autorización judicial</li>
</ul>

<p style="text-align: justify; line-height: 1.8; margin-top: 15px;">
    El producido de la venta se aplicará primero a gastos y costas, luego a intereses y finalmente al 
    capital. Si existiere excedente, será devuelto al deudor; si el monto no alcanzara, el DEUDOR 
    continuará obligado por la diferencia.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    SÉPTIMA – DOMICILIO Y JURISDICCIÓN
</h2>

<p style="text-align: justify; line-height: 1.8;">
    Las partes constituyen domicilio en los consignados en el encabezado del presente, donde serán 
    válidas todas las notificaciones, incluso las efectuadas por medios electrónicos.
</p>

<p style="text-align: justify; line-height: 1.8;">
    Para toda controversia, se someten a la jurisdicción de los Tribunales Ordinarios de la ciudad 
    de Buenos Aires, Provincia de Buenos Aires, renunciando a cualquier otro fuero.
</p>

<h2 style="color: #1f2937; font-size: 18px; margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
    OCTAVA – ACEPTACIÓN
</h2>

<p style="text-align: justify; line-height: 1.8;">
    Leído el presente contrato, las partes lo firman en dos ejemplares de un mismo tenor y a un 
    solo efecto, en el lugar y fecha indicados al inicio.
</p>

<div style="margin-top: 60px; display: flex; justify-content: space-around;">
    <div style="text-align: center; flex: 1; padding: 20px;">
        <div style="border-top: 2px solid #1f2937; padding-top: 10px; max-width: 250px; margin: 0 auto;">
            <p style="margin: 0; font-weight: bold;">Firma del ACREEDOR</p>
            <p style="margin: 10px 0 0 0; font-size: 14px;">PRÉSTAMO LÍDER S.A.</p>
            <p style="margin: 5px 0 0 0; font-size: 14px;">CUIT: 30-12345678-9</p>
        </div>
    </div>
    
    <div style="text-align: center; flex: 1; padding: 20px;">
        <div style="border-top: 2px solid #1f2937; padding-top: 10px; max-width: 250px; margin: 0 auto;">
            <p style="margin: 0; font-weight: bold;">Firma del DEUDOR</p>
            <p style="margin: 10px 0 0 0; font-size: 14px;">$nombre_completo</p>
            <p style="margin: 5px 0 0 0; font-size: 14px;">DNI: $dni</p>
            <p style="margin: 5px 0 0 0; font-size: 14px;">CUIT: $cuil</p>
        </div>
    </div>
</div>

</div>
HTML;
    
    return $contenido;
}

/**
 * Convierte un número a letras en español
 */
function numeroALetras($numero) {
    $numero = round($numero, 2);
    $entero = floor($numero);
    $decimales = round(($numero - $entero) * 100);
    
    $unidades = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
    $decenas = ['', 'DIEZ', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
    $especiales = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
    $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];
    
    if ($entero == 0) return 'CERO';
    
    $letras = '';
    
    // Millones
    if ($entero >= 1000000) {
        $millones = floor($entero / 1000000);
        $letras .= ($millones == 1 ? 'UN MILLON' : numeroALetrasHelper($millones, $unidades, $decenas, $especiales, $centenas) . ' MILLONES');
        $entero = $entero % 1000000;
        if ($entero > 0) $letras .= ' ';
    }
    
    // Miles
    if ($entero >= 1000) {
        $miles = floor($entero / 1000);
        $letras .= ($miles == 1 ? 'MIL' : numeroALetrasHelper($miles, $unidades, $decenas, $especiales, $centenas) . ' MIL');
        $entero = $entero % 1000;
        if ($entero > 0) $letras .= ' ';
    }
    
    // Centenas, decenas y unidades
    if ($entero > 0) {
        $letras .= numeroALetrasHelper($entero, $unidades, $decenas, $especiales, $centenas);
    }
    
    return $letras;
}

function numeroALetrasHelper($numero, $unidades, $decenas, $especiales, $centenas) {
    $letras = '';
    
    // Centenas
    if ($numero >= 100) {
        $c = floor($numero / 100);
        $letras .= ($numero == 100 ? 'CIEN' : $centenas[$c]);
        $numero = $numero % 100;
        if ($numero > 0) $letras .= ' ';
    }
    
    // Decenas y unidades
    if ($numero >= 10 && $numero < 20) {
        $letras .= $especiales[$numero - 10];
    } else {
        if ($numero >= 20) {
            $d = floor($numero / 10);
            $letras .= $decenas[$d];
            $numero = $numero % 10;
            if ($numero > 0) $letras .= ' Y ';
        }
        if ($numero > 0 && $numero < 10) {
            $letras .= $unidades[$numero];
        }
    }
    
    return $letras;
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Firma de Contrato - <?php echo h($tipo_nombre); ?> #<?php echo $prestamo_id; ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style_clientes.css">
  
  <style>
    .contrato-container {
      max-width: 900px;
      margin: 0 auto;
    }

    .contrato-header {
      text-align: center;
      padding: 30px 20px;
      background: linear-gradient(135deg, #7c5cff, #a78bfa);
      border-radius: 16px;
      margin-bottom: 30px;
    }

    .contrato-header h1 {
      color: white;
      font-size: 2rem;
      margin: 0 0 10px 0;
    }

    .contrato-header p {
      color: rgba(255, 255, 255, 0.9);
      font-size: 1.1rem;
      margin: 0;
    }

    .contrato-numero {
      display: inline-block;
      background: rgba(255, 255, 255, 0.2);
      padding: 8px 20px;
      border-radius: 20px;
      font-weight: 700;
      margin-top: 10px;
    }

    .resumen-card {
      background: white;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      padding: 30px;
      margin-bottom: 30px;
    }

    .resumen-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-top: 20px;
    }

    .resumen-item {
      text-align: center;
      padding: 20px;
      background: #f9fafb;
      border-radius: 12px;
    }

    .resumen-label {
      font-size: 0.85rem;
      color: #6b7280;
      margin-bottom: 8px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .resumen-value {
      font-size: 1.5rem;
      font-weight: 700;
      color: #1f2937;
    }

    .resumen-value.destacado {
      color: #7c5cff;
      font-size: 2rem;
    }

    .contrato-contenido {
      background: white;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      padding: 40px;
      margin-bottom: 30px;
      max-height: 600px;
      overflow-y: auto;
      line-height: 1.8;
    }

    .contrato-contenido h3 {
      color: #1f2937;
      margin-top: 30px;
      margin-bottom: 15px;
    }

    .contrato-contenido p,
    .contrato-contenido li {
      color: #4b5563;
      text-align: justify;
    }

    .firma-section {
      background: white;
      border: 2px solid #7c5cff;
      border-radius: 16px;
      padding: 30px;
      margin-bottom: 30px;
    }

    .firma-title {
      font-size: 1.3rem;
      font-weight: 700;
      color: #1f2937;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .firma-canvas-container {
      border: 2px dashed #d1d5db;
      border-radius: 12px;
      background: #f9fafb;
      padding: 20px;
      margin-bottom: 20px;
      position: relative;
    }

    #firma-canvas {
      width: 100%;
      height: 200px;
      border: 2px solid #e5e7eb;
      border-radius: 8px;
      background: white;
      cursor: crosshair;
      touch-action: none;
    }

    .firma-instrucciones {
      text-align: center;
      color: #6b7280;
      font-size: 0.9rem;
      margin-top: 10px;
    }

    .firma-actions {
      display: flex;
      gap: 10px;
      justify-content: center;
      margin-bottom: 20px;
    }

    .btn-limpiar {
      padding: 10px 20px;
      background: #ef4444;
      color: white;
      border: none;
      border-radius: 8px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s;
    }

    .btn-limpiar:hover {
      background: #dc2626;
      transform: translateY(-1px);
    }

    .terminos-check {
      display: flex;
      align-items: start;
      gap: 12px;
      padding: 20px;
      background: #fef3c7;
      border: 2px solid #fbbf24;
      border-radius: 12px;
      margin-bottom: 20px;
    }

    .terminos-check input[type="checkbox"] {
      width: 24px;
      height: 24px;
      margin-top: 2px;
      cursor: pointer;
    }

    .terminos-text {
      flex: 1;
      color: #78350f;
      line-height: 1.6;
    }

    .terminos-text strong {
      color: #92400e;
    }

    .btn-firmar {
      width: 100%;
      padding: 16px;
      background: linear-gradient(135deg, #10b981, #059669);
      color: white;
      border: none;
      border-radius: 12px;
      font-size: 1.1rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.3s;
    }

    .btn-firmar:hover:not(:disabled) {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(16, 185, 129, 0.4);
    }

    .btn-firmar:disabled {
      background: #d1d5db;
      cursor: not-allowed;
    }

    .alerta {
      padding: 16px 20px;
      border-radius: 12px;
      margin-bottom: 20px;
      display: flex;
      align-items: start;
      gap: 12px;
    }

    .alerta.error {
      background: #fee2e2;
      border: 1px solid #fecaca;
      color: #991b1b;
    }

    .alerta.success {
      background: #d1fae5;
      border: 1px solid #a7f3d0;
      color: #065f46;
    }

    @media (max-width: 768px) {
      .contrato-header h1 {
        font-size: 1.5rem;
      }

      .resumen-grid {
        grid-template-columns: 1fr;
      }

      .contrato-contenido {
        padding: 20px;
      }
    }
  </style>
</head>
<body>

<div class="app">
  <?php include __DIR__ . '/sidebar_clientes.php'; ?>

  <main class="main">
    <div class="contrato-container">

      <?php if ($error): ?>
        <div class="alerta error">
          <span style="font-size: 1.5rem;">❌</span>
          <div>
            <strong>Error:</strong> <?php echo h($error); ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['msg'])): ?>
        <div class="alerta success">
          <span style="font-size: 1.5rem;">✅</span>
          <div>
            <?php echo h($_GET['msg']); ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Header -->
      <div class="contrato-header">
        <h1>Firma de Contrato</h1>
        <p><?php echo h($tipo_nombre); ?> - Pre-Aprobado</p>
        <div class="contrato-numero">
          📄 <?php echo h($contrato['numero_contrato']); ?>
        </div>
      </div>

      <!-- Resumen -->
      <div class="resumen-card">
        <h2 style="text-align: center; color: #1f2937; margin-bottom: 10px;">Resumen de tu <?php echo h($tipo_nombre); ?></h2>
        <p style="text-align: center; color: #6b7280; margin-bottom: 30px;">
          Revisá los detalles antes de firmar
        </p>

        <div class="resumen-grid">
          <div class="resumen-item">
            <div class="resumen-label">Monto a Recibir</div>
            <div class="resumen-value destacado">
              $<?php echo number_format((float)($prestamo['monto_final'] ?? 0), 0, ',', '.'); ?>
            </div>
          </div>

          <div class="resumen-item">
            <div class="resumen-label">Cantidad de Cuotas</div>
            <div class="resumen-value">
              <?php echo (int)($prestamo['cuotas_final'] ?? 0); ?>
            </div>
            <div style="font-size: 0.85rem; color: #6b7280; margin-top: 5px;">
              <?php echo ucfirst(h($prestamo['frecuencia_final'] ?? 'mensual')); ?>es
            </div>
          </div>

          <div class="resumen-item">
            <div class="resumen-label">Tasa de Interés</div>
            <div class="resumen-value">
              <?php echo number_format((float)($prestamo['tasa_final'] ?? 15), 2); ?>%
            </div>
          </div>

          <div class="resumen-item">
            <div class="resumen-label">Total a Pagar</div>
            <div class="resumen-value">
              $<?php echo number_format((float)($prestamo['total_final'] ?? 0), 0, ',', '.'); ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Contenido del contrato -->
      <div class="contrato-contenido">
        <?php echo $contrato['contenido_contrato'] ?? ''; ?>
      </div>

      <!-- Área de firma -->
      <form method="POST" id="form-firma">
        <input type="hidden" name="accion" value="firmar">
        <input type="hidden" name="firma_data" id="firma-data">

        <div class="firma-section">
          <div class="firma-title">
            <span>✍️</span>
            <span>Firmá tu Contrato</span>
          </div>

          <div class="firma-canvas-container">
            <canvas id="firma-canvas"></canvas>
            <div class="firma-instrucciones">
              Dibujá tu firma en el recuadro usando el mouse o el dedo (en dispositivos táctiles)
            </div>
          </div>

          <div class="firma-actions">
            <button type="button" class="btn-limpiar" onclick="limpiarFirma()">
              🗑️ Limpiar Firma
            </button>
          </div>

          <div class="terminos-check">
            <input type="checkbox" name="acepta_terminos" id="acepta-terminos" required>
            <label for="acepta-terminos" class="terminos-text">
              <strong>Declaro que:</strong><br>
              • He leído y comprendido todos los términos y condiciones del presente contrato.<br>
              • Acepto las condiciones de pago, intereses y plazos establecidos.<br>
              • La información proporcionada es veraz y completa.<br>
              • Comprendo que este documento tiene validez legal.
            </label>
          </div>

          <button type="submit" class="btn-firmar" id="btn-firmar" disabled>
            🔒 Firmar y Aceptar Contrato
          </button>
        </div>
      </form>

      <!-- Info adicional -->
      <div class="card" style="margin-top: 30px; padding: 20px; background: #f0fdf4; border: 1px solid #86efac;">
        <div style="display: flex; align-items: start; gap: 12px;">
          <span style="font-size: 2rem;">✅</span>
          <div>
            <h3 style="margin: 0 0 10px 0; color: #166534;">¿Qué sucede después de firmar?</h3>
            <ul style="margin: 0; padding-left: 20px; color: #15803d; line-height: 1.8;">
              <li>Deberás <strong>solicitar el desembolso</strong> ingresando tus datos bancarios</li>
              <li>El dinero será depositado en tu cuenta en las próximas <strong>24-48 horas hábiles</strong></li>
              <li>Recibirás un email con el contrato firmado y el cronograma de pagos</li>
              <li>Tu primera cuota vencerá 30 días después del depósito</li>
              <li>Podrás consultar el estado de tu préstamo en cualquier momento desde tu dashboard</li>
            </ul>
          </div>
        </div>
      </div>

    </div>
  </main>
</div>

<script>
// Canvas de firma
const canvas = document.getElementById('firma-canvas');
const ctx = canvas.getContext('2d');
const btnFirmar = document.getElementById('btn-firmar');
const firmaData = document.getElementById('firma-data');
const aceptaTerminos = document.getElementById('acepta-terminos');

// Ajustar tamaño del canvas
function resizeCanvas() {
  const rect = canvas.getBoundingClientRect();
  canvas.width = rect.width;
  canvas.height = rect.height;
  ctx.lineWidth = 2;
  ctx.lineCap = 'round';
  ctx.strokeStyle = '#000';
}

resizeCanvas();
window.addEventListener('resize', resizeCanvas);

let dibujando = false;
let firmado = false;

// Mouse events
canvas.addEventListener('mousedown', (e) => {
  dibujando = true;
  const rect = canvas.getBoundingClientRect();
  ctx.beginPath();
  ctx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
});

canvas.addEventListener('mousemove', (e) => {
  if (!dibujando) return;
  const rect = canvas.getBoundingClientRect();
  ctx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
  ctx.stroke();
  firmado = true;
  verificarFormulario();
});

canvas.addEventListener('mouseup', () => {
  dibujando = false;
});

canvas.addEventListener('mouseleave', () => {
  dibujando = false;
});

// Touch events para móviles
canvas.addEventListener('touchstart', (e) => {
  e.preventDefault();
  dibujando = true;
  const rect = canvas.getBoundingClientRect();
  const touch = e.touches[0];
  ctx.beginPath();
  ctx.moveTo(touch.clientX - rect.left, touch.clientY - rect.top);
});

canvas.addEventListener('touchmove', (e) => {
  e.preventDefault();
  if (!dibujando) return;
  const rect = canvas.getBoundingClientRect();
  const touch = e.touches[0];
  ctx.lineTo(touch.clientX - rect.left, touch.clientY - rect.top);
  ctx.stroke();
  firmado = true;
  verificarFormulario();
});

canvas.addEventListener('touchend', () => {
  dibujando = false;
});

function limpiarFirma() {
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  firmado = false;
  firmaData.value = '';
  verificarFormulario();
}

function verificarFormulario() {
  btnFirmar.disabled = !(firmado && aceptaTerminos.checked);
}

aceptaTerminos.addEventListener('change', verificarFormulario);

// Al enviar el formulario
document.getElementById('form-firma').addEventListener('submit', function(e) {
  if (!firmado) {
    e.preventDefault();
    alert('Por favor, firmá el contrato antes de continuar');
    return;
  }

  if (!aceptaTerminos.checked) {
    e.preventDefault();
    alert('Debes aceptar los términos y condiciones');
    return;
  }

  // Guardar firma como base64
  firmaData.value = canvas.toDataURL('image/png');
  
  // Deshabilitar botón para evitar doble envío
  btnFirmar.disabled = true;
  btnFirmar.textContent = '⏳ Procesando...';
});
</script>

</body>
</html>