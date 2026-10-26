<?php
/**
 * Common Header Layout Component with Mobile Responsiveness
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';

$pdo = getDB();
$activePage = $activePage ?? 'dashboard';

// Authentication + role access for this page (see includes/access.php)
requireAuth();
$pageAccess = $pageAccess ?? (NX_PAGE_ACCESS[$activePage] ?? null);
if ($pageAccess) {
    requireAccess(...$pageAccess);
}
$toast = getToast();

// Get doctors for current tenant context selector
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$doctorsStmt = $pdo->prepare("SELECT * FROM doctors WHERE tenant_id = ? OR tenant_id IS NULL ORDER BY name ASC");
$doctorsStmt->execute([$currentTenantId]);
$doctors = $doctorsStmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($doctors)) {
    $allDocsStmt = $pdo->query("SELECT * FROM doctors ORDER BY name ASC");
    $doctors = $allDocsStmt ? $allDocsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}
$currentDoctorId = $_SESSION['current_doctor_id'] ?? ($doctors[0]['id'] ?? 'doc-1');
$currentDoctor = array_filter($doctors, fn($d) => $d['id'] === $currentDoctorId);
$currentDoctor = reset($currentDoctor) ?: ($doctors[0] ?? ['id' => 'doc-1', 'name' => 'Dr. Sarah Jenkins', 'specialty' => 'Internal Medicine']);

// The clinic currently being worked in (see includes/settings.php)
$activeClinic = nxClinic();
$activeClinicName = $activeClinic['name'];

require_once __DIR__ . '/nav.php';

$nxUserRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Doctor';
$nxUserName = $_SESSION['user_name'] ?? $currentDoctor['name'] ?? 'Clinician';
$nxNav = nxNavItems($nxUserRole);
$nxActiveNav = null;
foreach ($nxNav as $nxItem) {
    if ($nxItem['id'] === $activePage) { $nxActiveNav = $nxItem; break; }
}
if (!function_exists('nxInitials')) {
    function nxInitials(string $name): string {
        $name = preg_replace('/^(Dr\.?|Mr\.?|Mrs\.?|Ms\.?)\s+/i', '', trim($name));
        $parts = preg_split('/\s+/', $name) ?: [];
        $out = '';
        foreach (array_slice($parts, 0, 2) as $p) { $out .= mb_strtoupper(mb_substr($p, 0, 1)); }
        return $out ?: '?';
    }
}

// Live counters for the sidebar (cheap COUNT queries; never block rendering)
$nxCounts = ['calendar' => 0, 'dashboard' => 0, 'inventory' => 0];
try {
    $c = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND appointment_date = ? AND status NOT IN ('Completed','Cancelled')");
    $c->execute([$currentTenantId, date('Y-m-d')]);
    $nxCounts['calendar'] = (int) $c->fetchColumn();
    $c = $pdo->prepare("SELECT COUNT(*) FROM queue WHERE tenant_id = ? AND status = 'Waiting'");
    $c->execute([$currentTenantId]);
    $nxCounts['dashboard'] = (int) $c->fetchColumn();
} catch (\Throwable $e) {
    error_log('[header] counters: ' . $e->getMessage());
}

// Tenants for the clinic switcher
// Switching the active clinic is a developer-only action
$nxCanSwitchClinic = ($nxUserRole === 'Developer');
$allTenantsHeader = [];
if ($nxCanSwitchClinic) {
    try {
        $tenantServiceHeader = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\TenantService::class);
        $allTenantsHeader = $tenantServiceHeader->getAllTenants();
    } catch (\Throwable $e) {}
}

$nxAvatar = $currentDoctor['avatar'] ?? '';
$nxCsrf = htmlspecialchars(getCsrfToken());
?>
<!DOCTYPE html>
<html class="h-full" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <meta name="csrf-token" content="<?= $nxCsrf ?>"/>
    <title><?= htmlspecialchars($pageTitle ?? 'Nuvis Medcare X') ?></title>
    <?php include __DIR__ . '/theme_head.php'; ?>
    <script>
      window.NUVIS_VERSION = <?= json_encode(AppVersion::get('full')) ?>;
      window.NX_CONTEXT = <?= json_encode([
          'tenant' => $currentTenantId,
          'user' => $nxUserName,
          'role' => $nxUserRole,
          'nav' => array_map(fn($n) => ['label' => $n['label'], 'url' => $n['url'], 'icon' => $n['icon'], 'keywords' => $n['keywords'] ?? ''], $nxNav),
          'toast' => $toast ?: null,
          'can' => array_map(fn($m) => nxAccess()->level($nxUserRole, $m), array_combine(array_keys(\ClinicFlow\Services\AccessControl::MODULES), array_keys(\ClinicFlow\Services\AccessControl::MODULES))),
      ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="assets/js/app.js?v=<?= $nxAssetVersion ?>" defer></script>
</head>
<body class="min-h-full font-sans text-slate-900 bg-background">
<a href="#nx-main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[200] focus:bg-white focus:px-4 focus:py-2 focus:rounded-lg focus:shadow-pop">Skip to content</a>

<div class="flex min-h-screen">
<?php include __DIR__ . '/sidebar.php'; ?>

<div class="flex-1 min-w-0 flex flex-col">
<!-- Top bar -->
<header class="nx-header sticky top-0 z-40">
    <div class="h-full flex items-center gap-3 px-3 sm:px-6">
        <button type="button" onclick="toggleMobileSidebar()" aria-label="Open navigation" class="nx-icon-btn md:hidden">
            <span class="material-symbols-outlined">menu</span>
        </button>

        <div class="min-w-0 hidden sm:block">
            <div class="text-[15px] font-semibold text-slate-900 truncate leading-tight"><?= htmlspecialchars($nxActiveNav['label'] ?? ($activePage === 'register-patient' ? 'Register patient' : 'Nuvis Medcare X')) ?></div>
            <div class="text-xs text-slate-500 truncate leading-tight"><?= htmlspecialchars($activeClinicName) ?></div>
        </div>

        <!-- Search / command palette trigger -->
        <button type="button" data-nx-palette-open class="nx-search-trigger min-w-0 flex-1 sm:flex-none sm:w-72 lg:w-96 sm:ml-6" aria-label="Search patients and pages">
            <span class="material-symbols-outlined text-[18px]">search</span>
            <span class="truncate">Search patients, MRN, pages…</span>
            <span class="ml-auto hidden sm:flex items-center gap-1"><kbd data-nx-mod>Ctrl</kbd><kbd>K</kbd></span>
        </button>

        <div class="ml-auto flex items-center gap-1 sm:gap-1.5">
            <?php if ($nxCanSwitchClinic && count($allTenantsHeader) > 1): ?>
            <form action="actions/tenant_actions.php" method="POST" class="hidden lg:block" title="Developer only: change which clinic you are working in">
                <input type="hidden" name="csrf_token" value="<?= $nxCsrf ?>">
                <input type="hidden" name="action" value="switch_tenant">
                <label class="sr-only" for="nx-tenant">Active clinic</label>
                <select id="nx-tenant" name="tenant_id" onchange="this.form.submit()" class="h-[38px] max-w-[200px] bg-slate-50 border border-slate-200 text-slate-800 text-[13px] rounded-[10px] pl-3 pr-8 focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500">
                    <?php foreach ($allTenantsHeader as $tn): ?>
                        <option value="<?= htmlspecialchars($tn['id']) ?>" <?= $tn['id'] === $currentTenantId ? 'selected' : '' ?>><?= htmlspecialchars($tn['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>

            <button type="button" class="nx-icon-btn hidden sm:inline-flex" data-nx-theme-toggle aria-label="Switch colour theme" title="Switch theme (Shift+D)">
                <span class="material-symbols-outlined" data-nx-theme-icon>dark_mode</span>
            </button>

            <!-- Notifications -->
            <div class="relative">
                <button type="button" id="notif-bell-btn" class="nx-icon-btn" aria-haspopup="true" aria-expanded="false" aria-controls="notif-dropdown" aria-label="Notifications">
                    <span class="material-symbols-outlined">notifications</span>
                    <span id="notif-badge" class="hidden absolute top-1 right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-600 text-white text-[10px] font-bold leading-[18px] text-center ring-2 ring-white"></span>
                </button>
                <div id="notif-dropdown" class="nx-menu w-[min(380px,calc(100vw-24px))] p-0 overflow-hidden" hidden>
                    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">Notifications</div>
                            <div class="text-xs text-slate-500" id="notif-status">Live updates for this clinic</div>
                        </div>
                        <button type="button" data-nx-notif-clear class="text-xs font-medium text-blue-700 hover:underline">Clear all</button>
                    </div>
                    <div id="notif-list" class="max-h-[360px] overflow-y-auto"></div>
                    <div id="notif-empty" class="nx-empty">
                        <span class="material-symbols-outlined">notifications_off</span>
                        <p>You're all caught up. New registrations and bookings appear here.</p>
                    </div>
                </div>
            </div>

            <button type="button" class="nx-icon-btn hidden sm:inline-flex" data-nx-help-open aria-label="Keyboard shortcuts and help" title="Shortcuts (?)">
                <span class="material-symbols-outlined">help</span>
            </button>

            <!-- Profile menu -->
            <div class="relative pl-1 sm:pl-2 sm:ml-1 sm:border-l border-slate-200">
                <button type="button" id="nx-profile-btn" class="flex items-center gap-2.5 rounded-xl p-1 pr-2 hover:bg-slate-100 transition" aria-haspopup="true" aria-expanded="false" aria-controls="nx-profile-menu">
                    <span class="nx-avatar w-8 h-8 text-xs">
                        <?php if ($nxAvatar): ?><img src="<?= htmlspecialchars($nxAvatar) ?>" alt="" onerror="this.remove()"><?php endif; ?>
                        <span><?= htmlspecialchars(nxInitials($currentDoctor['name'] ?? $nxUserName)) ?></span>
                    </span>
                    <span class="hidden lg:block text-left leading-tight">
                        <span class="block text-[13px] font-semibold text-slate-900 max-w-[160px] truncate"><?= htmlspecialchars($currentDoctor['name'] ?? $nxUserName) ?></span>
                        <span class="block text-[11px] text-slate-500 max-w-[160px] truncate"><?= htmlspecialchars($currentDoctor['specialty'] ?? $nxUserRole) ?></span>
                    </span>
                    <span class="material-symbols-outlined text-[18px] text-slate-400 hidden lg:inline-block">expand_more</span>
                </button>
                <div id="nx-profile-menu" class="nx-menu p-1.5" hidden>
                    <div class="px-3 pt-2 pb-3 border-b border-slate-200 mb-1.5">
                        <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($nxUserName) ?></div>
                        <div class="text-xs text-slate-500"><?= htmlspecialchars($nxUserRole) ?> · <?= htmlspecialchars($activeClinicName) ?></div>
                    </div>
                    <?php if (count($doctors) > 1): ?>
                    <form action="actions/set_doctor.php" method="POST" class="px-3 pb-2">
                        <input type="hidden" name="csrf_token" value="<?= $nxCsrf ?>">
                        <label for="nx-doctor" class="block text-xs font-medium text-slate-500 mb-1">Acting clinician</label>
                        <select id="nx-doctor" name="doctor_id" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-200 text-[13px] text-slate-900 rounded-lg py-1.5 focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500">
                            <?php foreach ($doctors as $doc): ?>
                                <option value="<?= htmlspecialchars($doc['id']) ?>" <?= $doc['id'] === $currentDoctor['id'] ? 'selected' : '' ?>><?= htmlspecialchars($doc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>
                    <?php if ($nxCanSwitchClinic && count($allTenantsHeader) > 1): ?>
                    <form action="actions/tenant_actions.php" method="POST" class="px-3 pb-2 lg:hidden">
                        <input type="hidden" name="csrf_token" value="<?= $nxCsrf ?>">
                        <input type="hidden" name="action" value="switch_tenant">
                        <label for="nx-tenant-m" class="block text-xs font-medium text-slate-500 mb-1">Clinic</label>
                        <select id="nx-tenant-m" name="tenant_id" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-200 text-[13px] text-slate-900 rounded-lg py-1.5">
                            <?php foreach ($allTenantsHeader as $tn): ?>
                                <option value="<?= htmlspecialchars($tn['id']) ?>" <?= $tn['id'] === $currentTenantId ? 'selected' : '' ?>><?= htmlspecialchars($tn['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>
                    <button type="button" class="nx-menu-item" onclick="openChangePasswordModal()"><span class="material-symbols-outlined">key</span>Change password</button>
                    <a href="settings.php" class="nx-menu-item"><span class="material-symbols-outlined">tune</span>Settings</a>
                    <button type="button" class="nx-menu-item sm:hidden" data-nx-theme-toggle><span class="material-symbols-outlined" data-nx-theme-icon>dark_mode</span>Switch theme</button>
                    <button type="button" class="nx-menu-item hidden sm:flex" data-nx-help-open><span class="material-symbols-outlined">keyboard</span>Keyboard shortcuts</button>
                    <div class="my-1.5 border-t border-slate-200"></div>
                    <a href="actions/logout.php" class="nx-menu-item is-danger"><span class="material-symbols-outlined">logout</span>Sign out</a>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Change password -->
<div id="modal-change-password-global" class="fixed inset-0 z-[80] bg-slate-950/50 backdrop-blur-xs flex items-center justify-center hidden p-4" data-nx-modal>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-pop max-w-md w-full overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="nx-cp-title">
        <div class="px-6 pt-5 pb-4 flex items-start justify-between">
            <div>
                <h3 id="nx-cp-title" class="font-semibold text-base text-slate-900">Change password</h3>
                <p class="text-xs text-slate-500 mt-0.5">At least 8 characters, with one capital letter and one number.</p>
            </div>
            <button type="button" onclick="closeChangePasswordModal()" class="nx-icon-btn -mr-2 -mt-1" aria-label="Close"><span class="material-symbols-outlined">close</span></button>
        </div>
        <form action="actions/change_password.php" method="POST" class="px-6 pb-6 space-y-4" data-nx-password-form>
            <input type="hidden" name="csrf_token" value="<?= $nxCsrf ?>">
            <input type="text" name="username" value="<?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>" autocomplete="username" class="hidden" tabindex="-1" aria-hidden="true">
            <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '../index.php') ?>">
            <div>
                <label class="form-label" for="nx-cp-current">Current password</label>
                <input id="nx-cp-current" type="password" name="current_password" required autocomplete="current-password" class="form-input">
            </div>
            <div>
                <label class="form-label" for="nx-cp-new">New password</label>
                <input id="nx-cp-new" type="password" name="new_password" required minlength="8" autocomplete="new-password" pattern="(?=.*[A-Z])(?=.*\d).{8,}" title="At least 8 characters, one capital letter and one number" class="form-input">
                <ul class="mt-2 grid grid-cols-3 gap-1.5 text-[11px] text-slate-500" data-nx-pw-rules>
                    <li data-rule="len" class="flex items-center gap-1"><span class="nx-dot bg-slate-300"></span>8+ characters</li>
                    <li data-rule="upper" class="flex items-center gap-1"><span class="nx-dot bg-slate-300"></span>Capital letter</li>
                    <li data-rule="digit" class="flex items-center gap-1"><span class="nx-dot bg-slate-300"></span>Number</li>
                </ul>
            </div>
            <div>
                <label class="form-label" for="nx-cp-confirm">Confirm new password</label>
                <input id="nx-cp-confirm" type="password" name="confirm_password" required minlength="8" autocomplete="new-password" class="form-input">
                <p class="hidden mt-1 text-xs text-red-600" data-nx-pw-mismatch>Passwords don't match yet.</p>
            </div>
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closeChangePasswordModal()" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-primary">Update password</button>
            </div>
        </form>
    </div>
</div>

<script>
function openChangePasswordModal() { if (window.NX) NX.openModal('modal-change-password-global'); else document.getElementById('modal-change-password-global').classList.remove('hidden'); }
function closeChangePasswordModal() { if (window.NX) NX.closeModal('modal-change-password-global'); else document.getElementById('modal-change-password-global').classList.add('hidden'); }

// Report uncaught browser errors to the server log
window.onerror = function (msg, url, lineNo, columnNo, error) {
    try {
        fetch('actions/log_js_error.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: msg, url: url, line: lineNo, column: columnNo, stack: error && error.stack ? error.stack : '', userAgent: navigator.userAgent }) }).catch(function () {});
    } catch (e) {}
    return false;
};
window.onunhandledrejection = function (event) {
    try {
        fetch('actions/log_js_error.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: 'Unhandled Promise Rejection: ' + (event.reason ? (event.reason.message || event.reason) : 'Unknown reason'), url: window.location.href, stack: event.reason && event.reason.stack ? event.reason.stack : '', userAgent: navigator.userAgent }) }).catch(function () {});
    } catch (e) {}
};
</script>

<main id="nx-main" class="flex-1 w-full max-w-[1680px] mx-auto px-3 py-4 sm:px-6 sm:py-6 lg:px-8">
