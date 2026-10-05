<?php
require_once __DIR__ . '/includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Doctor';
if ($currentUserRole !== 'Developer') {
    setToast("Access Denied", "Developer Options are strictly restricted to system developers.", "error");
    header("Location: index.php");
    exit;
}

$pageTitle = "Developer Options - NuvisMedcareX";
$activePage = "developer";
require_once __DIR__ . '/includes/pagination.php';
include __DIR__ . '/includes/header.php';

$pdo = getDB();
$settingsRows = $pdo->query("SELECT * FROM clinic_settings")->fetchAll();
$settings = [];
foreach ($settingsRows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$tenantService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\TenantService::class);
$tenantsList = $tenantService->getAllTenants();
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$labOrderService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\LabOrderService::class);
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-on-surface flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-2xl">code</span>
            <span>Developer Workspace & System Diagnostics</span>
        </h1>
        <p class="text-xs text-outline font-medium">Independent developer controls: Git continuous deployment, error logs, database diagnostics, and environment configuration</p>
    </div>

    <div class="flex items-center gap-2">
        <span class="px-3 py-1 bg-amber-100 text-amber-900 border border-amber-300 rounded-xl text-xs font-bold flex items-center gap-1.5 shadow-2xs">
            <span class="material-symbols-outlined text-sm text-amber-700">shield</span>
            <span>Developer Level Access</span>
        </span>
    </div>
</div>

<!-- Developer Child Tabs Navigation -->
<div class="flex border-b border-outline-variant/30 mb-6 space-x-2 text-xs font-bold flex-wrap">
    <button type="button" onclick="switchDevTab('git')" id="dev-tab-btn-git" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 bg-primary text-white">
        <span class="material-symbols-outlined text-base">update</span>
        <span>Git Updates & Terminal</span>
    </button>
    <button type="button" onclick="switchDevTab('tenants')" id="dev-tab-btn-tenants" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high">
        <span class="material-symbols-outlined text-base">apartment</span>
        <span>Multi-Tenancy Clinics</span>
    </button>
    <button type="button" onclick="switchDevTab('vms')" id="dev-tab-btn-vms" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high">
        <span class="material-symbols-outlined text-base">point_of_sale</span>
        <span>VMS Fiscal Settings</span>
    </button>
    <button type="button" onclick="switchDevTab('lab')" id="dev-tab-btn-lab" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high">
        <span class="material-symbols-outlined text-base">science</span>
        <span>Lab Catalog & Customization</span>
    </button>
    <button type="button" onclick="switchDevTab('pagination')" id="dev-tab-btn-pagination" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high">
        <span class="material-symbols-outlined text-base">format_list_numbered</span>
        <span>Pagination Settings</span>
    </button>
    <button type="button" onclick="switchDevTab('rbac')" id="dev-tab-btn-rbac" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high">
        <span class="material-symbols-outlined text-base">admin_panel_settings</span>
        <span>Role Permissions & Access Control</span>
    </button>
    <button type="button" onclick="switchDevTab('logs')" id="dev-tab-btn-logs" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high">
        <span class="material-symbols-outlined text-base">bug_report</span>
        <span>Error Logs & Diagnostics</span>
    </button>
</div>

