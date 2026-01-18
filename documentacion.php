<?php
session_start();

if (!isset($_SESSION['cliente_id'])) {
    header('Location: login_clientes.php');
    exit;
}

require_once __DIR__ . '/backend/connection.php';

$usuario_id = (int)$_SESSION['cliente_id'];

$stmt = $pdo->prepare("SELECT * FROM clientes_detalles WHERE usuario_id = ? LIMIT 1");
$stmt->execute([$usuario_id]);
$cli = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cli) {
    die('Cliente no encontrado');
}

function h($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Determinar si está en revisión
$enRevision = (
    ($cli['estado_validacion'] ?? '') === 'activo'
    || (int)($cli['docs_completos'] ?? 0) === 1
);

// Verificar si el email ya está verificado
$emailVerificado = (int)($cli['email_verificado'] ?? 0) === 1;

// Mensajes de sesión
$successMsg = $_SESSION['success_msg'] ?? '';
$errorMsg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Documentación</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="style_clientes.css">

<style>
.rev-banner{
  margin:14px 0 18px;
  padding:14px;
  border-radius:14px;
  border:1px solid rgba(255,255,255,.10);
  background:rgba(16,185,129,.10);
  color:rgba(234,240,255,.92);
  display:flex;
  gap:12px;
  align-items:center;
}
.rev-icon{
  font-size: 1.5rem;
}

.alert {
  padding: 14px;
  border-radius: 10px;
  margin: 14px 0;
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 0.95rem;
}

.alert-success {
  background: #ecfdf5;
  border: 1px solid #10b981;
  color: #065f46; /* texto verde oscuro */
}
.alert-error {
  background: #fef2f2;
  border: 1px solid #ef4444;
  color: #7f1d1d; /* texto rojo oscuro */
}

.input-error{ border-color:#ff4d6d !important; }
.error-text{ color:#ff4d6d; font-size:.9rem; margin-top:6px; }
.success-text{ color:#10b981; font-size:.9rem; margin-top:6px; }

.phone-container {
  display: flex;
  gap: 8px;
}

.phone-container .cod-area {
  width: 90px;
  flex-shrink: 0;
}

.phone-container .phone-number {
  flex: 1;
}

.file-upload-zone {
  position: relative;
  border: 2px dashed rgba(255,255,255,.2);
  border-radius: 12px;
  padding: 20px;
  text-align: center;
  cursor: pointer;
  transition: all 0.3s;
  background: rgba(255,255,255,.03);
  min-height: 120px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.file-upload-zone:hover {
  border-color: rgba(124,92,255,.6);
  background: rgba(124,92,255,.05);
}

.file-upload-zone input[type="file"] {
  display: none;
}

.file-upload-zone .icon {
  font-size: 2.5rem;
  margin-bottom: 8px;
  opacity: .7;
}

.file-upload-zone .text {
  font-size: 0.95rem;
  color: #0f172a; /* texto principal legible */
  font-weight: 600;
}

.file-upload-zone .subtext {
  font-size: 0.8rem;
  color: #64748b; /* texto secundario */
  margin-top: 4px;
}


.file-preview {
  margin-top: 10px;
  padding: 12px;
  background: rgba(16,185,129,.15);
  border: 1px solid rgba(16,185,129,.3);
  border-radius: 8px;
  color: #10b981;
  font-size: 0.9rem;
  font-weight: 600;
  display: none;
}

.file-preview.show {
  display: block;
}

.badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border-radius: 20px;
  font-size: 0.9rem;
  font-weight: 700;
  margin-top: 8px;
}

.badge.verified {
  background: rgba(16,185,129,.15);
  color: #10b981;
  border: 1px solid rgba(16,185,129,.3);
}

.badge.pending {
  background: rgba(245,158,11,.15);
  color: #f59e0b;
  border: 1px solid rgba(245,158,11,.3);
}

.section-title {
  font-size: 1.2rem;
  font-weight: 700;
  color: rgba(234,240,255,.95);
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.required-docs-info {
  padding: 14px;
  background: rgba(59,130,246,.1);
  border: 1px solid rgba(59,130,246,.3);
  border-radius: 10px;
  margin-bottom: 20px;
  color: rgba(234,240,255,.95);
  font-size: 0.95rem;
  line-height: 1.6;
}

.required-docs-info strong {
  color: rgba(234,240,255,1);
  font-weight: 700;
}

.required-docs-info ul {
  margin: 10px 0 0 20px;
  padding: 0;
}

.required-docs-info ul li {
  margin: 6px 0;
  color: rgba(234,240,255,.9);
}

.overlay-gate {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,.85);
  z-index: 99999;
  justify-content: center;
  align-items: center;
  padding: 20px;
  animation: fadeIn 0.2s ease-in-out;
}

.overlay-gate.show {
  display: flex !important;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.gate-box {
  background: linear-gradient(135deg, rgba(30,30,50,.98), rgba(20,20,35,.98));
  border: 2px solid rgba(124,92,255,.4);
  border-radius: 16px;
  max-width: 500px;
  width: 100%;
  padding: 28px;
  box-shadow: 0 25px 80px rgba(0,0,0,.7);
  animation: slideUp 0.3s ease-out;
}

@keyframes slideUp {
  from { transform: translateY(30px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}

.gate-head {
  margin-bottom: 20px;
}

.gate-title {
  font-size: 1.6rem;
  font-weight: 800;
  color: rgba(234,240,255,.98);
  margin-bottom: 10px;
}

.gate-sub {
  font-size: 1.05rem;
  color: rgba(234,240,255,.85);
  line-height: 1.5;
}

.gate-mid {
  margin: 20px 0;
  max-height: 350px;
  overflow-y: auto;
}

.cuil-option {
  display: flex;
  align-items: center;
  padding: 14px;
  cursor: pointer;
  border-radius: 10px;
  margin: 8px 0;
  background: rgba(255,255,255,.04);
  border: 2px solid rgba(255,255,255,.1);
  transition: all 0.2s;
}

.cuil-option:hover {
  background: rgba(124,92,255,.15);
  border-color: rgba(124,92,255,.4);
}

.cuil-option input[type="radio"] {
  margin-right: 12px;
  width: 18px;
  height: 18px;
  cursor: pointer;
}

.cuil-option-content {
  flex: 1;
}

.cuil-option-name {
  font-size: 1rem;
  font-weight: 700;
  color: rgba(234,240,255,.95);
  margin-bottom: 4px;
}

.cuil-option-cuil {
  font-size: 0.9rem;
  color: rgba(234,240,255,.7);
}

.gate-actions {
  display: flex;
  gap: 12px;
  margin-top: 24px;
}

.gate-actions button {
  flex: 1;
  font-size: 1rem;
  font-weight: 700;
}

.verify-email-section {
  margin-top: 12px;
  padding: 16px;
  background: #fff7ed;                 /* amarillo claro legible */
  border: 1px solid #f59e0b;
  border-radius: 12px;
}

.verify-email-section .title {
  font-weight: 800;
  font-size: 1.05rem;
  color: #92400e;                      /* marrón oscuro */
  margin-bottom: 8px;
}

.verify-email-section .description {
  font-size: 0.95rem;
  color: #78350f;                      /* texto principal legible */
  margin-bottom: 14px;
  line-height: 1.5;
}

.verify-code-container {
  display: flex;
  gap: 8px;
  margin-top: 12px;
}

.verify-code-container input {
  flex: 1;
}


#verifyCodeStatus {
  margin-top: 10px;
  font-size: 0.95rem;
  font-weight: 600;
}

label {
  font-weight: 700 !important;
  color: #0f172a !important; /* texto oscuro y legible */
  font-size: 1rem !important;
}


.subtext {
  color: #64748b !important; /* gris legible */
  font-weight: 500 !important;
}


.dni-validating {
  border-color: rgba(59,130,246,.5) !important;
  background: rgba(59,130,246,.05) !important;
}

/* ===============================
   FIX TEXTO INVISIBLE (FINAL)
=============================== */

/* Banner documentación completa */
.rev-banner,
.rev-banner div {
  color: #065f46 !important; /* verde oscuro */
}

/* Caja documentos requeridos */
.required-docs-info {
  color: #0f172a !important;
}

.required-docs-info strong {
  color: #0f172a !important;
}

.required-docs-info ul li {
  color: #334155 !important;
}

/* Por si hay spans internos */
.required-docs-info span {
  color: inherit !important;
}



</style>
</head>

<body>
<div class="container">

<a class="back" href="dashboard_clientes.php">← Volver</a>

<h1>📄 Documentación</h1>
<p class="sub">Completá todos los datos obligatorios para poder solicitar préstamos.</p>

<?php if ($successMsg): ?>
<div class="alert alert-success">
  <span style="font-size:1.3rem;">✅</span>
  <span><?= h($successMsg) ?></span>
</div>
<?php endif; ?>

<?php if ($errorMsg): ?>
<div class="alert alert-error">
  <span style="font-size:1.3rem;">❌</span>
  <span><?= h($errorMsg) ?></span>
</div>
<?php endif; ?>

<?php if ($enRevision): ?>
<div class="rev-banner">
  <div class="rev-icon">✅</div>
  <div>
    <div style="font-weight:800">Documentación completa</div>
    <div style="opacity:.85">Ya podés solicitar préstamos.</div>
  </div>
</div>
<?php endif; ?>

<div class="required-docs-info">
  <strong>📋 Documentos requeridos:</strong><br>
  Para activar tu cuenta y poder solicitar préstamos, necesitamos que subas estos 3 documentos:
  <ul>
    <li>✓ Foto del <strong>DNI frente</strong></li>
    <li>✓ Foto del <strong>DNI dorso</strong></li>
    <li>✓ <strong>Selfie sosteniendo tu DNI</strong> (para verificar tu identidad)</li>
  </ul>
</div>

<form id="docForm" action="backend/documentacion/guardar.php" method="POST" enctype="multipart/form-data">

<!-- ================= DATOS OBLIGATORIOS ================= -->
<div class="section">
<h2 class="section-title">🔍 Información personal</h2>

<!-- DNI con autocompletado -->
<label>DNI *</label>
<input class="input" id="dni" name="dni" type="text" placeholder="Ingresá tu número de DNI" required value="<?= h($cli['dni'] ?? '') ?>" autocomplete="off">
<div id="dniStatus" style="margin-top:6px; font-size:0.85rem; color:rgba(234,240,255,.7);"></div>

<!-- Nombre completo (autocompletado) -->
<label>Nombre completo *</label>
<input class="input" id="nombre_completo" name="nombre_completo" placeholder="Nombre completo" required value="<?= h($cli['nombre_completo'] ?? '') ?>" readonly style="background: rgba(255,255,255,.05);">

<!-- CUIT (autocompletado, oculto) -->
<input type="hidden" id="cuit" name="cuit" value="<?= h($cli['cuit'] ?? '') ?>">

<!-- Teléfono con código de área -->
<label>Teléfono con código de área *</label>
<div class="phone-container">
  <input class="input cod-area" id="cod_area" name="cod_area" placeholder="Ej: 11" required maxlength="5" value="<?= h($cli['cod_area'] ?? '') ?>">
  <input class="input phone-number" id="telefono" name="telefono" placeholder="Número sin 15 ni 0" required value="<?= h($cli['telefono'] ?? '') ?>">
</div>
<div class="subtext" style="margin-top:6px; font-size:.9rem;">Ejemplo: Código 11 y número 12345678</div>

<!-- Email -->
<label>Email *</label>
<input class="input" type="email" id="email" name="email" placeholder="tu@email.com" required value="<?= h($cli['email'] ?? '') ?>" <?= $emailVerificado ? 'readonly style="background: rgba(255,255,255,.05);"' : '' ?>>

<?php if ($emailVerificado): ?>
  <div class="badge verified">
    <span>✓</span>
    <span>Email verificado</span>
  </div>
<?php else: ?>
  <div class="verify-email-section" id="verifyEmailSection">
    <div class="title">⚠️ Verificá tu email</div>
    <div class="description">Para completar tu registro, necesitamos verificar tu email.</div>
    <button type="button" class="btn btn-primary" id="sendCodeBtn" onclick="enviarCodigo()">
      Enviar código de verificación
    </button>
    <div id="verifyCodeContainer" style="display:none;">
      <div class="verify-code-container">
        <input type="text" class="input" id="verifyCode" placeholder="Ingresá el código de 6 dígitos" maxlength="6" pattern="[0-9]{6}">
        <button type="button" class="btn btn-primary" onclick="verificarCodigo()">Verificar</button>
      </div>
      <div id="verifyCodeStatus"></div>
    </div>
  </div>
<?php endif; ?>
</div>

<!-- ================= DOCUMENTOS REQUERIDOS ================= -->
<div class="section">
<h2 class="section-title">📸 Documentos (obligatorios)</h2>

<!-- DNI Frente -->
<label style="margin-top:20px;">DNI Frente *</label>
<div class="file-upload-zone" id="zone_dni_frente">
  <div class="icon">🪪</div>
  <div class="text">Click para subir foto del DNI (frente)</div>
  <div class="subtext">Formato: JPG, PNG (máx. 5MB)</div>
</div>
<input type="file" id="doc_dni_frente" name="doc_dni_frente" accept="image/*" <?= empty($cli['doc_dni_frente']) ? 'required' : '' ?> style="display:none;">
<div class="file-preview" id="preview_dni_frente"></div>
<?php if (!empty($cli['doc_dni_frente'])): ?>
  <div class="success-text" style="font-weight:600;">✓ Archivo ya cargado anteriormente</div>
<?php endif; ?>

<!-- DNI Dorso -->
<label style="margin-top:20px;">DNI Dorso *</label>
<div class="file-upload-zone" id="zone_dni_dorso">
  <div class="icon">🪪</div>
  <div class="text">Click para subir foto del DNI (dorso)</div>
  <div class="subtext">Formato: JPG, PNG (máx. 5MB)</div>
</div>
<input type="file" id="doc_dni_dorso" name="doc_dni_dorso" accept="image/*" <?= empty($cli['doc_dni_dorso']) ? 'required' : '' ?> style="display:none;">
<div class="file-preview" id="preview_dni_dorso"></div>
<?php if (!empty($cli['doc_dni_dorso'])): ?>
  <div class="success-text" style="font-weight:600;">✓ Archivo ya cargado anteriormente</div>
<?php endif; ?>

<!-- Selfie con DNI -->
<label style="margin-top:20px;">Selfie con DNI en mano *</label>
<div class="file-upload-zone" id="zone_selfie_dni">
  <div class="icon">🤳</div>
  <div class="text">Click para subir tu selfie sosteniendo el DNI</div>
  <div class="subtext">Formato: JPG, PNG (máx. 5MB)</div>
</div>
<input type="file" id="doc_selfie_dni" name="doc_selfie_dni" accept="image/*" <?= empty($cli['doc_selfie_dni']) ? 'required' : '' ?> style="display:none;">
<div class="file-preview" id="preview_selfie_dni"></div>
<?php if (!empty($cli['doc_selfie_dni'])): ?>
  <div class="success-text" style="font-weight:600;">✓ Archivo ya cargado anteriormente</div>
<?php endif; ?>
</div>

<button class="btn btn-primary" type="submit" id="btnSubmit">Enviar documentación</button>

</form>
</div>

<!-- ================= MODAL DNI ================= -->
<div id="dniModal" class="overlay-gate">
  <div class="gate-box">
    <div class="gate-head">
      <div class="gate-title">Confirmación de identidad</div>
      <div class="gate-sub" id="dniModalText"></div>
    </div>
    <div class="gate-mid" id="dniOpciones"></div>
    <div class="gate-actions">
      <button type="button" class="btn btn-ghost" onclick="cerrarDniModal()">No soy</button>
      <button type="button" class="btn btn-primary" id="confirmarDniBtn">Sí, soy yo</button>
    </div>
  </div>
</div>

<!-- ================= JS ================= -->
<script>
console.log('🚀 Script iniciado');

/* ===== PREVIEW DE ARCHIVOS ===== */
function setupFilePreview(inputId, previewId, zoneId) {
  const input = document.getElementById(inputId);
  const preview = document.getElementById(previewId);
  const zone = document.getElementById(zoneId);

  zone.addEventListener('click', function(e) {
    e.preventDefault();
    input.click();
  });

  input.addEventListener('change', function () {
    if (!this.files || !this.files[0]) return;

    const file = this.files[0];

    if (!file.type.startsWith('image/')) {
      alert('Solo se permiten imágenes');
      return;
    }

    const reader = new FileReader();

    reader.onload = function (e) {
      const sizeKB = (file.size / 1024).toFixed(0);

      preview.innerHTML = `
        <div style="display:flex; gap:12px; align-items:center;">
          <img src="${e.target.result}" 
               style="width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid #10b981;">
          <div>
            <div style="font-weight:700;">✓ Archivo seleccionado</div>
            <div style="font-size:.85rem;">${file.name}</div>
            <div style="font-size:.8rem;opacity:.8;">${sizeKB} KB</div>
          </div>
        </div>
      `;

      preview.classList.add('show');
    };

    reader.readAsDataURL(file);
  });
}


setupFilePreview('doc_dni_frente', 'preview_dni_frente', 'zone_dni_frente');
setupFilePreview('doc_dni_dorso', 'preview_dni_dorso', 'zone_dni_dorso');
setupFilePreview('doc_selfie_dni', 'preview_selfie_dni', 'zone_selfie_dni');

/* ===== VALIDACIÓN TELÉFONO ===== */
const codAreaInput = document.getElementById('cod_area');
const telefonoInput = document.getElementById('telefono');

codAreaInput.addEventListener('input', function() {
  this.value = this.value.replace(/\D/g, '');
});

telefonoInput.addEventListener('input', function() {
  this.value = this.value.replace(/\D/g, '');
});

/* ===== DNI AUTOCOMPLETE (CONFIRMACIÓN + SELECCIÓN CUIL) ===== */

const dniInput = document.getElementById('dni');
const nombreInput = document.getElementById('nombre_completo');
const cuitInput = document.getElementById('cuit');
const dniStatus = document.getElementById('dniStatus');

let dniData = null;
let validacionEnProceso = false;
let ultimoDniValidado = '';

function validarDNI() {
  const dni = (dniInput.value || '').replace(/\D/g, '');

  if (validacionEnProceso) return;

  if (dni.length < 7 || dni.length > 8) {
    dniStatus.textContent = '';
    return;
  }

  // Evitar revalidar si ya está confirmado
  if (dni === ultimoDniValidado && nombreInput.value && cuitInput.value) {
    return;
  }

  validacionEnProceso = true;
  ultimoDniValidado = dni;

  dniInput.classList.add('dni-validating');
  dniStatus.innerHTML = '<span style="color:#3b82f6;">🔄 Validando DNI...</span>';

  fetch('api/chatbot/validar_dni.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ dni })
  })
  .then(r => {
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  })
  .then(data => {
    validacionEnProceso = false;
    dniInput.classList.remove('dni-validating');

    if (!data || !data.success) {
      dniStatus.innerHTML = '<span style="color:#ef4444;">❌ No se encontró información para este DNI</span>';
      return;
    }

    dniData = data;
    dniStatus.textContent = '';

    // 🔒 Normalizamos: SIEMPRE trabajamos con un array de opciones
    let opciones = [];

    if (data.opciones && data.opciones.length > 0) {
      opciones = data.opciones;
    } else if (data.cuil && data.nombre) {
      opciones = [{
        cuil: data.cuil,
        nombre: data.nombre
      }];
    }

    if (opciones.length === 0) {
      dniStatus.innerHTML = '<span style="color:#ef4444;">❌ No se pudo validar el DNI</span>';
      return;
    }

    // Construir opciones del modal
    let html = '';
    opciones.forEach((o, i) => {
      html += `
        <div class="cuil-option" onclick="selectOption(this)">
          <input type="radio"
                 name="cuil_sel"
                 value="${o.cuil}"
                 data-nombre="${o.nombre}"
                 ${i === 0 ? 'checked' : ''}>
          <div class="cuil-option-content">
            <div class="cuil-option-name">${o.nombre}</div>
            <div class="cuil-option-cuil">CUIL: ${o.cuil}</div>
          </div>
        </div>
      `;
    });

    // Texto del modal
    document.getElementById('dniModalText').innerHTML =
      opciones.length > 1
        ? `El DNI ingresado corresponde a <strong>${opciones[0].nombre}</strong>.<br>
           Detectamos <strong>${opciones.length}</strong> CUIL asociados.<br>
           <strong>Seleccioná cuál querés usar:</strong>`
        : `El DNI ingresado corresponde a <strong>${opciones[0].nombre}</strong>.<br>
           ¿Es correcto?`;

    document.getElementById('dniOpciones').innerHTML = html;
    document.getElementById('dniModal').classList.add('show');
  })
  .catch(err => {
    validacionEnProceso = false;
    dniInput.classList.remove('dni-validating');
    dniStatus.innerHTML = '<span style="color:#ef4444;">❌ Error al validar el DNI</span>';
    console.error(err);
  });
}

/* ===== MODAL ===== */

function selectOption(element) {
  const radio = element.querySelector('input[type="radio"]');
  if (radio) radio.checked = true;
}

document.getElementById('confirmarDniBtn').onclick = () => {
  if (!dniData) return;

  const sel = document.querySelector('input[name="cuil_sel"]:checked');
  if (!sel) return;

  cuitInput.value = sel.value;
  nombreInput.value = sel.getAttribute('data-nombre') || '';

  dniStatus.innerHTML =
    '<span style="color:#10b981;">✓ Identidad confirmada</span>';

  cerrarDniModal();
};

function cerrarDniModal() {
  document.getElementById('dniModal').classList.remove('show');
}

// Cerrar modal al clickear fondo
document.getElementById('dniModal').addEventListener('click', e => {
  if (e.target.id === 'dniModal') cerrarDniModal();
});

/* ===== EVENTOS ===== */

dniInput.addEventListener('blur', () => setTimeout(validarDNI, 200));

dniInput.addEventListener('keypress', e => {
  if (e.key === 'Enter') {
    e.preventDefault();
    validarDNI();
  }
});

dniInput.addEventListener('paste', () => {
  setTimeout(validarDNI, 100);
});


/* ===== VERIFICACIÓN DE EMAIL ===== */
function enviarCodigo() {
  const email = document.getElementById('email').value.trim();
  
  if (!email || !email.includes('@')) {
    alert('Por favor ingresá un email válido');
    return;
  }
  
  const btn = document.getElementById('sendCodeBtn');
  btn.disabled = true;
  btn.textContent = 'Enviando...';
  
  fetch('backend/email/enviar_codigo.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({email})
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      alert('✓ Código enviado a tu email. Revisá tu bandeja de entrada.');
      document.getElementById('verifyCodeContainer').style.display = 'block';
      btn.textContent = 'Reenviar código';
    } else {
      alert('Error: ' + (data.error || 'No se pudo enviar el código'));
      btn.textContent = 'Enviar código de verificación';
    }
    btn.disabled = false;
  })
  .catch(err => {
    console.error('Error:', err);
    alert('Error al enviar el código');
    btn.textContent = 'Enviar código de verificación';
    btn.disabled = false;
  });
}

function verificarCodigo() {
  const email = document.getElementById('email').value.trim();
  const codigo = document.getElementById('verifyCode').value.trim();
  const status = document.getElementById('verifyCodeStatus');
  
  if (!codigo || codigo.length !== 6) {
    status.innerHTML = '<span class="error-text">El código debe tener 6 dígitos</span>';
    return;
  }
  
  status.innerHTML = '<span style="color: #3b82f6;">Verificando...</span>';
  
  fetch('backend/email/verificar_codigo.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({email, codigo})
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      status.innerHTML = '<span class="success-text">✓ Email verificado correctamente</span>';
      setTimeout(() => {
        location.reload();
      }, 1500);
    } else {
      status.innerHTML = '<span class="error-text">✗ ' + (data.error || 'Código incorrecto') + '</span>';
    }
  })
  .catch(err => {
    console.error('Error:', err);
    status.innerHTML = '<span class="error-text">Error al verificar el código</span>';
  });
}

/* ===== VALIDACIÓN FORM ===== */
const form = document.getElementById('docForm');

form.addEventListener('submit', e => {
  const codArea = codAreaInput.value.trim();
  if (codArea.length < 2) {
    e.preventDefault();
    alert('El código de área debe tener al menos 2 dígitos');
    codAreaInput.focus();
    return;
  }

  const telefono = telefonoInput.value.trim();
  if (telefono.length < 6) {
    e.preventDefault();
    alert('El número de teléfono debe tener al menos 6 dígitos');
    telefonoInput.focus();
    return;
  }
  
  if (!cuitInput.value) {
    e.preventDefault();
    alert('Por favor completá la validación del DNI');
    dniInput.focus();
    return;
  }
  
  const btn = document.getElementById('btnSubmit');
  btn.disabled = true;
  btn.textContent = 'Enviando...';
});

console.log('✅ Script cargado completamente');
console.log('📍 Elementos encontrados:', {
  dniInput: !!dniInput,
  nombreInput: !!nombreInput,
  cuitInput: !!cuitInput,
  modal: !!document.getElementById('dniModal')
});
</script>

</body>
</html>