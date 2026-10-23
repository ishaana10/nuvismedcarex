<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/pagination.php';
$pageTitle = "Administrator Settings - NuvisMedcareX";
$activePage = "admin";
include __DIR__ . '/includes/header.php';

// Ensure active tenant ID is initialized
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// Fetch current clinic settings merged with tenant-specific settings
$settingsRows = $pdo->query("SELECT * FROM clinic_settings")->fetchAll();
$settings = [];
foreach ($settingsRows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$tenantSettingsStmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
$tenantSettingsStmt->execute([$currentTenantId]);
while ($tr = $tenantSettingsStmt->fetch()) {
    $settings[$tr['setting_key']] = $tr['setting_value'];
}

// Fetch multi-tenants clinics list
$tenantService = new \ClinicFlow\Services\TenantService($pdo);
$tenantsList = $tenantService->getAllTenants();
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// Determine active tab from query parameter (default: users)
$currentSessionUserRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Doctor';
$isDeveloper = ($currentSessionUserRole === 'Developer');

$activeTab = $_GET['tab'] ?? 'users';
if (!in_array($activeTab, ['users', 'clinic', 'email', 'inventory', 'audit'])) {
    $activeTab = 'users';
}

$pagination = getPaginationParams(10, [5, 10, 25, 50, 100], $pdo);

// Ensure active tenant ID is initialized
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// Fetch Audit Logs if audit tab active or for initial render
$auditSearch = trim($_GET['audit_search'] ?? '');
$auditActionFilter = trim($_GET['audit_action'] ?? '');
$auditPage = (int)($_GET['audit_page'] ?? 1);
if ($auditPage < 1) $auditPage = 1;
$auditLimit = (int)($_GET['audit_limit'] ?? 10);
if (!in_array($auditLimit, [5, 10, 25, 50, 100], true)) $auditLimit = 10;
$auditOffset = ($auditPage - 1) * $auditLimit;

$auditWhere = ["tenant_id = :tid"];
$auditParams = [':tid' => $currentTenantId];

if ($auditSearch !== '') {
    $auditWhere[] = "(user_name LIKE :search OR user_role LIKE :search OR action LIKE :search OR details LIKE :search OR ip_address LIKE :search OR patient_id LIKE :search)";
    $auditParams[':search'] = '%' . $auditSearch . '%';
}

if ($auditActionFilter !== '') {
    $auditWhere[] = "action = :action_filter";
    $auditParams[':action_filter'] = $auditActionFilter;
}

$auditWhereSql = implode(' AND ', $auditWhere);

$countAuditStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE {$auditWhereSql}");
$countAuditStmt->execute($auditParams);
$totalAuditLogs = (int)$countAuditStmt->fetchColumn();

$auditSql = "SELECT * FROM audit_logs WHERE {$auditWhereSql} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
$auditStmt = $pdo->prepare($auditSql);
foreach ($auditParams as $k => $v) {
    $auditStmt->bindValue($k, $v);
}
$auditStmt->bindValue(':limit', $auditLimit, PDO::PARAM_INT);
$auditStmt->bindValue(':offset', $auditOffset, PDO::PARAM_INT);
$auditStmt->execute();
$auditLogsList = $auditStmt->fetchAll();

// Fetch distinct actions for audit filter dropdown
$distinctActionsStmt = $pdo->prepare("SELECT DISTINCT action FROM audit_logs WHERE tenant_id = :tid ORDER BY action ASC");
$distinctActionsStmt->execute([':tid' => $currentTenantId]);
$distinctAuditActions = $distinctActionsStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch total count and paginated user/doctor staff
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$totalUsersStmt = $pdo->prepare("SELECT COUNT(*) FROM doctors WHERE tenant_id = ? OR tenant_id IS NULL");
$totalUsersStmt->execute([$currentTenantId]);
$totalUsers = (int)$totalUsersStmt->fetchColumn();

$usersStmt = $pdo->prepare("SELECT * FROM doctors WHERE tenant_id = :tid OR tenant_id IS NULL ORDER BY name ASC LIMIT :limit OFFSET :offset");
$usersStmt->bindValue(':tid', $currentTenantId);
$usersStmt->bindValue(':limit', $pagination['limit'], PDO::PARAM_INT);
$usersStmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$usersStmt->execute();
$usersList = $usersStmt->fetchAll();
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-on-surface">System Administration</h1>
        <p class="text-xs text-outline font-medium">Manage user accounts, clinic profile, prescription templates, and system updates</p>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center gap-2 bg-surface-container-low p-1.5 rounded-2xl border border-outline-variant/30 text-xs font-bold flex-wrap">
        <button type="button" onclick="switchAdminTab('users')" id="tab-btn-users" class="px-4 py-2 rounded-xl transition flex items-center gap-1.5 <?= ($activeTab === 'users') ? 'bg-primary text-white shadow-xs' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">
            <span class="material-symbols-outlined text-base">group</span>
            <span>User Management</span>
        </button>
        <button type="button" onclick="switchAdminTab('clinic')" id="tab-btn-clinic" class="px-4 py-2 rounded-xl transition flex items-center gap-1.5 <?= $activeTab === 'clinic' ? 'bg-primary text-white shadow-xs' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">
            <span class="material-symbols-outlined text-base">domain</span>
            <span>Clinic & Branding Settings</span>
        </button>
        <button type="button" onclick="switchAdminTab('email')" id="tab-btn-email" class="px-4 py-2 rounded-xl transition flex items-center gap-1.5 <?= $activeTab === 'email' ? 'bg-primary text-white shadow-xs' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">
            <span class="material-symbols-outlined text-base">mail</span>
            <span>Email Setup</span>
        </button>
        <button type="button" onclick="switchAdminTab('inventory')" id="tab-btn-inventory" class="px-4 py-2 rounded-xl transition flex items-center gap-1.5 <?= $activeTab === 'inventory' ? 'bg-primary text-white shadow-xs' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">
            <span class="material-symbols-outlined text-base">inventory_2</span>
            <span>Inventory Settings</span>
        </button>
        <button type="button" onclick="switchAdminTab('audit')" id="tab-btn-audit" class="px-4 py-2 rounded-xl transition flex items-center gap-1.5 <?= $activeTab === 'audit' ? 'bg-primary text-white shadow-xs' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">
            <span class="material-symbols-outlined text-base">history_edu</span>
            <span>Audit Log</span>
        </button>
    </div>
</div>

<!-- ================= TAB 1: USER MANAGEMENT ================= -->
<div id="admin-tab-users" class="<?= $activeTab === 'users' ? '' : 'hidden' ?> space-y-6">
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-outline-variant/20">
            <div>
                <h2 class="text-sm font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg">manage_accounts</span>
                    <span>System User Directory</span>
                </h2>
                <p class="text-xs text-outline font-medium mt-0.5">Add new users, edit credentials, assign roles, and manage account statuses</p>
            </div>

            <button type="button" onclick="openUserModal()" class="px-4 py-2.5 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-base">person_add</span>
                <span>Add New User</span>
            </button>
        </div>

        <!-- Role Filter & Search Bar -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
            <div class="flex items-center gap-1.5 flex-wrap">
                <span class="font-bold text-outline mr-1">Filter Role:</span>
                <button type="button" onclick="filterUserRole('all')" class="user-role-filter-btn px-3 py-1.5 rounded-lg bg-primary text-white font-bold transition" data-role="all">All</button>
                <button type="button" onclick="filterUserRole('Developer')" class="user-role-filter-btn px-3 py-1.5 rounded-lg bg-surface-container-high text-on-surface font-semibold hover:bg-surface-variant transition" data-role="Developer">Developer</button>
                <button type="button" onclick="filterUserRole('Administrator')" class="user-role-filter-btn px-3 py-1.5 rounded-lg bg-surface-container-high text-on-surface font-semibold hover:bg-surface-variant transition" data-role="Administrator">Administrator</button>
                <button type="button" onclick="filterUserRole('Doctor')" class="user-role-filter-btn px-3 py-1.5 rounded-lg bg-surface-container-high text-on-surface font-semibold hover:bg-surface-variant transition" data-role="Doctor">Doctor</button>
                <button type="button" onclick="filterUserRole('Nurse')" class="user-role-filter-btn px-3 py-1.5 rounded-lg bg-surface-container-high text-on-surface font-semibold hover:bg-surface-variant transition" data-role="Nurse">Nurse</button>
                <button type="button" onclick="filterUserRole('Receptionist')" class="user-role-filter-btn px-3 py-1.5 rounded-lg bg-surface-container-high text-on-surface font-semibold hover:bg-surface-variant transition" data-role="Receptionist">Receptionist</button>
            </div>

            <div class="relative w-full sm:w-64">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-base">search</span>
                <input type="text" id="user-search-input" onkeyup="searchUsers()" placeholder="Search user name or email..." class="w-full bg-surface-container-low pl-9 pr-3 py-2 rounded-xl border border-outline-variant/40 text-xs font-medium focus:outline-none focus:border-primary">
            </div>
        </div>

        <!-- User Table View -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-surface-container text-outline uppercase font-semibold text-[10px]">
                        <tr>
                            <th class="py-3 px-4">User</th>
                            <th class="py-3 px-4">Role & Specialty</th>
                            <th class="py-3 px-4">Medical License</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20 font-medium">
                        <?php foreach ($usersList as $usr):
                            $isActive = (isset($usr['is_active']) && (int)$usr['is_active'] === 0) ? false : true;
                            $userRole = $usr['role'] ?? 'Doctor';
                        ?>
                            <tr class="user-row hover:bg-surface-container-low/50 transition" data-role="<?= htmlspecialchars($userRole) ?>" data-search="<?= htmlspecialchars(strtolower($usr['name'] . ' ' . $usr['email'] . ' ' . $usr['role'] . ' ' . $usr['specialty'])) ?>">
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-3">
                                        <img src="<?= htmlspecialchars($usr['avatar'] ?: 'https://images.unsplash.com/photo-1537368910025-700350fe46c7?auto=format&fit=crop&q=80&w=200') ?>" class="w-9 h-9 rounded-xl object-cover border border-slate-200 shrink-0" alt="Avatar">
                                        <div>
                                            <div class="font-bold text-on-surface"><?= htmlspecialchars($usr['name']) ?></div>
                                            <div class="text-[11px] text-outline font-normal"><?= htmlspecialchars($usr['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="px-2 py-0.5 rounded-lg bg-primary/10 text-primary font-bold text-[10px] uppercase">
                                            <?= htmlspecialchars($userRole) ?>
                                        </span>
                                        <?php if (!empty($usr['specialty'])): ?>
                                            <span class="px-2 py-0.5 rounded-lg bg-surface-container-high text-on-surface-variant text-[10px]">
                                                <?= htmlspecialchars($usr['specialty']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-3 px-4 font-mono text-[11px] text-slate-600">
                                    <div>Lic: <?= htmlspecialchars($usr['prc_number'] ?: 'N/A') ?></div>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold inline-flex items-center gap-1 <?= $isActive ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                        <span class="w-1.5 h-1.5 rounded-full <?= $isActive ? 'bg-emerald-500' : 'bg-rose-500' ?>"></span>
                                        <span><?= $isActive ? 'Active' : 'Inactive' ?></span>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <button type="button" onclick='viewCardDetails(<?= htmlspecialchars(json_encode($usr), ENT_QUOTES, "UTF-8") ?>)' class="px-2.5 py-1 bg-surface-container-high hover:bg-surface-variant text-on-surface rounded-lg text-xs font-semibold transition flex items-center gap-1" title="View Full Card Details">
                                            <span class="material-symbols-outlined text-sm">visibility</span>
                                            <span>View</span>
                                        </button>
                                        <button type="button" onclick='openUserModal(<?= htmlspecialchars(json_encode($usr), ENT_QUOTES, "UTF-8") ?>)' class="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg text-xs font-semibold transition flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">edit</span>
                                            <span>Edit</span>
                                        </button>
                                        <form action="actions/doctor_actions.php" method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= htmlspecialchars($usr['id']) ?>">
                                            <input type="hidden" name="status" value="<?= $isActive ? 0 : 1 ?>">
                                            <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-semibold transition flex items-center gap-1 <?= $isActive ? 'bg-amber-100 text-amber-800 hover:bg-amber-200' : 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' ?>">
                                                <span class="material-symbols-outlined text-sm"><?= $isActive ? 'block' : 'check_circle' ?></span>
                                                <span><?= $isActive ? 'Deactivate' : 'Activate' ?></span>
                                            </button>
                                        </form>
                                        <a href="actions/doctor_actions.php?action=delete&id=<?= urlencode($usr['id']) ?>" onclick="return confirm('Are you sure you want to permanently delete user account \'<?= htmlspecialchars(addslashes($usr['name'])) ?>\'?')" class="p-1 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Delete User Account">
                                            <span class="material-symbols-outlined text-base">delete</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination Controls -->
        <?php
        echo renderPagination($totalUsers, $pagination['page'], $pagination['limit'], 'admin.php', ['tab' => 'users']);
        ?>
    </div>
</div>

<!-- ================= TAB 2: CLINIC & BRANDING SETTINGS ================= -->
<div id="admin-tab-clinic" class="<?= $activeTab === 'clinic' ? '' : 'hidden' ?> space-y-6">
    <form action="actions/admin_save_settings.php" method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
            <span class="material-symbols-outlined text-base">domain</span>
            <span>1. Clinic Branding & General Info</span>
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <div class="md:col-span-2">
                <label class="block font-bold text-slate-700 mb-1">Clinic Name <span class="text-red-500">*</span></label>
                <input type="text" name="clinic_name" value="<?= htmlspecialchars($settings['clinic_name'] ?? 'Nuvis Medico Healthcare') ?>" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-bold text-on-surface">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Subtitle / Slogan</label>
                <input type="text" name="clinic_subtitle" value="<?= htmlspecialchars($settings['clinic_subtitle'] ?? 'Integrated Healthcare Center') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Phone Number</label>
                <input type="text" name="clinic_phone" value="<?= htmlspecialchars($settings['clinic_phone'] ?? '(555) 019-2831') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Clinic Email</label>
                <input type="email" name="clinic_email" value="<?= htmlspecialchars($settings['clinic_email'] ?? 'medico@nuvistechnologies.com.fj') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">DEA License Number</label>
                <input type="text" name="clinic_dea" value="<?= htmlspecialchars($settings['clinic_dea'] ?? 'FC9823019') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">NPI Number</label>
                <input type="text" name="clinic_npi" value="<?= htmlspecialchars($settings['clinic_npi'] ?? '1092830192') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium">
            </div>

            <div class="md:col-span-2">
                <label class="block font-bold text-slate-700 mb-1">Default Physician Medical License No.</label>
                <input type="text" name="doc_prc_no" value="<?= htmlspecialchars($settings['doc_prc_no'] ?? 'LIC-0098412') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Default Table Items Per Page (Pagination Limit)</label>
                <select name="default_pagination_limit" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-bold text-primary">
                    <?php
                    $currDefLimit = (int)($settings['default_pagination_limit'] ?? 10);
                    foreach ([5, 10, 25, 50, 100] as $optLimit): ?>
                        <option value="<?= $optLimit ?>" <?= $currDefLimit === $optLimit ? 'selected' : '' ?>><?= $optLimit ?> items per page</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="block font-bold text-slate-700 mb-1">Physical Address</label>
                <input type="text" name="clinic_address" value="<?= htmlspecialchars($settings['clinic_address'] ?? '100 Healthcare Way, Suite 400, Springfield, OR') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>
        </div>

        <hr class="border-outline-variant/20">

        <!-- Fully Customisable Prescription Settings -->
        <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
            <span class="material-symbols-outlined text-base">print</span>
            <span>2. Prescription Template Customization</span>
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <div class="md:col-span-2">
                <label class="block font-bold text-slate-700 mb-1">Prescription Header Title</label>
                <input type="text" name="rx_header_title" value="<?= htmlspecialchars($settings['rx_header_title'] ?? 'OFFICIAL MEDICAL PRESCRIPTION') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-bold">
            </div>

            <div class="md:col-span-2">
                <label class="block font-bold text-slate-700 mb-1">Custom Disclaimer Text</label>
                <textarea name="rx_disclaimer" rows="2" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 font-medium"><?= htmlspecialchars($settings['rx_disclaimer'] ?? 'Notice: This prescription is valid for 30 days from date of issue.') ?></textarea>
            </div>

            <div class="md:col-span-2">
                <label class="block font-bold text-slate-700 mb-1">Footer Instructions / Refill Policy Note</label>
                <textarea name="rx_footer_note" rows="2" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 font-medium"><?= htmlspecialchars($settings['rx_footer_note'] ?? 'Substitution Permitted unless DAW is indicated.') ?></textarea>
            </div>
        </div>

        <hr class="border-outline-variant/20">

        <!-- Fully Customisable Invoice & Receipt Settings -->
        <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
            <span class="material-symbols-outlined text-base">receipt_long</span>
            <span>3. Invoice & Payment Receipt Customization</span>
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <div>
                <label class="block font-bold text-slate-700 mb-1">Invoice Header Title</label>
                <input type="text" name="invoice_header_title" value="<?= htmlspecialchars($settings['invoice_header_title'] ?? 'MEDICAL SERVICES INVOICE') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-bold">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Tax ID / EIN Number</label>
                <input type="text" name="invoice_tax_id" value="<?= htmlspecialchars($settings['invoice_tax_id'] ?? '93-1029384') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium">
            </div>

            <div class="md:col-span-2">
                <label class="block font-bold text-slate-700 mb-1">Invoice Payment Terms & Instructions</label>
                <input type="text" name="invoice_payment_terms" value="<?= htmlspecialchars($settings['invoice_payment_terms'] ?? 'Net 30 Days. Please remit payment promptly.') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div class="md:col-span-2">
                <label class="block font-bold text-slate-700 mb-1">Invoice Footer Note</label>
                <textarea name="invoice_footer_note" rows="2" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 font-medium"><?= htmlspecialchars($settings['invoice_footer_note'] ?? 'Thank you for choosing Nuvis Medico Healthcare for your care.') ?></textarea>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Receipt Header Title</label>
                <input type="text" name="receipt_header_title" value="<?= htmlspecialchars($settings['receipt_header_title'] ?? 'OFFICIAL PAYMENT RECEIPT') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-bold">
            </div>

            <div class="md:col-span-2">
                <label class="block font-bold text-slate-700 mb-1">Receipt Thank You & Confirmation Message</label>
                <textarea name="receipt_thank_you_msg" rows="2" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 font-medium"><?= htmlspecialchars($settings['receipt_thank_you_msg'] ?? 'Thank you for your payment. Your account balance for this invoice is cleared.') ?></textarea>
            </div>
        </div>

        <hr class="border-outline-variant/20">

        <!-- Server-Sent Events (SSE) Real-Time Patient Registration Notifications Toggle -->
        <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
            <span class="material-symbols-outlined text-base">sensors</span>
            <span>4. Real-Time Patient Notifications (Server-Sent Events)</span>
        </h2>

        <div class="p-4 bg-surface-container-low rounded-xl border border-outline-variant/30 flex items-center justify-between gap-4">
            <div>
                <h3 class="font-bold text-on-surface text-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-base">notifications_active</span>
                    <span>Enable Real-Time SSE Patient Registration Stream</span>
                </h3>
                <p class="text-[11px] text-outline mt-0.5">Push instant audio/visual notifications to doctor dashboards whenever a new patient is registered in the clinic.</p>
            </div>
            <div class="flex items-center gap-2">
                <input type="hidden" name="sse_setting_submitted" value="1">
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="sse_notifications_enabled" value="1" <?= ($settings['sse_notifications_enabled'] ?? '1') === '1' ? 'checked' : '' ?> class="w-4 h-4 text-primary rounded focus:ring-primary">
                    <span class="ml-2 font-bold text-xs text-on-surface">Enabled</span>
                </label>
            </div>
        </div>

        <hr class="border-outline-variant/20">

        <!-- Fully Customisable Medical Certificate Settings -->
        <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
            <span class="material-symbols-outlined text-base">badge</span>
            <span>5. Medical Certificate Customization</span>
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <div class="md:col-span-2">
                <label class="block font-bold text-slate-700 mb-1">Medical Certificate Header Title</label>
                <input type="text" name="cert_header_title" value="<?= htmlspecialchars($settings['cert_header_title'] ?? 'OFFICIAL MEDICAL CERTIFICATE') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-bold">
            </div>

            <div class="md:col-span-2">
                <label class="block font-bold text-slate-700 mb-1">Certificate Purpose & Disclaimer Statement</label>
                <textarea name="cert_disclaimer" rows="2" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 font-medium"><?= htmlspecialchars($settings['cert_disclaimer'] ?? 'This medical certificate is issued upon request of the patient for whatever legal or administrative purpose it may serve.') ?></textarea>
            </div>

            <div class="md:col-span-2">
                <label class="block font-bold text-slate-700 mb-1">Certificate Footer Note / Validity Notice</label>
                <textarea name="cert_footer_note" rows="2" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 font-medium"><?= htmlspecialchars($settings['cert_footer_note'] ?? 'Valid with official clinic digital stamp and physician e-signature.') ?></textarea>
            </div>
        </div>

        <div class="flex justify-end pt-3">
            <button type="submit" class="px-6 py-2.5 bg-primary text-white text-xs font-semibold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-base">save</span>
                <span>Save Clinic & Financial Template Settings</span>
            </button>
        </div>
    </form>
</div>

<!-- ================= TAB: AUDIT LOG ================= -->
<div id="admin-tab-audit" class="<?= $activeTab === 'audit' ? '' : 'hidden' ?> space-y-6">
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-outline-variant/20">
            <div>
                <h2 class="text-sm font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg">history_edu</span>
                    <span>Clinic Compliance & Security Audit Trail</span>
                </h2>
                <p class="text-xs text-outline font-medium mt-0.5">Immutable audit logging tracking user activities, medical chart reads, authentication events, and administrative actions isolated by tenant <code class="font-mono text-primary font-bold"><?= htmlspecialchars($currentTenantId) ?></code></p>
            </div>
        </div>

        <!-- Filter & Search Controls -->
        <form action="admin.php" method="GET" class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
            <input type="hidden" name="tab" value="audit">
            <div class="flex items-center gap-2 flex-wrap w-full sm:w-auto">
                <span class="font-bold text-outline">Action:</span>
                <select name="audit_action" onchange="this.form.submit()" class="bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-bold text-on-surface">
                    <option value="">All Audit Actions</option>
                    <?php foreach ($distinctAuditActions as $act): ?>
                        <option value="<?= htmlspecialchars($act) ?>" <?= $auditActionFilter === $act ? 'selected' : '' ?>><?= htmlspecialchars($act) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="relative w-full sm:w-80 flex items-center gap-2">
                <div class="relative flex-1">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-base">search</span>
                    <input type="text" name="audit_search" value="<?= htmlspecialchars($auditSearch) ?>" placeholder="Search user, action, details, IP, patient..." class="w-full bg-surface-container-low pl-9 pr-3 py-2 rounded-xl border border-outline-variant/40 text-xs font-medium focus:outline-none focus:border-primary">
                </div>
                <button type="submit" class="px-3.5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 transition shadow-xs">Search</button>
                <?php if ($auditSearch !== '' || $auditActionFilter !== ''): ?>
                    <a href="admin.php?tab=audit" class="px-3 py-2 bg-slate-200 text-slate-700 font-semibold rounded-xl hover:bg-slate-300 transition">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Audit Table View -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-surface-container text-outline uppercase font-semibold text-[10px]">
                        <tr>
                            <th class="py-3 px-4">Timestamp</th>
                            <th class="py-3 px-4">User</th>
                            <th class="py-3 px-4">Action</th>
                            <th class="py-3 px-4">Details</th>
                            <th class="py-3 px-4">Patient Ref</th>
                            <th class="py-3 px-4 text-right">IP Address</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20 font-medium">
                        <?php if (empty($auditLogsList)): ?>
                            <tr>
                                <td colspan="6" class="py-8 text-center text-outline italic">No audit records found matching criteria.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($auditLogsList as $alog):
                                $actionType = $alog['action'] ?? 'ACTION';
                                $badgeClass = 'bg-slate-100 text-slate-700 border-slate-300';
                                if (str_contains($actionType, 'LOGIN') || str_contains($actionType, 'AUTH')) $badgeClass = 'bg-blue-100 text-blue-800 border-blue-200';
                                elseif (str_contains($actionType, 'READ') || str_contains($actionType, 'VIEW')) $badgeClass = 'bg-emerald-100 text-emerald-800 border-emerald-200';
                                elseif (str_contains($actionType, 'DELETE') || str_contains($actionType, 'REVOKE')) $badgeClass = 'bg-rose-100 text-rose-800 border-rose-200';
                                elseif (str_contains($actionType, 'SAVE') || str_contains($actionType, 'UPDATE')) $badgeClass = 'bg-amber-100 text-amber-800 border-amber-200';
                            ?>
                                <tr class="hover:bg-surface-container-low/50 transition">
                                    <td class="py-3 px-4 font-mono text-[11px] text-outline whitespace-nowrap">
                                        <?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($alog['created_at'] ?? 'now'))) ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="font-bold text-on-surface"><?= htmlspecialchars($alog['user_name'] ?? 'System') ?></div>
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-surface-container-high text-outline">
                                            <?= htmlspecialchars($alog['user_role'] ?? 'System') ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="px-2.5 py-1 rounded-lg text-[10px] font-bold border <?= $badgeClass ?>">
                                            <?= htmlspecialchars($actionType) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 max-w-xs truncate text-slate-700" title="<?= htmlspecialchars($alog['details'] ?? '') ?>">
                                        <?= htmlspecialchars($alog['details'] ?? '') ?>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-[11px] text-primary">
                                        <?= !empty($alog['patient_id']) ? htmlspecialchars($alog['patient_id']) : '<span class="text-slate-400">N/A</span>' ?>
                                    </td>
                                    <td class="py-3 px-4 text-right font-mono text-[11px] text-slate-500">
                                        <?= htmlspecialchars($alog['ip_address'] ?? '127.0.0.1') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination Controls for Audit Log -->
        <?php
        echo renderPagination($totalAuditLogs, $auditPage, $auditLimit, 'admin.php', [
            'tab' => 'audit',
            'audit_search' => $auditSearch,
            'audit_action' => $auditActionFilter
        ], [5, 10, 25, 50, 100], 'audit_page', 'audit_limit');
        ?>
    </div>
</div>

<!-- ================= TAB: EMAIL SETUP ================= -->
<div id="admin-tab-email" class="<?= $activeTab === 'email' ? '' : 'hidden' ?> space-y-6">
    <form action="actions/admin_save_settings.php" method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="section" value="email">
        <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
            <span class="material-symbols-outlined text-base">mail</span>
            <span>Email Server (SMTP) & Automated Notifications Configuration</span>
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs font-medium">
            <div>
                <label class="block font-bold text-on-surface mb-1">SMTP Host Server</label>
                <input type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? 'smtp.a2hosting.com') ?>" placeholder="smtp.mailserver.com" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40">
            </div>

            <div>
                <label class="block font-bold text-on-surface mb-1">SMTP Port</label>
                <input type="number" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>" placeholder="587" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40">
            </div>

            <div>
                <label class="block font-bold text-on-surface mb-1">SMTP Username / Email</label>
                <input type="text" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? 'no-reply@nuvistechnologies.com.fj') ?>" placeholder="user@nuvistechnologies.com.fj" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40">
            </div>

            <div>
                <label class="block font-bold text-on-surface mb-1">SMTP Password</label>
                <input type="password" name="smtp_pass" value="<?= htmlspecialchars($settings['smtp_pass'] ?? '') ?>" placeholder="••••••••" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40">
            </div>

            <div>
                <label class="block font-bold text-on-surface mb-1">Sender 'From' Email Address</label>
                <input type="email" name="smtp_from" value="<?= htmlspecialchars($settings['smtp_from'] ?? 'no-reply@nuvistechnologies.com.fj') ?>" placeholder="no-reply@nuvistechnologies.com.fj" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40">
            </div>

            <div>
                <label class="block font-bold text-on-surface mb-1">Encryption Protocol</label>
                <select name="smtp_secure" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40">
                    <option value="tls" <?= ($settings['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Port 587)</option>
                    <option value="ssl" <?= ($settings['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                    <option value="none" <?= ($settings['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
                </select>
            </div>
        </div>

        <div class="p-4 bg-primary/5 rounded-xl border border-primary/20 text-xs text-on-surface space-y-1">
            <span class="font-bold flex items-center gap-1.5 text-primary">
                <span class="material-symbols-outlined text-sm">mark_email_read</span>
                <span>Automated Document Emailing Active</span>
            </span>
            <p class="text-outline">Patient invoices, payment receipts, and clinical prescriptions are dispatched automatically to patient email addresses using these SMTP server settings.</p>
        </div>

        <div class="flex justify-end pt-3">
            <button type="submit" class="px-6 py-2.5 bg-primary text-white text-xs font-semibold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-base">save</span>
                <span>Save Email SMTP Settings</span>
            </button>
        </div>
    </form>
</div>

<!-- ================= TAB: INVENTORY DEVELOPER SETTINGS ================= -->
<div id="admin-tab-inventory" class="<?= $activeTab === 'inventory' ? '' : 'hidden' ?> space-y-6">
    <form action="actions/admin_save_settings.php" method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
            <span class="material-symbols-outlined text-base">inventory_2</span>
            <span>Developer & Administrator Inventory Customization</span>
        </h2>

        <div class="space-y-4 text-xs">
            <div>
                <label class="block font-bold text-slate-700 mb-1">Custom Inventory Categories (Comma-Separated)</label>
                <input type="text" name="inventory_categories" value="<?= htmlspecialchars($settings['inventory_categories'] ?? 'Pharmaceuticals, Surgical Supplies, Medical Equipment, Diagnostics, Consumables') ?>" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium text-on-surface">
                <p class="text-[11px] text-outline mt-1">Specify custom categories for the clinic. These will be available in search filters and add/edit modals.</p>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Default Low Stock Alert Threshold</label>
                <input type="number" name="inventory_default_min_threshold" value="<?= (int)($settings['inventory_default_min_threshold'] ?? 10) ?>" min="1" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-bold text-on-surface">
                <p class="text-[11px] text-outline mt-1">Default minimum reorder threshold applied to newly created inventory items.</p>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Developer Dynamic Custom Fields JSON Definition</label>
                <textarea name="inventory_custom_fields_def" rows="5" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 font-mono text-xs text-on-surface"><?= htmlspecialchars($settings['inventory_custom_fields_def'] ?? "[\n  {\"name\": \"manufacturer\", \"label\": \"Manufacturer / Brand\", \"type\": \"text\", \"placeholder\": \"e.g. Pfizer, GSK\"},\n  {\"name\": \"storage_temp\", \"label\": \"Storage Temperature\", \"type\": \"text\", \"placeholder\": \"e.g. 2°C - 8°C\"}\n]") ?></textarea>
                <p class="text-[11px] text-outline mt-1">Define JSON array of custom attributes dynamically injected into inventory forms and displays. Example keys: name, label, type, placeholder.</p>
            </div>
        </div>

        <div class="flex justify-end pt-3 border-t border-outline-variant/20">
            <button type="submit" class="px-6 py-2.5 bg-primary text-white text-xs font-semibold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-base">save</span>
                <span>Save Inventory Customization Settings</span>
            </button>
        </div>
    </form>
</div>


<!-- Modal: Add/Edit Clinic Tenant -->
<div id="tenantModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl max-w-xl w-full shadow-2xl border border-slate-200 overflow-hidden my-8">
        <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
            <h3 class="font-bold text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-base">apartment</span>
                <span>Clinic Tenant Profile</span>
            </h3>
            <button type="button" onclick="closeTenantModal()" class="text-slate-400 hover:text-white text-lg">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/tenant_actions.php" method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="action" value="save_tenant">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="md:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">Clinic / Practice Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" required placeholder="e.g. Lautoka Specialist Clinic" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Unique Tenant ID <span class="text-red-500">*</span></label>
                    <input type="text" name="tenant_id" required placeholder="e.g. clinic-lautoka-01" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-mono font-medium">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Clinic Code <span class="text-red-500">*</span></label>
                    <input type="text" name="code" required placeholder="e.g. LTK-01" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-mono font-bold">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Subscription Plan</label>
                    <select name="plan" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
                        <option value="enterprise" selected>Enterprise</option>
                        <option value="professional">Professional</option>
                        <option value="standard">Standard</option>
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Status</label>
                    <select name="status" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
                        <option value="active" selected>Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">Address</label>
                    <input type="text" name="address" placeholder="e.g. 15 Vitogo Parade, Lautoka" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Phone</label>
                    <input type="text" name="phone" placeholder="+679 666 1234" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Email</label>
                    <input type="email" name="email" placeholder="lautoka@clinicflow.com.fj" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Drive Storage Allocation Plan</label>
                    <select name="storage_limit_mb" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold text-primary">
                        <option value="250">250 MB (Starter Tier)</option>
                        <option value="500" selected>500 MB (Standard Tier)</option>
                        <option value="1024">1 GB (1,024 MB - Professional)</option>
                        <option value="2048">2 GB (2,048 MB - Professional Plus)</option>
                        <option value="5120">5 GB (5,120 MB - Enterprise)</option>
                        <option value="10240">10 GB (10,240 MB - Enterprise Max)</option>
                        <option value="51200">50 GB (51,200 MB - Unlimited Scale)</option>
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Dedicated Drive Storage Destination</label>
                    <select name="storage_provider" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold text-amber-800">
                        <option value="server" selected>Local Server Storage</option>
                        <option value="googledrive">Google Drive (Dedicated Folder)</option>
                        <option value="onedrive">Microsoft OneDrive</option>
                    </select>
                </div>

                <div class="md:col-span-2 pt-2 border-t border-slate-200">
                    <h4 class="font-bold text-slate-800 text-xs flex items-center gap-1 mb-2">
                        <span class="material-symbols-outlined text-primary text-base">payments</span>
                        <span>Subscription & Nuvis ERP Integration Details</span>
                    </h4>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Plan Price Amount</label>
                    <div class="flex gap-2">
                        <select name="currency" class="w-24 bg-slate-50 px-2 py-2 rounded-xl border border-slate-300 font-bold text-xs">
                            <option value="FJD" selected>FJD ($)</option>
                            <option value="USD">USD ($)</option>
                            <option value="AUD">AUD ($)</option>
                            <option value="NZD">NZD ($)</option>
                            <option value="EUR">EUR (€)</option>
                        </select>
                        <input type="number" step="0.01" min="0" name="plan_price" placeholder="99.00" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Billing Cycle</label>
                    <select name="billing_cycle" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
                        <option value="monthly" selected>Monthly</option>
                        <option value="quarterly">Quarterly (3 Months)</option>
                        <option value="semi_annual">Semi-Annual (6 Months)</option>
                        <option value="yearly">Yearly (Annual)</option>
                        <option value="one_time">One-Time Lifetime</option>
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Subscription Status</label>
                    <select name="subscription_status" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
                        <option value="active" selected>Active</option>
                        <option value="trial">Trial Period</option>
                        <option value="past_due">Past Due / Pending Payment</option>
                        <option value="canceled">Canceled</option>
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Next Renewal Date</label>
                    <input type="date" name="next_renewal_date" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Billing Email Contact</label>
                    <input type="email" name="billing_email" placeholder="billing@clinic.com.fj" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Nuvis ERP Client Reference ID (nuviserpx)</label>
                    <input type="text" name="nuvis_erp_client_id" placeholder="e.g. ERP-CLI-9021" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-mono font-bold text-emerald-800">
                </div>
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-slate-200">
                <button type="button" onclick="closeTenantModal()" class="px-4 py-2 bg-slate-200 text-slate-700 font-semibold rounded-xl hover:bg-slate-300 transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">save</span>
                    <span>Save Clinic Tenant</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal View User Details Card -->
<div id="modal-view-user-details" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/30 shadow-2xl max-w-md w-full overflow-hidden">
        <div class="p-5 bg-slate-900 text-white flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-xl">badge</span>
                <h3 class="font-bold text-sm" id="view-card-name">User Profile Card</h3>
            </div>
            <button type="button" onclick="closeModal('modal-view-user-details')" class="text-slate-400 hover:text-white">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-6 space-y-4 text-xs">
            <div class="flex items-center gap-4 border-b border-outline-variant/20 pb-4">
                <img id="view-card-avatar" src="" class="w-16 h-16 rounded-2xl object-cover border-2 border-primary/20 shadow-xs shrink-0" alt="Avatar">
                <div>
                    <h2 class="text-base font-bold text-on-surface" id="view-card-fullname">User Name</h2>
                    <p class="text-xs text-outline font-medium" id="view-card-email">user@clinicflow.com</p>
                    <div class="flex items-center gap-2 mt-2">
                        <span class="px-2.5 py-0.5 rounded-lg bg-primary/10 text-primary font-bold text-[10px] uppercase" id="view-card-role">Doctor</span>
                        <span class="px-2.5 py-0.5 rounded-lg bg-surface-container-high text-on-surface-variant text-[10px]" id="view-card-specialty">Internal Medicine</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 text-xs">
                <div class="md:col-span-2">
                    <span class="text-outline text-[10px] block font-bold">Medical License No.</span>
                    <span class="font-mono font-bold text-on-surface" id="view-card-prc">LIC-0000000</span>
                </div>
            </div>

            <div class="space-y-3">
                <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 flex items-center justify-between">
                    <span class="font-bold text-on-surface">Digital e-Signature:</span>
                    <span id="view-card-sig-status" class="px-2 py-0.5 rounded text-[10px] font-bold"></span>
                </div>
                <div id="view-card-sig-container" class="p-3 bg-white rounded-xl border border-slate-200 hidden text-center">
                    <p class="text-[10px] font-bold text-slate-400 mb-1.5 uppercase tracking-wider">Attending Physician E-Signature</p>
                    <img id="view-card-sig-img" src="" class="max-h-24 max-w-full object-contain mx-auto" alt="E-Signature Preview">
                </div>

                <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 flex items-center justify-between">
                    <span class="font-bold text-on-surface">Digital Stamp:</span>
                    <span id="view-card-stamp-status" class="px-2 py-0.5 rounded text-[10px] font-bold"></span>
                </div>
                <div id="view-card-stamp-container" class="p-3 bg-white rounded-xl border border-slate-200 hidden text-center">
                    <p class="text-[10px] font-bold text-slate-400 mb-1.5 uppercase tracking-wider">Official Clinic Digital Stamp</p>
                    <img id="view-card-stamp-img" src="" class="max-h-32 max-w-full object-contain mx-auto" alt="Digital Stamp Preview">
                </div>
            </div>

            <div class="flex items-center justify-end pt-3 border-t border-outline-variant/20">
                <button type="button" onclick="closeModal('modal-view-user-details')" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: User Add/Edit Form -->
<div id="userModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl max-w-xl w-full shadow-2xl border border-slate-200 overflow-hidden my-8">
        <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
            <h3 id="userModalTitle" class="font-bold text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-base">person_add</span>
                <span>User Account Details</span>
            </h3>
            <button type="button" onclick="closeUserModal()" class="text-slate-400 hover:text-white text-lg">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/doctor_actions.php" method="POST" enctype="multipart/form-data" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="doctor_id" id="usr_id">
            <input type="hidden" name="existing_esignature" id="usr_existing_esignature">
            <input type="hidden" name="existing_digital_stamp" id="usr_existing_digital_stamp">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Full Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="usr_name" required placeholder="e.g. Dr. Sarah Jenkins" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Email Address <span class="text-red-500">*</span></label>
                    <input type="email" name="email" id="usr_email" required placeholder="sjenkins@clinicflow.com" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">User Role <span class="text-red-500">*</span></label>
                    <select name="role" id="usr_role" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
                        <option value="Doctor">Doctor</option>
                        <option value="Administrator">Administrator</option>
                        <option value="Developer">Developer</option>
                        <option value="Nurse">Nurse</option>
                        <option value="Receptionist">Receptionist</option>
                        <option value="Staff">Staff</option>
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Specialty / Department</label>
                    <input type="text" name="specialty" id="usr_specialty" placeholder="e.g. Internal Medicine / Nursing" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Account Password</label>
                    <input type="password" name="password" id="usr_password" placeholder="Leave blank to keep unchanged" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Account Status</label>
                    <select name="is_active" id="usr_is_active" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
                        <option value="1">Active Account</option>
                        <option value="0">Deactivated / Suspended</option>
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">Medical License Number</label>
                    <input type="text" name="prc_number" id="usr_prc" placeholder="LIC-0098412" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-mono font-medium">
                </div>

                <div class="md:col-span-2 space-y-2">
                    <label class="block font-bold text-slate-700">Profile Avatar Image (Upload File or Image URL)</label>
                    <div class="flex items-center gap-3">
                        <input type="file" name="avatar_file" id="usr_avatar_file" accept="image/*" class="text-xs text-slate-600">
                        <span class="text-xs text-slate-400 font-semibold">OR Image URL:</span>
                    </div>
                    <input type="text" name="avatar" id="usr_avatar" placeholder="https://images.unsplash.com/photo-..." class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                    <div id="avatar_preview" class="p-2 border border-slate-200 rounded-xl bg-white hidden flex items-center gap-3 shadow-2xs">
                        <img id="img_avatar_preview" src="" class="w-12 h-12 rounded-xl object-cover border border-slate-200" alt="Avatar Preview">
                        <div>
                            <p class="text-[11px] font-bold text-slate-800">Profile Image Preview</p>
                            <p class="text-[10px] text-slate-400">Selected profile picture for user account.</p>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="border-slate-200">

            <!-- E-Signature Section -->
            <div class="space-y-2">
                <label class="block font-bold text-slate-700">Official E-Signature (Image File or Data URL)</label>
                <div class="flex items-center gap-3">
                    <input type="file" name="esignature_file" id="usr_esignature_file" accept="image/*" class="text-xs text-slate-600">
                    <span class="text-xs text-slate-400 font-semibold">OR Data String:</span>
                </div>
                <textarea name="esignature_data" id="usr_esignature_data" rows="2" placeholder="data:image/png;base64,..." class="w-full bg-slate-50 p-2.5 rounded-xl border border-slate-300 font-mono text-[11px]"></textarea>
                <div id="esignature_preview" class="p-3 border border-slate-200 rounded-xl bg-white hidden text-center shadow-2xs">
                    <p class="text-[10px] text-slate-500 font-bold mb-1 uppercase tracking-wider">E-Signature Preview</p>
                    <img id="img_esignature_preview" src="" class="max-h-24 max-w-full object-contain mx-auto" alt="Signature Preview">
                </div>
            </div>

            <!-- Digital Stamp Section -->
            <div class="space-y-2">
                <label class="block font-bold text-slate-700">Official Digital Stamp (Image File or Data URL)</label>
                <div class="flex items-center gap-3">
                    <input type="file" name="stamp_file" id="usr_stamp_file" accept="image/*" class="text-xs text-slate-600">
                    <span class="text-xs text-slate-400 font-semibold">OR Data String:</span>
                </div>
                <textarea name="digital_stamp_data" id="usr_digital_stamp_data" rows="2" placeholder="data:image/png;base64,..." class="w-full bg-slate-50 p-2.5 rounded-xl border border-slate-300 font-mono text-[11px]"></textarea>
                <div id="stamp_preview" class="p-3 border border-slate-200 rounded-xl bg-white hidden text-center shadow-2xs">
                    <p class="text-[10px] text-slate-500 font-bold mb-1 uppercase tracking-wider">Digital Stamp Preview</p>
                    <img id="img_stamp_preview" src="" class="max-h-32 max-w-full object-contain mx-auto" alt="Stamp Preview">
                </div>
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-slate-200">
                <button type="button" onclick="closeUserModal()" class="px-4 py-2 bg-slate-200 text-slate-700 font-semibold rounded-xl hover:bg-slate-300 transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">save</span>
                    <span>Save User Account</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openTenantModal(tnt = null) {
    const modal = document.getElementById('tenantModal');
    if (!modal) return;
    modal.classList.remove('hidden');

    const form = modal.querySelector('form');
    if (!form) return;

    if (tnt) {
        if (form.elements['name']) form.elements['name'].value = tnt.name || '';
        if (form.elements['tenant_id']) {
            form.elements['tenant_id'].value = tnt.id || '';
            form.elements['tenant_id'].readOnly = true;
        }
        if (form.elements['code']) form.elements['code'].value = tnt.code || '';
        if (form.elements['plan']) form.elements['plan'].value = tnt.plan || 'enterprise';
        if (form.elements['status']) form.elements['status'].value = tnt.status || 'active';
        if (form.elements['address']) form.elements['address'].value = tnt.address || '';
        if (form.elements['phone']) form.elements['phone'].value = tnt.phone || '';
        if (form.elements['email']) form.elements['email'].value = tnt.email || '';
        if (form.elements['storage_limit_mb']) form.elements['storage_limit_mb'].value = tnt.storage_limit_mb || '500';
        if (form.elements['storage_provider']) form.elements['storage_provider'].value = tnt.storage_provider || 'server';
        if (form.elements['plan_price']) form.elements['plan_price'].value = tnt.plan_price || '0.00';
        if (form.elements['billing_cycle']) form.elements['billing_cycle'].value = tnt.billing_cycle || 'monthly';
        if (form.elements['currency']) form.elements['currency'].value = tnt.currency || 'FJD';
        if (form.elements['next_renewal_date']) form.elements['next_renewal_date'].value = tnt.next_renewal_date || '';
        if (form.elements['billing_email']) form.elements['billing_email'].value = tnt.billing_email || tnt.email || '';
        if (form.elements['nuvis_erp_client_id']) form.elements['nuvis_erp_client_id'].value = tnt.nuvis_erp_client_id || '';
        if (form.elements['subscription_status']) form.elements['subscription_status'].value = tnt.subscription_status || 'active';
    } else {
        form.reset();
        if (form.elements['tenant_id']) form.elements['tenant_id'].readOnly = false;
    }
}

function closeTenantModal() {
    const modal = document.getElementById('tenantModal');
    if (modal) modal.classList.add('hidden');
}

function switchAdminTab(tab) {
    const panels = ['users', 'clinic', 'email', 'inventory', 'audit'];
    panels.forEach(p => {
        const el = document.getElementById('admin-tab-' + p);
        if (el) el.classList.add('hidden');
        const btn = document.getElementById('tab-btn-' + p);
        if (btn) btn.className = 'px-4 py-2 rounded-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';
    });

    const activeEl = document.getElementById('admin-tab-' + tab);
    if (activeEl) activeEl.classList.remove('hidden');
    const activeBtn = document.getElementById('tab-btn-' + tab);
    if (activeBtn) activeBtn.className = 'px-4 py-2 rounded-xl transition flex items-center gap-1.5 bg-primary text-white shadow-xs';

    if (tab === 'developer') {
        if (typeof refreshGitStatus === 'function') refreshGitStatus();
        if (typeof fetchDeveloperErrorLogs === 'function') fetchDeveloperErrorLogs();
    }
}

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

let activeRoleFilter = 'all';

function filterUserRole(role) {
    activeRoleFilter = role;
    document.querySelectorAll('.user-role-filter-btn').forEach(btn => {
        if (btn.getAttribute('data-role') === role) {
            btn.className = 'user-role-filter-btn px-3 py-1.5 rounded-lg bg-primary text-white font-bold transition';
        } else {
            btn.className = 'user-role-filter-btn px-3 py-1.5 rounded-lg bg-surface-container-high text-on-surface font-semibold hover:bg-surface-variant transition';
        }
    });
    searchUsers();
}

function searchUsers() {
    const query = (document.getElementById('user-search-input').value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('.user-row');

    rows.forEach(row => {
        const rowRole = row.getAttribute('data-role');
        const rowSearchText = row.getAttribute('data-search') || '';

        const matchesRole = (activeRoleFilter === 'all' || rowRole === activeRoleFilter);
        const matchesQuery = (!query || rowSearchText.includes(query));

        if (matchesRole && matchesQuery) {
            row.classList.remove('hidden');
        } else {
            row.classList.add('hidden');
        }
    });
}

function viewCardDetails(usr) {
    if (!usr) return;
    document.getElementById('view-card-name').innerText = usr.name || 'User Details';
    document.getElementById('view-card-fullname').innerText = usr.name || 'User Name';
    document.getElementById('view-card-email').innerText = usr.email || 'N/A';
    document.getElementById('view-card-role').innerText = usr.role || 'Doctor';
    document.getElementById('view-card-specialty').innerText = usr.specialty || 'General Practice';
    document.getElementById('view-card-prc').innerText = usr.prc_number || 'N/A';
    document.getElementById('view-card-avatar').src = usr.avatar || 'https://images.unsplash.com/photo-1537368910025-700350fe46c7?auto=format&fit=crop&q=80&w=200';

    const sigEl = document.getElementById('view-card-sig-status');
    const sigContainer = document.getElementById('view-card-sig-container');
    const sigImg = document.getElementById('view-card-sig-img');
    if (usr.esignature) {
        sigEl.innerText = 'Uploaded / Active';
        sigEl.className = 'px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800';
        if (sigContainer && sigImg) {
            sigContainer.classList.remove('hidden');
            sigImg.src = usr.esignature;
        }
    } else {
        sigEl.innerText = 'Not Uploaded';
        sigEl.className = 'px-2 py-0.5 rounded text-[10px] font-bold bg-surface-container-high text-outline';
        if (sigContainer) sigContainer.classList.add('hidden');
    }

    const stampEl = document.getElementById('view-card-stamp-status');
    const stampContainer = document.getElementById('view-card-stamp-container');
    const stampImg = document.getElementById('view-card-stamp-img');
    if (usr.digital_stamp) {
        stampEl.innerText = 'Uploaded / Active';
        stampEl.className = 'px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-800';
        if (stampContainer && stampImg) {
            stampContainer.classList.remove('hidden');
            stampImg.src = usr.digital_stamp;
        }
    } else {
        stampEl.innerText = 'Not Uploaded';
        stampEl.className = 'px-2 py-0.5 rounded text-[10px] font-bold bg-surface-container-high text-outline';
        if (stampContainer) stampContainer.classList.add('hidden');
    }

    openModal('modal-view-user-details');
}

function openUserModal(usr = null) {
    const modal = document.getElementById('userModal');
    modal.classList.remove('hidden');

    if (usr) {
        document.getElementById('userModalTitle').innerText = 'Edit User Account & Credentials';
        document.getElementById('usr_id').value = usr.id || '';
        document.getElementById('usr_name').value = usr.name || '';
        document.getElementById('usr_email').value = usr.email || '';
        document.getElementById('usr_role').value = usr.role || 'Doctor';
        document.getElementById('usr_specialty').value = usr.specialty || '';
        document.getElementById('usr_password').value = '';
        document.getElementById('usr_is_active').value = (usr.is_active !== undefined) ? usr.is_active : '1';
        document.getElementById('usr_prc').value = usr.prc_number || '';
        document.getElementById('usr_avatar').value = usr.avatar || '';
        if (usr.avatar) {
            document.getElementById('avatar_preview').classList.remove('hidden');
            document.getElementById('img_avatar_preview').src = usr.avatar;
        } else {
            document.getElementById('avatar_preview').classList.add('hidden');
        }

        document.getElementById('usr_existing_esignature').value = usr.esignature || '';
        document.getElementById('usr_esignature_data').value = usr.esignature || '';
        if (usr.esignature) {
            document.getElementById('esignature_preview').classList.remove('hidden');
            document.getElementById('img_esignature_preview').src = usr.esignature;
        } else {
            document.getElementById('esignature_preview').classList.add('hidden');
        }

        document.getElementById('usr_existing_digital_stamp').value = usr.digital_stamp || '';
        document.getElementById('usr_digital_stamp_data').value = usr.digital_stamp || '';
        if (usr.digital_stamp) {
            document.getElementById('stamp_preview').classList.remove('hidden');
            document.getElementById('img_stamp_preview').src = usr.digital_stamp;
        } else {
            document.getElementById('stamp_preview').classList.add('hidden');
        }
    } else {
        document.getElementById('userModalTitle').innerText = 'Add New User Account';
        document.getElementById('usr_id').value = '';
        document.getElementById('usr_name').value = '';
        document.getElementById('usr_email').value = '';
        document.getElementById('usr_role').value = 'Doctor';
        document.getElementById('usr_specialty').value = '';
        document.getElementById('usr_password').value = '';
        document.getElementById('usr_is_active').value = '1';
        document.getElementById('usr_prc').value = '';
        document.getElementById('usr_avatar').value = '';
        document.getElementById('avatar_preview').classList.add('hidden');
        document.getElementById('usr_existing_esignature').value = '';
        document.getElementById('usr_esignature_data').value = '';
        document.getElementById('esignature_preview').classList.add('hidden');
        document.getElementById('usr_existing_digital_stamp').value = '';
        document.getElementById('usr_digital_stamp_data').value = '';
        document.getElementById('stamp_preview').classList.add('hidden');
    }
}

function closeUserModal() {
    document.getElementById('userModal').classList.add('hidden');
}

document.addEventListener('DOMContentLoaded', function() {
    const avatarFileInput = document.getElementById('usr_avatar_file');
    const avatarUrlInput = document.getElementById('usr_avatar');
    const avatarPreview = document.getElementById('avatar_preview');
    const avatarImg = document.getElementById('img_avatar_preview');

    if (avatarFileInput) {
        avatarFileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(evt) {
                    avatarImg.src = evt.target.result;
                    avatarPreview.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        });
    }

    if (avatarUrlInput) {
        avatarUrlInput.addEventListener('input', function(e) {
            const val = e.target.value.trim();
            if (val) {
                avatarImg.src = val;
                avatarPreview.classList.remove('hidden');
            } else {
                avatarPreview.classList.add('hidden');
            }
        });
    }

    const esigFileInput = document.getElementById('usr_esignature_file');
    const esigDataInput = document.getElementById('usr_esignature_data');
    const esigPreview = document.getElementById('esignature_preview');
    const esigImg = document.getElementById('img_esignature_preview');

    if (esigFileInput) {
        esigFileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(evt) {
                    esigImg.src = evt.target.result;
                    esigPreview.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        });
    }

    if (esigDataInput) {
        esigDataInput.addEventListener('input', function(e) {
            const val = e.target.value.trim();
            if (val) {
                esigImg.src = val;
                esigPreview.classList.remove('hidden');
            } else {
                esigPreview.classList.add('hidden');
            }
        });
    }

    const stampFileInput = document.getElementById('usr_stamp_file');
    const stampDataInput = document.getElementById('usr_digital_stamp_data');
    const stampPreview = document.getElementById('stamp_preview');
    const stampImg = document.getElementById('img_stamp_preview');

    if (stampFileInput) {
        stampFileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(evt) {
                    stampImg.src = evt.target.result;
                    stampPreview.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        });
    }

    if (stampDataInput) {
        stampDataInput.addEventListener('input', function(e) {
            const val = e.target.value.trim();
            if (val) {
                stampImg.src = val;
                stampPreview.classList.remove('hidden');
            } else {
                stampPreview.classList.add('hidden');
            }
        });
    }
});

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