<div class="space-y-6">
    <!-- 1. System Updates (Git Updater) -->
    <div id="dev-panel-git" class="space-y-6">
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-outline-variant/20 pb-3">
                <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">update</span>
                    <span>Continuous System Updates (1-Click Git Updater)</span>
                </h2>
                <div class="flex gap-2">
                    <button type="button" onclick="switchGitSubTab('console')" id="btn-git-console" class="px-3 py-1 bg-primary text-white text-[11px] font-bold rounded-lg transition">Terminal Status</button>
                    <button type="button" onclick="switchGitSubTab('history')" id="btn-git-history" class="px-3 py-1 bg-surface-container-high text-on-surface text-[11px] font-bold rounded-lg transition">Commit History</button>
                </div>
            </div>

            <!-- Terminal Console Tab -->
            <div id="git-tab-console" class="space-y-4 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Git Repository Terminal Status</label>
                    <div id="git-status-console" class="font-mono text-xs bg-slate-900 text-emerald-400 p-4 rounded-xl border border-slate-800 h-40 overflow-y-auto whitespace-pre-wrap leading-relaxed shadow-inner">
                        Querying Git repository status...
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-2">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Executable Path</label>
                        <input type="text" id="git_path" value="<?= htmlspecialchars($settings['git_path'] ?? 'git') ?>" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-mono text-xs">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Repository Directory</label>
                        <input type="text" id="git_repo_dir" value="<?= htmlspecialchars($settings['git_repo_dir'] ?? __DIR__) ?>" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-mono text-xs">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Update Branch</label>
                        <select id="update_branch" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-bold">
                            <option value="main">main</option>
                            <option value="master">master</option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-outline-variant/20">
                    <button type="button" onclick="saveGitSettings()" class="px-4 py-2 bg-surface-container-high text-on-surface text-xs font-semibold rounded-xl hover:bg-surface-variant transition flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm">settings</span>
                        <span>Save Git Settings</span>
                    </button>

                    <div class="flex gap-2">
                        <button type="button" onclick="refreshGitStatus()" class="px-4 py-2 bg-slate-200 text-slate-800 text-xs font-semibold rounded-xl hover:bg-slate-300 transition flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-sm">sync</span>
                            <span>Check Status</span>
                        </button>
                        <button type="button" onclick="triggerGitPull()" class="px-5 py-2 bg-emerald-600 text-white text-xs font-bold rounded-xl hover:bg-emerald-700 transition shadow-xs flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-sm">download</span>
                            <span>Pull Updates from Git</span>
                        </button>
                    </div>
                </div>

                <!-- Initialize / Link Git Repo Form (if missing) -->
                <div id="git-init-card" class="mt-4 p-4 rounded-xl bg-amber-50 border border-amber-200 space-y-3">
                    <p class="font-bold text-amber-900 text-xs flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm text-amber-700">link</span>
                        <span>Link Remote Git Repository</span>
                    </p>
                    <div class="flex gap-2">
                        <input type="text" id="git_remote_url" placeholder="https://github.com/username/repository.git" value="<?= htmlspecialchars($settings['git_remote_url'] ?? '') ?>" class="flex-1 bg-white px-3 py-2 rounded-xl border border-amber-300 text-xs font-mono">
                        <button type="button" onclick="initializeGitRepo()" class="px-4 py-2 bg-amber-800 text-white font-bold rounded-xl text-xs hover:bg-amber-900 transition">
                            Link & Sync
                        </button>
                    </div>
                </div>
            </div>

            <!-- Commit History Tab -->
            <div id="git-tab-history" class="hidden space-y-3 text-xs">
                <div id="git-commit-list" class="space-y-2 max-h-60 overflow-y-auto">
                    <p class="text-slate-500 italic">Loading commit logs...</p>
                </div>
            </div>
        </div>
    </div> <!-- End dev-panel-git -->

    <!-- Multi-Tenancy Clinics Panel -->
    <div id="dev-panel-tenants" class="hidden space-y-6">
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-outline-variant/20">
                <div>
                    <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">apartment</span>
                        <span>Multi-Clinic Tenancy Directory</span>
                    </h2>
                    <p class="text-xs text-outline font-medium mt-0.5">Manage multi-tenant clinic instances, isolation profiles, and switch active clinic context. Active Context: <code class="text-primary font-bold font-mono"><?= htmlspecialchars($currentTenantId) ?></code></p>
                </div>

                <button type="button" onclick="openTenantModal()" class="px-4 py-2 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">add_location_alt</span>
                    <span>Add New Clinic Tenant</span>
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($tenantsList as $tnt):
                    $isCurrent = ($tnt['id'] === $currentTenantId);
                ?>
                    <div class="p-4 rounded-2xl bg-surface-container-low border border-outline-variant/30 flex flex-col justify-between gap-3 text-xs transition hover:shadow-md <?= $isCurrent ? 'ring-2 ring-primary/60 bg-primary/5' : '' ?>">
                        <div class="space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="font-bold text-on-surface text-sm flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-base text-primary">domain</span>
                                    <span><?= htmlspecialchars($tnt['name']) ?></span>
                                </h3>
                                <?php if ($isCurrent): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-primary text-white flex items-center gap-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>
                                        <span>Active Context</span>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="p-2.5 rounded-xl bg-surface-container-lowest border border-outline-variant/20 grid grid-cols-2 gap-2 text-[10px] font-mono text-slate-600">
                                <div><span class="text-slate-400">Code:</span> <?= htmlspecialchars($tnt['code']) ?></div>
                                <div><span class="text-slate-400">Plan:</span> <span class="uppercase font-bold text-primary"><?= htmlspecialchars($tnt['plan']) ?></span></div>
                                <div><span class="text-slate-400">Status:</span> <?= htmlspecialchars($tnt['status']) ?></div>
                                <div><span class="text-slate-400">Drive Cap:</span> <span class="font-bold text-slate-800"><?= ((int)($tnt['storage_limit_mb'] ?? 500) >= 1024) ? round(($tnt['storage_limit_mb'] ?? 500) / 1024, 1) . ' GB' : ($tnt['storage_limit_mb'] ?? 500) . ' MB' ?></span></div>
                                <div class="col-span-2"><span class="text-slate-400">Tenant ID:</span> <?= htmlspecialchars($tnt['id']) ?></div>
                            </div>

                            <p class="text-[11px] text-slate-600 flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs text-slate-400">location_on</span>
                                <span><?= htmlspecialchars($tnt['address'] ?: 'No address specified') ?></span>
                            </p>
                        </div>

                        <div class="pt-3 border-t border-outline-variant/20 flex items-center justify-between gap-2">
                            <form action="actions/tenant_actions.php" method="POST" class="w-full">
                                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                <input type="hidden" name="action" value="switch_tenant">
                                <input type="hidden" name="tenant_id" value="<?= htmlspecialchars($tnt['id']) ?>">
                                <button type="submit" <?= $isCurrent ? 'disabled' : '' ?> class="w-full py-2 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 <?= $isCurrent ? 'bg-slate-200 text-slate-500 cursor-not-allowed' : 'bg-primary text-white hover:bg-primary/90' ?>">
                                    <span class="material-symbols-outlined text-base">swap_horiz</span>
                                    <span><?= $isCurrent ? 'Current Active Clinic' : 'Switch to This Clinic' ?></span>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div> <!-- End dev-panel-tenants -->

    <!-- Lab Catalog Customization Panel -->
    <div id="dev-panel-lab" class="hidden space-y-6">
        <?php
        $labCatalog = $labOrderService->getLabCatalog();
        $labCatalogJson = json_encode($labCatalog, JSON_PRETTY_PRINT);
        ?>
        <form action="actions/admin_save_settings.php" method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <div class="flex items-center justify-between border-b border-outline-variant/20 pb-3">
                <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">science</span>
                    <span>Customizable Laboratory & Diagnostic Test Catalog</span>
                </h2>
                <span class="text-xs text-outline font-medium">Developer configuration for clinic lab procedures and categories</span>
            </div>

            <div class="space-y-2">
                <label class="block font-bold text-slate-700 text-xs mb-1">Lab Catalog JSON Configuration</label>
                <textarea name="lab_catalog_json" rows="14" class="w-full bg-slate-950 text-emerald-400 p-4 rounded-xl font-mono text-xs border border-slate-800 leading-relaxed shadow-inner focus:outline-none focus:ring-2 focus:ring-primary"><?= htmlspecialchars($labCatalogJson) ?></textarea>
                <p class="text-[11px] text-slate-500">Developers can modify categories and available test procedure templates in JSON format.</p>
            </div>

            <div class="flex justify-end pt-3 border-t border-outline-variant/20">
                <button type="submit" class="px-6 py-2.5 bg-primary text-white text-xs font-semibold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span>Save Lab Catalog Configuration</span>
                </button>
            </div>
        </form>
    </div> <!-- End dev-panel-lab -->

    <!-- Pagination Settings Panel -->
    <div id="dev-panel-pagination" class="hidden space-y-6">
        <form action="actions/admin_save_settings.php" method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-base">format_list_numbered</span>
                <span>Global Pagination & Table Records Customization</span>
            </h2>

            <div class="space-y-4 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Default Items Per Page (Limit)</label>
                    <select name="default_pagination_limit" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-bold text-primary max-w-xs">
                        <?php
                        $currDefLimit = (int)($settings['default_pagination_limit'] ?? 10);
                        foreach ([5, 10, 25, 50, 100] as $optLimit): ?>
                            <option value="<?= $optLimit ?>" <?= $currDefLimit === $optLimit ? 'selected' : '' ?>><?= $optLimit ?> items per page</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[11px] text-outline mt-1">Configures default record limit across Patients, Inventory, Billing, and User Directory tables.</p>
                </div>
            </div>

            <div class="flex justify-end pt-3 border-t border-outline-variant/20">
                <button type="submit" class="px-6 py-2.5 bg-primary text-white text-xs font-semibold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span>Save Pagination Settings</span>
                </button>
            </div>
        </form>
    </div> <!-- End dev-panel-pagination -->

    <!-- VMS Fiscal Settings Panel -->
    <div id="dev-panel-vms" class="hidden space-y-6">
        <form action="actions/admin_save_settings.php" method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-base">point_of_sale</span>
                <span>FRCS VAT Monitoring System (VMS Phase 3) Configuration</span>
            </h2>

            <div class="flex items-center gap-2 p-3 bg-slate-50 border border-slate-200 rounded-xl">
                <input type="checkbox" id="vms_enabled_dev" name="vms_enabled" value="1" <?= ($settings['vms_enabled'] ?? '1') === '1' ? 'checked' : '' ?> class="w-4 h-4 text-primary rounded focus:ring-primary">
                <label for="vms_enabled_dev" class="font-bold text-on-surface text-xs">Enable FRCS VMS Fiscalization for all created invoices</label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Taxpayer Seller TIN <span class="text-red-500">*</span></label>
                    <input type="text" name="vms_seller_tin" value="<?= htmlspecialchars($settings['vms_seller_tin'] ?? '502579006') ?>" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-bold">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Accredited POS Number <span class="text-red-500">*</span></label>
                    <input type="text" name="vms_pos_number" value="<?= htmlspecialchars($settings['vms_pos_number'] ?? 'ASDF238/1.2') ?>" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-bold">
                </div>

                <div class="md:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">Business Location Address <span class="text-red-500">*</span></label>
                    <input type="text" name="vms_business_location" value="<?= htmlspecialchars($settings['vms_business_location'] ?? 'Suva Central Clinic, 2 Woodstand Road, Suva') ?>" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
                </div>

                <div class="md:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">SDC / VMS Sandbox API Base URL <span class="text-red-500">*</span></label>
                    <input type="url" name="vms_sdc_url" value="<?= htmlspecialchars($settings['vms_sdc_url'] ?? 'https://tap.sandbox.vms.frcs.org.fj') ?>" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium">
                </div>
            </div>

            <hr class="border-outline-variant/20">

            <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider">VMS Tax Label Rates (%)</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Label A (Standard VAT %)</label>
                    <input type="number" step="0.01" name="vms_tax_rate_a" value="<?= htmlspecialchars($settings['vms_tax_rate_a'] ?? '15.00') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Label E (Exempt %)</label>
                    <input type="number" step="0.01" name="vms_tax_rate_e" value="<?= htmlspecialchars($settings['vms_tax_rate_e'] ?? '0.00') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Label F (Zero-Rated %)</label>
                    <input type="number" step="0.01" name="vms_tax_rate_f" value="<?= htmlspecialchars($settings['vms_tax_rate_f'] ?? '0.00') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Label P (Special Tax %)</label>
                    <input type="number" step="0.01" name="vms_tax_rate_p" value="<?= htmlspecialchars($settings['vms_tax_rate_p'] ?? '0.25') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-bold">
                </div>
            </div>

            <div class="flex justify-end pt-3">
                <button type="submit" class="px-6 py-2.5 bg-primary text-white text-xs font-semibold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span>Save VMS Fiscal Settings</span>
                </button>
            </div>
        </form>
    </div> <!-- End dev-panel-vms -->

    <!-- 2. Role Permissions & User Access Level Matrix Module -->
    <div id="dev-panel-rbac" class="hidden space-y-6">
    <?php
    $defaultRoles = ['Developer', 'Administrator', 'Doctor', 'Nurse', 'Receptionist'];
    $customRolesJson = $settings['rbac_custom_roles'] ?? '[]';
    $customRoles = json_decode($customRolesJson, true) ?: [];
    $roles = array_unique(array_merge($defaultRoles, $customRoles));
    $defaultModules = [
        'patients' => 'Patients & Clinical Records',
        'billing' => 'Billing & Financial Invoices',
        'inventory' => 'Inventory & Pharmacy',
        'admin' => 'Administrator & User Management',
        'developer' => 'Developer Workspace & Tools'
    ];
    $customModulesJson = $settings['rbac_custom_modules'] ?? '{}';
    $customModules = json_decode($customModulesJson, true) ?: [];
    $modules = array_merge($defaultModules, $customModules);

    $savedRbacJson = $settings['rbac_group_permissions'] ?? '{}';
    $savedRbac = json_decode($savedRbacJson, true) ?: [];

    $usersStmt = $pdo->query("SELECT id, name, email, role, is_active FROM doctors ORDER BY name ASC");
    $allUsers = $usersStmt->fetchAll();

    $totalUsersCount = count($allUsers);
    $paginationUsers = getPaginationParams(10, [5, 10, 25, 50, 100], $pdo);
    $paginatedUsers = array_slice($allUsers, $paginationUsers['offset'], $paginationUsers['limit']);
    ?>
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
        <div class="flex items-center justify-between border-b border-outline-variant/20 pb-3">
            <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-base">admin_panel_settings</span>
                <span>Role Permissions & User Access Level Management</span>
            </h2>
        </div>

        <form action="actions/admin_save_settings.php" method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <!-- Group Read/Write/Delete Matrix -->
            <div class="overflow-x-auto">
                <p class="text-xs font-bold text-slate-800 mb-2">Group Access Matrix (Read / Write / Delete Permissions):</p>
                <table class="w-full text-left text-xs border border-outline-variant/30 rounded-xl overflow-hidden">
                    <thead class="bg-surface-container text-outline uppercase font-semibold text-[10px]">
                        <tr>
                            <th class="py-2.5 px-3">Role / Group</th>
                            <?php foreach ($modules as $modKey => $modLabel): ?>
                                <th class="py-2.5 px-3 text-center"><?= htmlspecialchars($modLabel) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20">
                        <?php foreach ($roles as $r): ?>
                            <tr class="hover:bg-surface-container-low/50">
                                <td class="py-2.5 px-3 font-bold text-on-surface"><?= htmlspecialchars($r) ?></td>
                                <?php foreach ($modules as $modKey => $modLabel):
                                    $canRead = $savedRbac[$r][$modKey . '_read'] ?? ($r === 'Developer' || $r === 'Administrator' || ($r === 'Doctor' && $modKey !== 'admin' && $modKey !== 'developer'));
                                    $canWrite = $savedRbac[$r][$modKey . '_write'] ?? ($r === 'Developer' || ($r === 'Administrator' && $modKey !== 'developer'));
                                    $canDelete = $savedRbac[$r][$modKey . '_delete'] ?? ($r === 'Developer' || ($r === 'Administrator' && $modKey !== 'developer'));
                                ?>
                                    <td class="py-2.5 px-3 text-center">
                                        <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                            <label class="inline-flex items-center gap-0.5 cursor-pointer">
                                                <input type="checkbox" name="rbac[<?= $r ?>][<?= $modKey ?>_read]" value="1" <?= $canRead ? 'checked' : '' ?> class="rounded text-primary focus:ring-primary">
                                                <span class="text-[10px] font-medium text-slate-600">Read</span>
                                            </label>
                                            <label class="inline-flex items-center gap-0.5 cursor-pointer">
                                                <input type="checkbox" name="rbac[<?= $r ?>][<?= $modKey ?>_write]" value="1" <?= $canWrite ? 'checked' : '' ?> class="rounded text-primary focus:ring-primary">
                                                <span class="text-[10px] font-medium text-slate-600">Write</span>
                                            </label>
                                            <label class="inline-flex items-center gap-0.5 cursor-pointer">
                                                <input type="checkbox" name="rbac[<?= $r ?>][<?= $modKey ?>_delete]" value="1" <?= $canDelete ? 'checked' : '' ?> class="rounded text-rose-600 focus:ring-rose-600">
                                                <span class="text-[10px] font-medium text-rose-700">Delete</span>
                                            </label>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="px-5 py-2 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition shadow-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span>Save Role Permissions Matrix</span>
                </button>
            </div>
        </form>

        <!-- Custom Role Add & Delete Controls -->
        <div class="pt-4 border-t border-outline-variant/20 space-y-3">
            <p class="text-xs font-bold text-slate-800">Dynamic User Role Management:</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Add New Role -->
                <form action="actions/admin_save_settings.php" method="POST" class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 space-y-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="add_custom_role">
                    <span class="text-xs font-bold text-primary block">Add New Custom Role</span>
                    <div class="flex gap-2">
                        <input type="text" name="role_name" required placeholder="e.g. Lab Technician, Pharmacist" class="w-full bg-white px-2.5 py-1.5 rounded-lg border border-outline-variant/40 text-xs">
                        <button type="submit" class="px-3 py-1.5 bg-primary text-white rounded-lg text-xs font-bold hover:bg-primary/90 shrink-0">Add Role</button>
                    </div>
                </form>

                <!-- Delete Custom Role -->
                <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 space-y-2">
                    <span class="text-xs font-bold text-rose-700 block">Delete Custom Role</span>
                    <?php if (empty($customRoles)): ?>
                        <p class="text-xs text-outline italic">No dynamic custom roles defined yet.</p>
                    <?php else: ?>
                        <div class="space-y-1">
                            <?php foreach ($customRoles as $crName): ?>
                                <div class="flex items-center justify-between p-1.5 bg-white rounded-lg border border-outline-variant/30 text-xs">
                                    <span class="font-bold text-on-surface"><?= htmlspecialchars($crName) ?></span>
                                    <form action="actions/admin_save_settings.php" method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="action" value="delete_custom_role">
                                        <input type="hidden" name="role_name" value="<?= htmlspecialchars($crName) ?>">
                                        <button type="submit" onclick="return confirm('Delete role \'<?= htmlspecialchars($crName) ?>\'?')" class="px-2 py-0.5 bg-rose-600 text-white rounded text-[10px] font-bold">Delete Role</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Custom Permission/Module Add & Delete Controls -->
        <div class="pt-4 border-t border-outline-variant/20 space-y-3">
            <p class="text-xs font-bold text-slate-800">Dynamic Custom Permission / Module Management:</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Add New Module -->
                <form action="actions/admin_save_settings.php" method="POST" class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 space-y-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="add_custom_permission">
                    <span class="text-xs font-bold text-primary block">Add New Custom Permission/Module</span>
                    <div class="flex gap-2">
                        <input type="text" name="permission_key" required placeholder="e.g. lab_reports" class="w-1/2 bg-white px-2.5 py-1.5 rounded-lg border border-outline-variant/40 text-xs">
                        <input type="text" name="permission_label" required placeholder="e.g. Lab Reports & Diagnostics" class="w-1/2 bg-white px-2.5 py-1.5 rounded-lg border border-outline-variant/40 text-xs">
                    </div>
                    <button type="submit" class="px-3 py-1.5 bg-primary text-white rounded-lg text-xs font-bold hover:bg-primary/90">Add Permission</button>
                </form>

                <!-- Delete Custom Module -->
                <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 space-y-2">
                    <span class="text-xs font-bold text-rose-700 block">Delete Custom Permission/Module</span>
                    <?php if (empty($customModules)): ?>
                        <p class="text-xs text-outline italic">No dynamic custom permissions defined yet.</p>
                    <?php else: ?>
                        <div class="space-y-1">
                            <?php foreach ($customModules as $cKey => $cLabel): ?>
                                <div class="flex items-center justify-between p-1.5 bg-white rounded-lg border border-outline-variant/30 text-xs">
                                    <span class="font-bold text-on-surface"><?= htmlspecialchars($cLabel) ?> <span class="text-[10px] text-outline font-mono">(<?= htmlspecialchars($cKey) ?>)</span></span>
                                    <form action="actions/admin_save_settings.php" method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="action" value="delete_custom_permission">
                                        <input type="hidden" name="permission_key" value="<?= htmlspecialchars($cKey) ?>">
                                        <button type="submit" onclick="return confirm('Delete permission \'<?= htmlspecialchars($cKey) ?>\'?')" class="px-2 py-0.5 bg-rose-600 text-white rounded text-[10px] font-bold">Delete</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- User Access Revocation List -->
        <div class="pt-4 border-t border-outline-variant/20 space-y-3">
            <p class="text-xs font-bold text-slate-800">User Access Level Status & Revocation:</p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border border-outline-variant/30 rounded-xl">
                    <thead class="bg-surface-container text-outline uppercase font-semibold text-[10px]">
                        <tr>
                            <th class="py-2.5 px-3">User Name</th>
                            <th class="py-2.5 px-3">Email</th>
                            <th class="py-2.5 px-3">Role</th>
                            <th class="py-2.5 px-3">Status</th>
                            <th class="py-2.5 px-3 text-right">Access Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20">
                        <?php if (empty($paginatedUsers)): ?>
                            <tr>
                                <td colspan="5" class="py-6 text-center text-outline italic">No users found.</td>
                            </tr>
                        <?php else: ?>
                        <?php foreach ($paginatedUsers as $u): ?>
                            <tr class="hover:bg-surface-container-low/50">
                                <td class="py-2.5 px-3 font-bold text-on-surface"><?= htmlspecialchars($u['name']) ?></td>
                                <td class="py-2.5 px-3 text-slate-600"><?= htmlspecialchars($u['email']) ?></td>
                                <td class="py-2.5 px-3 font-semibold"><?= htmlspecialchars($u['role']) ?></td>
                                <td class="py-2.5 px-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= !empty($u['is_active']) ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                        <?= !empty($u['is_active']) ? 'Active' : 'Revoked' ?>
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 text-right">
                                    <?php if ($u['role'] !== 'Developer'): ?>
                                        <form action="actions/admin_save_doctor.php" method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                            <input type="hidden" name="doctor_id" value="<?= htmlspecialchars($u['id']) ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <button type="submit" class="px-2.5 py-1 <?= !empty($u['is_active']) ? 'bg-rose-600 text-white' : 'bg-emerald-600 text-white' ?> rounded-lg text-[10px] font-bold transition">
                                                <?= !empty($u['is_active']) ? 'Revoke Access' : 'Restore Access' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= renderPagination($totalUsersCount, $paginationUsers['page'], $paginationUsers['limit'], 'developer.php', ['dev_tab' => 'rbac']) ?>
        </div>
    </div>

    </div> <!-- End dev-panel-rbac -->

    <!-- 3. Developer Error Logger Module -->
    <div id="dev-panel-logs" class="hidden space-y-6">
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
        <div class="flex items-center justify-between border-b border-outline-variant/20 pb-3">
            <h2 class="text-xs font-bold text-rose-700 uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-base">bug_report</span>
                <span>Developer Error Logger & System Diagnostics</span>
            </h2>
            <div class="flex items-center gap-2">
                <button type="button" onclick="fetchDeveloperErrorLogs()" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold rounded-xl transition flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">refresh</span>
                    <span>Refresh Logs</span>
                </button>
                <button type="button" onclick="clearDeveloperErrorLogs()" class="px-3 py-1.5 bg-rose-100 hover:bg-rose-200 text-rose-800 text-xs font-bold rounded-xl transition flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">delete_sweep</span>
                    <span>Clear Logs</span>
                </button>
            </div>
        </div>

        <div>
            <div id="developer-error-console" class="font-mono text-xs bg-slate-950 text-slate-200 p-4 rounded-xl border border-slate-800 h-64 overflow-y-auto leading-relaxed space-y-2 shadow-inner">
                <p class="text-slate-500 italic">Initializing developer error logger...</p>
            </div>
        </div>
    </div>
    </div>
    </div> <!-- End dev-panel-logs -->
