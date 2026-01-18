<?php

session_start();

/* =========================
   PROD SAFE DEBUG (NO UI)
========================= */
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

$logFile = __DIR__ . '/logs/perfil_clientes.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0755, true);
}
function dlog($msg) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
}
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* =========================
   VALIDACIÓN DE SESIÓN
========================= */
if (!isset($_SESSION['cliente_id'])) {
    header('Location: login_clientes.php');
    exit;
}

$cliente_id = (int)$_SESSION['cliente_id'];
if ($cliente_id <= 0) {
    dlog("ERROR: cliente_id inválido en sesión.");
    header('Location: login_clientes.php');
    exit;
}

/* =========================
   DB
========================= */
try {
    require_once __DIR__ . '/backend/connection.php';
} catch (Throwable $e) {
    dlog("ERROR loading connection.php: " . $e->getMessage());
    header('Location: login_clientes.php');
    exit;
}
if (!isset($pdo)) {
    dlog("ERROR: \$pdo no existe. Revisar backend/connection.php");
    header('Location: login_clientes.php');
    exit;
}

/* =========================
   MENSAJES
========================= */
$mensaje_error = null;
$mensaje_exito = null;

/* =========================
   PROCESAR ACTUALIZACIÓN PERFIL
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_perfil'])) {
    
    try {
        // Datos de usuarios
        $nombre = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        
        // Datos de clientes_detalles
        $dni = trim($_POST['dni'] ?? '');
        $cuit = trim($_POST['cuit'] ?? '');
        $nombre_completo = trim($_POST['nombre_completo'] ?? '');
        $fecha_nacimiento = trim($_POST['fecha_nacimiento'] ?? '');
        
        $calle = trim($_POST['calle'] ?? '');
        $numero = trim($_POST['numero'] ?? '');
        $localidad = trim($_POST['localidad'] ?? '');
        $ciudad = trim($_POST['ciudad'] ?? '');
        $provincia = trim($_POST['provincia'] ?? '');
        $codigo_postal = trim($_POST['codigo_postal'] ?? '');
        
        $direccion = trim($calle . ' ' . $numero);
        
        $banco = trim($_POST['banco'] ?? '');
        $cbu = trim($_POST['cbu'] ?? '');
        
        $tipo_ingreso = trim($_POST['tipo_ingreso'] ?? '');
        $monto_ingresos = trim($_POST['monto_ingresos'] ?? '');
        
        $contacto1_nombre = trim($_POST['contacto1_nombre'] ?? '');
        $contacto1_relacion = trim($_POST['contacto1_relacion'] ?? '');
        $contacto1_telefono = trim($_POST['contacto1_telefono'] ?? '');
        
        $contacto2_nombre = trim($_POST['contacto2_nombre'] ?? '');
        $contacto2_relacion = trim($_POST['contacto2_relacion'] ?? '');
        $contacto2_telefono = trim($_POST['contacto2_telefono'] ?? '');
        
        // Validaciones
        if (!$mensaje_error && !empty($dni) && !preg_match('/^\d{7,8}$/', $dni)) {
            $mensaje_error = 'El DNI debe tener 7 u 8 dígitos.';
        }
        
        if (!$mensaje_error && !empty($cbu) && !preg_match('/^\d{22}$/', $cbu)) {
            $mensaje_error = 'El CBU debe tener 22 dígitos.';
        }
        
        if (!$mensaje_error) {
            // Actualizar usuarios
            $stmt = $pdo->prepare("
                UPDATE usuarios
                SET nombre = ?, apellido = ?, email = ?, telefono = ?
                WHERE id = ?
            ");
            $stmt->execute([$nombre, $apellido, $email, $telefono, $cliente_id]);
            
            // Actualizar clientes_detalles
            $stmt = $pdo->prepare("
                UPDATE clientes_detalles
                SET 
                    dni = ?,
                    cuit = ?,
                    nombre_completo = ?,
                    telefono = ?,
                    fecha_nacimiento = ?,
                    direccion = ?,
                    calle = ?,
                    numero = ?,
                    localidad = ?,
                    ciudad = ?,
                    provincia = ?,
                    codigo_postal = ?,
                    banco = ?,
                    cbu = ?,
                    tipo_ingreso = ?,
                    monto_ingresos = ?,
                    contacto1_nombre = ?,
                    contacto1_relacion = ?,
                    contacto1_telefono = ?,
                    contacto2_nombre = ?,
                    contacto2_relacion = ?,
                    contacto2_telefono = ?
                WHERE usuario_id = ?
            ");
            $stmt->execute([
                $dni,
                $cuit,
                $nombre_completo,
                $telefono,
                $fecha_nacimiento ?: null,
                $direccion,
                $calle,
                $numero,
                $localidad,
                $ciudad,
                $provincia,
                $codigo_postal,
                $banco,
                $cbu,
                $tipo_ingreso ?: null,
                $monto_ingresos ?: null,
                $contacto1_nombre,
                $contacto1_relacion,
                $contacto1_telefono,
                $contacto2_nombre,
                $contacto2_relacion,
                $contacto2_telefono,
                $cliente_id
            ]);
            
            $_SESSION['cliente_email'] = $email;
            
            $mensaje_exito = 'Perfil actualizado correctamente.';
            dlog("Perfil actualizado para cliente_id=$cliente_id");
        }
        
    } catch (Throwable $e) {
        dlog("EXCEPTION actualizar perfil: " . $e->getMessage());
        $mensaje_error = 'Error al actualizar el perfil.';
    }
}

/* =========================
   PROCESAR DOCUMENTOS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_documento'])) {
    
    try {
        $campo = $_POST['campo'] ?? '';
        $campos_validos = [
            'doc_dni_frente',
            'doc_dni_dorso',
            'doc_selfie',
            'doc_comprobante_ingresos',
            'doc_cbu'
        ];
        
        if (!in_array($campo, $campos_validos, true)) {
            $mensaje_error = 'Campo de documento inválido.';
        }
        
        if (!$mensaje_error) {
            if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                $mensaje_error = 'Seleccioná un archivo.';
            }
        }
        
        if (!$mensaje_error) {
            $archivo = $_FILES['archivo'];
            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
            $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
            
            if (!in_array($extension, $allowedExt, true)) {
                $mensaje_error = 'Formato de archivo no permitido.';
            }
            
            if (!$mensaje_error && ($archivo['size'] ?? 0) > 5 * 1024 * 1024) {
                $mensaje_error = 'El archivo supera el tamaño máximo de 5MB.';
            }
            
            if (!$mensaje_error && function_exists('finfo_open')) {
                $mimePermitidos = ['image/jpeg', 'image/png', 'application/pdf'];
                $mime = null;
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = finfo_file($finfo, $archivo['tmp_name']);
                    finfo_close($finfo);
                }
                if ($mime !== null && !in_array($mime, $mimePermitidos, true)) {
                    $mensaje_error = 'Tipo de archivo no válido.';
                }
            }
            
            if (!$mensaje_error) {
                $nombre_archivo = $campo . '_' . $cliente_id . '_' . time() . '.' . $extension;
                
                $dirFisico = __DIR__ . '/../uploads/clientes_docs/';
                $ruta_destino = $dirFisico . $nombre_archivo;
                $ruta_publica = '../uploads/clientes_docs/' . $nombre_archivo;
                
                if (!is_dir($dirFisico)) {
                    @mkdir($dirFisico, 0755, true);
                }
                
                if (move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
                    $stmt = $pdo->prepare("SELECT $campo FROM clientes_detalles WHERE usuario_id = ? LIMIT 1");
                    $stmt->execute([$cliente_id]);
                    $anterior = $stmt->fetchColumn();
                    
                    if ($anterior && file_exists(__DIR__ . '/' . $anterior)) {
                        @unlink(__DIR__ . '/' . $anterior);
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE clientes_detalles
                        SET $campo = ?, docs_updated_at = NOW()
                        WHERE usuario_id = ?
                    ");
                    $stmt->execute([$ruta_publica, $cliente_id]);
                    
                    $mensaje_exito = 'Documento actualizado correctamente.';
                    dlog("Documento $campo actualizado para cliente_id=$cliente_id");
                } else {
                    $mensaje_error = 'Error al subir el archivo.';
                }
            }
        }
        
    } catch (Throwable $e) {
        dlog("EXCEPTION actualizar documento: " . $e->getMessage());
        $mensaje_error = 'Error al actualizar el documento.';
    }
}

/* =========================
   OBTENER DATOS
========================= */
$cliente_info = null;

