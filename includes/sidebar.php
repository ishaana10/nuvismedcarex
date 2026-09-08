<?php
/**
 * Shared Navigation Sidebar Component with Mobile Drawer
 */
$activePage = $activePage ?? 'dashboard';

$userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Doctor';

$navItems = [
    ['id' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'url' => 'index.php'],
    ['id' => 'patients', 'label' => 'Patients', 'icon' => 'group', 'url' => 'patients.php'],
    ['id' => 'calendar', 'label' => 'Calendar', 'icon' => 'calendar_month', 'url' => 'calendar.php'],
    ['id' => 'clinical-visit', 'label' => 'Clinical Encounter', 'icon' => 'clinical_notes', 'url' => 'clinical_visit.php'],
    ['id' => 'billing', 'label' => 'Billing & Financials', 'icon' => 'payments', 'url' => 'billing.php'],
    ['id' => 'inventory', 'label' => 'Inventory', 'icon' => 'inventory_2', 'url' => 'inventory.php'],
    ['id' => 'register-patient', 'label' => 'Register Patient', 'icon' => 'person_add', 'url' => 'register_patient.php'],
    ['id' => 'admin', 'label' => 'Administrator', 'icon' => 'admin_panel_settings', 'url' => 'admin.php'],
];

if ($userRole === 'Developer') {
    $navItems[] = ['id' => 'developer', 'label' => 'Developer Options', 'icon' => 'code', 'url' => 'admin.php?tab=developer'];
}
?>

<!-- Desktop Sidebar -->
<aside class="w-64 bg-white border-r border-slate-200/80 flex flex-col justify-between p-4 shrink-0 hidden md:flex min-h-[calc(100vh-65px)]">
    <div class="space-y-5">
        <!-- Clinic Profile Badge -->
        <div class="flex items-center gap-3 px-2 py-1">
            <img src="assets/images/nuvis_medicoz_logo.png" alt="Nuvis Medicoz Logo" class="w-10 h-10 rounded-full object-cover border border-amber-500/50 shadow-md">
            <div class="overflow-hidden">
                <div class="font-bold text-sm text-slate-900 truncate">Nuvis Medicoz</div>
                <div class="text-[11px] font-medium text-slate-500">Admin Portal</div>
            </div>
        </div>

        <!-- Primary Action Button -->
        <a href="register_patient.php" class="flex items-center justify-center gap-2 w-full py-2.5 px-4 bg-blue-700 hover:bg-blue-800 text-white font-semibold text-xs rounded-xl shadow-md shadow-blue-700/20 transition">
            <span class="material-symbols-outlined text-lg">add</span>
            <span>Register Patient</span>
        </a>

        <!-- Navigation Menu -->
        <nav class="space-y-1">
            <?php foreach ($navItems as $item):
                $isActive = ($activePage === $item['id']);
            ?>
                <a href="<?= $item['url'] ?>" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl font-medium text-xs transition-all <?= $isActive ? 'bg-blue-600 text-white font-semibold shadow-md shadow-blue-600/20' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">
                    <span class="material-symbols-outlined text-lg <?= $isActive ? 'fill' : '' ?>"><?= $item['icon'] ?></span>
                    <span><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <!-- Quick Help & Doctor Profile Badge -->
    <div class="space-y-2 pt-4 border-t border-slate-200/80 text-xs">
        <a href="#" class="flex items-center gap-3 px-3.5 py-2 text-slate-600 hover:bg-slate-100 rounded-xl transition">
            <span class="material-symbols-outlined text-lg">help_outline</span>
            <span>Help</span>
        </a>
        <a href="actions/logout.php" class="flex items-center gap-3 px-3.5 py-2 text-slate-600 hover:bg-red-50 hover:text-red-600 rounded-xl transition">
            <span class="material-symbols-outlined text-lg">logout</span>
            <span>Logout</span>
        </a>
    </div>
</aside>

<!-- Mobile Overlay Backdrop -->
<div id="mobileBackdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-[60] hidden md:hidden transition-opacity"></div>

<!-- Mobile Drawer Sidebar -->
<aside id="mobileSidebar" class="fixed top-0 left-0 bottom-0 w-[280px] sm:w-72 bg-surface-container-lowest z-[70] transform -translate-x-full transition-transform duration-300 ease-in-out md:hidden flex flex-col justify-between p-5 shadow-2xl border-r border-outline-variant/30 overflow-y-auto">
    <div class="space-y-4">
        <div class="flex items-center justify-between border-b border-outline-variant/30 pb-3">
            <div class="flex items-center gap-2">
                <img src="assets/images/nuvis_medicoz_logo.png" alt="Nuvis Medicoz" class="w-8 h-8 rounded-full object-cover border border-amber-500/50">
                <span class="font-bold text-sm text-slate-900">Nuvis Medicoz</span>
            </div>
            <button onclick="toggleMobileSidebar()" class="p-1.5 text-outline hover:text-on-surface rounded-lg">
                <span class="material-symbols-outlined text-xl">close</span>
            </button>
        </div>

        <nav class="space-y-1">
            <?php foreach ($navItems as $item):
                $isActive = ($activePage === $item['id']);
            ?>
                <a href="<?= $item['url'] ?>" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl font-medium text-xs transition-all <?= $isActive ? 'bg-surface-container-high text-primary font-bold shadow-2xs' : 'text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface' ?>">
                    <span class="material-symbols-outlined text-lg <?= $isActive ? 'fill text-primary' : '' ?>"><?= $item['icon'] ?></span>
                    <span><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="space-y-3 pt-4 border-t border-outline-variant/30">
        <a href="register_patient.php" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-primary text-white text-xs font-semibold rounded-xl shadow-xs">
            <span class="material-symbols-outlined text-base">person_add</span>
            <span>Register Patient</span>
        </a>
        <a href="calendar.php?action=book" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-primary-container text-white text-xs font-semibold rounded-xl shadow-xs">
            <span class="material-symbols-outlined text-base">calendar_add_on</span>
            <span>Book Appointment</span>
        </a>
    </div>
</aside>

<script>
function toggleMobileSidebar() {
    const sidebar = document.getElementById('mobileSidebar');
    const backdrop = document.getElementById('mobileBackdrop');
    if (sidebar.classList.contains('-translate-x-full')) {
        sidebar.classList.remove('-translate-x-full');
        sidebar.classList.add('translate-x-0');
        backdrop.classList.remove('hidden');
    } else {
        sidebar.classList.add('-translate-x-full');
        sidebar.classList.remove('translate-x-0');
        backdrop.classList.add('hidden');
    }
}
</script>
