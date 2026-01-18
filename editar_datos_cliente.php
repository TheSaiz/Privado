<?php
session_start();

// Verificar que sea admin o asesor
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'asesor'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/backend/connection.php';

$usuario_actualizador = (int)$_SESSION['usuario_id'];
$cliente_id = (int)($_GET['cliente_id'] ?? 0);

if ($cliente_id <= 0) {
    die('Cliente no especificado');
}

// Obtener datos del cliente
$stmt = $pdo->prepare("
    SELECT u.*, cd.*
    FROM usuarios u
    LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
    WHERE u.id = ? AND u.rol = 'cliente'
    LIMIT 1
");
$stmt->execute([$cliente_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    die('Cliente no encontrado');
}

$mensaje = '';
$error = '';

// Procesar actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_datos'])) {
    
    try {
        $pdo->beginTransaction();
        
        // 1. Datos personales básicos
        $nombre_completo = trim($_POST['nombre_completo'] ?? '');
        $dni = preg_replace('/\D/', '', $_POST['dni'] ?? '');
        $fecha_nacimiento = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
        $telefono = preg_replace('/\D/', '', $_POST['telefono'] ?? '');
        $cod_area = preg_replace('/\D/', '', $_POST['cod_area'] ?? '');
        
        // 2. Datos personales adicionales
        $cuil_cuit = preg_replace('/\D/', '', $_POST['cuil_cuit'] ?? '');
        $estado_civil = $_POST['estado_civil'] ?? null;
        $hijos_a_cargo = (int)($_POST['hijos_a_cargo'] ?? 0);
        $dni_conyuge = $estado_civil === 'casado' ? preg_replace('/\D/', '', $_POST['dni_conyuge'] ?? '') : null;
        $nombre_conyuge = $estado_civil === 'casado' ? trim($_POST['nombre_conyuge'] ?? '') : null;
        
        // 3. Dirección completa
        $direccion_calle = trim($_POST['direccion_calle'] ?? '');
        $direccion_piso = trim($_POST['direccion_piso'] ?? '') ?: null;
        $direccion_departamento = trim($_POST['direccion_departamento'] ?? '') ?: null;
        $direccion_barrio = trim($_POST['direccion_barrio'] ?? '');
        $direccion_codigo_postal = trim($_POST['direccion_codigo_postal'] ?? '');
        $direccion_localidad = trim($_POST['direccion_localidad'] ?? '');
        $direccion_provincia = trim($_POST['direccion_provincia'] ?? '');
        $direccion_latitud = !empty($_POST['direccion_latitud']) ? (float)$_POST['direccion_latitud'] : null;
        $direccion_longitud = !empty($_POST['direccion_longitud']) ? (float)$_POST['direccion_longitud'] : null;
        
        // 4. Información laboral e ingresos
        $tipo_ingreso = $_POST['tipo_ingreso'] ?? null;
        $monto_ingresos = !empty($_POST['monto_ingresos']) ? (float)$_POST['monto_ingresos'] : null;
        $empleador_cuit = preg_replace('/\D/', '', $_POST['empleador_cuit'] ?? '') ?: null;
        $empleador_razon_social = trim($_POST['empleador_razon_social'] ?? '') ?: null;
        $empleador_telefono = trim($_POST['empleador_telefono'] ?? '') ?: null;
        $empleador_direccion = trim($_POST['empleador_direccion'] ?? '') ?: null;
        $empleador_sector = trim($_POST['empleador_sector'] ?? '') ?: null;
        $cargo = trim($_POST['cargo'] ?? '') ?: null;
        $antiguedad_laboral = !empty($_POST['antiguedad_laboral']) ? (int)$_POST['antiguedad_laboral'] : null;
        
        // 5. Contactos de referencia
        $contacto1_nombre = trim($_POST['contacto1_nombre'] ?? '') ?: null;
        $contacto1_relacion = trim($_POST['contacto1_relacion'] ?? '') ?: null;
        $contacto1_telefono = trim($_POST['contacto1_telefono'] ?? '') ?: null;
        $contacto2_nombre = trim($_POST['contacto2_nombre'] ?? '') ?: null;
        $contacto2_relacion = trim($_POST['contacto2_relacion'] ?? '') ?: null;
        $contacto2_telefono = trim($_POST['contacto2_telefono'] ?? '') ?: null;
        
        // 6. Datos bancarios
        $banco = trim($_POST['banco'] ?? '') ?: null;
        $cbu = preg_replace('/\D/', '', $_POST['cbu'] ?? '') ?: null;
        
        // Control de bloqueo
        $datos_bloqueados = isset($_POST['datos_bloqueados']) ? 1 : 0;
        
        // Actualizar clientes_detalles
        $stmt = $pdo->prepare("
            UPDATE clientes_detalles SET
                nombre_completo = ?,
                dni = ?,
                fecha_nacimiento = ?,
                telefono = ?,
                cod_area = ?,
                cuil_cuit = ?,
                estado_civil = ?,
                hijos_a_cargo = ?,
                dni_conyuge = ?,
                nombre_conyuge = ?,
                direccion_calle = ?,
                direccion_piso = ?,
                direccion_departamento = ?,
                direccion_barrio = ?,
                direccion_codigo_postal = ?,
                direccion_localidad = ?,
                direccion_provincia = ?,
                direccion_latitud = ?,
                direccion_longitud = ?,
                tipo_ingreso = ?,
                monto_ingresos = ?,
                empleador_cuit = ?,
                empleador_razon_social = ?,
                empleador_telefono = ?,
                empleador_direccion = ?,
                empleador_sector = ?,
                cargo = ?,
                antiguedad_laboral = ?,
                contacto1_nombre = ?,
                contacto1_relacion = ?,
                contacto1_telefono = ?,
                contacto2_nombre = ?,
                contacto2_relacion = ?,
                contacto2_telefono = ?,
                banco = ?,
                cbu = ?,
                datos_bloqueados = ?,
                ultima_actualizacion_admin = NOW(),
                actualizado_por = ?
            WHERE usuario_id = ?
        ");
        
        $stmt->execute([
            $nombre_completo,
            $dni,
            $fecha_nacimiento,
            $telefono,
            $cod_area,
            $cuil_cuit,
            $estado_civil,
            $hijos_a_cargo,
            $dni_conyuge,
            $nombre_conyuge,
            $direccion_calle,
            $direccion_piso,
            $direccion_departamento,
            $direccion_barrio,
            $direccion_codigo_postal,
            $direccion_localidad,
            $direccion_provincia,
            $direccion_latitud,
            $direccion_longitud,
            $tipo_ingreso,
            $monto_ingresos,
            $empleador_cuit,
            $empleador_razon_social,
            $empleador_telefono,
            $empleador_direccion,
            $empleador_sector,
            $cargo,
            $antiguedad_laboral,
            $contacto1_nombre,
            $contacto1_relacion,
            $contacto1_telefono,
            $contacto2_nombre,
            $contacto2_relacion,
            $contacto2_telefono,
            $banco,
            $cbu,
            $datos_bloqueados,
            $usuario_actualizador,
            $cliente_id
        ]);
        
        $pdo->commit();
        
        $mensaje = '✅ Datos actualizados correctamente';
        
        // Recargar datos
        $stmt = $pdo->prepare("
            SELECT u.*, cd.*
            FROM usuarios u
            LEFT JOIN clientes_detalles cd ON u.id = cd.usuario_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$cliente_id]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Redirigir si viene desde ver_prestamo
        if (isset($_GET['redirect']) && $_GET['redirect'] === 'ver_prestamo' && isset($_GET['operacion_id']) && isset($_GET['tipo'])) {
            header('Location: ver_prestamo.php?id=' . $_GET['operacion_id'] . '&tipo=' . $_GET['tipo'] . '&msg=' . urlencode($mensaje));
            exit;
        }
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Error al actualizar: ' . $e->getMessage();
    }
}

$provincias = [
    'Buenos Aires', 'CABA', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba', 
    'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja',
    'Mendoza', 'Misiones', 'Neuquén', 'Río Negro', 'Salta', 'San Juan',
    'San Luis', 'Santa Cruz', 'Santa Fe', 'Santiago del Estero', 'Tierra del Fuego',
    'Tucumán'
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Datos del Cliente - Préstamo Líder</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <style>
        .section-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 24px;
            margin-bottom: 24px;
        }
        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e5e7eb;
        }
    </style>
</head>
<body class="bg-gray-50">

<?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
    <?php include 'sidebar.php'; ?>
    <div class="ml-64">
<?php else: ?>
    <?php include 'sidebar_asesores.php'; ?>
    <div class="ml-64">
<?php endif; ?>

<nav class="bg-white shadow-md sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
        <h1 class="text-xl font-bold text-gray-800">✏️ Editar Datos del Cliente</h1>
        <div class="flex gap-4">
            <?php if (isset($_GET['redirect']) && $_GET['redirect'] === 'ver_prestamo' && isset($_GET['operacion_id']) && isset($_GET['tipo'])): ?>
                <a href="ver_prestamo.php?id=<?= $_GET['operacion_id'] ?>&tipo=<?= $_GET['tipo'] ?>" 
                   class="text-blue-600 hover:underline flex items-center gap-1">
                    <span class="material-icons-outlined text-sm">arrow_back</span>
                    Volver al Préstamo
                </a>
            <?php else: ?>
                <a href="clientes.php" class="text-blue-600 hover:underline flex items-center gap-1">
                    <span class="material-icons-outlined text-sm">arrow_back</span>
                    Volver a Clientes
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="max-w-6xl mx-auto px-4 py-8">
    
    <!-- Header del cliente -->
    <div class="bg-gradient-to-r from-blue-600 to-blue-800 rounded-xl shadow-lg p-6 mb-6 text-white">
        <div class="flex items-center gap-4">
            <div class="w-20 h-20 bg-white bg-opacity-20 rounded-full flex items-center justify-center text-3xl font-bold backdrop-blur">
                <?php echo strtoupper(substr($cliente['nombre'] ?? 'C', 0, 1) . substr($cliente['apellido'] ?? 'L', 0, 1)); ?>
            </div>
            <div class="flex-1">
                <h2 class="text-2xl font-bold mb-1">
                    <?php echo htmlspecialchars(($cliente['nombre'] ?? '') . ' ' . ($cliente['apellido'] ?? '')); ?>
                </h2>
                <p class="text-blue-100"><?php echo htmlspecialchars($cliente['email'] ?? ''); ?></p>
                <div class="flex gap-4 mt-2 text-sm">
                    <span class="bg-white bg-opacity-20 px-3 py-1 rounded-full">
                        DNI: <?php echo htmlspecialchars($cliente['dni'] ?? 'No especificado'); ?>
                    </span>
                    <?php if (!empty($cliente['cuil_cuit'])): ?>
                    <span class="bg-white bg-opacity-20 px-3 py-1 rounded-full">
                        CUIL/CUIT: <?php echo htmlspecialchars($cliente['cuil_cuit']); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($mensaje): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 px-6 py-4 rounded-lg mb-6 flex items-center gap-3">
        <span class="material-icons-outlined">check_circle</span>
        <span><?php echo $mensaje; ?></span>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 px-6 py-4 rounded-lg mb-6 flex items-center gap-3">
        <span class="material-icons-outlined">error</span>
        <span><?php echo $error; ?></span>
    </div>
    <?php endif; ?>

    <!-- Formulario -->
    <form method="POST" class="space-y-6">
        
        <!-- ========================================= -->
        <!-- 1. INFORMACIÓN PERSONAL BÁSICA -->
        <!-- ========================================= -->
        <div class="section-card">
            <h3 class="section-title">
                <span class="material-icons-outlined text-blue-600">person</span>
                Información Personal Básica
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Nombre Completo</label>
                    <input type="text" name="nombre_completo" 
                           value="<?php echo htmlspecialchars($cliente['nombre_completo'] ?? ''); ?>"
                           placeholder="Juan Pérez"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">DNI</label>
                    <input type="text" name="dni" 
                           value="<?php echo htmlspecialchars($cliente['dni'] ?? ''); ?>"
                           placeholder="12345678" maxlength="10"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">CUIL / CUIT</label>
                    <input type="text" name="cuil_cuit" 
                           value="<?php echo htmlspecialchars($cliente['cuil_cuit'] ?? ''); ?>"
                           placeholder="20-12345678-9" maxlength="13"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Fecha de Nacimiento</label>
                    <input type="date" name="fecha_nacimiento" 
                           value="<?php echo htmlspecialchars($cliente['fecha_nacimiento'] ?? ''); ?>"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Código de Área</label>
                    <input type="text" name="cod_area" 
                           value="<?php echo htmlspecialchars($cliente['cod_area'] ?? ''); ?>"
                           placeholder="011" maxlength="5"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Teléfono</label>
                    <input type="text" name="telefono" 
                           value="<?php echo htmlspecialchars($cliente['telefono'] ?? ''); ?>"
                           placeholder="12345678"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
            </div>
        </div>

        <!-- ========================================= -->
        <!-- 2. INFORMACIÓN FAMILIAR -->
        <!-- ========================================= -->
        <div class="section-card">
            <h3 class="section-title">
                <span class="material-icons-outlined text-purple-600">family_restroom</span>
                Información Familiar
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Estado Civil</label>
                    <select name="estado_civil" id="estado_civil"
                            class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500"
                            onchange="toggleConyugeFields(this.value)">
                        <option value="">Seleccionar...</option>
                        <option value="soltero" <?php echo ($cliente['estado_civil'] ?? '') === 'soltero' ? 'selected' : ''; ?>>Soltero/a</option>
                        <option value="casado" <?php echo ($cliente['estado_civil'] ?? '') === 'casado' ? 'selected' : ''; ?>>Casado/a</option>
                        <option value="divorciado" <?php echo ($cliente['estado_civil'] ?? '') === 'divorciado' ? 'selected' : ''; ?>>Divorciado/a</option>
                        <option value="viudo" <?php echo ($cliente['estado_civil'] ?? '') === 'viudo' ? 'selected' : ''; ?>>Viudo/a</option>
                        <option value="union_libre" <?php echo ($cliente['estado_civil'] ?? '') === 'union_libre' ? 'selected' : ''; ?>>Unión Libre</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Hijos a Cargo</label>
                    <input type="number" name="hijos_a_cargo" min="0" max="20"
                           value="<?php echo htmlspecialchars($cliente['hijos_a_cargo'] ?? 0); ?>"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div id="conyuge_fields" style="display: <?php echo ($cliente['estado_civil'] ?? '') === 'casado' ? 'contents' : 'none'; ?>;">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">DNI del Cónyuge</label>
                        <input type="text" name="dni_conyuge" 
                               value="<?php echo htmlspecialchars($cliente['dni_conyuge'] ?? ''); ?>"
                               placeholder="12345678" maxlength="10"
                               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Nombre del Cónyuge</label>
                        <input type="text" name="nombre_conyuge" 
                               value="<?php echo htmlspecialchars($cliente['nombre_conyuge'] ?? ''); ?>"
                               placeholder="María García"
                               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                
            </div>
        </div>

        <!-- ========================================= -->
        <!-- 3. DIRECCIÓN -->
        <!-- ========================================= -->
        <div class="section-card">
            <h3 class="section-title">
                <span class="material-icons-outlined text-green-600">home</span>
                Dirección Domicilio
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Calle y Número</label>
                    <input type="text" name="direccion_calle" 
                           value="<?php echo htmlspecialchars($cliente['direccion_calle'] ?? ''); ?>"
                           placeholder="Av. Corrientes 1234"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Piso</label>
                    <input type="text" name="direccion_piso" 
                           value="<?php echo htmlspecialchars($cliente['direccion_piso'] ?? ''); ?>"
                           placeholder="5" maxlength="10"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Departamento</label>
                    <input type="text" name="direccion_departamento" 
                           value="<?php echo htmlspecialchars($cliente['direccion_departamento'] ?? ''); ?>"
                           placeholder="A" maxlength="10"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Barrio</label>
                    <input type="text" name="direccion_barrio" 
                           value="<?php echo htmlspecialchars($cliente['direccion_barrio'] ?? ''); ?>"
                           placeholder="Palermo"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Código Postal</label>
                    <input type="text" name="direccion_codigo_postal" 
                           value="<?php echo htmlspecialchars($cliente['direccion_codigo_postal'] ?? ''); ?>"
                           placeholder="C1414" maxlength="10"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Localidad</label>
                    <input type="text" name="direccion_localidad" 
                           value="<?php echo htmlspecialchars($cliente['direccion_localidad'] ?? ''); ?>"
                           placeholder="CABA"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Provincia</label>
                    <select name="direccion_provincia" 
                            class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Seleccionar...</option>
                        <?php foreach ($provincias as $prov): ?>
                            <option value="<?= $prov ?>" <?php echo ($cliente['direccion_provincia'] ?? '') === $prov ? 'selected' : ''; ?>>
                                <?= $prov ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Latitud GPS (opcional)</label>
                    <input type="text" name="direccion_latitud" 
                           value="<?php echo htmlspecialchars($cliente['direccion_latitud'] ?? ''); ?>"
                           placeholder="-34.603722"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Longitud GPS (opcional)</label>
                    <input type="text" name="direccion_longitud" 
                           value="<?php echo htmlspecialchars($cliente['direccion_longitud'] ?? ''); ?>"
                           placeholder="-58.381592"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
            </div>

            <?php if (!empty($cliente['direccion_latitud']) && !empty($cliente['direccion_longitud'])): ?>
            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                <a href="https://www.google.com/maps?q=<?= $cliente['direccion_latitud'] ?>,<?= $cliente['direccion_longitud'] ?>" 
                   target="_blank"
                   class="text-blue-600 hover:underline flex items-center gap-2">
                    <span class="material-icons-outlined">map</span>
                    📍 Ver ubicación en Google Maps
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- ========================================= -->
        <!-- 4. INFORMACIÓN LABORAL E INGRESOS -->
        <!-- ========================================= -->
        <div class="section-card">
            <h3 class="section-title">
                <span class="material-icons-outlined text-orange-600">work</span>
                Información Laboral e Ingresos
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Tipo de Ingreso</label>
                    <select name="tipo_ingreso" id="tipo_ingreso"
                            class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500"
                            onchange="toggleEmpleadorFields(this.value)">
                        <option value="">Seleccionar...</option>
                        <option value="dependencia" <?php echo ($cliente['tipo_ingreso'] ?? '') === 'dependencia' ? 'selected' : ''; ?>>Relación de Dependencia</option>
                        <option value="autonomo" <?php echo ($cliente['tipo_ingreso'] ?? '') === 'autonomo' ? 'selected' : ''; ?>>Autónomo</option>
                        <option value="monotributo" <?php echo ($cliente['tipo_ingreso'] ?? '') === 'monotributo' ? 'selected' : ''; ?>>Monotributista</option>
                        <option value="jubilacion" <?php echo ($cliente['tipo_ingreso'] ?? '') === 'jubilacion' ? 'selected' : ''; ?>>Jubilado/Pensionado</option>
                        <option value="negro" <?php echo ($cliente['tipo_ingreso'] ?? '') === 'negro' ? 'selected' : ''; ?>>Trabajo Informal</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Ingresos Mensuales</label>
                    <input type="number" name="monto_ingresos" min="0" step="1000"
                           value="<?php echo htmlspecialchars($cliente['monto_ingresos'] ?? ''); ?>"
                           placeholder="150000"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div id="empleador_fields" style="display: <?php echo in_array($cliente['tipo_ingreso'] ?? '', ['dependencia', 'autonomo', 'monotributo']) ? 'contents' : 'none'; ?>;">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">CUIT del Empleador</label>
                        <input type="text" name="empleador_cuit" 
                               value="<?php echo htmlspecialchars($cliente['empleador_cuit'] ?? ''); ?>"
                               placeholder="30-12345678-9" maxlength="13"
                               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Razón Social</label>
                        <input type="text" name="empleador_razon_social" 
                               value="<?php echo htmlspecialchars($cliente['empleador_razon_social'] ?? ''); ?>"
                               placeholder="Empresa S.A."
                               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Teléfono del Empleador</label>
                        <input type="text" name="empleador_telefono" 
                               value="<?php echo htmlspecialchars($cliente['empleador_telefono'] ?? ''); ?>"
                               placeholder="011-1234-5678"
                               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Sector</label>
                        <input type="text" name="empleador_sector" 
                               value="<?php echo htmlspecialchars($cliente['empleador_sector'] ?? ''); ?>"
                               placeholder="Tecnología, Salud, etc."
                               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Cargo</label>
                        <input type="text" name="cargo" 
                               value="<?php echo htmlspecialchars($cliente['cargo'] ?? ''); ?>"
                               placeholder="Analista, Gerente, etc."
                               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Antigüedad (meses)</label>
                        <input type="number" name="antiguedad_laboral" min="0" max="600"
                               value="<?php echo htmlspecialchars($cliente['antiguedad_laboral'] ?? ''); ?>"
                               placeholder="24"
                               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Dirección del Lugar de Trabajo</label>
                        <input type="text" name="empleador_direccion" 
                               value="<?php echo htmlspecialchars($cliente['empleador_direccion'] ?? ''); ?>"
                               placeholder="Av. del Libertador 1000"
                               class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                
            </div>
        </div>

        <!-- ========================================= -->
        <!-- 5. CONTACTOS DE REFERENCIA -->
        <!-- ========================================= -->
        <div class="section-card">
            <h3 class="section-title">
                <span class="material-icons-outlined text-pink-600">contacts</span>
                Contactos de Referencia
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <div class="md:col-span-2">
                    <h4 class="font-semibold text-gray-700 mb-3">Contacto 1</h4>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Nombre</label>
                    <input type="text" name="contacto1_nombre" 
                           value="<?php echo htmlspecialchars($cliente['contacto1_nombre'] ?? ''); ?>"
                           placeholder="Pedro López"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Relación</label>
                    <input type="text" name="contacto1_relacion" 
                           value="<?php echo htmlspecialchars($cliente['contacto1_relacion'] ?? ''); ?>"
                           placeholder="Hermano, Amigo, etc."
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Teléfono</label>
                    <input type="text" name="contacto1_telefono" 
                           value="<?php echo htmlspecialchars($cliente['contacto1_telefono'] ?? ''); ?>"
                           placeholder="011-1234-5678"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div class="md:col-span-2 border-t pt-4 mt-2">
                    <h4 class="font-semibold text-gray-700 mb-3">Contacto 2</h4>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Nombre</label>
                    <input type="text" name="contacto2_nombre" 
                           value="<?php echo htmlspecialchars($cliente['contacto2_nombre'] ?? ''); ?>"
                           placeholder="Ana Martínez"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Relación</label>
                    <input type="text" name="contacto2_relacion" 
                           value="<?php echo htmlspecialchars($cliente['contacto2_relacion'] ?? ''); ?>"
                           placeholder="Hermana, Amiga, etc."
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Teléfono</label>
                    <input type="text" name="contacto2_telefono" 
                           value="<?php echo htmlspecialchars($cliente['contacto2_telefono'] ?? ''); ?>"
                           placeholder="011-8765-4321"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
            </div>
        </div>

        <!-- ========================================= -->
        <!-- 6. DATOS BANCARIOS -->
        <!-- ========================================= -->
        <div class="section-card">
            <h3 class="section-title">
                <span class="material-icons-outlined text-indigo-600">account_balance</span>
                Datos Bancarios
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Banco</label>
                    <input type="text" name="banco" 
                           value="<?php echo htmlspecialchars($cliente['banco'] ?? ''); ?>"
                           placeholder="Banco Galicia"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">CBU</label>
                    <input type="text" name="cbu" 
                           value="<?php echo htmlspecialchars($cliente['cbu'] ?? ''); ?>"
                           placeholder="1234567890123456789012" maxlength="22"
                           class="w-full px-4 py-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
            </div>
        </div>

        <!-- ========================================= -->
        <!-- CONTROL DE EDICIÓN -->
        <!-- ========================================= -->
        <div class="bg-yellow-50 border-2 border-yellow-300 rounded-xl p-6">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="datos_bloqueados" value="1" 
                       <?php echo ($cliente['datos_bloqueados'] ?? 0) ? 'checked' : ''; ?>
                       class="w-6 h-6 text-blue-600 rounded focus:ring-2 focus:ring-blue-500 mt-1">
                <div class="flex-1">
                    <span class="font-bold text-gray-800 text-lg">🔒 Bloquear Edición del Cliente</span>
                    <p class="text-sm text-gray-700 mt-1">
                        Si activas esta opción, el cliente <strong>NO podrá editar</strong> estos datos desde su panel. 
                        Solo podrá ver la información y solicitar cambios al administrador.
                    </p>
                </div>
            </label>
        </div>

        <!-- Botones -->
        <div class="flex gap-4 sticky bottom-4 bg-white p-4 rounded-xl shadow-lg border-2 border-gray-200">
            <button type="submit" name="actualizar_datos"
                    class="flex-1 px-8 py-4 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 transition font-bold text-lg flex items-center justify-center gap-2">
                <span class="material-icons-outlined">save</span>
                Guardar Todos los Cambios
            </button>
            <?php if (isset($_GET['redirect']) && $_GET['redirect'] === 'ver_prestamo' && isset($_GET['operacion_id']) && isset($_GET['tipo'])): ?>
                <a href="ver_prestamo.php?id=<?= $_GET['operacion_id'] ?>&tipo=<?= $_GET['tipo'] ?>" 
                   class="px-8 py-4 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-semibold text-lg flex items-center justify-center gap-2">
                    <span class="material-icons-outlined">close</span>
                    Cancelar
                </a>
            <?php else: ?>
                <a href="clientes.php" 
                   class="px-8 py-4 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-semibold text-lg flex items-center justify-center gap-2">
                    <span class="material-icons-outlined">close</span>
                    Cancelar
                </a>
            <?php endif; ?>
        </div>

    </form>

</div>

</div>

<script>
function toggleConyugeFields(estadoCivil) {
    const fields = document.getElementById('conyuge_fields');
    fields.style.display = estadoCivil === 'casado' ? 'contents' : 'none';
}

function toggleEmpleadorFields(tipoIngreso) {
    const fields = document.getElementById('empleador_fields');
    const requiereEmpleador = ['dependencia', 'autonomo', 'monotributo'].includes(tipoIngreso);
    fields.style.display = requiereEmpleador ? 'contents' : 'none';
}

// Inicializar en carga
document.addEventListener('DOMContentLoaded', function() {
    const estadoCivil = document.getElementById('estado_civil').value;
    const tipoIngreso = document.getElementById('tipo_ingreso').value;
    toggleConyugeFields(estadoCivil);
    toggleEmpleadorFields(tipoIngreso);
});
</script>

</body>
</html>