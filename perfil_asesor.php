<?php
session_start();

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['usuario_rol'])) {
    header("Location: login.php");
    exit;
}

// Verificar que sea asesor o admin
if (!in_array($_SESSION['usuario_rol'], ['asesor', 'admin'])) {
    header("Location: login.php");
    exit;
}

require_once 'backend/connection.php';

$usuario_id = $_SESSION['usuario_id'];
$success_message = '';
$error_message = '';

// Procesar actualización de perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        
        // ACTUALIZAR DATOS DE CONTACTO Y REDES SOCIALES
        if ($_POST['action'] === 'update_profile') {
            $celular = trim($_POST['celular'] ?? '');
            $whatsapp = trim($_POST['whatsapp'] ?? '');
            $telegram = trim($_POST['telegram'] ?? '');
            $instagram = trim($_POST['instagram'] ?? '');
            $facebook = trim($_POST['facebook'] ?? '');
            $tiktok = trim($_POST['tiktok'] ?? '');
            
            try {
                // Verificar si ya existe un perfil
                $stmt = $pdo->prepare("SELECT id FROM asesores_perfil WHERE usuario_id = ?");
                $stmt->execute([$usuario_id]);
                $perfil_existente = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($perfil_existente) {
                    // Actualizar perfil existente
                    $stmt = $pdo->prepare("
                        UPDATE asesores_perfil 
                        SET celular = ?, whatsapp = ?, telegram = ?, instagram = ?, facebook = ?, tiktok = ?
                        WHERE usuario_id = ?
                    ");
                    $stmt->execute([$celular, $whatsapp, $telegram, $instagram, $facebook, $tiktok, $usuario_id]);
                } else {
                    // Crear nuevo perfil
                    $stmt = $pdo->prepare("
                        INSERT INTO asesores_perfil (usuario_id, celular, whatsapp, telegram, instagram, facebook, tiktok)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$usuario_id, $celular, $whatsapp, $telegram, $instagram, $facebook, $tiktok]);
                }
                
                $success_message = "Información de contacto actualizada correctamente";
            } catch (PDOException $e) {
                $error_message = "Error al actualizar el perfil: " . $e->getMessage();
            }
        }
        
        // ACTUALIZAR EMAIL Y CONTRASEÑA
        elseif ($_POST['action'] === 'update_credentials') {
            $email = trim($_POST['email'] ?? '');
            $password_actual = $_POST['password_actual'] ?? '';
            $password_nueva = $_POST['password_nueva'] ?? '';
            $password_confirmar = $_POST['password_confirmar'] ?? '';
            
            try {
                // Verificar email no esté en uso por otro usuario
                if (!empty($email)) {
                    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $usuario_id]);
                    if ($stmt->fetch()) {
                        throw new Exception("El email ya está en uso por otro usuario");
                    }
                }
                
                // Si se quiere cambiar contraseña, validar
                $cambiar_password = !empty($password_nueva);
                if ($cambiar_password) {
                    // Verificar contraseña actual
                    $stmt = $pdo->prepare("SELECT password FROM usuarios WHERE id = ?");
                    $stmt->execute([$usuario_id]);
                    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!password_verify($password_actual, $usuario['password'])) {
                        throw new Exception("La contraseña actual es incorrecta");
                    }
                    
                    if ($password_nueva !== $password_confirmar) {
                        throw new Exception("Las contraseñas nuevas no coinciden");
                    }
                    
                    if (strlen($password_nueva) < 6) {
                        throw new Exception("La contraseña debe tener al menos 6 caracteres");
                    }
                }
                
                // Actualizar datos
                if ($cambiar_password) {
                    $password_hash = password_hash($password_nueva, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE usuarios SET email = ?, password = ? WHERE id = ?");
                    $stmt->execute([$email, $password_hash, $usuario_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE usuarios SET email = ? WHERE id = ?");
                    $stmt->execute([$email, $usuario_id]);
                }
                
                $success_message = "Credenciales actualizadas correctamente";
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        
        // SUBIR FOTO DE PERFIL
        elseif ($_POST['action'] === 'upload_photo') {
            if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $max_size = 5 * 1024 * 1024; // 5MB
                
                $file = $_FILES['foto_perfil'];
                $file_type = $file['type'];
                $file_size = $file['size'];
                
                if (!in_array($file_type, $allowed_types)) {
                    $error_message = "Solo se permiten imágenes (JPG, PNG, GIF, WEBP)";
                } elseif ($file_size > $max_size) {
                    $error_message = "La imagen no puede superar los 5MB";
                } else {
                    try {
                        // Crear directorio si no existe
                        $upload_dir = 'uploads/perfiles/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        // Generar nombre único
                        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $nombre_archivo = 'perfil_' . $usuario_id . '_' . time() . '.' . $extension;
                        $ruta_destino = $upload_dir . $nombre_archivo;
                        
                        // Eliminar foto anterior si existe
                        $stmt = $pdo->prepare("SELECT foto_perfil FROM asesores_perfil WHERE usuario_id = ?");
                        $stmt->execute([$usuario_id]);
                        $perfil_anterior = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($perfil_anterior && !empty($perfil_anterior['foto_perfil'])) {
                            $foto_anterior = $perfil_anterior['foto_perfil'];
                            if (file_exists($foto_anterior)) {
                                unlink($foto_anterior);
                            }
                        }
                        
                        // Mover archivo
                        if (move_uploaded_file($file['tmp_name'], $ruta_destino)) {
                            // Actualizar en BD
                            $stmt = $pdo->prepare("SELECT id FROM asesores_perfil WHERE usuario_id = ?");
                            $stmt->execute([$usuario_id]);
                            $perfil_existente = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($perfil_existente) {
                                $stmt = $pdo->prepare("UPDATE asesores_perfil SET foto_perfil = ? WHERE usuario_id = ?");
                                $stmt->execute([$ruta_destino, $usuario_id]);
                            } else {
                                $stmt = $pdo->prepare("INSERT INTO asesores_perfil (usuario_id, foto_perfil) VALUES (?, ?)");
                                $stmt->execute([$usuario_id, $ruta_destino]);
                            }
                            
                            $success_message = "Foto de perfil actualizada correctamente";
                        } else {
                            $error_message = "Error al subir la imagen";
                        }
                    } catch (Exception $e) {
                        $error_message = "Error: " . $e->getMessage();
                    }
                }
            } else {
                $error_message = "No se seleccionó ninguna imagen";
            }
        }
    }
}

