<?php
session_start();

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['usuario_rol'])) {
    header("Location: login.php");
    exit;
}

// Verificar que sea admin
if ($_SESSION['usuario_rol'] !== 'admin') {
    // Si no es admin, lo sacamos del dashboard
    header("Location: panel_asesor.php");
    exit;
}
require_once 'backend/connection.php';

// Obtener departamentos
$stmtDepts = $pdo->query("SELECT * FROM departamentos ORDER BY nombre");
$departamentos = $stmtDepts->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PROCESAR ACCIONES
// ============================================

// CREAR PREGUNTA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_question') {
    $pregunta = trim($_POST['pregunta']);
    $tipo = $_POST['tipo'];
    
    if ($pregunta) {
        $stmt = $pdo->prepare("INSERT INTO chatbot_flujo (pregunta, tipo) VALUES (?, ?)");
        $stmt->execute([$pregunta, $tipo]);
        $_SESSION['message'] = 'Pregunta creada exitosamente';
        header('Location: chatbot.php');
        exit;
    }
}

// ACTUALIZAR PREGUNTA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_question') {
    $id = intval($_POST['id']);
    $pregunta = trim($_POST['pregunta']);
    $tipo = $_POST['tipo'];
    
    if ($id && $pregunta) {
        $stmt = $pdo->prepare("UPDATE chatbot_flujo SET pregunta = ?, tipo = ? WHERE id = ?");
        $stmt->execute([$pregunta, $tipo, $id]);
        $_SESSION['message'] = 'Pregunta actualizada exitosamente';
        header('Location: chatbot.php');
        exit;
    }
}

// ELIMINAR PREGUNTA
if (isset($_GET['delete_question'])) {
    $id = intval($_GET['delete_question']);
    
    // Primero eliminar opciones asociadas
    $stmt = $pdo->prepare("DELETE FROM chatbot_opciones WHERE flujo_id = ?");
    $stmt->execute([$id]);
    
    // Luego eliminar pregunta
    $stmt = $pdo->prepare("DELETE FROM chatbot_flujo WHERE id = ?");
    $stmt->execute([$id]);
    
    $_SESSION['message'] = 'Pregunta eliminada exitosamente';
    header('Location: chatbot.php');
    exit;
}

// CREAR OPCIÓN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_option') {
    $flujo_id = intval($_POST['flujo_id']);
    $texto = trim($_POST['texto']);
    $departamento_id = intval($_POST['departamento_id']);
    
    if ($flujo_id && $texto && $departamento_id) {
        $stmt = $pdo->prepare("INSERT INTO chatbot_opciones (flujo_id, texto, departamento_id) VALUES (?, ?, ?)");
        $stmt->execute([$flujo_id, $texto, $departamento_id]);
        $_SESSION['message'] = 'Opción creada exitosamente';
        header('Location: chatbot.php');
        exit;
    }
}