</div>

<script>
function switchDevTab(tab) {
    if (document.getElementById('dev-panel-git')) document.getElementById('dev-panel-git').classList.add('hidden');
    if (document.getElementById('dev-panel-tenants')) document.getElementById('dev-panel-tenants').classList.add('hidden');
    if (document.getElementById('dev-panel-vms')) document.getElementById('dev-panel-vms').classList.add('hidden');
    if (document.getElementById('dev-panel-lab')) document.getElementById('dev-panel-lab').classList.add('hidden');
    if (document.getElementById('dev-panel-pagination')) document.getElementById('dev-panel-pagination').classList.add('hidden');
    if (document.getElementById('dev-panel-rbac')) document.getElementById('dev-panel-rbac').classList.add('hidden');
    if (document.getElementById('dev-panel-logs')) document.getElementById('dev-panel-logs').classList.add('hidden');

    if (document.getElementById('dev-tab-btn-git')) document.getElementById('dev-tab-btn-git').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';
    if (document.getElementById('dev-tab-btn-tenants')) document.getElementById('dev-tab-btn-tenants').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';
    if (document.getElementById('dev-tab-btn-vms')) document.getElementById('dev-tab-btn-vms').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';
    if (document.getElementById('dev-tab-btn-lab')) document.getElementById('dev-tab-btn-lab').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';
    if (document.getElementById('dev-tab-btn-pagination')) document.getElementById('dev-tab-btn-pagination').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';
    if (document.getElementById('dev-tab-btn-rbac')) document.getElementById('dev-tab-btn-rbac').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';
    if (document.getElementById('dev-tab-btn-logs')) document.getElementById('dev-tab-btn-logs').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';

    if (tab === 'git') {
        if (document.getElementById('dev-panel-git')) document.getElementById('dev-panel-git').classList.remove('hidden');
        if (document.getElementById('dev-tab-btn-git')) document.getElementById('dev-tab-btn-git').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 bg-primary text-white';
    } else if (tab === 'tenants') {
        if (document.getElementById('dev-panel-tenants')) document.getElementById('dev-panel-tenants').classList.remove('hidden');
        if (document.getElementById('dev-tab-btn-tenants')) document.getElementById('dev-tab-btn-tenants').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 bg-primary text-white';
    } else if (tab === 'vms') {
        if (document.getElementById('dev-panel-vms')) document.getElementById('dev-panel-vms').classList.remove('hidden');
        if (document.getElementById('dev-tab-btn-vms')) document.getElementById('dev-tab-btn-vms').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 bg-primary text-white';
    } else if (tab === 'lab') {
        if (document.getElementById('dev-panel-lab')) document.getElementById('dev-panel-lab').classList.remove('hidden');
        if (document.getElementById('dev-tab-btn-lab')) document.getElementById('dev-tab-btn-lab').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 bg-primary text-white';
    } else if (tab === 'pagination') {
        if (document.getElementById('dev-panel-pagination')) document.getElementById('dev-panel-pagination').classList.remove('hidden');
        if (document.getElementById('dev-tab-btn-pagination')) document.getElementById('dev-tab-btn-pagination').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 bg-primary text-white';
    } else if (tab === 'rbac') {
        if (document.getElementById('dev-panel-rbac')) document.getElementById('dev-panel-rbac').classList.remove('hidden');
        if (document.getElementById('dev-tab-btn-rbac')) document.getElementById('dev-tab-btn-rbac').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 bg-primary text-white';
    } else if (tab === 'logs') {
        if (document.getElementById('dev-panel-logs')) document.getElementById('dev-panel-logs').classList.remove('hidden');
        if (document.getElementById('dev-tab-btn-logs')) document.getElementById('dev-tab-btn-logs').className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 bg-primary text-white';
    }
}
document.addEventListener('DOMContentLoaded', function() {
    refreshGitStatus();
    fetchDeveloperErrorLogs();
    const urlParams = new URLSearchParams(window.location.search);
    const activeDevTab = urlParams.get('dev_tab');
    if (activeDevTab) {
        switchDevTab(activeDevTab);
    }
});

