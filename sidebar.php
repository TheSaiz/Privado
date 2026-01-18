<?php
$pagina_actual = basename($_SERVER['PHP_SELF']);

function activo($archivo) {
    global $pagina_actual;
    return $pagina_actual === $archivo
        ? 'bg-blue-50 text-blue-600 font-semibold'
        : 'text-gray-700 hover:bg-blue-50 hover:text-blue-600';
}
?>

<aside class="w-64 h-screen bg-white shadow-xl fixed left-0 top-0 flex flex-col border-r border-gray-200 z-50">

    <!-- Logo -->
    <div class="p-6 border-b border-gray-100">
        <h1 class="text-2xl font-bold text-blue-600">Panel Admin</h1>
    </div>

    <!-- Menú -->
    <nav class="flex-1 overflow-y-auto p-4">
        <ul class="space-y-2">

            <li>
                <a href="dashboard.php" class="flex items-center p-3 rounded-lg transition <?php echo activo('dashboard.php'); ?>">
                    <span class="material-icons-outlined mr-3">dashboard</span>
                    Dashboard
                </a>
            </li>

            <li>
                <a href="clientes.php" class="flex items-center p-3 rounded-lg transition <?php echo activo('clientes.php'); ?>">
                    <span class="material-icons-outlined mr-3">groups</span>
                    Clientes
                </a>
            </li>

            <li>
                <a href="asesores.php" class="flex items-center p-3 rounded-lg transition <?php echo activo('asesores.php'); ?>">
                    <span class="material-icons-outlined mr-3">support_agent</span>
                    Asesores
                </a>
            </li>

            <!-- SOLICITUDES -->
            <li>
                <a href="solicitudes.php"
                   class="flex items-center justify-between p-3 rounded-lg transition <?php echo activo('solicitudes.php'); ?>">
                    <div class="flex items-center">
                        <span class="material-icons-outlined mr-3">assignment</span>
                        Solicitudes
                    </div>
                    <span id="badge-solicitudes"
                          class="hidden min-w-[22px] h-[22px] px-2 text-xs font-bold rounded-full bg-red-600 text-white flex items-center justify-center">
                        0
                    </span>
                </a>
            </li>

            <li>
                <a href="chatbot.php" class="flex items-center p-3 rounded-lg transition <?php echo activo('chatbot.php'); ?>">
                    <span class="material-icons-outlined mr-3">smart_toy</span>
                    ChatBot
                </a>
            </li>

            <li>
                <a href="plantillas.php" class="flex items-center p-3 rounded-lg transition <?php echo activo('plantillas.php'); ?>">
                    <span class="material-icons-outlined mr-3">email</span>
                    Plantillas Email
                </a>
            </li>

            <li>
                <a href="perfil.php" class="flex items-center p-3 rounded-lg transition <?php echo activo('perfil.php'); ?>">
                    <span class="material-icons-outlined mr-3">person</span>
                    Perfil
                </a>
            </li>

            <li>
                <a href="reportes.php" class="flex items-center p-3 rounded-lg transition <?php echo activo('reportes.php'); ?>">
                    <span class="material-icons-outlined mr-3">bar_chart</span>
                    Reportes
                </a>
            </li>

            <li>
                <a href="configuracion.php" class="flex items-center p-3 rounded-lg transition <?php echo activo('configuracion.php'); ?>">
                    <span class="material-icons-outlined mr-3">settings</span>
                    Configuración
                </a>
            </li>
            
            <!-- PRÉSTAMOS CON CONTADOR -->
            <li>
                <a href="prestamos_admin.php" 
                   class="flex items-center justify-between p-3 rounded-lg transition <?php echo activo('prestamos_admin.php'); ?>">
                    <div class="flex items-center">
                        <span class="material-icons-outlined mr-3">account_balance_wallet</span>
                        Préstamos
                    </div>
                    <span id="badge-prestamos"
                          class="hidden min-w-[22px] h-[22px] px-2 text-xs font-bold rounded-full bg-red-600 text-white flex items-center justify-center">
                        0
                    </span>
                </a>
            </li>
            
            <!-- PAGOS ADMIN CON CONTADOR -->
            <li>
                <a href="pagos_admin.php" 
                   class="flex items-center justify-between p-3 rounded-lg transition <?php echo activo('pagos_admin.php'); ?>">
                    <div class="flex items-center">
                        <span class="material-icons-outlined mr-3">payments</span>
                        Pagos
                    </div>
                    <span id="badge-pagos"
                          class="hidden min-w-[22px] h-[22px] px-2 text-xs font-bold rounded-full bg-orange-600 text-white flex items-center justify-center">
                        0
                    </span>
                </a>
            </li>
            
            <li>
                <a href="configuracion_prestamos.php" class="flex items-center p-3 rounded-lg transition <?php echo activo('configuracion_prestamos.php'); ?>">
                    <span class="material-icons-outlined mr-3">tune</span>
                    Config. Préstamos
                </a>
            </li>

        </ul>
    </nav>

    <div class="p-4 border-t border-gray-200">
        <a href="logout.php" class="flex items-center p-3 rounded-lg text-red-600 hover:bg-red-50 transition">
            <span class="material-icons-outlined mr-3">logout</span>
            Cerrar Sesión
        </a>
    </div>

