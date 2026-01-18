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

// Obtener parámetros
$prestamo_id = (int)($_GET['id'] ?? 0);
$tipo = $_GET['tipo'] ?? 'prestamo';

if ($prestamo_id <= 0) {
    header('Location: dashboard_clientes.php');
    exit;
}

$mensaje = '';
$error = '';

// Determinar tabla según tipo
$tabla = match($tipo) {
    'prestamo' => 'prestamos',
    'empeno' => 'empenos',
    'prendario' => 'creditos_prendarios',
    default => null
};

if (!$tabla) {
    header('Location: dashboard_clientes.php');
    exit;
}

// Obtener información del préstamo
try {
    $stmt = $pdo->prepare("
        SELECT * FROM {$tabla}
        WHERE id = ? AND cliente_id = ?
        LIMIT 1
    ");
    $stmt->execute([$prestamo_id, $cliente_id]);
    $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$prestamo) {
        header('Location: dashboard_clientes.php');
        exit;
    }
    
    // Verificar que el contrato esté firmado
    if (($prestamo['estado_contrato'] ?? '') !== 'firmado') {
        header('Location: detalle_prestamo.php?id=' . $prestamo_id . '&tipo=' . $tipo);
        exit;
    }
    
    // Verificar que no haya solicitado ya el desembolso
    if (!empty($prestamo['solicitud_desembolso_fecha'])) {
        header('Location: detalle_prestamo.php?id=' . $prestamo_id . '&tipo=' . $tipo . '&msg=' . urlencode('Ya solicitaste el desembolso'));
        exit;
    }
    
} catch (Throwable $e) {
    error_log("Error al obtener préstamo: " . $e->getMessage());
    header('Location: dashboard_clientes.php');
    exit;
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $banco_id = (int)($_POST['banco_id'] ?? 0);
        $banco_nombre = trim($_POST['banco_nombre'] ?? '');
        $tipo_cuenta = trim($_POST['tipo_cuenta'] ?? '');
        $cbu = trim($_POST['cbu'] ?? '');
        $alias_cbu = trim($_POST['alias_cbu'] ?? '');
        $titular_cuenta = trim($_POST['titular_cuenta'] ?? '');
        
        // Validaciones
        $errores = [];
        
        if ($banco_id <= 0 || empty($banco_nombre)) {
            $errores[] = 'Debe seleccionar un banco';
        }
        
        if (!in_array($tipo_cuenta, ['caja_ahorro', 'cuenta_corriente'])) {
            $errores[] = 'Tipo de cuenta inválido';
        }
        
        if (empty($cbu) || strlen($cbu) !== 22 || !ctype_digit($cbu)) {
            $errores[] = 'El CBU debe tener exactamente 22 dígitos';
        }
        
        if (empty($titular_cuenta)) {
            $errores[] = 'Debe ingresar el titular de la cuenta';
        }
        
        if (!empty($errores)) {
            $error = implode('<br>', $errores);
        } else {
            // Guardar datos del desembolso
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                UPDATE {$tabla} SET 
                    banco = ?,
                    tipo_cuenta = ?,
                    cbu = ?,
                    alias_cbu = ?,
                    titular_cuenta = ?,
                    solicitud_desembolso_fecha = NOW(),
                    desembolso_estado = 'pendiente'
                WHERE id = ? AND cliente_id = ?
            ");
            
            $stmt->execute([
                $banco_nombre,
                $tipo_cuenta,
                $cbu,
                $alias_cbu,
                $titular_cuenta,
                $prestamo_id,
                $cliente_id
            ]);
            
            // Crear notificación para admins
            $stmt = $pdo->prepare("
                INSERT INTO clientes_notificaciones (
                    cliente_id, tipo, titulo, mensaje, url_accion, texto_accion
                ) VALUES (?, 'info', 'Solicitud de Desembolso Enviada', 
                'Tu solicitud de desembolso fue enviada. El equipo revisará los datos y te transferirá el dinero en 24-48hs hábiles.', 
                'detalle_prestamo.php?id={$prestamo_id}&tipo={$tipo}', 'Ver Préstamo')
            ");
            $stmt->execute([$cliente_id]);
            
            $pdo->commit();
            
            header('Location: detalle_prestamo.php?id=' . $prestamo_id . '&tipo=' . $tipo . '&msg=' . urlencode('✅ Solicitud de desembolso enviada correctamente'));
            exit;
        }
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error al solicitar desembolso: " . $e->getMessage());
        $error = 'Error al procesar la solicitud. Intenta nuevamente.';
    }
}

