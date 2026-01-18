<?php
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['usuario_rol'])) {
  header("Location: login.php");
  exit;
}
if ($_SESSION['usuario_rol'] !== 'admin') {
  header("Location: panel_asesor.php");
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$templatesFile = __DIR__ . '/templates.json';

// Defaults si no existe o está vacío
$defaultTemplates = [
  "registro" => [
    "subject" => "Bienvenido/a {{NOMBRE}} - Acceso a Préstamo Líder",
    "body" => "<h2>Hola {{NOMBRE}}</h2><p>Tu usuario es: <strong>{{EMAIL}}</strong></p><p>Tu contraseña provisoria es: <strong>{{PASSWORD}}</strong></p><p>Ingresá desde: <a href='{{LINK_LOGIN}}'>{{LINK_LOGIN}}</a></p>"
  ],
  "recupero_password" => [
    "subject" => "Recuperar contraseña - Préstamo Líder",
    "body" => "<h2>Hola {{NOMBRE}}</h2><p>Para restablecer tu contraseña ingresá acá:</p><p><a href='{{LINK_RESET}}'>{{LINK_RESET}}</a></p>"
  ],
  "docs_en_revision" => [
    "subject" => "Documentación en revisión - Préstamo Líder",
    "body" => "<p>Hola {{NOMBRE}}, recibimos tu documentación. Estado: <strong>En revisión</strong>.</p>"
  ],
  "docs_aprobados" => [
    "subject" => "Documentación aprobada - Préstamo Líder",
    "body" => "<p>Hola {{NOMBRE}}, tu documentación fue <strong>aprobada</strong>.</p>"
  ],
  "docs_rechazados" => [
    "subject" => "Documentación rechazada - Préstamo Líder",
    "body" => "<p>Hola {{NOMBRE}}, tu documentación fue <strong>rechazada</strong>.</p><p>Motivo: {{MENSAJE}}</p>"
  ],
  "prestamo_aprobado" => [
    "subject" => "Préstamo aprobado - Préstamo Líder",
    "body" => "<p>Hola {{NOMBRE}}, tu préstamo fue <strong>aprobado</strong>.</p>"
  ],
  "prestamo_rechazado" => [
    "subject" => "Préstamo rechazado - Préstamo Líder",
    "body" => "<p>Hola {{NOMBRE}}, tu préstamo fue <strong>rechazado</strong>.</p><p>Motivo: {{MENSAJE}}</p>"
  ],
  "asesor_respuesta" => [
    "subject" => "Un asesor te respondió - Préstamo Líder",
    "body" => "<p>Hola {{NOMBRE}}, un asesor te respondió:</p><p>{{MENSAJE}}</p>"
  ]
];

if (!file_exists($templatesFile)) {
  file_put_contents($templatesFile, json_encode($defaultTemplates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$templates = json_decode(file_get_contents($templatesFile), true);
if (!is_array($templates)) {
  $templates = $defaultTemplates;
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_templates') {
  $data = json_decode($_POST['templates'] ?? '', true);

  if (is_array($data)) {
    // Normalizar (si faltan claves, las agregamos)
    foreach ($defaultTemplates as $k => $v) {
      if (!isset($data[$k]) || !is_array($data[$k])) $data[$k] = $v;
      $data[$k]['subject'] = (string)($data[$k]['subject'] ?? '');
      $data[$k]['body'] = (string)($data[$k]['body'] ?? '');
    }

    file_put_contents($templatesFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $templates = $data;
    $msg = "✅ Plantillas guardadas correctamente.";
  } else {
    $msg = "❌ No se pudo guardar: formato inválido.";
  }
}

$keys = array_keys($templates);
sort($keys);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Plantillas - Préstamo Líder</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
  <link rel="stylesheet" href="style_actualizacion.css">
  <style>
    .email-preview-container {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      padding: 40px 20px;
      border-radius: 12px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    }
    
    .email-device-frame {
      background: #ffffff;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 10px 40px rgba(0,0,0,0.2);
      max-width: 600px;
      margin: 0 auto;
    }
    
    .email-header {
      background: linear-gradient(135deg, #3b82f6 0%, #10b981 100%);
      padding: 20px;
      color: white;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    
    .email-subject {
      background: #f8fafc;
      padding: 16px 20px;
      border-bottom: 2px solid #e2e8f0;
    }
    
    .email-body {
      padding: 30px 20px;
      background: #ffffff;
      min-height: 200px;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      line-height: 1.6;
      color: #1f2937;
    }
    
    .email-body h2 {
      color: #1e40af;
      margin-bottom: 16px;
      font-size: 24px;
    }
    
    .email-body p {
      margin-bottom: 12px;
      font-size: 15px;
    }
    
    .email-body a {
      color: #3b82f6;
      text-decoration: underline;
    }
    
    .email-body strong {
      color: #059669;
      font-weight: 600;
    }
    
    .email-footer {
      background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
      padding: 20px;
      text-align: center;
      font-size: 12px;
      color: #64748b;
      border-top: 1px solid #cbd5e1;
    }
    
    .preview-badge {
      display: inline-block;
      background: rgba(255,255,255,0.2);
      color: white;
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      backdrop-filter: blur(10px);
      margin-bottom: 20px;
    }
    
    .editor-container {
      background: white;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
      padding: 24px;
    }
    
    .tab-button-active {
      background: linear-gradient(135deg, #3b82f6 0%, #10b981 100%) !important;
      color: white !important;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    }
    
    .placeholder-badge {
      display: inline-block;
      background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
      color: #1e40af;
      padding: 4px 10px;
      border-radius: 6px;
      font-size: 12px;
      font-weight: 600;
      margin: 2px;
      border: 1px solid #93c5fd;
    }
    
    .placeholder-badge-warn {
      background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
      color: #92400e;
      border: 1px solid #fbbf24;
    }
  </style>
</head>
<body class="bg-gray-50">

<?php include 'sidebar.php'; ?>

<div class="ml-64">
  <nav class="bg-white shadow-md sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
      <div class="flex items-center gap-2">
        <span class="material-icons-outlined text-blue-600">mail</span>
        <span class="text-xl font-bold text-gray-800">Plantillas de Email</span>
      </div>
      <div class="flex gap-2">
        <button onclick="saveTemplates()" class="px-6 py-2 bg-gradient-to-r from-blue-600 to-green-600 text-white rounded-lg hover:shadow-lg transition flex items-center gap-2">
          <span class="material-icons-outlined text-white text-[20px]">save</span>
          Guardar
        </button>
      </div>
    </div>
  </nav>

  <div class="max-w-7xl mx-auto px-4 py-8">

    <?php if ($msg): ?>
      <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center gap-2">
        <span class="material-icons-outlined">check_circle</span>
        <?php echo h($msg); ?>
      </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-6">
      <div class="border-b bg-gradient-to-r from-gray-50 to-gray-100">
        <div class="flex gap-2 p-3 overflow-x-auto">
          <?php foreach ($keys as $i => $k): ?>
            <button type="button"
              class="px-5 py-3 rounded-lg font-semibold whitespace-nowrap transition-all duration-300 <?php echo $i===0 ? 'tab-button-active' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm'; ?>"
              data-tab="<?php echo h($k); ?>"
              onclick="openTab('<?php echo h($k); ?>')">
              <span class="material-icons-outlined text-[18px] align-middle mr-1">email</span>
              <?php echo h($k); ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Editor y Preview -->
    <?php foreach ($keys as $i => $k):
      $t = $templates[$k] ?? ['subject'=>'','body'=>''];
    ?>
      <div class="tpl-section <?php echo $i===0 ? '' : 'hidden'; ?>" data-section="<?php echo h($k); ?>">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          
          <!-- Panel Editor -->
          <div class="editor-container">
            <div class="flex items-center gap-2 mb-6 pb-4 border-b border-gray-200">
              <span class="material-icons-outlined text-blue-600">edit</span>
              <h3 class="text-lg font-bold text-gray-800">Editor</h3>
            </div>

            <div class="mb-6">
              <div class="flex items-center gap-2 mb-3">
                <span class="material-icons-outlined text-gray-600 text-[20px]">label</span>
                <label class="text-sm font-semibold text-gray-700">Placeholders disponibles</label>
              </div>
              <div class="flex flex-wrap gap-2">
                <span class="placeholder-badge">{{NOMBRE}}</span>
                <span class="placeholder-badge">{{EMAIL}}</span>
                <span class="placeholder-badge">{{PASSWORD}}</span>
                <span class="placeholder-badge">{{LINK_LOGIN}}</span>
                <span class="placeholder-badge">{{LINK_RESET}}</span>
                <span class="placeholder-badge placeholder-badge-warn">{{MENSAJE}}</span>
              </div>
            </div>

            <div class="mb-6">
              <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                <span class="material-icons-outlined text-[18px]">subject</span>
                Asunto del Email
              </label>
              <input
                class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition tpl-subject"
                data-key="<?php echo h($k); ?>"
                value="<?php echo h($t['subject'] ?? ''); ?>"
                oninput="updatePreview('<?php echo h($k); ?>')"
                placeholder="Asunto del correo electrónico"
              >
            </div>

            <div class="mb-6">
              <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
                <span class="material-icons-outlined text-[18px]">code</span>
                Cuerpo del Email (HTML)
              </label>
              <textarea
                class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition font-mono text-sm tpl-body"
                data-key="<?php echo h($k); ?>"
                rows="12"
                oninput="updatePreview('<?php echo h($k); ?>')"
                placeholder="Contenido HTML del correo..."
              ><?php echo h($t['body'] ?? ''); ?></textarea>
            </div>

            <div class="flex items-center gap-3 p-4 bg-blue-50 rounded-lg border border-blue-200">
              <span class="material-icons-outlined text-blue-600">info</span>
              <span class="text-sm text-blue-800">La vista previa se actualiza automáticamente mientras escribes</span>
            </div>
          </div>

          <!-- Panel Preview -->
          <div class="sticky top-24" style="height: fit-content;">
            <div class="bg-white rounded-xl shadow-lg p-6">
              <div class="flex items-center gap-2 mb-6 pb-4 border-b border-gray-200">
                <span class="material-icons-outlined text-green-600">visibility</span>
                <h3 class="text-lg font-bold text-gray-800">Vista Previa</h3>
              </div>

              <div id="preview-<?php echo h($k); ?>" class="email-preview-container">
                <div class="text-center">
                  <div class="preview-badge">
                    <span class="material-icons-outlined text-[14px] align-middle mr-1">mail</span>
                    Vista Previa del Email
                  </div>
                </div>
                
                <div class="email-device-frame">
                  <div class="email-header">
                    <span class="material-icons-outlined text-[28px]">account_circle</span>
                    <div>
                      <div class="font-bold text-sm">Préstamo Líder</div>
                      <div class="text-xs opacity-90">notificaciones@prestamolider.com</div>
                    </div>
                  </div>
                  
                  <div class="email-subject">
                    <div class="text-xs text-gray-500 mb-1 flex items-center gap-1">
                      <span class="material-icons-outlined text-[14px]">subject</span>
                      Asunto
                    </div>
                    <div class="font-bold text-gray-800 preview-subject">
                      <?php echo h($t['subject']); ?>
                    </div>
                  </div>
                  
                  <div class="email-body preview-body">
                    <?php echo $t['body']; ?>
                  </div>
                  
                  <div class="email-footer">
                    <div class="font-semibold text-gray-700 mb-2">Préstamo Líder</div>
                    <div class="mb-1">Tu solución financiera de confianza</div>
                    <div class="flex justify-center gap-3 mt-3">
                      <span class="material-icons-outlined text-[16px]">phone</span>
                      <span class="material-icons-outlined text-[16px]">email</span>
                      <span class="material-icons-outlined text-[16px]">location_on</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    <?php endforeach; ?>

    <form method="post" id="tplForm" class="hidden">
      <input type="hidden" name="action" value="save_templates">
      <input type="hidden" name="templates" id="templatesInput">
    </form>

  </div>
</div>

<script>
  // Inicializar preview al cargar
  document.addEventListener('DOMContentLoaded', function() {
    const firstKey = document.querySelector('.tpl-section')?.dataset.section;
    if (firstKey) {
      updatePreview(firstKey);
    }
  });

  function openTab(key){
    document.querySelectorAll('.tpl-section').forEach(s => s.classList.add('hidden'));
    document.querySelectorAll('button[data-tab]').forEach(b => {
      b.classList.remove('tab-button-active');
      b.classList.add('bg-white','text-gray-700','hover:bg-gray-100','shadow-sm');
    });

    const sec = document.querySelector('.tpl-section[data-section="'+key+'"]');
    if (sec) sec.classList.remove('hidden');

    const btn = document.querySelector('button[data-tab="'+key+'"]');
    if (btn){
      btn.classList.add('tab-button-active');
      btn.classList.remove('bg-white','text-gray-700','hover:bg-gray-100','shadow-sm');
    }

    updatePreview(key);
  }

  function updatePreview(key){
    const subj = document.querySelector('.tpl-subject[data-key="'+key+'"]')?.value || 'Sin asunto';
    const body = document.querySelector('.tpl-body[data-key="'+key+'"]')?.value || '<p>Sin contenido</p>';
    
    const previewBox = document.querySelector('#preview-'+key);
    if (!previewBox) return;

    // Reemplazar placeholders con valores demo
    const replacedSubject = subj
      .replaceAll('{{NOMBRE}}', 'María González')
      .replaceAll('{{EMAIL}}', 'maria.gonzalez@email.com')
      .replaceAll('{{PASSWORD}}', '********')
      .replaceAll('{{LINK_LOGIN}}', 'https://prestamolider.com/system/login.php')
      .replaceAll('{{LINK_RESET}}', 'https://prestamolider.com/system/reset.php?token=demo123')
      .replaceAll('{{MENSAJE}}', 'Este es un mensaje de ejemplo del sistema.');

    const replacedBody = body
      .replaceAll('{{NOMBRE}}', 'María González')
      .replaceAll('{{EMAIL}}', 'maria.gonzalez@email.com')
      .replaceAll('{{PASSWORD}}', '********')
      .replaceAll('{{LINK_LOGIN}}', 'https://prestamolider.com/system/login.php')
      .replaceAll('{{LINK_RESET}}', 'https://prestamolider.com/system/reset.php?token=demo123')
      .replaceAll('{{MENSAJE}}', 'Este es un mensaje de ejemplo del sistema.');

    // Actualizar preview
    const subjElement = previewBox.querySelector('.preview-subject');
    const bodyElement = previewBox.querySelector('.preview-body');
    
    if (subjElement) subjElement.textContent = replacedSubject;
    if (bodyElement) bodyElement.innerHTML = replacedBody;
  }

  function collectTemplates(){
    const out = {};
    document.querySelectorAll('.tpl-subject').forEach(inp => {
      const k = inp.dataset.key;
      out[k] = out[k] || {};
      out[k].subject = inp.value || '';
    });
    document.querySelectorAll('.tpl-body').forEach(tx => {
      const k = tx.dataset.key;
      out[k] = out[k] || {};
      out[k].body = tx.value || '';
    });
    return out;
  }

  function saveTemplates(){
    const data = collectTemplates();
    document.getElementById('templatesInput').value = JSON.stringify(data);
    document.getElementById('tplForm').submit();
  }
</script>

</body>
</html>