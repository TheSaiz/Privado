<?php
session_start();

// Auth
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['usuario_rol'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['usuario_rol'] !== 'admin') {
    header("Location: panel_asesor.php");
    exit;
}

// RUTA ABSOLUTA
$configFile = __DIR__ . '/config.json';

// Defaults (agregado SMTP)
$defaultConfig = [
    'hero' => [
        'title' => 'Tu préstamo en 24 horas',
        'subtitle' => 'Hasta $4.000.000 con la mejor tasa del mercado',
        'cta' => 'Solicitar ahora'
    ],
    'benefits' => [
        ['icon' => 'shield', 'title' => '100% Seguro', 'description' => 'Oficinas propias y transparencia total'],
        ['icon' => 'headphones', 'title' => 'Atención Personalizada', 'description' => 'Personas reales que te ayudan'],
        ['icon' => 'trending', 'title' => 'Sin Anticipos', 'description' => 'No pedimos pagos adelantados'],
        ['icon' => 'award', 'title' => 'Mejor Tasa', 'description' => 'Desde 100% TNA según tu perfil']
    ],
    'products' => [
        ['title' => 'Empleados Nacionales', 'maxAmount' => '4.000.000', 'minAge' => '1 mes', 'tna' => '100%', 'features' => ['Hasta $4M', '24-36 cuotas', 'Por recibo']],
        ['title' => 'Empleados Provinciales', 'maxAmount' => '1.000.000', 'minAge' => '1 mes', 'tna' => '165%', 'features' => ['Hasta $1M', '12-36 cuotas', 'Rápido']],
        ['title' => 'Empleados Privados', 'maxAmount' => '120.000', 'minAge' => '12 meses', 'tna' => '180%', 'features' => ['Hasta $120K', '12 cuotas', 'Con Veraz']],
        ['title' => 'Jubilados ANSES', 'maxAmount' => '90.000', 'minAge' => '1 mes', 'tna' => '195%', 'features' => ['Hasta $90K', '6-11 cuotas', '1er cobro']]
    ],
    'contact' => [
        'address' => 'Córdoba 2454 Piso 6 Of. B, Posadas, Misiones',
        'phone' => '0376-5431525',
        'whatsapp' => '0376-4739033',
        'email' => 'info@prestamolider.com',
        'hours' => 'Lun-Vie 9-17hs | Sáb 7-15hs'
    ],
    'colors' => [
        'primary' => '#2563eb',
        'secondary' => '#16a34a',
        'accent' => '#f59e0b'
    ],
    'faq' => [],
    'smtp' => [
        'host' => '',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'from_email' => '',
        'from_name' => ''
    ]
];

// ================= SAVE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
    $newConfig = json_decode($_POST['config'] ?? '', true);

    if (is_array($newConfig)) {
        $newConfig['hero']['cta'] = $newConfig['hero']['cta'] ?? $defaultConfig['hero']['cta'];
        $newConfig['faq'] = is_array($newConfig['faq'] ?? null) ? $newConfig['faq'] : [];
        $newConfig['smtp'] = array_replace($defaultConfig['smtp'], $newConfig['smtp'] ?? []);

        @file_put_contents($configFile, json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $_SESSION['message'] = 'Configuración guardada exitosamente';
    } else {
        $_SESSION['message'] = '❌ Error: JSON inválido (no se guardó).';
    }

    header('Location: configuracion.php');
    exit;
}

// ================= LOAD =================
$config = $defaultConfig;