</aside>

<script>
(function () {
    // Badge de Solicitudes
    const badgeSolicitudes = document.getElementById('badge-solicitudes');
    if (badgeSolicitudes) {
        async function actualizarSolicitudes() {
            try {
                const res = await fetch('api/contador_solicitudes.php', { cache: 'no-store' });
                const data = await res.json();

                if (data.success && data.total > 0) {
                    badgeSolicitudes.textContent = data.total;
                    badgeSolicitudes.classList.remove('hidden');
                } else {
                    badgeSolicitudes.classList.add('hidden');
                }
            } catch (e) {}
        }

        actualizarSolicitudes();
        setInterval(actualizarSolicitudes, 1000);
    }

    // Badge de Préstamos Pendientes
    const badgePrestamos = document.getElementById('badge-prestamos');
    if (badgePrestamos) {
        async function actualizarPrestamos() {
            try {
                const res = await fetch('api/contador_prestamos.php', { cache: 'no-store' });
                const data = await res.json();

                if (data.success && data.total > 0) {
                    badgePrestamos.textContent = data.total;
                    badgePrestamos.classList.remove('hidden');
                } else {
                    badgePrestamos.classList.add('hidden');
                }
            } catch (e) {}
        }

        actualizarPrestamos();
        setInterval(actualizarPrestamos, 1000);
    }

    // Badge de Pagos Pendientes (NUEVO)
    const badgePagos = document.getElementById('badge-pagos');
    if (badgePagos) {
        async function actualizarPagos() {
            try {
                const res = await fetch('api/contador_pagos_pendientes.php', { cache: 'no-store' });
                const data = await res.json();

                if (data.success && data.total > 0) {
                    badgePagos.textContent = data.total;
                    badgePagos.classList.remove('hidden');
                } else {
                    badgePagos.classList.add('hidden');
                }
            } catch (e) {}
        }

        actualizarPagos();
        setInterval(actualizarPagos, 5000); // Actualizar cada 5 segundos
    }

    // Badge de Legajos Pendientes
    const badgeLegajos = document.getElementById('badge-legajos');
    if (badgeLegajos) {
        async function actualizarLegajos() {
            try {
                const res = await fetch('api/contador_legajos.php', { cache: 'no-store' });
                const data = await res.json();

                if (data.success && data.total > 0) {
                    badgeLegajos.textContent = data.total;
                    badgeLegajos.classList.remove('hidden');
                } else {
                    badgeLegajos.classList.add('hidden');
                }
            } catch (e) {}
        }

        actualizarLegajos();
        setInterval(actualizarLegajos, 30000); // Cada 30 segundos
    }
})();
</script>