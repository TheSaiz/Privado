<?php
// Verificar sesión
if (!isset($_SESSION['asesor_id'])) {
    header("Location: login.php");
    exit;
}

$asesor_nombre = $_SESSION['asesor_nombre'] ?? 'Asesor';
$asesor_id = $_SESSION['asesor_id'];
?>

<style>
    /* ============================
       SIDEBAR STYLES
    ============================ */
    .sidebar-asesor {
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
        transition: left 0.3s ease;
    }

    .sidebar-header {
        padding: 1.5rem 1rem;
        border-bottom: 1px solid #e5e7eb;
        background: linear-gradient(135deg, #1d4ed8, #22c55e);
    }

    .sidebar-logo {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        color: white;
        text-decoration: none;
    }

    .sidebar-logo-icon {
        font-size: 2rem;
    }

    .sidebar-logo-text {
        font-size: 1.25rem;
        font-weight: bold;
    }

    .sidebar-menu {
        padding: 1rem 0;
    }

    .sidebar-section {
        margin-bottom: 1.5rem;
    }

    .sidebar-section-title {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #6b7280;
        padding: 0.5rem 1rem;
        margin-bottom: 0.5rem;
    }

    .sidebar-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        color: #374151;
        text-decoration: none;
        transition: all 0.2s;
        position: relative;
    }

    .sidebar-item:hover {
        background: #f3f4f6;
        color: #2563eb;
    }

    .sidebar-item.active {
        background: #e0f2fe;
        color: #0284c7;
        border-left: 3px solid #0284c7;
    }

    .sidebar-item-icon {
        font-size: 1.5rem;
    }

    .sidebar-item-text {
        font-size: 0.875rem;
        font-weight: 500;
    }

    .sidebar-badge {
        margin-left: auto;
        background: #ef4444;
        color: white;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.25rem 0.5rem;
        border-radius: 9999px;
        min-width: 20px;
        text-align: center;
        animation: pulse 2s infinite;
    }

    .sidebar-footer {
        border-top: 1px solid #e5e7eb;
        padding: 1rem;
        margin-top: auto;
    }

    .sidebar-user {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem;
        background: #f9fafb;
        border-radius: 0.5rem;
    }

    .sidebar-user-avatar {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 9999px;
        background: linear-gradient(135deg, #2563eb, #22c55e);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
    }

    .sidebar-user-info {
        flex: 1;
    }

    .sidebar-user-name {
        font-size: 0.875rem;
        font-weight: 600;
        color: #111827;
    }

    .sidebar-user-status {
        font-size: 0.75rem;
        color: #6b7280;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }

    .status-dot {
        width: 0.5rem;
        height: 0.5rem;
        border-radius: 9999px;
        background: #22c55e;
    }

    /* ============================
       MOBILE STYLES
    ============================ */
    @media (max-width: 768px) {
        .sidebar-asesor {
            left: -100%;
        }

        .sidebar-asesor.open {
            left: 0;
        }
    }

    /* ============================
       ANIMATIONS
    ============================ */
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
</style>

<aside class="sidebar-asesor">
    <!-- Header -->
    <div class="sidebar-header">
        <a href="dashboard_asesores.php" class="sidebar-logo">
            <span class="material-icons-outlined sidebar-logo-icon">account_balance</span>
            <span class="sidebar-logo-text">Préstamo Líder</span>
        </a>
    </div>

    <!-- Menu -->
    <nav class="sidebar-menu">
        <!-- Panel Principal -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Panel Principal</div>
            
            <a href="dashboard_asesor.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard_asesores.php' ? 'active' : ''; ?>">
                <span class="material-icons-outlined sidebar-item-icon">dashboard</span>
                <span class="sidebar-item-text">Dashboard</span>
            </a>

            <a href="panel_asesor.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) === 'panel_asesor.php' ? 'active' : ''; ?>">
                <span class="material-icons-outlined sidebar-item-icon">chat</span>
                <span class="sidebar-item-text">Chats</span>
                <span id="sidebar-pending-chats-badge" class="sidebar-badge" style="display: none;">0</span>
            </a>
        </div>
        <a href="prestamos_admin.php"
   class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) === 'prestamos_admin.php' ? 'active' : ''; ?>">
    <span class="material-icons-outlined sidebar-item-icon">account_balance_wallet</span>
    <span class="sidebar-item-text">Préstamos</span>
    <span id="sidebar-prestamos-badge" class="sidebar-badge" style="display:none;">0</span>
</a>


        <!-- Sistema de Referidos -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Sistema de Referidos</div>
            
            <a href="referidos_asesores.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) === 'referidos_asesores.php' ? 'active' : ''; ?>">
                <span class="material-icons-outlined sidebar-item-icon">group_add</span>
                <span class="sidebar-item-text">Mis Referidos</span>
            </a>

            <a href="link_referidos.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) === 'link_referidos.php' ? 'active' : ''; ?>">
                <span class="material-icons-outlined sidebar-item-icon">link</span>
                <span class="sidebar-item-text">Mi Link</span>
            </a>

        </div>

        <!-- Reportes -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Reportes</div>
            
            <a href="estadisticas.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) === 'estadisticas.php' ? 'active' : ''; ?>">
                <span class="material-icons-outlined sidebar-item-icon">bar_chart</span>
                <span class="sidebar-item-text">Estadísticas</span>
            </a>

            <a href="historial_chats.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) === 'historial_chats.php' ? 'active' : ''; ?>">
                <span class="material-icons-outlined sidebar-item-icon">history</span>
                <span class="sidebar-item-text">Historial</span>
            </a>
        </div>

        <!-- Configuración -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Configuración</div>
            
            <a href="perfil_asesor.php" class="sidebar-item <?php echo basename($_SERVER['PHP_SELF']) === 'perfil_asesor.php' ? 'active' : ''; ?>">
                <span class="material-icons-outlined sidebar-item-icon">settings</span>
                <span class="sidebar-item-text">Mi Perfil</span>
            </a>

            <a href="logout.php" class="sidebar-item">
                <span class="material-icons-outlined sidebar-item-icon">logout</span>
                <span class="sidebar-item-text">Cerrar Sesión</span>
            </a>
        </div>
    </nav>

    <!-- Footer - User Info -->
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-user-avatar">
                <?php echo strtoupper(substr($asesor_nombre, 0, 2)); ?>
            </div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($asesor_nombre); ?></div>
                <div class="sidebar-user-status">
                    <span class="status-dot"></span>
                    <span>En línea</span>
                </div>
            </div>
        </div>
    </div>
</aside>

<!-- Overlay para mobile -->
<div id="sidebar-overlay" class="sidebar-overlay"></div>

<!-- Botón mobile -->
<button id="mobile-menu-btn" class="mobile-menu-btn">
    <span class="material-icons-outlined">menu</span>
</button>

<script>
// ============================
// POLLING DE CHATS PENDIENTES
// ============================
const asesorId = <?php echo $asesor_id; ?>;

async function updatePendingChatsBadge() {
    try {
        const response = await fetch(`api/asesores/get_pending_chats.php?asesor_id=${asesorId}`);
        const data = await response.json();

        const badge = document.getElementById('sidebar-pending-chats-badge');
        
        if (data.success && data.data.chats.length > 0) {
            badge.textContent = data.data.chats.length;
            badge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
        }
    } catch (error) {
        console.error('Error actualizando badge de chats:', error);
    }
}

// Actualizar cada 3 segundos
setInterval(updatePendingChatsBadge, 3000);
updatePendingChatsBadge(); // Ejecutar inmediatamente

// ============================
// MOBILE MENU TOGGLE
// ============================
const mobileMenuBtn = document.getElementById('mobile-menu-btn');
const sidebar = document.querySelector('.sidebar-asesor');
const overlay = document.getElementById('sidebar-overlay');

if (mobileMenuBtn) {
    mobileMenuBtn.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
    });
}

