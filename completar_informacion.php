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

if ($cliente_id <= 0) {
    header('Location: login_clientes.php');
    exit;
}

// Conexión
try {
    require_once __DIR__ . '/backend/connection.php';
} catch (Throwable $e) {
    header('Location: login_clientes.php');
    exit;
}

$mensaje = '';
$error = '';

// Obtener datos actuales del cliente
$cliente_info = null;
try {
    $stmt = $pdo->prepare("
        SELECT * FROM clientes_detalles
        WHERE usuario_id = ?
        LIMIT 1
    ");
    $stmt->execute([$cliente_id]);
    $cliente_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error obteniendo info: " . $e->getMessage());
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // 1. INFORMACIÓN PERSONAL
        $cuil_cuit = trim($_POST['cuil_cuit'] ?? '');
        $estado_civil = $_POST['estado_civil'] ?? '';
        $hijos_a_cargo = (int)($_POST['hijos_a_cargo'] ?? 0);
        $dni_conyuge = trim($_POST['dni_conyuge'] ?? '');
        $nombre_conyuge = trim($_POST['nombre_conyuge'] ?? '');
        
        // 2. DIRECCIÓN
        $direccion_calle = trim($_POST['direccion_calle'] ?? '');
        $direccion_piso = trim($_POST['direccion_piso'] ?? '');
        $direccion_departamento = trim($_POST['direccion_departamento'] ?? '');
        $direccion_barrio = trim($_POST['direccion_barrio'] ?? '');
        $direccion_codigo_postal = trim($_POST['direccion_codigo_postal'] ?? '');
        $direccion_localidad = trim($_POST['direccion_localidad'] ?? '');
        $direccion_provincia = trim($_POST['direccion_provincia'] ?? '');
        $latitud = !empty($_POST['latitud']) ? (float)$_POST['latitud'] : null;
        $longitud = !empty($_POST['longitud']) ? (float)$_POST['longitud'] : null;
        
        // 3. INFORMACIÓN LABORAL
        $tipo_ingreso = $_POST['tipo_ingreso'] ?? '';
        $empleador_cuit = trim($_POST['empleador_cuit'] ?? '');
        $empleador_razon_social = trim($_POST['empleador_razon_social'] ?? '');
        $empleador_telefono = trim($_POST['empleador_telefono'] ?? '');
        $empleador_direccion = trim($_POST['empleador_direccion'] ?? '');
        $empleador_sector = trim($_POST['empleador_sector'] ?? '');
        $cargo = trim($_POST['cargo'] ?? '');
        $antiguedad_laboral = (int)($_POST['antiguedad_laboral'] ?? 0);
        $monto_ingresos = !empty($_POST['monto_ingresos']) ? (float)$_POST['monto_ingresos'] : null;
        
        // VALIDACIONES
        $errores = [];
        
        // Validar CUIL/CUIT
        if (empty($cuil_cuit)) {
            $errores[] = 'El CUIL/CUIT es obligatorio';
        } elseif (strlen(str_replace(['-', ' '], '', $cuil_cuit)) !== 11) {
            $errores[] = 'El CUIL/CUIT debe tener 11 dígitos';
        }
        
        // Validar estado civil
        if (empty($estado_civil)) {
            $errores[] = 'El estado civil es obligatorio';
        }
        
        // Validar datos de cónyuge si está casado
        if ($estado_civil === 'casado') {
            if (empty($dni_conyuge)) {
                $errores[] = 'El DNI del cónyuge es obligatorio';
            }
            if (empty($nombre_conyuge)) {
                $errores[] = 'El nombre del cónyuge es obligatorio';
            }
        }
        
        // Validar dirección
        if (empty($direccion_calle)) {
            $errores[] = 'La dirección es obligatoria';
        }
        if (empty($direccion_barrio)) {
            $errores[] = 'El barrio es obligatorio';
        }
        if (empty($direccion_localidad)) {
            $errores[] = 'La localidad es obligatoria';
        }
        if (empty($direccion_provincia)) {
            $errores[] = 'La provincia es obligatoria';
        }
        
        // Validar información laboral (solo si es dependencia o autónomo)
        if (in_array($tipo_ingreso, ['dependencia', 'autonomo', 'monotributo'])) {
            if (empty($empleador_razon_social)) {
                $errores[] = 'La razón social del empleador es obligatoria';
            }
            if (empty($cargo)) {
                $errores[] = 'El cargo es obligatorio';
            }
            if ($antiguedad_laboral <= 0) {
                $errores[] = 'La antigüedad laboral es obligatoria';
            }
        }
        
        if (!empty($errores)) {
            throw new Exception(implode('<br>', $errores));
        }
        
        // Actualizar datos
        $stmt = $pdo->prepare("
            UPDATE clientes_detalles SET
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
                empleador_cuit = ?,
                empleador_razon_social = ?,
                empleador_telefono = ?,
                empleador_direccion = ?,
                empleador_sector = ?,
                cargo = ?,
                antiguedad_laboral = ?,
                monto_ingresos = ?,
                fecha_actualizacion = NOW()
            WHERE usuario_id = ?
        ");
        
        $stmt->execute([
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
            $latitud,
            $longitud,
            $tipo_ingreso,
            $empleador_cuit,
            $empleador_razon_social,
            $empleador_telefono,
            $empleador_direccion,
            $empleador_sector,
            $cargo,
            $antiguedad_laboral,
            $monto_ingresos,
            $cliente_id
        ]);
        
        $pdo->commit();
        
        // Redirigir según desde dónde vino
        $redirect = $_GET['redirect'] ?? 'dashboard_clientes.php';
        $prestamo_id = (int)($_GET['prestamo_id'] ?? 0);
        $tipo_prestamo = $_GET['tipo'] ?? 'prestamo';
        
        if ($prestamo_id > 0 && in_array($redirect, ['firmar_contrato'])) {
            // Si viene desde firma de contrato, redirigir ahí
            header('Location: firmar_contrato.php?id=' . $prestamo_id . '&tipo=' . $tipo_prestamo . '&msg=' . urlencode('✅ Información actualizada correctamente'));
            exit;
        } else {
            // Sino, al dashboard
            header('Location: dashboard_clientes.php?msg=' . urlencode('✅ Información completada correctamente'));
            exit;
        }
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Variables para sidebar
$pagina_activa = 'perfil';
$noti_no_leidas = 0;
$nombre_mostrar = trim($cliente_info['nombre_completo'] ?? 'Cliente');

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Completar Información - Préstamo Líder</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style_clientes.css">
  
  <style>
    body, .main, p, span, div, label, h1, h2, h3 {
      color: #1f2937 !important;
    }
    
    .main {
      background: #f5f7fb !important;
    }
    
    .form-container {
      max-width: 1000px;
      margin: 0 auto;
    }
    
    .section-card {
      background: white;
      padding: 32px;
      border-radius: 16px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
      margin-bottom: 24px;
    }
    
    .section-title {
      font-size: 1.5rem;
      font-weight: 700;
      color: #1f2937 !important;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    
    .section-subtitle {
      color: #6b7280 !important;
      margin-bottom: 24px;
      font-size: 0.95rem;
    }
    
    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 20px;
      margin-bottom: 20px;
    }
    
    .form-group {
      margin-bottom: 0;
    }
    
    .form-group.full-width {
      grid-column: 1 / -1;
    }
    
    .form-label {
      display: block;
      font-weight: 600;
      color: #374151 !important;
      margin-bottom: 8px;
      font-size: 0.9rem;
    }
    
    .form-label.required::after {
      content: " *";
      color: #ef4444;
    }
    
    .form-input, .form-select, .form-textarea {
      width: 100%;
      padding: 12px 16px;
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      font-size: 0.95rem;
      transition: all 0.2s;
      color: #1f2937 !important;
      background: white;
    }
    
    .form-input:focus, .form-select:focus, .form-textarea:focus {
      outline: none;
      border-color: #7c5cff;
      box-shadow: 0 0 0 3px rgba(124, 92, 255, 0.1);
    }
    
    .form-help {
      font-size: 0.85rem;
      color: #6b7280 !important;
      margin-top: 6px;
    }
    
    .alert-info {
      background: linear-gradient(135deg, #e0e7ff, #ddd6fe);
      padding: 20px;
      border-radius: 12px;
      margin-bottom: 24px;
      border-left: 4px solid #7c5cff;
    }
    
    .alert-info-title {
      font-weight: 700;
      color: #4c1d95 !important;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .alert-info-text {
      color: #5b21b6 !important;
      font-size: 0.9rem;
      line-height: 1.6;
    }
    
    .alert-error {
      background: #fee2e2;
      border: 2px solid #ef4444;
      color: #991b1b !important;
      padding: 16px;
      border-radius: 12px;
      margin-bottom: 24px;
    }
    
    .btn-submit {
      width: 100%;
      padding: 16px 24px;
      background: linear-gradient(135deg, #7c5cff, #6a4de8);
      color: white !important;
      font-weight: 700;
      border: none;
      border-radius: 12px;
      font-size: 1.1rem;
      cursor: pointer;
      transition: all 0.3s;
      margin-top: 24px;
    }
    
    .btn-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(124, 92, 255, 0.4);
    }
    
    .btn-submit:active {
      transform: translateY(0);
    }
    
    .progress-bar {
      background: #f3f4f6;
      border-radius: 10px;
      height: 10px;
      margin-bottom: 32px;
      overflow: hidden;
    }
    
    .progress-fill {
      background: linear-gradient(90deg, #7c5cff, #10b981);
      height: 100%;
      transition: width 0.3s;
    }
    
    @media (max-width: 768px) {
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .section-card {
        padding: 20px;
      }
    }
    
    /* Estilo para campos condicionales */
    .conditional-field {
      display: none;
    }
    
    .conditional-field.active {
      display: block;
    }
  </style>
</head>
<body>

<div class="app">
  <?php include __DIR__ . '/sidebar_clientes.php'; ?>

  <main class="main">
    <div class="page-header">
      <div class="page-title">📋 Completar Información</div>
      <div class="page-sub">Necesitamos algunos datos adicionales para procesar tu solicitud</div>
    </div>

    <div class="form-container">
      
      <?php if ($error): ?>
        <div class="alert-error">
          <strong>❌ Error:</strong><br>
          <?php echo $error; ?>
        </div>
      <?php endif; ?>
      
      <div class="alert-info">
        <div class="alert-info-title">
          <span>ℹ️</span>
          <span>¿Por qué necesitamos esta información?</span>
        </div>
        <div class="alert-info-text">
          Esta información es requerida para completar tu perfil crediticio y poder aprobar tu préstamo. 
          Todos los datos son confidenciales y están protegidos según la Ley de Protección de Datos Personales.
        </div>
      </div>
      
      <form method="POST" id="formInformacion">
        
        <!-- 1. INFORMACIÓN PERSONAL -->
        <div class="section-card">
          <div class="section-title">
            <span>👤</span>
            <span>Información Personal</span>
          </div>
          <div class="section-subtitle">Datos personales y familiares</div>
          
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label required">CUIL / CUIT</label>
              <input 
                type="text" 
                name="cuil_cuit" 
                class="form-input" 
                placeholder="20-12345678-9"
                value="<?php echo h($cliente_info['cuil_cuit'] ?? ''); ?>"
                required
                maxlength="13"
              >
              <p class="form-help">Sin espacios ni guiones (11 dígitos)</p>
            </div>
            
            <div class="form-group">
              <label class="form-label required">Estado Civil</label>
              <select name="estado_civil" class="form-select" required id="estadoCivil">
                <option value="">Seleccionar...</option>
                <option value="soltero" <?php echo ($cliente_info['estado_civil'] ?? '') === 'soltero' ? 'selected' : ''; ?>>Soltero/a</option>
                <option value="casado" <?php echo ($cliente_info['estado_civil'] ?? '') === 'casado' ? 'selected' : ''; ?>>Casado/a</option>
                <option value="divorciado" <?php echo ($cliente_info['estado_civil'] ?? '') === 'divorciado' ? 'selected' : ''; ?>>Divorciado/a</option>
                <option value="viudo" <?php echo ($cliente_info['estado_civil'] ?? '') === 'viudo' ? 'selected' : ''; ?>>Viudo/a</option>
                <option value="union_libre" <?php echo ($cliente_info['estado_civil'] ?? '') === 'union_libre' ? 'selected' : ''; ?>>Unión Libre</option>
              </select>
            </div>
            
            <div class="form-group">
              <label class="form-label">Hijos a Cargo</label>
              <input 
                type="number" 
                name="hijos_a_cargo" 
                class="form-input" 
                min="0"
                max="20"
                value="<?php echo (int)($cliente_info['hijos_a_cargo'] ?? 0); ?>"
              >
            </div>
          </div>
          
          <!-- Datos del cónyuge (solo si está casado) -->
          <div id="datosConyugeContainer" class="conditional-field <?php echo ($cliente_info['estado_civil'] ?? '') === 'casado' ? 'active' : ''; ?>">
            <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">
            <h4 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 16px; color: #374151 !important;">Datos del Cónyuge</h4>
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label" id="labelDniConyuge">DNI del Cónyuge</label>
                <input 
                  type="text" 
                  name="dni_conyuge" 
                  class="form-input" 
                  placeholder="12345678"
                  value="<?php echo h($cliente_info['dni_conyuge'] ?? ''); ?>"
                  maxlength="10"
                >
              </div>
              
              <div class="form-group">
                <label class="form-label" id="labelNombreConyuge">Nombre Completo del Cónyuge</label>
                <input 
                  type="text" 
                  name="nombre_conyuge" 
                  class="form-input" 
                  placeholder="Juan Pérez"
                  value="<?php echo h($cliente_info['nombre_conyuge'] ?? ''); ?>"
                >
              </div>
            </div>
          </div>
        </div>
        
        <!-- 2. DIRECCIÓN -->
        <div class="section-card">
          <div class="section-title">
            <span>🏠</span>
            <span>Dirección</span>
          </div>
          <div class="section-subtitle">Domicilio personal completo</div>
          
          <div class="form-grid">
            <div class="form-group full-width">
              <label class="form-label required">Calle y Número</label>
              <input 
                type="text" 
                name="direccion_calle" 
                class="form-input" 
                placeholder="Av. Corrientes 1234"
                value="<?php echo h($cliente_info['direccion_calle'] ?? ''); ?>"
                required
              >
            </div>
            
            <div class="form-group">
              <label class="form-label">Piso</label>
              <input 
                type="text" 
                name="direccion_piso" 
                class="form-input" 
                placeholder="5"
                value="<?php echo h($cliente_info['direccion_piso'] ?? ''); ?>"
                maxlength="10"
              >
            </div>
            
            <div class="form-group">
              <label class="form-label">Departamento</label>
              <input 
                type="text" 
                name="direccion_departamento" 
                class="form-input" 
                placeholder="B"
                value="<?php echo h($cliente_info['direccion_departamento'] ?? ''); ?>"
                maxlength="10"
              >
            </div>
            
            <div class="form-group">
              <label class="form-label required">Barrio</label>
              <input 
                type="text" 
                name="direccion_barrio" 
                class="form-input" 
                placeholder="Palermo"
                value="<?php echo h($cliente_info['direccion_barrio'] ?? ''); ?>"
                required
              >
            </div>
            
            <div class="form-group">
              <label class="form-label">Código Postal</label>
              <input 
                type="text" 
                name="direccion_codigo_postal" 
                class="form-input" 
                placeholder="C1414"
                value="<?php echo h($cliente_info['direccion_codigo_postal'] ?? ''); ?>"
                maxlength="10"
              >
            </div>
            
            <div class="form-group">
              <label class="form-label required">Localidad</label>
              <input 
                type="text" 
                name="direccion_localidad" 
                class="form-input" 
                placeholder="CABA"
                value="<?php echo h($cliente_info['direccion_localidad'] ?? ''); ?>"
                required
              >
            </div>
            
            <div class="form-group">
              <label class="form-label required">Provincia</label>
              <select name="direccion_provincia" class="form-select" required>
                <option value="">Seleccionar...</option>
                <?php
                $provincias = [
                  'Buenos Aires', 'CABA', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba', 
                  'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja',
                  'Mendoza', 'Misiones', 'Neuquén', 'Río Negro', 'Salta', 'San Juan',
                  'San Luis', 'Santa Cruz', 'Santa Fe', 'Santiago del Estero', 'Tierra del Fuego',
                  'Tucumán'
                ];
                foreach ($provincias as $prov) {
                    $selected = ($cliente_info['direccion_provincia'] ?? '') === $prov ? 'selected' : '';
                    echo "<option value=\"{$prov}\" {$selected}>{$prov}</option>";
                }
                ?>
              </select>
            </div>
          </div>
          
          <div class="alert-info" style="margin-top: 20px;">
            <div class="alert-info-text">
              <strong>📍 Ubicación en Mapa (Opcional):</strong> Si querés, podés usar el botón de "Obtener mi ubicación" 
              para que completemos automáticamente las coordenadas de tu domicilio.
            </div>
          </div>
          
          <input type="hidden" name="latitud" id="latitud" value="<?php echo $cliente_info['direccion_latitud'] ?? ''; ?>">
          <input type="hidden" name="longitud" id="longitud" value="<?php echo $cliente_info['direccion_longitud'] ?? ''; ?>">
          
          <button type="button" onclick="obtenerUbicacion()" class="btn" style="margin-top: 12px; background: #10b981; color: white; padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer;">
            📍 Obtener mi Ubicación
          </button>
          <span id="ubicacionStatus" style="margin-left: 12px; color: #6b7280; font-size: 0.9rem;"></span>
        </div>
        
        <!-- 3. INFORMACIÓN LABORAL -->
        <div class="section-card">
          <div class="section-title">
            <span>💼</span>
            <span>Información Laboral</span>
          </div>
          <div class="section-subtitle">Datos sobre tu situación laboral e ingresos</div>
          
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label required">Tipo de Ingreso</label>
              <select name="tipo_ingreso" class="form-select" required id="tipoIngreso">
                <option value="">Seleccionar...</option>
                <option value="dependencia" <?php echo ($cliente_info['tipo_ingreso'] ?? '') === 'dependencia' ? 'selected' : ''; ?>>Relación de Dependencia</option>
                <option value="autonomo" <?php echo ($cliente_info['tipo_ingreso'] ?? '') === 'autonomo' ? 'selected' : ''; ?>>Autónomo</option>
                <option value="monotributo" <?php echo ($cliente_info['tipo_ingreso'] ?? '') === 'monotributo' ? 'selected' : ''; ?>>Monotributista</option>
                <option value="jubilacion" <?php echo ($cliente_info['tipo_ingreso'] ?? '') === 'jubilacion' ? 'selected' : ''; ?>>Jubilado/Pensionado</option>
                <option value="negro" <?php echo ($cliente_info['tipo_ingreso'] ?? '') === 'negro' ? 'selected' : ''; ?>>Trabajo Informal</option>
              </select>
            </div>
            
            <div class="form-group">
              <label class="form-label">Ingresos Mensuales</label>
              <input 
                type="number" 
                name="monto_ingresos" 
                class="form-input" 
                placeholder="150000"
                value="<?php echo $cliente_info['monto_ingresos'] ?? ''; ?>"
                min="0"
                step="1000"
              >
              <p class="form-help">Monto aproximado mensual</p>
            </div>
          </div>
          
          <!-- Datos del empleador (solo si es dependencia, autónomo o monotributo) -->
          <div id="datosEmpleadorContainer" class="conditional-field <?php echo in_array($cliente_info['tipo_ingreso'] ?? '', ['dependencia', 'autonomo', 'monotributo']) ? 'active' : ''; ?>">
            <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">
            <h4 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 16px; color: #374151 !important;">Datos del Empleador</h4>
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label" id="labelEmpleadorCuit">CUIT del Empleador</label>
                <input 
                  type="text" 
                  name="empleador_cuit" 
                  class="form-input" 
                  placeholder="30-12345678-9"
                  value="<?php echo h($cliente_info['empleador_cuit'] ?? ''); ?>"
                  maxlength="13"
                >
              </div>
              
              <div class="form-group">
                <label class="form-label" id="labelEmpleadorRazon">Razón Social</label>
                <input 
                  type="text" 
                  name="empleador_razon_social" 
                  class="form-input" 
                  placeholder="Empresa S.A."
                  value="<?php echo h($cliente_info['empleador_razon_social'] ?? ''); ?>"
                >
              </div>
              
              <div class="form-group">
                <label class="form-label">Teléfono del Empleador</label>
                <input 
                  type="text" 
                  name="empleador_telefono" 
                  class="form-input" 
                  placeholder="011-1234-5678"
                  value="<?php echo h($cliente_info['empleador_telefono'] ?? ''); ?>"
                >
              </div>
              
              <div class="form-group full-width">
                <label class="form-label">Domicilio Laboral</label>
                <input 
                  type="text" 
                  name="empleador_direccion" 
                  class="form-input" 
                  placeholder="Av. Libertador 1000, CABA"
                  value="<?php echo h($cliente_info['empleador_direccion'] ?? ''); ?>"
                >
              </div>
              
              <div class="form-group">
                <label class="form-label">Sector</label>
                <input 
                  type="text" 
                  name="empleador_sector" 
                  class="form-input" 
                  placeholder="Tecnología, Salud, Construcción..."
                  value="<?php echo h($cliente_info['empleador_sector'] ?? ''); ?>"
                >
              </div>
              
              <div class="form-group">
                <label class="form-label" id="labelCargo">Cargo</label>
                <input 
                  type="text" 
                  name="cargo" 
                  class="form-input" 
                  placeholder="Analista, Gerente, Operario..."
                  value="<?php echo h($cliente_info['cargo'] ?? ''); ?>"
                >
              </div>
              
              <div class="form-group">
                <label class="form-label" id="labelAntiguedad">Antigüedad (meses)</label>
                <input 
                  type="number" 
                  name="antiguedad_laboral" 
                  class="form-input" 
                  placeholder="24"
                  value="<?php echo $cliente_info['antiguedad_laboral'] ?? ''; ?>"
                  min="0"
                  max="600"
                >
                <p class="form-help">Tiempo en el empleo actual</p>
              </div>
            </div>
          </div>
        </div>
        
        <button type="submit" class="btn-submit">
          ✓ Guardar y Continuar
        </button>
      </form>
    </div>
  </main>
</div>

<script>
// Mostrar/ocultar datos del cónyuge
document.getElementById('estadoCivil').addEventListener('change', function() {
  const container = document.getElementById('datosConyugeContainer');
  const esCasado = this.value === 'casado';
  
  if (esCasado) {
    container.classList.add('active');
    document.getElementById('labelDniConyuge').classList.add('required');
    document.getElementById('labelNombreConyuge').classList.add('required');
  } else {
    container.classList.remove('active');
    document.getElementById('labelDniConyuge').classList.remove('required');
    document.getElementById('labelNombreConyuge').classList.remove('required');
  }
});

// Mostrar/ocultar datos del empleador
document.getElementById('tipoIngreso').addEventListener('change', function() {
  const container = document.getElementById('datosEmpleadorContainer');
  const requiereEmpleador = ['dependencia', 'autonomo', 'monotributo'].includes(this.value);
  
  if (requiereEmpleador) {
    container.classList.add('active');
    document.getElementById('labelEmpleadorRazon').classList.add('required');
    document.getElementById('labelCargo').classList.add('required');
    document.getElementById('labelAntiguedad').classList.add('required');
  } else {
    container.classList.remove('active');
    document.getElementById('labelEmpleadorRazon').classList.remove('required');
    document.getElementById('labelCargo').classList.remove('required');
    document.getElementById('labelAntiguedad').classList.remove('required');
  }
});

// Obtener ubicación
function obtenerUbicacion() {
  const status = document.getElementById('ubicacionStatus');
  
  if (!navigator.geolocation) {
    status.textContent = '❌ Tu navegador no soporta geolocalización';
    status.style.color = '#ef4444';
    return;
  }
  
  status.textContent = '⏳ Obteniendo ubicación...';
  status.style.color = '#f59e0b';
  
  navigator.geolocation.getCurrentPosition(
    function(position) {
      document.getElementById('latitud').value = position.coords.latitude.toFixed(8);
      document.getElementById('longitud').value = position.coords.longitude.toFixed(8);
      status.textContent = '✅ Ubicación obtenida correctamente';
      status.style.color = '#10b981';
    },
    function(error) {
      status.textContent = '❌ No se pudo obtener la ubicación';
      status.style.color = '#ef4444';
      console.error('Error:', error);
    }
  );
}

// Validación del formulario
document.getElementById('formInformacion').addEventListener('submit', function(e) {
  const estadoCivil = document.getElementById('estadoCivil').value;
  const tipoIngreso = document.getElementById('tipoIngreso').value;
  
  // Validar cónyuge si está casado
  if (estadoCivil === 'casado') {
    const dniConyuge = document.querySelector('input[name="dni_conyuge"]').value.trim();
    const nombreConyuge = document.querySelector('input[name="nombre_conyuge"]').value.trim();
    
    if (!dniConyuge || !nombreConyuge) {
      e.preventDefault();
      alert('Si estás casado/a, debes completar los datos del cónyuge');
      return;
    }
  }
  
  // Validar empleador si corresponde
  if (['dependencia', 'autonomo', 'monotributo'].includes(tipoIngreso)) {
    const razonSocial = document.querySelector('input[name="empleador_razon_social"]').value.trim();
    const cargo = document.querySelector('input[name="cargo"]').value.trim();
    const antiguedad = document.querySelector('input[name="antiguedad_laboral"]').value;
    
    if (!razonSocial || !cargo || !antiguedad || antiguedad <= 0) {
      e.preventDefault();
      alert('Debes completar los datos del empleador (razón social, cargo y antigüedad)');
      return;
    }
  }
});
</script>

</body>
</html>