function fetchDeveloperErrorLogs() {
    const consoleBox = document.getElementById('developer-error-console');
    if (!consoleBox) return;

    fetch('actions/git_actions.php?action=get_error_logs')
    .then(r => r.json())
    .then(d => {
        if (d.success && d.logs && d.logs.length > 0) {
            consoleBox.innerHTML = d.logs.map(log => {
                let colorClass = 'text-slate-300';
                if (log.type === 'ERROR') colorClass = 'text-rose-400 font-bold';
                else if (log.type === 'WARNING') colorClass = 'text-amber-300';
                else if (log.type === 'AUDIT') colorClass = 'text-emerald-400';

                return `<div class="p-1 border-b border-slate-900 text-[11px]"><span class="text-slate-500">[${log.timestamp}]</span> <span class="px-1.5 py-0.5 rounded text-[9px] font-bold ${colorClass} bg-slate-900 mr-1">${log.type}</span> ${log.message}</div>`;
            }).join('');
        } else {
            consoleBox.innerHTML = '<p class="text-slate-400 italic">No log entries found.</p>';
        }
    })
    .catch(err => {
        consoleBox.innerHTML = '<p class="text-rose-400 font-bold">Error retrieving developer logs: ' + err + '</p>';
    });
}

function clearDeveloperErrorLogs() {
    if (!confirm('Are you sure you want to clear the developer error log buffer?')) return;

    fetch('actions/git_actions.php?action=clear_error_logs')
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('Developer log buffer cleared.');
            fetchDeveloperErrorLogs();
        } else {
            alert('Failed to clear logs: ' + (d.error || 'Unknown error'));
        }
    });
}