// ELIMINAR OPCIÓN
if (isset($_GET['delete_option'])) {
    $id = intval($_GET['delete_option']);
    $stmt = $pdo->prepare("DELETE FROM chatbot_opciones WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['message'] = 'Opción eliminada exitosamente';
    header('Location: chatbot.php');
    exit;
}

// REORDENAR PREGUNTAS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reorder') {
    $order = json_decode($_POST['order'], true);
    foreach ($order as $index => $id) {
        $stmt = $pdo->prepare("UPDATE chatbot_flujo SET orden = ? WHERE id = ?");
        $stmt->execute([$index + 1, $id]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ============================================
// OBTENER DATOS
// ============================================

// Obtener todas las preguntas con sus opciones
$stmt = $pdo->query("SELECT * FROM chatbot_flujo ORDER BY id ASC");
$preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener opciones para cada pregunta
foreach ($preguntas as &$pregunta) {
    $stmt = $pdo->prepare("
        SELECT co.*, d.nombre as departamento_nombre 
        FROM chatbot_opciones co
        LEFT JOIN departamentos d ON co.departamento_id = d.id
        WHERE co.flujo_id = ?
        ORDER BY co.id
    ");
    $stmt->execute([$pregunta['id']]);
    $pregunta['opciones'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$message = isset($_SESSION['message']) ? $_SESSION['message'] : '';
unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrador de Chatbot - Préstamo Líder</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <style>
        .sortable-ghost { opacity: 0.4; }
        .sortable-drag { cursor: move; }
    </style>
</head>
<body class="bg-gray-50">

    <?php include 'sidebar.php'; ?>

    <div class="ml-64">
        
        <!-- Navegación superior -->
        <nav class="bg-white shadow-md sticky top-0 z-40">
            <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <span class="material-icons-outlined text-blue-600 text-3xl">smart_toy</span>
                    <span class="text-xl font-bold text-gray-800">Administrador de Chatbot</span>
                </div>
                <button onclick="openModal('createQuestionModal')" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition flex items-center gap-2">
                    <span class="material-icons-outlined">add</span>
                    Nueva Pregunta
                </button>
            </div>
        </nav>

        <div class="max-w-7xl mx-auto px-4 py-8">
            
            <!-- Mensaje de éxito -->
            <?php if ($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center gap-2">
                <span class="material-icons-outlined">check_circle</span>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
            <?php endif; ?>

            <!-- Información -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-blue-600">info</span>
                    <div>
                        <h3 class="font-semibold text-blue-900 mb-1">Cómo funciona el chatbot</h3>
                        <p class="text-sm text-blue-800">
                            Las preguntas se muestran secuencialmente al cliente. Las preguntas tipo <strong>"opción"</strong> 
                            muestran botones que derivan a un departamento. Las de tipo <strong>"texto"</strong> permiten 
                            que el cliente escriba su respuesta libremente.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Lista de preguntas -->
            <div class="space-y-4" id="questions-list">
                <?php if (empty($preguntas)): ?>
                <div class="bg-white rounded-xl shadow-lg p-12 text-center">
                    <span class="material-icons-outlined text-gray-400 text-6xl mb-4">quiz</span>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">No hay preguntas configuradas</h3>
                    <p class="text-gray-500 mb-6">Crea la primera pregunta para comenzar a configurar tu chatbot</p>
                    <button onclick="openModal('createQuestionModal')" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        Crear Primera Pregunta
                    </button>
                </div>
                <?php else: ?>
                    <?php foreach ($preguntas as $index => $pregunta): ?>
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden" data-id="<?php echo $pregunta['id']; ?>">
                        
                        <!-- Header de la pregunta -->
                        <div class="bg-gradient-to-r from-blue-600 to-blue-700 p-4 flex justify-between items-center">
                            <div class="flex items-center gap-3 text-white">
                                <span class="bg-white bg-opacity-20 rounded-full w-10 h-10 flex items-center justify-center font-bold">
                                    <?php echo $index + 1; ?>
                                </span>
                                <div>
                                    <h3 class="font-semibold text-lg"><?php echo htmlspecialchars($pregunta['pregunta']); ?></h3>
                                    <span class="text-sm opacity-90">
                                        Tipo: <?php echo $pregunta['tipo'] === 'opcion' ? '📋 Opciones múltiples' : '✏️ Texto libre'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button onclick='editQuestion(<?php echo json_encode($pregunta); ?>)' 
                                        class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white p-2 rounded-lg transition">
                                    <span class="material-icons-outlined">edit</span>
                                </button>
                                <button onclick="deleteQuestion(<?php echo $pregunta['id']; ?>)" 
                                        class="bg-red-500 bg-opacity-80 hover:bg-opacity-100 text-white p-2 rounded-lg transition">
                                    <span class="material-icons-outlined">delete</span>
                                </button>
                            </div>
                        </div>

                        <!-- Opciones (solo si es tipo "opcion") -->
                        <?php if ($pregunta['tipo'] === 'opcion'): ?>
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-4">
                                <h4 class="font-semibold text-gray-700 flex items-center gap-2">
                                    <span class="material-icons-outlined text-blue-600">list</span>
                                    Opciones de respuesta
                                </h4>
                                <button onclick="openOptionModal(<?php echo $pregunta['id']; ?>)" 
                                        class="px-3 py-1 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition flex items-center gap-1">
                                    <span class="material-icons-outlined text-sm">add</span>
                                    Agregar Opción
                                </button>
                            </div>

                            <?php if (empty($pregunta['opciones'])): ?>
                            <div class="bg-gray-50 rounded-lg p-4 text-center text-gray-500">
                                <span class="material-icons-outlined text-3xl mb-2">info</span>
                                <p class="text-sm">No hay opciones configuradas para esta pregunta</p>
                            </div>
                            <?php else: ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <?php foreach ($pregunta['opciones'] as $opcion): ?>
                                <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition">
                                    <div class="flex justify-between items-start">
                                        <div class="flex-1">
                                            <p class="font-semibold text-gray-800 mb-1"><?php echo htmlspecialchars($opcion['texto']); ?></p>
                                            <div class="flex items-center gap-2 text-sm">
                                                <span class="material-icons-outlined text-xs text-blue-600">arrow_forward</span>
                                                <span class="text-gray-600">Deriva a: <strong><?php echo htmlspecialchars($opcion['departamento_nombre']); ?></strong></span>
                                            </div>
                                        </div>
                                        <button onclick="deleteOption(<?php echo $opcion['id']; ?>)" 
                                                class="text-red-500 hover:bg-red-50 p-1 rounded transition">
                                            <span class="material-icons-outlined text-sm">close</span>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="p-6 bg-gray-50">
                            <p class="text-sm text-gray-600 flex items-center gap-2">
                                <span class="material-icons-outlined text-blue-600">edit_note</span>
                                El cliente podrá escribir su respuesta libremente
                            </p>
                        </div>
                        <?php endif; ?>

                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- MODAL: Crear/Editar Pregunta -->
    <div id="createQuestionModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl mx-4">
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 p-6 rounded-t-2xl">
                <h2 class="text-2xl font-bold text-white" id="modalTitle">Nueva Pregunta</h2>
            </div>
            <form id="questionForm" method="POST" class="p-6">
                <input type="hidden" name="action" id="formAction" value="create_question">
                <input type="hidden" name="id" id="questionId">
                
                <div class="space-y-6">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Pregunta</label>
                        <textarea name="pregunta" id="questionText" rows="3" required
                                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Ejemplo: ¿En qué podemos ayudarte hoy?"></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Tipo de Respuesta</label>
                        <select name="tipo" id="questionType" required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="opcion">📋 Opciones múltiples (botones)</option>
                            <option value="texto">✏️ Texto libre (input)</option>
                        </select>
                        <p class="mt-2 text-sm text-gray-500">
                            <strong>Opciones múltiples:</strong> El cliente verá botones para elegir.<br>
                            <strong>Texto libre:</strong> El cliente podrá escribir su respuesta.
                        </p>
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeModal('createQuestionModal')" 
                            class="flex-1 px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition font-semibold">
                        Cancelar
                    </button>
                    <button type="submit" 
                            class="flex-1 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold">
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Agregar Opción -->
    <div id="createOptionModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl mx-4">
            <div class="bg-gradient-to-r from-green-600 to-green-700 p-6 rounded-t-2xl">
                <h2 class="text-2xl font-bold text-white">Agregar Opción</h2>
            </div>
            <form method="POST" class="p-6">
                <input type="hidden" name="action" value="create_option">
                <input type="hidden" name="flujo_id" id="optionFlujoId">
                
                <div class="space-y-6">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Texto de la Opción</label>
                        <input type="text" name="texto" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                               placeholder="Ejemplo: Solicitar un préstamo">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Departamento de Destino</label>
                        <select name="departamento_id" required
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="">Selecciona un departamento</option>
                            <?php foreach ($departamentos as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-2 text-sm text-gray-500">
                            Cuando el cliente seleccione esta opción, será derivado al departamento elegido.
                        </p>
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeModal('createOptionModal')" 
                            class="flex-1 px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition font-semibold">
                        Cancelar
                    </button>
                    <button type="submit" 
                            class="flex-1 px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-semibold">
                        Guardar Opción
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Funciones para modales
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
            document.getElementById(modalId).classList.add('flex');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.getElementById(modalId).classList.remove('flex');
            
            // Resetear formulario si es el modal de pregunta
            if (modalId === 'createQuestionModal') {
                document.getElementById('questionForm').reset();
                document.getElementById('formAction').value = 'create_question';
                document.getElementById('questionId').value = '';
                document.getElementById('modalTitle').textContent = 'Nueva Pregunta';
            }
        }

        // Editar pregunta
        function editQuestion(pregunta) {
            document.getElementById('modalTitle').textContent = 'Editar Pregunta';
            document.getElementById('formAction').value = 'update_question';
            document.getElementById('questionId').value = pregunta.id;
            document.getElementById('questionText').value = pregunta.pregunta;
            document.getElementById('questionType').value = pregunta.tipo;
            openModal('createQuestionModal');
        }

        // Eliminar pregunta
        function deleteQuestion(id) {
            if (confirm('¿Estás seguro de eliminar esta pregunta? También se eliminarán todas sus opciones.')) {
                window.location.href = 'chatbot.php?delete_question=' + id;
            }
        }

        // Abrir modal de opción
        function openOptionModal(flujoId) {
            document.getElementById('optionFlujoId').value = flujoId;
            openModal('createOptionModal');
        }

        // Eliminar opción
        function deleteOption(id) {
            if (confirm('¿Estás seguro de eliminar esta opción?')) {
                window.location.href = 'chatbot.php?delete_option=' + id;
            }
        }

        // Cerrar modales con ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal('createQuestionModal');
                closeModal('createOptionModal');
            }
        });
    </script>

</body>
</html>