// Obtener datos actuales del usuario
$stmt = $pdo->prepare("
    SELECT u.*, ap.foto_perfil, ap.celular, ap.whatsapp, ap.telegram, ap.instagram, ap.facebook, ap.tiktok
    FROM usuarios u
    LEFT JOIN asesores_perfil ap ON u.id = ap.usuario_id
    WHERE u.id = ?
");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mi Perfil - Préstamo Líder</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: #f8fafc;
            overflow-x: hidden;
        }

        /* Wrapper general */
        .responsive-container {
            padding: 1rem;
            max-width: 100%;
            overflow-x: hidden;
        }

        /* Sidebar en desktop */
        aside {
            width: 260px;
            min-width: 240px;
            max-width: 280px;
            background: #fff;
            border-right: 1px solid #ddd;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
            z-index: 1000;
        }

        /* Contenido debe correr el espacio */
        .page-content {
            margin-left: 260px;
            min-width: 0;
        }

        /* -------- MOBILE -------- */
        @media (max-width: 768px) {
            aside {
                left: -100%;
                transition: left 0.3s ease;
                z-index: 9999;
            }

            aside.open {
                left: 0;
            }

            .page-content {
                margin-left: 0 !important;
            }
        }

        /* Botón flotante para abrir menu */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9998;
            background: #2563eb;
            color: white;
            border-radius: 50%;
            width: 56px;
            height: 56px;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 14px rgba(0,0,0,0.25);
        }

        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: flex;
            }
        }

        /* Overlay para cerrar menu */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9997;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Preview de foto */
        .photo-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #e5e7eb;
        }

        .upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            transition: all 0.3s;
        }

        .upload-area:hover {
            border-color: #2563eb;
            background: #f0f9ff;
        }

        /* Animación de mensajes */
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message-alert {
            animation: slideDown 0.3s ease-out;
        }

        /* Input con iconos */
        .input-with-icon {
            position: relative;
        }

        .input-with-icon input {
            padding-left: 2.5rem;
        }

        .input-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
    </style>