function refreshGitStatus() {
    const consoleBox = document.getElementById('git-status-console');
    if (!consoleBox) return;
    consoleBox.innerText = "Querying Git repository status...";

    fetch('actions/git_actions.php?action=git_status')
    .then(r => r.json())
    .then(d => {
        if (d.git_path && document.getElementById('git_path')) document.getElementById('git_path').value = d.git_path;
        if (d.git_repo_dir && document.getElementById('git_repo_dir')) document.getElementById('git_repo_dir').value = d.git_repo_dir;
        if (d.git_remote_url && document.getElementById('git_remote_url')) document.getElementById('git_remote_url').value = d.git_remote_url;

        if (d.remote_branches && d.remote_branches.length > 0) {
            const select = document.getElementById('update_branch');
            if (select) {
                select.innerHTML = '';
                d.remote_branches.forEach(b => {
                    const opt = document.createElement('option');
                    opt.value = b;
                    opt.textContent = b;
                    if (b === d.selected_branch) opt.selected = true;
                    select.appendChild(opt);
                });
            }
        }

        if (d.success) {
            consoleBox.innerHTML = `<span class="text-emerald-400 font-bold">✔ Git Repository Active</span>\nBranch: ${d.branch || 'main'}\n\n${d.status}`;
        } else {
            consoleBox.innerHTML = `<span class="text-amber-400 font-bold">⚠ Git Repository Not Linked or Unreachable</span>\n\n${d.status || d.error || 'Repository not initialized.'}`;
        }
    })
    .catch(err => {
        consoleBox.innerText = "Error querying Git status: " + err;
    });
}