if (file_exists($configFile)) {
    $raw = file_get_contents($configFile);
    $parsed = json_decode($raw, true);
    if (is_array($parsed)) {
        $config = array_replace_recursive($defaultConfig, $parsed);
    }
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Préstamo Líder</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <link rel="stylesheet" href="style_actualizacion.css">
</head>
<body class="bg-gray-50">

<?php include 'sidebar.php'; ?>

<div class="ml-64">

    <nav class="bg-white shadow-md sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="text-xl font-bold text-gray-800">Préstamo Líder</span>
            </div>
            <div class="flex gap-2">
                <a href="index.php" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">Ver Sitio</a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">

        <?php if ($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                ✅ <?php echo h($message); ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <div class="flex justify-between items-center flex-wrap gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 mb-2">Panel de Configuración</h1>
                    <p class="text-gray-600">Edita el contenido de tu landing page</p>
                </div>
                <div class="flex gap-3">
                    <button onclick="cancelEdit()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                        Cancelar
                    </button>
                    <button onclick="saveConfig()" class="px-6 py-2 bg-gradient-to-r from-blue-600 to-green-600 text-white rounded-lg hover:shadow-lg transition flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path>
                        </svg>
                        <span>Guardar</span>
                    </button>
                </div>
            </div>
        </div>

<div class="flex border-b overflow-x-auto">
    <button onclick="showTab('hero')" id="tab-hero"
        class="px-6 py-4 font-semibold transition whitespace-nowrap bg-blue-600 text-white">Hero</button>

    <button onclick="showTab('benefits')" id="tab-benefits"
        class="px-6 py-4 font-semibold transition whitespace-nowrap text-gray-600 hover:bg-gray-50">Beneficios</button>

    <button onclick="showTab('products')" id="tab-products"
        class="px-6 py-4 font-semibold transition whitespace-nowrap text-gray-600 hover:bg-gray-50">Productos</button>

    <button onclick="showTab('contact')" id="tab-contact"
        class="px-6 py-4 font-semibold transition whitespace-nowrap text-gray-600 hover:bg-gray-50">Contacto</button>

    <button onclick="showTab('faq')" id="tab-faq"
        class="px-6 py-4 font-semibold transition whitespace-nowrap text-gray-600 hover:bg-gray-50">Preguntas Frecuentes</button>

    <button onclick="showTab('colors')" id="tab-colors"
        class="px-6 py-4 font-semibold transition whitespace-nowrap text-gray-600 hover:bg-gray-50">Colores</button>

    <!-- ✅ SMTP -->
    <button onclick="showTab('smtp')" id="tab-smtp"
        class="px-6 py-4 font-semibold transition whitespace-nowrap text-gray-600 hover:bg-gray-50">SMTP</button>
</div>



<div class="p-8">

    <!-- HERO -->
    <div id="section-hero" class="tab-content">
        <h2 class="text-2xl font-bold mb-6">Sección Hero</h2>
        <div class="space-y-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Título Principal</label>
                <input type="text" id="hero-title" value="<?php echo h($config['hero']['title']); ?>"
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Subtítulo</label>
                <input type="text" id="hero-subtitle" value="<?php echo h($config['hero']['subtitle']); ?>"
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Texto del botón (CTA)</label>
                <input type="text" id="hero-cta" value="<?php echo h($config['hero']['cta']); ?>"
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
        </div>
    </div>

    <!-- BENEFICIOS -->
    <div id="section-benefits" class="tab-content hidden">
        <h2 class="text-2xl font-bold mb-6">Beneficios</h2>
        <div class="space-y-8">
            <?php foreach ($config['benefits'] as $index => $benefit): ?>
                <div class="p-6 bg-gray-50 rounded-lg space-y-4 border">
                    <h3 class="font-bold text-lg">Beneficio <?php echo (int)$index + 1; ?></h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Título</label>
                        <input type="text" id="benefit-title-<?php echo (int)$index; ?>"
                               value="<?php echo h($benefit['title']); ?>"
                               class="w-full px-4 py-2 border rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Descripción</label>
                        <input type="text" id="benefit-desc-<?php echo (int)$index; ?>"
                               value="<?php echo h($benefit['description']); ?>"
                               class="w-full px-4 py-2 border rounded-lg">
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- PRODUCTOS -->
    <div id="section-products" class="tab-content hidden">
        <h2 class="text-2xl font-bold mb-6">Productos</h2>
        <div class="space-y-8">
            <?php foreach ($config['products'] as $index => $product): ?>
                <div class="p-6 bg-gray-50 rounded-lg space-y-4 border">
                    <h3 class="font-bold text-lg"><?php echo h($product['title']); ?></h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Monto Máximo</label>
                            <input type="text" id="product-amount-<?php echo (int)$index; ?>"
                                   value="<?php echo h($product['maxAmount']); ?>"
                                   class="w-full px-4 py-2 border rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">TNA</label>
                            <input type="text" id="product-tna-<?php echo (int)$index; ?>"
                                   value="<?php echo h($product['tna']); ?>"
                                   class="w-full px-4 py-2 border rounded-lg">
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- CONTACTO -->
    <div id="section-contact" class="tab-content hidden">
        <h2 class="text-2xl font-bold mb-6">Información de Contacto</h2>
        <div class="space-y-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Dirección</label>
                <input type="text" id="contact-address"
                       value="<?php echo h($config['contact']['address']); ?>"
                       class="w-full px-4 py-3 border rounded-lg">
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Teléfono</label>
                    <input type="text" id="contact-phone"
                           value="<?php echo h($config['contact']['phone']); ?>"
                           class="w-full px-4 py-3 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">WhatsApp</label>
                    <input type="text" id="contact-whatsapp"
                           value="<?php echo h($config['contact']['whatsapp']); ?>"
                           class="w-full px-4 py-3 border rounded-lg">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                <input type="text" id="contact-email"
                       value="<?php echo h($config['contact']['email']); ?>"
                       class="w-full px-4 py-3 border rounded-lg">
            </div>
        </div>
    </div>

    <!-- FAQ -->
    <div id="section-faq" class="tab-content hidden">
        <h2 class="text-2xl font-bold mb-6">Preguntas Frecuentes</h2>

        <div id="faq-container" class="space-y-8">
            <?php foreach (($config['faq'] ?? []) as $index => $faq): ?>
                <div class="p-6 bg-gray-50 rounded-lg space-y-4 border">
                    <h3 class="font-bold text-lg">Pregunta <?php echo (int)$index + 1; ?></h3>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Pregunta</label>
                        <input type="text" id="faq-question-<?php echo (int)$index; ?>"
                               value="<?php echo h($faq['pregunta'] ?? ''); ?>"
                               class="w-full px-4 py-2 border rounded-lg">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Respuesta</label>
                        <textarea id="faq-answer-<?php echo (int)$index; ?>" rows="4"
                                  class="w-full px-4 py-2 border rounded-lg"><?php echo h($faq['respuesta'] ?? ''); ?></textarea>
                    </div>

                    <button type="button" onclick="deleteFAQ(<?php echo (int)$index; ?>)"
                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                        Eliminar
                    </button>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="button" onclick="addFAQ()"
                class="mt-6 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
            ➕ Agregar Pregunta
        </button>
    </div>

    <!-- COLORES -->
    <div id="section-colors" class="tab-content hidden">
        <h2 class="text-2xl font-bold mb-6">Colores del Sitio</h2>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($config['colors'] as $key => $value): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2 capitalize">
                        <?php echo h($key); ?>
                    </label>
                    <div class="flex gap-2">
                        <input type="color" id="color-<?php echo h($key); ?>"
                               value="<?php echo h($value); ?>"
                               class="w-16 h-12 rounded cursor-pointer">
                        <input type="text" id="color-text-<?php echo h($key); ?>"
                               value="<?php echo h($value); ?>"
                               class="flex-1 px-4 py-2 border rounded-lg"
                               onchange="document.getElementById('color-<?php echo h($key); ?>').value = this.value">
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-6 p-6 bg-gray-50 rounded-lg border">
            <h3 class="font-bold mb-4">Vista Previa</h3>
            <div class="flex gap-4">
                <?php foreach ($config['colors'] as $key => $value): ?>
                    <div id="preview-<?php echo h($key); ?>"
                         class="flex-1 h-24 rounded-lg"
                         style="background-color: <?php echo h($value); ?>"></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- SMTP -->
    <div id="section-smtp" class="tab-content hidden">
        <h2 class="text-2xl font-bold mb-6">Configuración SMTP</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium mb-1">Servidor SMTP</label>
                <input id="smtp-host" class="w-full px-4 py-2 border rounded-lg"
                       value="<?= h($config['smtp']['host']) ?>">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Puerto</label>
                <input id="smtp-port" class="w-full px-4 py-2 border rounded-lg"
                       value="<?= h($config['smtp']['port']) ?>">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Usuario</label>
                <input id="smtp-user" class="w-full px-4 py-2 border rounded-lg"
                       value="<?= h($config['smtp']['username']) ?>">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Contraseña</label>
                <input id="smtp-pass" type="password"
                       class="w-full px-4 py-2 border rounded-lg"
                       value="<?= h($config['smtp']['password']) ?>">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Email Remitente</label>
                <input id="smtp-from" class="w-full px-4 py-2 border rounded-lg"
                       value="<?= h($config['smtp']['from_email']) ?>">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Nombre Remitente</label>
                <input id="smtp-name" class="w-full px-4 py-2 border rounded-lg"
                       value="<?= h($config['smtp']['from_name']) ?>">
            </div>
            <div class="mt-6 flex gap-3">
    <button type="button"
            onclick="openTestEmailModal()"
            class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center gap-2">
        <span class="material-icons-outlined text-sm">email</span>
        Enviar correo de prueba
    </button>
</div>

<div>
    <label class="block text-sm font-medium mb-1">Tipo de encriptación</label>
    <select id="smtp-encryption"
            class="w-full px-4 py-2 border rounded-lg">
        <option value="ssl" <?= $config['smtp']['encryption'] === 'ssl' ? 'selected' : '' ?>>
            SSL (recomendado – puerto 465)
        </option>
        <option value="tls" <?= $config['smtp']['encryption'] === 'tls' ? 'selected' : '' ?>>
            TLS / STARTTLS (puerto 587)
        </option>
        <option value="none" <?= $config['smtp']['encryption'] === 'none' ? 'selected' : '' ?>>
            Sin encriptación (NO recomendado)
        </option>
    </select>
</div>
        </div>
    </div>

</div>

<!-- MODAL: TEST SMTP -->
<div id="testEmailModal"
     class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50 hidden">
    <div class="bg-white w-full max-w-md rounded-xl shadow-xl p-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-bold text-gray-800">Correo de prueba SMTP</h2>
            <button onclick="closeTestEmailModal()" class="text-gray-500 hover:text-gray-700">
                <span class="material-icons-outlined">close</span>
            </button>
        </div>

        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Enviar a</label>
                <input type="email" id="test-email-input"
                       placeholder="correo@ejemplo.com"
                       class="w-full px-4 py-2 border rounded-lg">
            </div>

            <div id="test-email-result" class="text-sm hidden"></div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <button onclick="closeTestEmailModal()"
                    class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">
                Cancelar
            </button>
            <button onclick="sendTestEmail()"
                    class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Enviar prueba
            </button>
        </div>
    </div>
</div>


<script>
    
    function openTestEmailModal() {
    document.getElementById("testEmailModal").classList.remove("hidden");
    document.getElementById("test-email-result").classList.add("hidden");
}

function closeTestEmailModal() {
    document.getElementById("testEmailModal").classList.add("hidden");
}

async function sendTestEmail() {
    const email = document.getElementById("test-email-input").value.trim();
    const resultBox = document.getElementById("test-email-result");

    if (!email) {
        alert("Ingresá un correo válido");
        return;
    }

    resultBox.classList.remove("hidden");
    resultBox.textContent = "⏳ Enviando correo de prueba...";
    resultBox.className = "text-sm text-gray-600";

    try {
        const response = await fetch("config/send_test_email.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email })
        });

        const data = await response.json();

        if (data.success) {
            resultBox.textContent = "✅ Correo enviado correctamente. SMTP funcionando.";
            resultBox.className = "text-sm text-green-600";
        } else {
            resultBox.textContent = "❌ Error: " + (data.message || "No se pudo enviar");
            resultBox.className = "text-sm text-red-600";
        }

    } catch (e) {
        resultBox.textContent = "❌ Error de conexión con el servidor";
        resultBox.className = "text-sm text-red-600";
    }
}

  let originalConfig = <?php echo json_encode($config, JSON_UNESCAPED_UNICODE); ?>;

  function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('[id^="tab-"]').forEach(el => {
      el.classList.remove('bg-blue-600', 'text-white');
      el.classList.add('text-gray-600', 'hover:bg-gray-50');
    });

    const section = document.getElementById('section-' + tab);
    const tabBtn  = document.getElementById('tab-' + tab);

    if (section) section.classList.remove('hidden');
    if (tabBtn) {
      tabBtn.classList.add('bg-blue-600', 'text-white');
      tabBtn.classList.remove('text-gray-600', 'hover:bg-gray-50');
    }
  }

  function cancelEdit() {
    if (confirm('¿Descartar cambios y volver?')) {
      window.location.href = 'index.php';
    }
  }

  function saveConfig() {
  let newConfig = {
    hero: {
      title: document.getElementById('hero-title').value,
      subtitle: document.getElementById('hero-subtitle').value,
      cta: document.getElementById('hero-cta').value
    },
    benefits: [],
    products: [],
    contact: {
      address: document.getElementById('contact-address').value,
      phone: document.getElementById('contact-phone').value,
      whatsapp: document.getElementById('contact-whatsapp').value,
      email: document.getElementById('contact-email').value,
      hours: originalConfig.contact?.hours || ''
    },
    colors: {
      primary: document.getElementById('color-primary').value,
      secondary: document.getElementById('color-secondary').value,
      accent: document.getElementById('color-accent').value
    },
    faq: [],
    smtp: {
      host: document.getElementById('smtp-host')?.value || '',
      port: document.getElementById('smtp-port')?.value || '',
      username: document.getElementById('smtp-user')?.value || '',
      password: document.getElementById('smtp-pass')?.value || '',
      from_email: document.getElementById('smtp-from')?.value || '',
      from_name: document.getElementById('smtp-name')?.value || '',
      encryption: document.getElementById('smtp-encryption')?.value || 'tls'
    }
  };

  // BENEFICIOS
  for (let i = 0; i < (originalConfig.benefits || []).length; i++) {
    newConfig.benefits.push({
      icon: originalConfig.benefits[i].icon,
      title: document.getElementById('benefit-title-' + i).value,
      description: document.getElementById('benefit-desc-' + i).value
    });
  }

  // PRODUCTOS
  for (let i = 0; i < (originalConfig.products || []).length; i++) {
    newConfig.products.push({
      title: originalConfig.products[i].title,
      maxAmount: document.getElementById('product-amount-' + i).value,
      minAge: originalConfig.products[i].minAge,
      tna: document.getElementById('product-tna-' + i).value,
      features: originalConfig.products[i].features
    });
  }

  // FAQ
  const faqContainer = document.getElementById("faq-container");
  const items = faqContainer.children;

  for (let i = 0; i < items.length; i++) {
    let pregunta = document.getElementById("faq-question-" + i).value;
    let respuesta = document.getElementById("faq-answer-" + i).value;
    newConfig.faq.push({ pregunta, respuesta });
  }

  let form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = `
    <input type="hidden" name="action" value="save_config">
    <input type="hidden" name="config" value='${JSON.stringify(newConfig).replace(/'/g, "&apos;")}'>
  `;
  document.body.appendChild(form);
  form.submit();
}


  function addFAQ() {
    const container = document.getElementById("faq-container");
    const index = container.children.length;

    const block = document.createElement("div");
    block.className = "p-6 bg-gray-50 rounded-lg space-y-4 border";

    block.innerHTML = `
      <h3 class="font-bold text-lg">Pregunta ${index + 1}</h3>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Pregunta</label>
        <input type="text" id="faq-question-${index}" class="w-full px-4 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Respuesta</label>
        <textarea id="faq-answer-${index}" rows="4" class="w-full px-4 py-2 border rounded-lg"></textarea>
      </div>

      <button type="button" onclick="deleteFAQ(${index})"
        class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
        Eliminar
      </button>
    `;

    container.appendChild(block);
  }

  function deleteFAQ(index) {
    const container = document.getElementById("faq-container");
    container.removeChild(container.children[index]);

    for (let i = 0; i < container.children.length; i++) {
      const block = container.children[i];
      block.querySelector("h3").innerText = "Pregunta " + (i + 1);
      block.querySelectorAll("input")[0].id = "faq-question-" + i;
      block.querySelectorAll("textarea")[0].id = "faq-answer-" + i;
      block.querySelector("button").setAttribute("onclick", "deleteFAQ(" + i + ")");
    }
  }

  document.querySelectorAll('[id^="color-"]').forEach(el => {
    if (el.type === 'color') {
      el.addEventListener('change', function() {
        let key = this.id.replace('color-', '');
        document.getElementById('color-text-' + key).value = this.value;
        const pv = document.getElementById('preview-' + key);
        if (pv) pv.style.backgroundColor = this.value;
      });
    }
  });
</script>


</body>
</html>
