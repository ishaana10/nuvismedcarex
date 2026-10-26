<?php
/**
 * Navigation: desktop rail (collapsible) + mobile drawer.
 * Items come from includes/nav.php so both stay in sync with the command palette.
 */
require_once __DIR__ . '/nav.php';
$activePage = $activePage ?? 'dashboard';
$nxUserRole = $nxUserRole ?? ($_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Doctor');
$nxNav = $nxNav ?? nxNavItems($nxUserRole);
$nxCounts = $nxCounts ?? [];
$nxClinic = $GLOBALS['activeClinicName'] ?? $activeClinicName ?? 'Nuvis Medico Healthcare';

$nxGrouped = [];
foreach ($nxNav as $item) { $nxGrouped[$item['group']][] = $item; }

$nxCountTitles = [
    'dashboard' => 'patients waiting',
    'calendar'  => 'appointments still to see today',
];

$renderNav = function (bool $mobile) use ($nxGrouped, $activePage, $nxCounts, $nxCountTitles) {
    foreach ($nxGrouped as $group => $items): ?>
        <div class="nx-rail-group"><span><?= htmlspecialchars($group) ?></span></div>
        <?php foreach ($items as $item):
            $isActive = ($activePage === $item['id']);
            $count = (int) ($nxCounts[$item['id']] ?? 0); ?>
            <a href="<?= htmlspecialchars($item['url']) ?>" class="nx-rail-link" <?= $isActive ? 'aria-current="page"' : '' ?> title="<?= htmlspecialchars($item['label']) ?>">
                <span class="material-symbols-outlined <?= $isActive ? 'fill' : '' ?>"><?= $item['icon'] ?></span>
                <span class="nx-label"><?= htmlspecialchars($item['label']) ?></span>
                <?php if ($count > 0): ?>
                    <span class="nx-count" title="<?= $count ?> <?= htmlspecialchars($nxCountTitles[$item['id']] ?? '') ?>"><?= $count > 99 ? '99+' : $count ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach;
    endforeach;
};
?>

<!-- Desktop rail -->
<aside class="desktop-sidebar nx-rail flex-col shrink-0 z-30" aria-label="Main navigation">
    <div class="nx-rail-brand flex items-center gap-3 px-4 shrink-0">
        <img src="assets/images/nuvis_mark.png" alt="" class="w-9 h-9 rounded-lg object-contain bg-white p-0.5 shrink-0">
        <div class="nx-label min-w-0 leading-tight">
            <div class="text-[13px] font-semibold text-white truncate" title="<?= htmlspecialchars($nxClinic) ?>"><?= htmlspecialchars($nxClinic) ?></div>
            <div class="text-[11px] text-white/50">Nuvis Medcare X</div>
        </div>
    </div>

    <a href="register_patient.php" class="nx-rail-cta" title="Register patient (N)" <?= $activePage === 'register-patient' ? 'aria-current="page"' : '' ?>>
        <span class="material-symbols-outlined text-[20px]">person_add</span>
        <span class="nx-label">Register patient</span>
    </a>

    <nav class="nx-rail-scroll flex-1 overflow-y-auto pb-4">
        <?php $renderNav(false); ?>
    </nav>

    <div class="shrink-0 border-t border-white/10 p-2">
        <button type="button" class="nx-rail-link w-[calc(100%-1rem)]" data-nx-sidebar-toggle title="Collapse sidebar ([)">
            <span class="material-symbols-outlined nx-collapse-icon transition-transform">left_panel_close</span>
            <span class="nx-label">Collapse</span>
        </button>
    </div>
</aside>

<!-- Mobile drawer -->
<div id="mobileBackdrop" onclick="toggleMobileSidebar()" class="mobile-backdrop fixed inset-0 bg-slate-950/50 backdrop-blur-xs z-[60] hidden md:hidden"></div>
<aside id="mobileSidebar" class="mobile-sidebar nx-rail fixed top-0 left-0 bottom-0 !h-full w-[284px] z-[70] -translate-x-full transition-transform duration-300 ease-out md:hidden flex-col shadow-2xl" aria-label="Main navigation">
    <div class="nx-rail-brand flex items-center gap-3 px-4 shrink-0">
        <img src="assets/images/nuvis_mark.png" alt="" class="w-9 h-9 rounded-lg object-contain bg-white p-0.5">
        <div class="min-w-0 flex-1 leading-tight">
            <div class="text-[13px] font-semibold text-white truncate"><?= htmlspecialchars($nxClinic) ?></div>
            <div class="text-[11px] text-white/50">Nuvis Medcare X</div>
        </div>
        <button type="button" onclick="toggleMobileSidebar()" class="p-2 -mr-2 rounded-lg text-white/70 hover:text-white hover:bg-white/10" aria-label="Close navigation">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    <a href="register_patient.php" class="nx-rail-cta">
        <span class="material-symbols-outlined text-[20px]">person_add</span>
        <span>Register patient</span>
    </a>
    <nav class="nx-rail-scroll flex-1 overflow-y-auto pb-4">
        <?php $renderNav(true); ?>
    </nav>
    <div class="shrink-0 border-t border-white/10 p-2 space-y-0.5">
        <a href="appointment.php?action=book" class="nx-rail-link"><span class="material-symbols-outlined">calendar_add_on</span><span>Book appointment</span></a>
        <button type="button" onclick="toggleMobileSidebar(); openChangePasswordModal();" class="nx-rail-link w-[calc(100%-1rem)]"><span class="material-symbols-outlined">key</span><span>Change password</span></button>
        <a href="actions/logout.php" class="nx-rail-link"><span class="material-symbols-outlined">logout</span><span>Sign out</span></a>
    </div>
</aside>

<script>
function toggleMobileSidebar() {
    var sidebar = document.getElementById('mobileSidebar');
    var backdrop = document.getElementById('mobileBackdrop');
    var open = sidebar.classList.contains('-translate-x-full');
    sidebar.classList.toggle('-translate-x-full', !open);
    sidebar.classList.toggle('translate-x-0', open);
    backdrop.classList.toggle('hidden', !open);
    backdrop.style.setProperty('display', open ? 'block' : 'none', 'important');
    document.body.style.overflow = open ? 'hidden' : '';
}
</script>