function saveGitSettings() {
    const gitPath = document.getElementById('git_path').value;
    const gitRepoDir = document.getElementById('git_repo_dir').value;
    const updateBranch = document.getElementById('update_branch').value;

    fetch('actions/git_actions.php?action=save_git_settings', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({git_path: gitPath, git_repo_dir: gitRepoDir, update_branch: updateBranch})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('Git settings saved successfully.');
            refreshGitStatus();
        } else {
            alert('Failed to save Git settings: ' + (d.error || 'Unknown error'));
        }
    });
}

function triggerGitPull() {
    const branch = document.getElementById('update_branch').value || 'main';
    const consoleBox = document.getElementById('git-status-console');
    if (!confirm(`Are you sure you want to pull updates from branch '${branch}'? Local files will be updated.`)) return;

    consoleBox.innerText = `Executing 'git pull origin ${branch}'... Please wait...`;

    fetch('actions/git_actions.php?action=git_pull', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({branch: branch})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('Git pull completed successfully!');
            consoleBox.innerHTML = `<span class="text-emerald-400 font-bold">✔ Git Pull Successful</span>\n\n${d.output}`;
            loadGitHistory();
        } else {
            alert('Git pull failed: ' + (d.error || 'Unknown error'));
            consoleBox.innerHTML = `<span class="text-red-400 font-bold">❌ Git Pull Error</span>\n\n${d.error || 'Pull failed'}`;
        }
    });
}