// Obtener información del cliente
$cliente_info = null;
try {
    $stmt = $pdo->prepare("
        SELECT nombre_completo, dni, cuit
        FROM clientes_detalles
        WHERE usuario_id = ?
        LIMIT 1
    ");
    $stmt->execute([$cliente_id]);
    $cliente_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Error al obtener info cliente: " . $e->getMessage());
}

// Variables para sidebar
$pagina_activa = 'prestamos';
$nombre_mostrar = trim($cliente_info['nombre_completo'] ?? 'Cliente');

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Solicitar Desembolso - Préstamo Líder</title>
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
      max-width: 800px;
      margin: 0 auto;
      background: white;
      padding: 40px;
      border-radius: 16px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .form-title {
      font-size: 1.75rem;
      font-weight: 700;
      color: #1f2937 !important;
      margin-bottom: 8px;
    }
    
    .form-subtitle {
      color: #6b7280 !important;
      margin-bottom: 32px;
      font-size: 0.95rem;
    }
    
    .form-group {
      margin-bottom: 24px;
    }
    
    .form-label {
      display: block;
      font-weight: 600;
      color: #374151 !important;
      margin-bottom: 8px;
      font-size: 0.95rem;
    }
    
    .form-input, .form-select {
      width: 100%;
      padding: 12px 16px;
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      font-size: 0.95rem;
      transition: all 0.2s;
      color: #1f2937 !important;
      background: white;
    }
    
    .form-input:focus, .form-select:focus {
      outline: none;
      border-color: #7c5cff;
      box-shadow: 0 0 0 3px rgba(124, 92, 255, 0.1);
    }
    
    .form-help {
      font-size: 0.85rem;
      color: #6b7280 !important;
      margin-top: 6px;
    }
    
    .info-card {
      background: linear-gradient(135deg, #e0e7ff, #ddd6fe);
      padding: 20px;
      border-radius: 12px;
      margin-bottom: 32px;
      border-left: 4px solid #7c5cff;
    }
    
    .info-card-title {
      font-weight: 700;
      color: #4c1d95 !important;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .info-card-text {
      color: #5b21b6 !important;
      font-size: 0.9rem;
      line-height: 1.6;
    }
    
    .btn-submit {
      width: 100%;
      padding: 14px 24px;
      background: linear-gradient(135deg, #7c5cff, #6a4de8);
      color: white !important;
      font-weight: 700;
      border: none;
      border-radius: 12px;
      font-size: 1rem;
      cursor: pointer;
      transition: all 0.3s;
    }
    
    .btn-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(124, 92, 255, 0.4);
    }
    
    .btn-submit:active {
      transform: translateY(0);
    }
    
    .btn-cancel {
      width: 100%;
      padding: 14px 24px;
      background: #f3f4f6;
      color: #374151 !important;
      font-weight: 600;
      border: none;
      border-radius: 12px;
      font-size: 1rem;
      cursor: pointer;
      transition: all 0.2s;
      margin-top: 12px;
      text-decoration: none;
      display: inline-block;
      text-align: center;
    }
    
    .btn-cancel:hover {
      background: #e5e7eb;
    }
    
    .alert-error {
      background: #fee2e2;
      border: 2px solid #ef4444;
      color: #991b1b !important;
      padding: 16px;
      border-radius: 12px;
      margin-bottom: 24px;
    }
    
    .prestamo-info {
      background: #f9fafb;
      padding: 20px;
      border-radius: 12px;
      margin-bottom: 32px;
    }
    
    .prestamo-info-item {
      display: flex;
      justify-content: space-between;
      padding: 12px 0;
      border-bottom: 1px solid #e5e7eb;
    }
    
    .prestamo-info-item:last-child {
      border-bottom: none;
    }
    
    .prestamo-info-label {
      font-weight: 600;
      color: #6b7280 !important;
    }
    
    .prestamo-info-value {
      font-weight: 700;
      color: #1f2937 !important;
    }
    
    /* Select2-like styling para el banco */
    .banco-search {
      position: relative;
    }
    
    .banco-search-input {
      width: 100%;
      padding: 12px 16px;
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      font-size: 0.95rem;
    }
    
    .banco-dropdown {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      max-height: 300px;
      overflow-y: auto;
      background: white;
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      margin-top: 4px;
      display: none;
      z-index: 1000;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    
    .banco-dropdown.active {
      display: block;
    }
    
    .banco-option {
      padding: 12px 16px;
      cursor: pointer;
      transition: all 0.2s;
      color: #1f2937 !important;
    }
    
    .banco-option:hover {
      background: #f3f4f6;
    }
    
    .banco-option.selected {
      background: #e0e7ff;
      color: #4c1d95 !important;
      font-weight: 600;
    }
    
    .cbu-input {
      font-family: 'Courier New', monospace;
      letter-spacing: 2px;
    }
  </style>
</head>
<body>

<div class="app">
  <?php include __DIR__ . '/sidebar_clientes.php'; ?>

  <main class="main">
    <div class="page-header">
      <div class="page-title">💰 Solicitar Desembolso</div>
      <div class="page-sub">Completá tus datos bancarios para recibir el dinero</div>
    </div>

    <div class="form-container">
      <?php if ($error): ?>
        <div class="alert-error">
          <strong>❌ Error:</strong><br>
          <?php echo $error; ?>
        </div>
      <?php endif; ?>
      
      <div class="form-title">📋 Datos del Préstamo</div>
      <div class="form-subtitle">Verificá los datos antes de continuar</div>
      
      <div class="prestamo-info">
        <div class="prestamo-info-item">
          <span class="prestamo-info-label">Monto a recibir:</span>
          <span class="prestamo-info-value">$<?php echo number_format((float)($prestamo['monto_ofrecido'] ?? $prestamo['monto_solicitado'] ?? 0), 0, ',', '.'); ?></span>
        </div>
        <div class="prestamo-info-item">
          <span class="prestamo-info-label">Tipo:</span>
          <span class="prestamo-info-value"><?php 
            $tipos = ['prestamo' => 'Préstamo', 'empeno' => 'Empeño', 'prendario' => 'Crédito Prendario'];
            echo $tipos[$tipo] ?? 'Préstamo';
          ?></span>
        </div>
        <div class="prestamo-info-item">
          <span class="prestamo-info-label">Estado:</span>
          <span class="prestamo-info-value">✅ Contrato Firmado</span>
        </div>
      </div>
      
      <div class="info-card">
        <div class="info-card-title">
          <span>ℹ️</span>
          <span>Información Importante</span>
        </div>
        <div class="info-card-text">
          • El dinero será transferido en <strong>24 a 48 horas hábiles</strong><br>
          • Verificá que los datos bancarios sean correctos<br>
          • El CBU debe tener exactamente 22 dígitos<br>
          • La cuenta debe estar a tu nombre<br>
          • Una vez enviada la solicitud, no podrás modificar los datos
        </div>
      </div>
      
      <form method="POST" id="formDesembolso">
        <div class="form-title" style="font-size: 1.25rem; margin-top: 24px;">🏦 Datos Bancarios</div>
        <div class="form-subtitle" style="margin-bottom: 24px;">Ingresá los datos de la cuenta donde querés recibir el dinero</div>
        
        <div class="form-group">
          <label class="form-label">Banco *</label>
          <div class="banco-search">
            <input 
              type="text" 
              class="form-input banco-search-input" 
              id="bancoSearchInput"
              placeholder="Buscar banco..."
              autocomplete="off"
            >
            <input type="hidden" name="banco_id" id="bancoId" required>
            <input type="hidden" name="banco_nombre" id="bancoNombre" required>
            <div class="banco-dropdown" id="bancoDropdown"></div>
          </div>
          <div class="form-help">Escribí el nombre del banco para buscarlo</div>
        </div>
        
        <div class="form-group">
          <label class="form-label">Tipo de Cuenta *</label>
          <select name="tipo_cuenta" class="form-select" required>
            <option value="">Seleccionar...</option>
            <option value="caja_ahorro">Caja de Ahorro</option>
            <option value="cuenta_corriente">Cuenta Corriente</option>
          </select>
        </div>
        
        <div class="form-group">
          <label class="form-label">CBU *</label>
          <input 
            type="text" 
            name="cbu" 
            class="form-input cbu-input" 
            placeholder="0000000000000000000000"
            maxlength="22"
            pattern="[0-9]{22}"
            required
            id="cbuInput"
          >
          <div class="form-help">22 dígitos sin espacios ni guiones</div>
        </div>
        
        <div class="form-group">
          <label class="form-label">Alias CBU (Opcional)</label>
          <input 
            type="text" 
            name="alias_cbu" 
            class="form-input" 
            placeholder="TU.ALIAS.CBU"
            maxlength="100"
          >
          <div class="form-help">Opcional, solo si conocés tu alias</div>
        </div>
        
        <div class="form-group">
          <label class="form-label">Titular de la Cuenta *</label>
          <input 
            type="text" 
            name="titular_cuenta" 
            class="form-input" 
            placeholder="Juan Pérez"
            value="<?php echo h($cliente_info['nombre_completo'] ?? ''); ?>"
            required
          >
          <div class="form-help">Debe coincidir con el nombre en tu DNI</div>
        </div>
        
        <button type="submit" class="btn-submit">
          ✓ Enviar Solicitud de Desembolso
        </button>
        
        <a href="detalle_prestamo.php?id=<?php echo $prestamo_id; ?>&tipo=<?php echo $tipo; ?>" class="btn-cancel">
          Cancelar
        </a>
      </form>
    </div>
  </main>
</div>

<script>
// Cargar lista de bancos
let bancos = [];

fetch('bancos.json')
  .then(response => response.json())
  .then(data => {
    bancos = data;
  })
  .catch(error => {
    console.error('Error al cargar bancos:', error);
  });

// Búsqueda de bancos
const bancoSearchInput = document.getElementById('bancoSearchInput');
const bancoDropdown = document.getElementById('bancoDropdown');
const bancoId = document.getElementById('bancoId');
const bancoNombre = document.getElementById('bancoNombre');

bancoSearchInput.addEventListener('input', function() {
  const searchTerm = this.value.toLowerCase().trim();
  
  if (searchTerm.length === 0) {
    bancoDropdown.classList.remove('active');
    return;
  }
  
  const filteredBancos = bancos.filter(banco => 
    banco.text.toLowerCase().includes(searchTerm)
  );
  
  if (filteredBancos.length === 0) {
    bancoDropdown.innerHTML = '<div class="banco-option" style="color: #9ca3af !important;">No se encontraron bancos</div>';
    bancoDropdown.classList.add('active');
    return;
  }
  
  bancoDropdown.innerHTML = filteredBancos.map(banco => 
    `<div class="banco-option" data-id="${banco.id}" data-name="${banco.text}">
      ${banco.text}
    </div>`
  ).join('');
  
  bancoDropdown.classList.add('active');
  
  // Agregar eventos a las opciones
  document.querySelectorAll('.banco-option').forEach(option => {
    option.addEventListener('click', function() {
      const id = this.getAttribute('data-id');
      const name = this.getAttribute('data-name');
      
      if (id && name) {
        bancoSearchInput.value = name;
        bancoId.value = id;
        bancoNombre.value = name;
        bancoDropdown.classList.remove('active');
      }
    });
  });
});

// Cerrar dropdown al hacer clic fuera
document.addEventListener('click', function(e) {
  if (!e.target.closest('.banco-search')) {
    bancoDropdown.classList.remove('active');
  }
});

// Validación del CBU
const cbuInput = document.getElementById('cbuInput');
cbuInput.addEventListener('input', function() {
  this.value = this.value.replace(/[^0-9]/g, '');
});

// Validación del formulario
document.getElementById('formDesembolso').addEventListener('submit', function(e) {
  const banco = bancoId.value;
  const cbu = cbuInput.value;
  
  if (!banco) {
    e.preventDefault();
    alert('Por favor, selecciona un banco de la lista');
    bancoSearchInput.focus();
    return;
  }
  
  if (cbu.length !== 22) {
    e.preventDefault();
    alert('El CBU debe tener exactamente 22 dígitos');
    cbuInput.focus();
    return;
  }
  
  if (!confirm('¿Estás seguro de que los datos bancarios son correctos?\n\nUna vez enviada la solicitud no podrás modificarlos.')) {
    e.preventDefault();
  }
});
</script>

</body>
</html>