if (overlay) {
    overlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
    });
}

// Cerrar sidebar al hacer click en un link (mobile)
document.querySelectorAll('.sidebar-item').forEach(item => {
    item.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        }
    });
});


async function updatePréstamosBadge() {
    try {
        const res = await fetch('api/contador_prestamos_admin.php', { cache: 'no-store' });
        const data = await res.json();

        const badge = document.getElementById('sidebar-prestamos-badge');
        if (!badge) return;

        if (data.success && data.total > 0) {
            badge.textContent = data.total;
            badge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
        }
    } catch (e) {}
}

updatePréstamosBadge();
setInterval(updatePréstamosBadge, 1000);


</script>

<style>
    /* Overlay y botón mobile */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 999;
    }

    .sidebar-overlay.active {
        display: block;
    }

    .mobile-menu-btn {
        display: none;
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 998;
        background: #2563eb;
        color: white;
        border: none;
        border-radius: 50%;
        width: 56px;
        height: 56px;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 14px rgba(0,0,0,0.25);
        cursor: pointer;
        transition: transform 0.2s;
    }

    .mobile-menu-btn:hover {
        transform: scale(1.05);
    }

    @media (max-width: 768px) {
        .mobile-menu-btn {
            display: flex;
        }
    }

    /* Ajuste del contenido principal */
    .page-content {
        margin-left: 260px;
        min-width: 0;
    }

    @media (max-width: 768px) {
        .page-content {
            margin-left: 0 !important;
        }
    }
</style>