try {
    // Primero obtener de usuarios
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([$cliente_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        dlog("ERROR: No se encontró usuario para id=$cliente_id");
        header('Location: logout.php');
        exit;
    }
    
    // Luego obtener de clientes_detalles
    $stmt = $pdo->prepare("SELECT * FROM clientes_detalles WHERE usuario_id = ? LIMIT 1");
    $stmt->execute([$cliente_id]);
    $detalles = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si no existe, crear
    if (!$detalles) {
        $ins = $pdo->prepare("INSERT INTO clientes_detalles (usuario_id) VALUES (?)");
        $ins->execute([$cliente_id]);
        $stmt->execute([$cliente_id]);
        $detalles = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Combinar ambos arrays
    $cliente_info = array_merge($usuario, $detalles ?: []);
    
} catch (Throwable $e) {
    dlog("EXCEPTION obtener datos: " . $e->getMessage());
    header('Location: logout.php');
    exit;
}

/* =========================
   UI VARS
========================= */
$pagina_activa = 'perfil';

$docs = [
    'doc_dni_frente' => [
        'label' => 'DNI Frente',
        'value' => $cliente_info['doc_dni_frente'] ?? null,
        'required' => true
    ],
    'doc_dni_dorso' => [
        'label' => 'DNI Dorso',
        'value' => $cliente_info['doc_dni_dorso'] ?? null,
        'required' => true
    ],
    'doc_selfie' => [
        'label' => 'Selfie con DNI',
        'value' => $cliente_info['doc_selfie'] ?? null,
        'required' => false
    ],
    'doc_comprobante_ingresos' => [
        'label' => 'Comprobante de Ingresos',
        'value' => $cliente_info['doc_comprobante_ingresos'] ?? null,
        'required' => false
    ],
    'doc_cbu' => [
        'label' => 'Comprobante de CBU',
        'value' => $cliente_info['doc_cbu'] ?? null,
        'required' => false
    ]
];

$nombreCompleto = trim(($cliente_info['nombre'] ?? '') . ' ' . ($cliente_info['apellido'] ?? ''));
if ($nombreCompleto === '') $nombreCompleto = 'Cliente';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mi Perfil - Préstamo Líder</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="style_clientes.css">
  <style>
    .doc-preview {
      width: 80px;
      height: 80px;
      border-radius: 8px;
      overflow: hidden;
      border: 2px solid rgba(255,255,255,.10);
      background: rgba(255,255,255,.04);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .doc-preview img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .doc-preview-pdf {
      font-size: 32px;
      color: var(--danger);
    }
  </style>
</head>

<body>
  <div class="app-layout">

    <?php include __DIR__ . '/sidebar_clientes.php'; ?>

    <main class="main-content">
      
      <div style="margin-bottom:1.5rem">
        <h1 class="page-title">Mi Perfil</h1>
        <p class="page-sub">Actualizá tu información personal y documentación</p>
      </div>

      <?php if ($mensaje_exito): ?>
        <div class="card" style="padding:1rem; margin-bottom:1.5rem; border-left:4px solid var(--accent); background: rgba(34,197,94,.10);">
          <p style="font-weight:600; color:#15803d;">✓ <?= h($mensaje_exito) ?></p>
        </div>
      <?php endif; ?>

      <?php if ($mensaje_error): ?>
        <div class="card" style="padding:1rem; margin-bottom:1.5rem; border-left:4px solid var(--danger); background: rgba(239,68,68,.10);">
          <p style="font-weight:600; color:#991b1b;">⚠ <?= h($mensaje_error) ?></p>
        </div>
      <?php endif; ?>

      <!-- INFORMACIÓN PERSONAL -->
      <div class="card" style="padding:1.5rem; margin-bottom:1.5rem">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem">
          <h2 style="font-size:1.25rem; font-weight:800">Información Personal</h2>
          <span class="chip info">Registrado: <?= date('d/m/Y', strtotime($cliente_info['created_at'] ?? 'now')) ?></span>
        </div>

        <form method="POST">
          <input type="hidden" name="actualizar_perfil" value="1">

          <div class="grid" style="margin-bottom:1rem">
            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Nombre *</label>
              <input type="text" name="nombre" value="<?= h($cliente_info['nombre'] ?? '') ?>" required class="input">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Apellido *</label>
              <input type="text" name="apellido" value="<?= h($cliente_info['apellido'] ?? '') ?>" required class="input">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Email *</label>
              <input type="email" name="email" value="<?= h($cliente_info['email'] ?? '') ?>" required class="input">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Teléfono *</label>
              <input type="tel" name="telefono" value="<?= h($cliente_info['telefono'] ?? '') ?>" required class="input" placeholder="Ej: 11 2345 6789">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">DNI *</label>
              <input type="text" name="dni" value="<?= h($cliente_info['dni'] ?? '') ?>" pattern="\d{7,8}" required class="input" placeholder="Sin puntos">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">CUIT</label>
              <input type="text" name="cuit" value="<?= h($cliente_info['cuit'] ?? '') ?>" class="input" placeholder="Ej: 20-12345678-9">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Nombre Completo</label>
              <input type="text" name="nombre_completo" value="<?= h($cliente_info['nombre_completo'] ?? '') ?>" class="input">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Fecha de Nacimiento</label>
              <input type="date" name="fecha_nacimiento" value="<?= h($cliente_info['fecha_nacimiento'] ?? '') ?>" class="input">
            </div>
          </div>

          <div style="display:flex; justify-content:flex-end; padding-top:1rem">
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
          </div>
        </form>
      </div>

      <!-- DIRECCIÓN -->
      <div class="card" style="padding:1.5rem; margin-bottom:1.5rem">
        <h2 style="font-size:1.25rem; font-weight:800; margin-bottom:1.5rem">Dirección</h2>

        <form method="POST">
          <input type="hidden" name="actualizar_perfil" value="1">

          <div class="grid" style="margin-bottom:1rem">
            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Calle</label>
              <input type="text" name="calle" value="<?= h($cliente_info['calle'] ?? '') ?>" class="input">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Número</label>
              <input type="text" name="numero" value="<?= h($cliente_info['numero'] ?? '') ?>" class="input">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Localidad</label>
              <input type="text" name="localidad" value="<?= h($cliente_info['localidad'] ?? '') ?>" class="input">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Ciudad</label>
              <input type="text" name="ciudad" value="<?= h($cliente_info['ciudad'] ?? '') ?>" class="input">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Provincia</label>
              <input type="text" name="provincia" value="<?= h($cliente_info['provincia'] ?? '') ?>" class="input">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Código Postal</label>
              <input type="text" name="codigo_postal" value="<?= h($cliente_info['codigo_postal'] ?? '') ?>" class="input">
            </div>
          </div>

          <div style="display:flex; justify-content:flex-end; padding-top:1rem">
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
          </div>
        </form>
      </div>

      <!-- INFORMACIÓN BANCARIA -->
      <div class="card" style="padding:1.5rem; margin-bottom:1.5rem">
        <h2 style="font-size:1.25rem; font-weight:800; margin-bottom:1.5rem">Información Bancaria</h2>

        <form method="POST">
          <input type="hidden" name="actualizar_perfil" value="1">

          <div class="grid" style="margin-bottom:1rem">
            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Banco *</label>
              <input type="text" name="banco" value="<?= h($cliente_info['banco'] ?? '') ?>" required class="input" placeholder="Ej: Banco Nación">
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">CBU *</label>
              <input type="text" name="cbu" value="<?= h($cliente_info['cbu'] ?? '') ?>" pattern="\d{22}" required class="input" placeholder="22 dígitos sin espacios">
              <p style="font-size:0.75rem; margin-top:0.25rem; color:var(--muted);">El CBU debe tener exactamente 22 dígitos</p>
            </div>
          </div>

          <div style="display:flex; justify-content:flex-end; padding-top:1rem">
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
          </div>
        </form>
      </div>

      <!-- INFORMACIÓN LABORAL -->
      <div class="card" style="padding:1.5rem; margin-bottom:1.5rem">
        <h2 style="font-size:1.25rem; font-weight:800; margin-bottom:1.5rem">Información Laboral</h2>

        <form method="POST">
          <input type="hidden" name="actualizar_perfil" value="1">

          <div class="grid" style="margin-bottom:1rem">
            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Tipo de Ingreso</label>
              <select name="tipo_ingreso" class="input">
                <option value="">Seleccionar...</option>
                <?php
                $tipos = ['dependencia' => 'Dependencia', 'monotributo' => 'Monotributo', 'jubilacion' => 'Jubilación', 'autonomo' => 'Autónomo', 'negro' => 'Informal'];
                foreach ($tipos as $val => $label) {
                  $selected = (($cliente_info['tipo_ingreso'] ?? '') === $val) ? 'selected' : '';
                  echo "<option value=\"$val\" $selected>$label</option>";
                }
                ?>
              </select>
            </div>

            <div>
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Ingreso Mensual</label>
              <input type="number" name="monto_ingresos" value="<?= h($cliente_info['monto_ingresos'] ?? '') ?>" class="input" placeholder="Ej: 500000" step="1000">
            </div>
          </div>

          <div style="display:flex; justify-content:flex-end; padding-top:1rem">
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
          </div>
        </form>
      </div>

      <!-- CONTACTOS DE EMERGENCIA -->
      <div class="card" style="padding:1.5rem; margin-bottom:1.5rem">
        <h2 style="font-size:1.25rem; font-weight:800; margin-bottom:1.5rem">Contactos de Emergencia</h2>

        <form method="POST">
          <input type="hidden" name="actualizar_perfil" value="1">

          <div style="margin-bottom:1.5rem">
            <h3 style="font-weight:700; margin-bottom:1rem">Contacto 1</h3>
            <div class="grid">
              <div>
                <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Nombre</label>
                <input type="text" name="contacto1_nombre" value="<?= h($cliente_info['contacto1_nombre'] ?? '') ?>" class="input">
              </div>

              <div>
                <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Relación</label>
                <input type="text" name="contacto1_relacion" value="<?= h($cliente_info['contacto1_relacion'] ?? '') ?>" class="input" placeholder="Ej: Hermano">
              </div>

              <div>
                <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Teléfono</label>
                <input type="tel" name="contacto1_telefono" value="<?= h($cliente_info['contacto1_telefono'] ?? '') ?>" class="input">
              </div>
            </div>
          </div>

          <div style="margin-bottom:1.5rem">
            <h3 style="font-weight:700; margin-bottom:1rem">Contacto 2</h3>
            <div class="grid">
              <div>
                <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Nombre</label>
                <input type="text" name="contacto2_nombre" value="<?= h($cliente_info['contacto2_nombre'] ?? '') ?>" class="input">
              </div>

              <div>
                <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Relación</label>
                <input type="text" name="contacto2_relacion" value="<?= h($cliente_info['contacto2_relacion'] ?? '') ?>" class="input" placeholder="Ej: Madre">
              </div>

              <div>
                <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Teléfono</label>
                <input type="tel" name="contacto2_telefono" value="<?= h($cliente_info['contacto2_telefono'] ?? '') ?>" class="input">
              </div>
            </div>
          </div>

          <div style="display:flex; justify-content:flex-end; padding-top:1rem">
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
          </div>
        </form>
      </div>

      <!-- DOCUMENTACIÓN -->
      <div class="card" style="padding:1.5rem; margin-bottom:1.5rem">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem">
          <h2 style="font-size:1.25rem; font-weight:800">Documentación</h2>
          <?php
            $estado = strtolower($cliente_info['estado_validacion'] ?? 'pendiente');
            if ($estado === 'aprobado') {
              echo '<span class="chip ok">✓ Verificado</span>';
            } elseif ($estado === 'en_revision') {
              echo '<span class="chip warn">⏳ En revisión</span>';
            } elseif ($estado === 'rechazado') {
              echo '<span class="chip danger">✕ Rechazado</span>';
            } else {
              echo '<span class="chip">Pendiente</span>';
            }
          ?>
        </div>

        <?php if (!empty($cliente_info['docs_updated_at'])): ?>
          <p style="font-size:0.875rem; margin-bottom:1.5rem; color:var(--muted);">
            Última actualización: <?= date('d/m/Y H:i', strtotime($cliente_info['docs_updated_at'])) ?>
          </p>
        <?php endif; ?>

        <div style="display:grid; gap:1rem">
          <?php foreach ($docs as $campo => $info): ?>
            <div style="padding:1rem; border-radius:0.75rem; border:1px solid rgba(255,255,255,.10); background: rgba(255,255,255,.04);">
              <div style="display:flex; align-items:center; gap:1rem">
                
                <?php if (!empty($info['value'])): ?>
                  <div class="doc-preview">
                    <?php if (preg_match('/\.(jpg|jpeg|png)$/i', $info['value'])): ?>
                      <img src="<?= h($info['value']) ?>" alt="<?= h($info['label']) ?>">
                    <?php else: ?>
                      <span class="doc-preview-pdf">📄</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <div style="flex:1">
                  <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.25rem">
                    <p style="font-weight:700">
                      <?= h($info['label']) ?>
                      <?php if ($info['required']): ?>
                        <span style="color:var(--danger);">*</span>
                      <?php endif; ?>
                    </p>
                    <?php if (!empty($info['value'])): ?>
                      <span class="chip ok" style="font-size:10px;">✓ Cargado</span>
                    <?php else: ?>
                      <span class="chip danger" style="font-size:10px;">✕ Sin cargar</span>
                    <?php endif; ?>
                  </div>

                  <?php if (!empty($info['value'])): ?>
                    <a href="<?= h($info['value']) ?>" target="_blank" style="font-size:0.875rem; color:var(--primary);">
                      Ver documento completo →
                    </a>
                  <?php endif; ?>
                </div>

                <button 
                  type="button" 
                  class="btn btn-ghost" 
                  onclick="abrirModalDocumento('<?= h($campo) ?>', '<?= h($info['label']) ?>')"
                >
                  <?= !empty($info['value']) ? 'Actualizar' : 'Cargar' ?>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:1.5rem; padding:1rem; border-radius:0.75rem; background: rgba(37,99,235,.08); border:1px solid rgba(37,99,235,.20);">
          <p style="font-size:0.875rem; color:var(--muted);">
            <strong>Importante:</strong> Los documentos marcados con * son obligatorios. 
            Los archivos deben ser JPG, PNG o PDF (máximo 5MB). 
            Cualquier cambio en la documentación será revisado por nuestro equipo.
          </p>
        </div>
      </div>

      <!-- MODAL DOCUMENTO -->
      <div id="modal-documento" class="fixed inset-0 hidden modal-bg" style="display:none; align-items:center; justify-content:center; z-index:999">
        <div class="modal-card" style="max-width:28rem; width:100%; margin:0 1rem; padding:1.5rem">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem">
            <h3 style="font-size:1.25rem; font-weight:800" id="modal-documento-titulo">Cargar Documento</h3>
            <button type="button" onclick="cerrarModalDocumento()" class="btn btn-ghost" style="padding:0.5rem 0.75rem">✕</button>
          </div>

          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="campo" id="modal-documento-campo">
            <input type="hidden" name="actualizar_documento" value="1">

            <div style="margin-bottom:1rem">
              <label style="display:block; font-size:0.875rem; font-weight:600; margin-bottom:0.5rem; color:var(--muted);">Seleccionar Archivo</label>
              <input type="file" name="archivo" accept=".jpg,.jpeg,.png,.pdf" required class="input">
              <p style="font-size:0.75rem; margin-top:0.5rem; color:var(--muted);">Formato: JPG, PNG o PDF. Máximo 5MB.</p>
            </div>

            <div style="display:flex; gap:0.75rem">
              <button type="button" onclick="cerrarModalDocumento()" class="btn btn-ghost" style="flex:1">Cancelar</button>
              <button type="submit" class="btn btn-primary" style="flex:1">Subir</button>
            </div>
          </form>
        </div>
      </div>

    </main>
  </div>

  <script>
    function abrirModalDocumento(campo, label) {
      document.getElementById('modal-documento-campo').value = campo;
      document.getElementById('modal-documento-titulo').textContent = 'Actualizar ' + label;
      const m = document.getElementById('modal-documento');
      m.style.display = 'flex';
    }

    function cerrarModalDocumento() {
      const m = document.getElementById('modal-documento');
      m.style.display = 'none';
    }
  </script>
</body>
</html>