</head>
<body>

    <?php include 'sidebar_asesores.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <div class="page-content">
        <div class="responsive-container max-w-6xl mx-auto py-8">
            
            <!-- Header -->
            <div class="mb-8">
                <h2 class="text-3xl font-bold text-gray-800">Mi Perfil</h2>
                <p class="text-gray-600 mt-2">Gestiona tu información personal y credenciales</p>
            </div>

            <!-- Mensajes de éxito/error -->
            <?php if ($success_message): ?>
                <div class="message-alert mb-6 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg">
                    <div class="flex items-center">
                        <span class="material-icons-outlined mr-2">check_circle</span>
                        <span><?php echo htmlspecialchars($success_message); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="message-alert mb-6 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg">
                    <div class="flex items-center">
                        <span class="material-icons-outlined mr-2">error</span>
                        <span><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- COLUMNA IZQUIERDA - FOTO DE PERFIL -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                            <span class="material-icons-outlined text-blue-600">photo_camera</span>
                            Foto de Perfil
                        </h3>

                        <div class="flex flex-col items-center">
                            <!-- Preview de la foto -->
                            <div class="mb-4">
                                <?php if (!empty($usuario['foto_perfil']) && file_exists($usuario['foto_perfil'])): ?>
                                    <img src="<?php echo htmlspecialchars($usuario['foto_perfil']); ?>" 
                                         alt="Foto de perfil" 
                                         class="photo-preview"
                                         id="photo-preview">
                                <?php else: ?>
                                    <div class="photo-preview bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-5xl font-bold" id="photo-preview">
                                        <?php echo strtoupper(substr($usuario['nombre'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Información del usuario -->
                            <h4 class="text-xl font-bold text-gray-800 text-center mb-1">
                                <?php echo htmlspecialchars($usuario['nombre'] . ' ' . ($usuario['apellido'] ?? '')); ?>
                            </h4>
                            <p class="text-sm text-gray-500 mb-4"><?php echo htmlspecialchars($usuario['email']); ?></p>

                            <!-- Formulario de subida -->
                            <form method="POST" enctype="multipart/form-data" id="upload-form" class="w-full">
                                <input type="hidden" name="action" value="upload_photo">
                                
                                <label for="foto_perfil" class="upload-area cursor-pointer block p-6 text-center">
                                    <span class="material-icons-outlined text-4xl text-gray-400 mb-2">cloud_upload</span>
                                    <p class="text-sm text-gray-600 font-semibold">Subir nueva foto</p>
                                    <p class="text-xs text-gray-500 mt-1">JPG, PNG, GIF o WEBP (máx. 5MB)</p>
                                </label>
                                <input type="file" 
                                       id="foto_perfil" 
                                       name="foto_perfil" 
                                       accept="image/*" 
                                       class="hidden"
                                       onchange="previewAndSubmitPhoto(this)">
                            </form>
                        </div>
                    </div>
                </div>

                <!-- COLUMNA DERECHA - FORMULARIOS -->
                <div class="lg:col-span-2 space-y-6">
                    
                    <!-- INFORMACIÓN DE CONTACTO Y REDES SOCIALES -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                            <span class="material-icons-outlined text-blue-600">contacts</span>
                            Información de Contacto y Redes Sociales
                        </h3>

                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="update_profile">

                            <!-- Celular -->
                            <div class="input-with-icon">
                                <span class="input-icon material-icons-outlined">phone</span>
                                <input type="tel" 
                                       name="celular" 
                                       value="<?php echo htmlspecialchars($usuario['celular'] ?? ''); ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="Ej: +5491127390105">
                                <label class="text-xs text-gray-500 ml-1 mt-1 block">📞 Número de celular para llamadas</label>
                            </div>

                            <!-- WhatsApp -->
                            <div class="input-with-icon">
                                <span class="input-icon material-icons-outlined">whatsapp</span>
                                <input type="tel" 
                                       name="whatsapp" 
                                       value="<?php echo htmlspecialchars($usuario['whatsapp'] ?? ''); ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                       placeholder="Ej: +5491127390105">
                                <label class="text-xs text-gray-500 ml-1 mt-1 block">💬 Número de WhatsApp</label>
                            </div>

                            <!-- Telegram -->
                            <div class="input-with-icon">
                                <span class="input-icon material-icons-outlined">telegram</span>
                                <input type="text" 
                                       name="telegram" 
                                       value="<?php echo htmlspecialchars($usuario['telegram'] ?? ''); ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 focus:border-transparent"
                                       placeholder="Ej: https://t.me/tunombre o @tunombre">
                                <label class="text-xs text-gray-500 ml-1 mt-1 block">📲 Link a Telegram (canal alternativo)</label>
                            </div>

                            <!-- Instagram -->
                            <div class="input-with-icon">
                                <span class="input-icon material-icons-outlined">photo_camera</span>
                                <input type="text" 
                                       name="instagram" 
                                       value="<?php echo htmlspecialchars($usuario['instagram'] ?? ''); ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent"
                                       placeholder="Ej: https://instagram.com/tuperfil">
                                <label class="text-xs text-gray-500 ml-1 mt-1 block">📸 Link de Instagram</label>
                            </div>

                            <!-- Facebook -->
                            <div class="input-with-icon">
                                <span class="input-icon material-icons-outlined">facebook</span>
                                <input type="text" 
                                       name="facebook" 
                                       value="<?php echo htmlspecialchars($usuario['facebook'] ?? ''); ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-600 focus:border-transparent"
                                       placeholder="Ej: https://facebook.com/tuperfil">
                                <label class="text-xs text-gray-500 ml-1 mt-1 block">📘 Link de Facebook</label>
                            </div>

                            <!-- TikTok -->
                            <div class="input-with-icon">
                                <span class="input-icon material-icons-outlined">video_library</span>
                                <input type="text" 
                                       name="tiktok" 
                                       value="<?php echo htmlspecialchars($usuario['tiktok'] ?? ''); ?>"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-black focus:border-transparent"
                                       placeholder="Ej: https://tiktok.com/@tunombre">
                                <label class="text-xs text-gray-500 ml-1 mt-1 block">🎵 Link de TikTok</label>
                            </div>

                            <button type="submit" 
                                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-lg transition flex items-center justify-center gap-2">
                                <span class="material-icons-outlined">save</span>
                                Guardar Información de Contacto
                            </button>
                        </form>
                    </div>

                    <!-- CREDENCIALES (EMAIL Y CONTRASEÑA) -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                            <span class="material-icons-outlined text-blue-600">security</span>
                            Credenciales de Acceso
                        </h3>

                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="update_credentials">

                            <!-- Email -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Email</label>
                                <input type="email" 
                                       name="email" 
                                       value="<?php echo htmlspecialchars($usuario['email']); ?>"
                                       required
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="tu@email.com">
                            </div>

                            <div class="border-t pt-4">
                                <p class="text-sm font-semibold text-gray-700 mb-3">Cambiar Contraseña (opcional)</p>
                                
                                <!-- Contraseña actual -->
                                <div class="mb-3">
                                    <label class="block text-sm text-gray-600 mb-2">Contraseña Actual</label>
                                    <input type="password" 
                                           name="password_actual" 
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                           placeholder="Tu contraseña actual">
                                </div>

                                <!-- Nueva contraseña -->
                                <div class="mb-3">
                                    <label class="block text-sm text-gray-600 mb-2">Nueva Contraseña</label>
                                    <input type="password" 
                                           name="password_nueva" 
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                           placeholder="Mínimo 6 caracteres">
                                </div>

                                <!-- Confirmar nueva contraseña -->
                                <div>
                                    <label class="block text-sm text-gray-600 mb-2">Confirmar Nueva Contraseña</label>
                                    <input type="password" 
                                           name="password_confirmar" 
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                           placeholder="Repite la nueva contraseña">
                                </div>
                            </div>

                            <button type="submit" 
                                    class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-6 rounded-lg transition flex items-center justify-center gap-2">
                                <span class="material-icons-outlined">check_circle</span>
                                Actualizar Credenciales
                            </button>
                        </form>
                    </div>

                </div>
            </div>

        </div>
    </div>

    <script>
        // Preview y submit automático de foto
        function previewAndSubmitPhoto(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const preview = document.getElementById('photo-preview');
                    preview.innerHTML = `<img src="${e.target.result}" alt="Preview" class="photo-preview">`;
                    
                    // Submit automático
                    document.getElementById('upload-form').submit();
                };
                
                reader.readAsDataURL(file);
            }
        }

        // Mobile menu toggle
        const mobileMenuBtn = document.createElement('button');
        mobileMenuBtn.className = 'mobile-menu-btn';
        mobileMenuBtn.innerHTML = '<span class="material-icons-outlined">menu</span>';
        document.body.appendChild(mobileMenuBtn);

        const overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);

        const sidebar = document.querySelector('aside');

        mobileMenuBtn.addEventListener('click', () => {
            sidebar.classList.add('open');
            overlay.classList.add('active');
        });

        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        });

        // Auto-hide mensajes después de 5 segundos
        setTimeout(() => {
            const messages = document.querySelectorAll('.message-alert');
            messages.forEach(msg => {
                msg.style.transition = 'opacity 0.5s';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            });
        }, 5000);
    </script>

</body>
</html>