function initializeGitRepo() {
    const repoUrl = document.getElementById('git_remote_url').value;
    if (!repoUrl) {
        alert('Please enter a remote Git URL (e.g. https://github.com/username/repository.git)');
        return;
    }

    if (!confirm('This will initialize a Git repository in your directory and link it to remote origin. Proceed?')) return;

    fetch('actions/git_actions.php?action=git_init', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({repo_url: repoUrl})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('Git repository linked successfully!');
            refreshGitStatus();
        } else {
            alert('Git init failed: ' + (d.error || 'Unknown error'));
        }
    });
}

function switchGitSubTab(tab) {
    if (tab === 'console') {
        document.getElementById('git-tab-console').classList.remove('hidden');
        document.getElementById('git-tab-history').classList.add('hidden');
        document.getElementById('btn-git-console').className = 'px-3 py-1 bg-primary text-white text-[11px] font-bold rounded-lg transition';
        document.getElementById('btn-git-history').className = 'px-3 py-1 bg-surface-container-high text-on-surface text-[11px] font-bold rounded-lg transition';
    } else {
        document.getElementById('git-tab-console').classList.add('hidden');
        document.getElementById('git-tab-history').classList.remove('hidden');
        document.getElementById('btn-git-console').className = 'px-3 py-1 bg-surface-container-high text-on-surface text-[11px] font-bold rounded-lg transition';
        document.getElementById('btn-git-history').className = 'px-3 py-1 bg-primary text-white text-[11px] font-bold rounded-lg transition';
        loadGitHistory();
    }
}

function loadGitHistory() {
    const list = document.getElementById('git-commit-list');
    list.innerHTML = '<p class="text-slate-500 italic">Fetching commit history...</p>';

    fetch('actions/git_actions.php?action=git_log')
    .then(r => r.json())
    .then(d => {
        if (d.success && d.commits && d.commits.length > 0) {
            list.innerHTML = d.commits.map(c => `
                <div class="p-3 rounded-xl bg-surface-container-low border border-outline-variant/30 text-xs">
                    <div class="flex items-center justify-between mb-1">
                        <span class="font-mono font-bold text-primary">${c.hash}</span>
                        <span class="text-[10px] text-outline">${c.date}</span>
                    </div>
                    <p class="font-bold text-on-surface">${c.message}</p>
                    <p class="text-[10px] text-slate-500 mt-0.5">Author: ${c.author}</p>
                </div>
            `).join('');
        } else {
            list.innerHTML = `<p class="text-slate-500 italic p-3">${d.error || 'No commits found.'}</p>`;
        }
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
