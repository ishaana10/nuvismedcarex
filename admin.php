<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/pagination.php';
$pageTitle = "Administrator Settings - NuvisMedcareX";
$activePage = "admin";
// Staff and audit need "Staff & access"; the clinic set-up tabs need "Clinic settings"
$requestedTab = $_GET['tab'] ?? 'users';
$pageAccess = in_array($requestedTab, ['clinic', 'email', 'inventory'], true) ? ['settings', 'view'] : ['users', 'view'];
include __DIR__ . '/includes/header.php';

// Ensure active tenant ID is initialized
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// Settings for the active clinic: its own values over the shared defaults
$settings = nxSettings();

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

<div class="mb-4">
    <h1 class="nx-page-title">Administration</h1>
    <p class="nx-page-sub">Staff accounts, what each role can do, clinic details and printed documents</p>
</div>
<?php $adminSection = $activeTab; include __DIR__ . '/includes/admin_nav.php'; ?>

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
                                        <?php if (nxCan('users', 'full')): ?>
                                        <form action="actions/doctor_actions.php" method="POST" class="inline" data-nx-confirm="Permanently delete the account for <?= htmlspecialchars($usr['name']) ?>? Their past records stay, but they can no longer sign in.">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= htmlspecialchars($usr['id']) ?>">
                                            <button type="submit" class="p-1 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Delete account" aria-label="Delete account for <?= htmlspecialchars($usr['name']) ?>">
                                                <span class="material-symbols-outlined text-base">delete</span>
                                            </button>
                                        </form>
                                        <?php endif; ?>
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
<?php
$cp = nxClinic();
$clinicCanEdit = nxCan('settings', 'edit');
$cdis = $clinicCanEdit ? '' : 'disabled';
$sv = fn(string $k, string $fallback = '') => htmlspecialchars((string) ($settings[$k] ?? $fallback));
?>
    <div class="flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-[13px] text-blue-900">
        <span class="material-symbols-outlined text-[20px]">info</span>
        <p>Everything here belongs to <strong><?= htmlspecialchars($cp['name']) ?></strong>. Other clinics on this installation keep their own details.</p>
    </div>

    <form action="actions/admin_save_settings.php" method="POST" enctype="multipart/form-data" id="clinic-form" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Clinic identity</h2>
                <p class="text-[13px] text-slate-500 mt-0.5">Used in the header, on printed documents and in emails to patients.</p>
            </div>

            <div class="flex flex-col sm:flex-row gap-5">
                <div class="shrink-0">
                    <span class="form-label">Logo</span>
                    <div class="w-28 h-28 rounded-xl border border-slate-200 bg-slate-50 flex items-center justify-center overflow-hidden" id="clinic-logo-thumb">
                        <?php if (!empty($cp['logo'])): ?>
                            <img src="<?= htmlspecialchars($cp['logo']) ?>" alt="Current logo" class="max-w-full max-h-full object-contain">
                        <?php else: ?>
                            <span class="text-xs text-slate-400">No logo</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($clinicCanEdit): ?>
                        <label class="btn-secondary !py-1.5 !px-3 mt-2 w-full cursor-pointer !text-xs">
                            <input type="file" name="clinic_logo_file" id="clinic-logo-file" accept="image/png,image/jpeg,image/webp" class="sr-only">
                            Choose image
                        </label>
                        <?php if (!empty($cp['logo'])): ?>
                            <label class="flex items-center gap-1.5 mt-2 text-xs text-slate-600">
                                <input type="checkbox" name="remove_clinic_logo" value="1" class="rounded border-slate-300 text-blue-700 focus:ring-blue-500/30">
                                Remove logo
                            </label>
                        <?php endif; ?>
                        <p class="text-[11px] text-slate-500 mt-1.5 max-w-[7rem]">PNG, JPG or WebP, under 300 KB.</p>
                    <?php endif; ?>
                </div>

                <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-4 min-w-0">
                    <div class="md:col-span-2">
                        <label class="form-label" for="f-clinic-name">Clinic name <span class="text-red-600">*</span></label>
                        <input id="f-clinic-name" type="text" name="clinic_name" required maxlength="120" value="<?= htmlspecialchars($cp['name']) ?>" class="form-input !text-sm !font-semibold" <?= $cdis ?>>
                        <p class="text-xs text-slate-500 mt-1">Shown in the header, the clinic switcher and at the top of every printed document.</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="form-label" for="f-clinic-subtitle">Strapline</label>
                        <input id="f-clinic-subtitle" type="text" name="clinic_subtitle" maxlength="160" value="<?= $sv('clinic_subtitle') ?>" placeholder="e.g. Family medicine and minor surgery" class="form-input" <?= $cdis ?>>
                    </div>
                    <div class="md:col-span-2">
                        <label class="form-label" for="f-clinic-address">Address</label>
                        <input id="f-clinic-address" type="text" name="clinic_address" maxlength="200" value="<?= htmlspecialchars($cp['address']) ?>" class="form-input" <?= $cdis ?>>
                    </div>
                    <div>
                        <label class="form-label" for="f-clinic-phone">Phone</label>
                        <input id="f-clinic-phone" type="text" name="clinic_phone" maxlength="60" value="<?= htmlspecialchars($cp['phone']) ?>" class="form-input" <?= $cdis ?>>
                    </div>
                    <div>
                        <label class="form-label" for="f-clinic-email">Email</label>
                        <input id="f-clinic-email" type="email" name="clinic_email" maxlength="120" value="<?= htmlspecialchars($cp['email']) ?>" class="form-input" <?= $cdis ?>>
                    </div>
                    <div class="md:col-span-2">
                        <label class="form-label" for="f-clinic-website">Website</label>
                        <input id="f-clinic-website" type="text" name="clinic_website" maxlength="120" value="<?= $sv('clinic_website') ?>" placeholder="e.g. clinic.com.fj" class="form-input" <?= $cdis ?>>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <span class="text-xs font-medium text-slate-500">How it appears on a document</span>
                <div class="mt-2 bg-white rounded-lg border border-slate-200 px-4 py-3 text-center">
                    <div class="flex items-center justify-center gap-2.5">
                        <?php if (!empty($cp['logo'])): ?><img src="<?= htmlspecialchars($cp['logo']) ?>" alt="" class="h-8 w-auto object-contain" id="prev-logo"><?php endif; ?>
                        <span class="text-base font-semibold text-slate-900" id="prev-name"><?= htmlspecialchars($cp['name']) ?></span>
                    </div>
                    <div class="text-xs text-slate-500 mt-0.5" id="prev-sub"><?= $sv('clinic_subtitle') ?></div>
                    <div class="text-[11px] text-slate-500 mt-1" id="prev-contact">
                        <?= htmlspecialchars(trim(implode(' · ', array_filter([$cp['address'], $cp['phone'], $cp['email']])), ' ·')) ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5">
            <h2 class="text-base font-semibold text-slate-900">Registration numbers</h2>
            <p class="text-[13px] text-slate-500 mt-0.5 mb-4">Printed on prescriptions, invoices and certificates where they apply.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="form-label" for="f-prc">Practitioner licence</label>
                    <input id="f-prc" type="text" name="doc_prc_no" maxlength="60" value="<?= $sv('doc_prc_no') ?>" class="form-input font-mono" <?= $cdis ?>>
                </div>
                <div>
                    <label class="form-label" for="f-ptr">Practice registration</label>
                    <input id="f-ptr" type="text" name="doc_ptr_no" maxlength="60" value="<?= $sv('doc_ptr_no') ?>" class="form-input font-mono" <?= $cdis ?>>
                </div>
                <div>
                    <label class="form-label" for="f-dea">Controlled drugs licence</label>
                    <input id="f-dea" type="text" name="clinic_dea" maxlength="60" value="<?= $sv('clinic_dea') ?>" class="form-input font-mono" <?= $cdis ?>>
                </div>
                <div>
                    <label class="form-label" for="f-npi">Provider number</label>
                    <input id="f-npi" type="text" name="clinic_npi" maxlength="60" value="<?= $sv('clinic_npi') ?>" class="form-input font-mono" <?= $cdis ?>>
                </div>
            </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-5">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Printed document wording</h2>
                    <p class="text-[13px] text-slate-500 mt-0.5">Headings and small print on what patients take away.</p>
                </div>
                <a href="receipt_settings.php" class="btn-secondary !py-1.5 !text-xs">
                    <span class="material-symbols-outlined text-[16px]">receipt</span> Receipt design
                </a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <div class="space-y-3">
                    <h3 class="text-[13px] font-semibold text-slate-800">Prescription</h3>
                    <div>
                        <label class="form-label" for="f-rx-title">Heading</label>
                        <input id="f-rx-title" type="text" name="rx_header_title" maxlength="80" value="<?= $sv('rx_header_title', 'Prescription') ?>" class="form-input" <?= $cdis ?>>
                    </div>
                    <div>
                        <label class="form-label" for="f-rx-disc">Notice</label>
                        <textarea id="f-rx-disc" name="rx_disclaimer" rows="2" maxlength="400" class="form-textarea" <?= $cdis ?>><?= $sv('rx_disclaimer') ?></textarea>
                    </div>
                    <div>
                        <label class="form-label" for="f-rx-foot">Footer</label>
                        <input id="f-rx-foot" type="text" name="rx_footer_note" maxlength="200" value="<?= $sv('rx_footer_note') ?>" class="form-input" <?= $cdis ?>>
                    </div>
                </div>

                <div class="space-y-3">
                    <h3 class="text-[13px] font-semibold text-slate-800">Invoice</h3>
                    <div>
                        <label class="form-label" for="f-inv-title">Heading</label>
                        <input id="f-inv-title" type="text" name="invoice_header_title" maxlength="80" value="<?= $sv('invoice_header_title', 'Invoice') ?>" class="form-input" <?= $cdis ?>>
                    </div>
                    <div>
                        <label class="form-label" for="f-inv-tax">Tax number on invoices</label>
                        <input id="f-inv-tax" type="text" name="invoice_tax_id" maxlength="60" value="<?= $sv('invoice_tax_id') ?>" class="form-input font-mono" <?= $cdis ?>>
                    </div>
                    <div>
                        <label class="form-label" for="f-inv-terms">Payment terms</label>
                        <input id="f-inv-terms" type="text" name="invoice_payment_terms" maxlength="200" value="<?= $sv('invoice_payment_terms') ?>" class="form-input" <?= $cdis ?>>
                    </div>
                    <div>
                        <label class="form-label" for="f-inv-foot">Footer</label>
                        <input id="f-inv-foot" type="text" name="invoice_footer_note" maxlength="200" value="<?= $sv('invoice_footer_note') ?>" class="form-input" <?= $cdis ?>>
                    </div>
                </div>

                <div class="space-y-3">
                    <h3 class="text-[13px] font-semibold text-slate-800">Medical certificate</h3>
                    <div>
                        <label class="form-label" for="f-cert-title">Heading</label>
                        <input id="f-cert-title" type="text" name="cert_header_title" maxlength="80" value="<?= $sv('cert_header_title', 'Medical certificate') ?>" class="form-input" <?= $cdis ?>>
                    </div>
                    <div>
                        <label class="form-label" for="f-cert-disc">Notice</label>
                        <textarea id="f-cert-disc" name="cert_disclaimer" rows="2" maxlength="400" class="form-textarea" <?= $cdis ?>><?= $sv('cert_disclaimer') ?></textarea>
                    </div>
                    <div>
                        <label class="form-label" for="f-cert-foot">Footer</label>
                        <input id="f-cert-foot" type="text" name="cert_footer_note" maxlength="200" value="<?= $sv('cert_footer_note') ?>" class="form-input" <?= $cdis ?>>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5">
            <h2 class="text-base font-semibold text-slate-900">Live notifications</h2>
            <input type="hidden" name="sse_setting_submitted" value="1">
            <label class="flex items-start gap-3 mt-3 cursor-pointer">
                <input type="checkbox" name="sse_notifications_enabled" value="1" class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500/30" <?= ($settings['sse_notifications_enabled'] ?? '1') === '1' ? 'checked' : '' ?> <?= $cdis ?>>
                <span class="text-[13px] text-slate-700">
                    Show staff a notification when a patient is registered or an appointment is booked
                    <span class="block text-xs text-slate-500 mt-0.5">Turn this off if the clinic's connection makes the live updates unreliable.</span>
                </span>
            </label>
        </section>

        <?php if ($clinicCanEdit): ?>
        <div class="sticky bottom-3 z-10 flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white/95 backdrop-blur px-4 py-3 shadow-pop">
            <span class="text-[13px] text-slate-500">Applies to <?= htmlspecialchars($cp['name']) ?> only</span>
            <button type="submit" class="btn-primary">Save clinic details</button>
        </div>
        <?php else: ?>
            <p class="text-[13px] text-slate-500">Changing clinic details needs Edit access to Clinic settings.</p>
        <?php endif; ?>
    </form>

    <script>
    (function () {
        var form = document.getElementById('clinic-form');
        if (!form) return;
        function bind(inputId, previewId) {
            var i = document.getElementById(inputId), p = document.getElementById(previewId);
            if (i && p) i.addEventListener('input', function () { p.textContent = i.value; });
        }
        bind('f-clinic-name', 'prev-name');
        bind('f-clinic-subtitle', 'prev-sub');
        ['f-clinic-address', 'f-clinic-phone', 'f-clinic-email'].forEach(function (id) {
            var i = document.getElementById(id);
            if (!i) return;
            i.addEventListener('input', function () {
                var parts = ['f-clinic-address', 'f-clinic-phone', 'f-clinic-email']
                    .map(function (x) { var el = document.getElementById(x); return el ? el.value.trim() : ''; })
                    .filter(Boolean);
                document.getElementById('prev-contact').textContent = parts.join(' · ');
            });
        });
        var file = document.getElementById('clinic-logo-file');
        if (file) file.addEventListener('change', function () {
            var f = file.files && file.files[0];
            if (!f) return;
            if (f.size > 300 * 1024) {
                if (window.NX) NX.toast('Image too large', 'Choose a logo under 300 KB.', 'error');
                file.value = '';
                return;
            }
            var url = URL.createObjectURL(f);
            var thumb = document.getElementById('clinic-logo-thumb');
            thumb.innerHTML = '';
            var img = new Image();
            img.src = url; img.alt = 'New logo'; img.className = 'max-w-full max-h-full object-contain';
            thumb.appendChild(img);
            var prev = document.getElementById('prev-logo');
            if (prev) prev.src = url;
        });
    })();
    </script>
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
<?php
use ClinicFlow\Services\EmailService;
$emailSvc = \ClinicFlow\Shared\Container::getInstance()->get(EmailService::class);
$mail = $emailSvc->getSmtpSettings($currentTenantId);
$mailCanEdit = nxCan('settings', 'edit');
$mdis = $mailCanEdit ? '' : 'disabled';
$hasPassword = $mail['smtp_pass'] !== '';
$providers = EmailService::PROVIDERS;
$activeProvider = $mail['smtp_provider'] ?: 'custom';
if (!isset($providers[$activeProvider])) $activeProvider = 'custom';

// Audit log
$emailStatus = $_GET['email_status'] ?? 'all';
$emailSearch = trim($_GET['email_search'] ?? '');
$emailPage = max(1, (int) ($_GET['email_page'] ?? 1));
$emailPerPage = 10;
$emailLogs = [];
$emailLogTotal = 0;
try {
    $emailLogs = $emailSvc->getEmailLogs($currentTenantId, $emailPerPage, ($emailPage - 1) * $emailPerPage, $emailStatus, $emailSearch);
    $emailLogTotal = $emailSvc->getEmailLogsCount($currentTenantId, $emailStatus, $emailSearch);
} catch (\Throwable $e) {
    error_log('[admin email log] ' . $e->getMessage());
}
$emailPages = max(1, (int) ceil($emailLogTotal / $emailPerPage));
$emailQuery = fn(array $extra) => 'admin.php?' . http_build_query(array_merge(
    ['tab' => 'email', 'email_status' => $emailStatus, 'email_search' => $emailSearch, 'email_page' => $emailPage], $extra));
?>
    <div class="flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-[13px] text-blue-900">
        <span class="material-symbols-outlined text-[20px]">mail</span>
        <p><strong><?= htmlspecialchars(nxClinicName()) ?></strong> sends from its own mailbox. Receipts, invoices and prescriptions go to patients from the address below, wherever this system is hosted.</p>
    </div>

    <form action="actions/admin_save_settings.php" method="POST" id="email-form" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
        <input type="hidden" name="section" value="email">

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Who sends the email</h2>
                <p class="text-[13px] text-slate-500 mt-0.5">Pick your provider and we'll fill in the server details.</p>
            </div>

            <fieldset class="grid grid-cols-2 lg:grid-cols-5 gap-2" <?= $mdis ?>>
                <legend class="sr-only">Email provider</legend>
                <?php foreach ($providers as $key => $pr): ?>
                    <label class="relative cursor-pointer">
                        <input type="radio" name="smtp_provider" value="<?= $key ?>" class="peer sr-only" data-provider
                               data-host="<?= htmlspecialchars($pr['host']) ?>" data-port="<?= (int) $pr['port'] ?>" data-secure="<?= $pr['secure'] ?>"
                               data-help="<?= htmlspecialchars($pr['help']) ?>" <?= $activeProvider === $key ? 'checked' : '' ?>>
                        <span class="block rounded-xl border border-slate-200 px-3 py-2.5 text-[13px] text-center text-slate-700 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-900 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 hover:border-slate-300">
                            <?= htmlspecialchars($pr['label']) ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <p class="flex items-start gap-2 rounded-xl bg-amber-50 border border-amber-200 px-3 py-2.5 text-[13px] text-amber-900" id="provider-help">
                <span class="material-symbols-outlined text-[18px]">lightbulb</span>
                <span id="provider-help-text"><?= htmlspecialchars($providers[$activeProvider]['help']) ?></span>
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label" for="m-user">Email address patients will see</label>
                    <input id="m-user" type="email" name="smtp_user" value="<?= htmlspecialchars($mail['smtp_user']) ?>" placeholder="clinic@gmail.com" autocomplete="username" class="form-input" <?= $mdis ?>>
                    <p class="text-xs text-slate-500 mt-1">The mailbox you sign in to. Used as the sender unless you set a different one below.</p>
                </div>
                <div>
                    <label class="form-label" for="m-pass">Password <?= $hasPassword ? '<span class="font-normal text-slate-400">(saved)</span>' : '' ?></label>
                    <input id="m-pass" type="password" name="smtp_pass" value="" autocomplete="new-password"
                           placeholder="<?= $hasPassword ? 'Leave blank to keep the saved password' : 'App password from your provider' ?>" class="form-input" <?= $mdis ?>>
                    <p class="text-xs text-slate-500 mt-1">Stored for this clinic only. Leave blank to keep what's already saved.</p>
                </div>
                <div>
                    <label class="form-label" for="m-from">Send from a different address <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="m-from" type="email" name="smtp_from" value="<?= htmlspecialchars($mail['smtp_from']) ?>" placeholder="noreply@yourclinic.com" class="form-input" <?= $mdis ?>>
                    <p class="text-xs text-slate-500 mt-1">Most providers only allow the mailbox you signed in with.</p>
                </div>
                <div>
                    <label class="form-label" for="m-reply">Replies should go to <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="m-reply" type="email" name="email_reply_to" value="<?= htmlspecialchars($mail['email_reply_to']) ?>" placeholder="frontdesk@yourclinic.com" class="form-input" <?= $mdis ?>>
                </div>
            </div>

            <details class="rounded-xl border border-slate-200 bg-slate-50/60 p-3" <?= $activeProvider === 'custom' ? 'open' : '' ?>>
                <summary class="text-[13px] font-medium text-slate-700 cursor-pointer">Server details</summary>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                    <div>
                        <label class="form-label" for="m-host">Outgoing server</label>
                        <input id="m-host" type="text" name="smtp_host" value="<?= htmlspecialchars($mail['smtp_host']) ?>" placeholder="smtp.example.com" class="form-input font-mono" <?= $mdis ?>>
                    </div>
                    <div>
                        <label class="form-label" for="m-port">Port</label>
                        <input id="m-port" type="number" name="smtp_port" value="<?= (int) $mail['smtp_port'] ?>" min="1" max="65535" class="form-input font-mono" <?= $mdis ?>>
                    </div>
                    <div>
                        <label class="form-label" for="m-secure">Encryption</label>
                        <select id="m-secure" name="smtp_secure" class="form-select" <?= $mdis ?>>
                            <option value="tls" <?= $mail['smtp_secure'] === 'tls' ? 'selected' : '' ?>>STARTTLS (usually port 587)</option>
                            <option value="ssl" <?= $mail['smtp_secure'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS (usually port 465)</option>
                            <option value="none" <?= $mail['smtp_secure'] === 'none' ? 'selected' : '' ?>>None (local server only)</option>
                        </select>
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-2">If mail times out, your web host may block the port. Port 465 with SSL often works where 587 does not.</p>
            </details>
        </section>

        <?php if ($mailCanEdit): ?>
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5">
            <h2 class="text-base font-semibold text-slate-900">Send yourself a test</h2>
            <p class="text-[13px] text-slate-500 mt-0.5 mb-3">Checks the settings above without emailing a patient. Save first to test the stored password.</p>
            <div class="flex flex-col sm:flex-row gap-2 max-w-xl">
                <label class="sr-only" for="m-test">Send a test to</label>
                <input id="m-test" type="email" placeholder="you@yourclinic.com" value="<?= htmlspecialchars($mail['smtp_user']) ?>" class="form-input">
                <button type="button" class="btn-secondary shrink-0" id="m-test-btn">Send test</button>
            </div>
            <p class="mt-3 text-[13px] hidden" id="m-test-result" role="status"></p>
        </section>

        <div class="sticky bottom-3 z-10 flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white/95 backdrop-blur px-4 py-3 shadow-pop">
            <span class="text-[13px] text-slate-500">Applies to <?= htmlspecialchars(nxClinicName()) ?> only</span>
            <button type="submit" class="btn-primary">Save email settings</button>
        </div>
        <?php else: ?>
            <p class="text-[13px] text-slate-500">Changing email settings needs Edit access to Clinic settings.</p>
        <?php endif; ?>
    </form>

    <!-- Audit log -->
    <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden">
        <div class="px-5 pt-5 pb-3 flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-slate-900">Email history</h2>
                <p class="text-[13px] text-slate-500 mt-0.5">Every message this clinic has tried to send, and why any failed.</p>
            </div>
            <form method="GET" action="admin.php" class="flex items-center gap-2 shrink-0">
                <input type="hidden" name="tab" value="email">
                <label class="sr-only" for="email-search">Search email history</label>
                <input id="email-search" type="search" name="email_search" value="<?= htmlspecialchars($emailSearch) ?>" placeholder="Recipient or subject" class="form-input !py-2 w-40 lg:w-56">
                <label class="sr-only" for="email-status">Status</label>
                <select id="email-status" name="email_status" onchange="this.form.submit()" class="form-select !py-2">
                    <?php foreach (['all' => 'All', 'Sent' => 'Sent', 'Failed' => 'Failed'] as $k => $label): ?>
                        <option value="<?= $k ?>" <?= $emailStatus === $k ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-secondary !py-2">Search</button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-y border-slate-200 bg-slate-50 text-[12px] font-semibold text-slate-500">
                        <th class="py-2.5 px-5">Sent</th>
                        <th class="py-2.5 px-3">To</th>
                        <th class="py-2.5 px-3 hidden md:table-cell">About</th>
                        <th class="py-2.5 px-3">Status</th>
                        <th class="py-2.5 px-5 text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (!$emailLogs): ?>
                        <tr><td colspan="5" class="px-5">
                            <div class="nx-empty">
                                <span class="material-symbols-outlined">outgoing_mail</span>
                                <p><?= ($emailSearch !== '' || $emailStatus !== 'all')
                                    ? 'No messages match that search.'
                                    : 'Nothing sent yet. Receipts and invoices emailed to patients will be listed here.' ?></p>
                            </div>
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($emailLogs as $log):
                        $failed = ($log['status'] ?? '') !== 'Sent';
                        $when = strtotime((string) ($log['sent_at'] ?? ''));
                    ?>
                        <tr class="hover:bg-slate-50 transition align-top">
                            <td class="py-3 px-5 whitespace-nowrap">
                                <div class="text-[13px] text-slate-900"><?= $when ? htmlspecialchars(date('j M Y', $when)) : '—' ?></div>
                                <div class="text-[11px] text-slate-500 tabular"><?= $when ? htmlspecialchars(date('H:i', $when)) : '' ?></div>
                            </td>
                            <td class="py-3 px-3">
                                <div class="text-[13px] text-slate-900 break-all"><?= htmlspecialchars($log['recipient'] ?? '') ?></div>
                                <div class="text-[11px] text-slate-500 truncate max-w-[280px]"><?= htmlspecialchars($log['subject'] ?? '') ?></div>
                            </td>
                            <td class="py-3 px-3 hidden md:table-cell">
                                <span class="inline-flex px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 text-[12px] font-medium">
                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($log['document_type'] ?? 'general')))) ?>
                                </span>
                            </td>
                            <td class="py-3 px-3">
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[12px] font-semibold ring-1 <?= $failed ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-emerald-50 text-emerald-800 ring-emerald-200' ?>">
                                    <span class="nx-dot !w-1.5 !h-1.5 <?= $failed ? 'bg-red-500' : 'bg-emerald-500' ?>"></span><?= htmlspecialchars($log['status'] ?? '') ?>
                                </span>
                                <?php if ($failed && !empty($log['error_message'])): ?>
                                    <div class="text-[11px] text-red-700 mt-1 max-w-[320px]"><?= htmlspecialchars($log['error_message']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-5 text-right whitespace-nowrap">
                                <?php if ($failed && $mailCanEdit): ?>
                                    <button type="button" class="btn-secondary !py-1.5 !px-3 !text-[12px]" data-resend="<?= htmlspecialchars($log['id']) ?>">Try again</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($emailPages > 1): ?>
            <div class="flex items-center justify-between gap-3 px-5 py-3 border-t border-slate-200 text-[13px] text-slate-600">
                <span>Page <?= $emailPage ?> of <?= $emailPages ?> · <?= number_format($emailLogTotal) ?> messages</span>
                <div class="flex gap-2">
                    <?php if ($emailPage > 1): ?><a href="<?= htmlspecialchars($emailQuery(['email_page' => $emailPage - 1])) ?>" class="btn-secondary !py-1.5">Previous</a><?php endif; ?>
                    <?php if ($emailPage < $emailPages): ?><a href="<?= htmlspecialchars($emailQuery(['email_page' => $emailPage + 1])) ?>" class="btn-secondary !py-1.5">Next</a><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <script>
    (function () {
        var form = document.getElementById('email-form');
        if (!form) return;
        var csrf = form.querySelector('[name=csrf_token]').value;

        // Picking a provider fills in its server details
        form.querySelectorAll('[data-provider]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked) return;
                var help = document.getElementById('provider-help-text');
                if (help) help.textContent = radio.getAttribute('data-help');
                var host = radio.getAttribute('data-host');
                if (host) {
                    document.getElementById('m-host').value = host;
                    document.getElementById('m-port').value = radio.getAttribute('data-port');
                    document.getElementById('m-secure').value = radio.getAttribute('data-secure');
                }
            });
        });

        var testBtn = document.getElementById('m-test-btn');
        if (testBtn) testBtn.addEventListener('click', function () {
            var to = document.getElementById('m-test').value.trim();
            var out = document.getElementById('m-test-result');
            if (!to) { if (window.NX) NX.toast('Add an address', 'Enter where the test should go.', 'error'); return; }
            testBtn.disabled = true;
            testBtn.textContent = 'Sending…';
            out.className = 'mt-3 text-[13px] text-slate-500';
            out.textContent = 'Connecting to the mail server…';
            var body = new FormData();
            body.append('csrf_token', csrf);
            body.append('recipient_email', to);
            ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_secure'].forEach(function (n) {
                var el = form.querySelector('[name=' + n + ']');
                if (el) body.append(n, el.value);
            });
            fetch('actions/test_email.php', { method: 'POST', body: body, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        out.className = 'mt-3 text-[13px] text-emerald-700';
                        out.textContent = 'Sent. Check ' + to + ' — it may take a minute to arrive.';
                    } else {
                        out.className = 'mt-3 text-[13px] text-red-700';
                        out.textContent = d.error || 'The test could not be sent.';
                    }
                })
                .catch(function () {
                    out.className = 'mt-3 text-[13px] text-red-700';
                    out.textContent = 'Could not reach the server to run the test.';
                })
                .finally(function () { testBtn.disabled = false; testBtn.textContent = 'Send test'; });
        });

        document.querySelectorAll('[data-resend]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                NX.confirm('Send this message again to the same address?', { danger: false, okLabel: 'Send again' }).then(function (yes) {
                    if (!yes) return;
                    btn.disabled = true; btn.textContent = 'Sending…';
                    var body = new FormData();
                    body.append('csrf_token', csrf);
                    body.append('log_id', btn.getAttribute('data-resend'));
                    fetch('actions/email_actions.php?action=resend_email', { method: 'POST', body: body, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d.success) { NX.toast('Sent', d.message || 'The message was sent.', 'success'); setTimeout(function () { location.reload(); }, 900); }
                            else { NX.toast('Still failing', d.error || d.message || 'It could not be sent.', 'error'); btn.disabled = false; btn.textContent = 'Try again'; }
                        })
                        .catch(function () { NX.toast('Could not retry', 'Check your connection and try again.', 'error'); btn.disabled = false; btn.textContent = 'Try again'; });
                });
            });
        });
    })();
    </script>
</div>

<!-- ================= TAB: INVENTORY DEVELOPER SETTINGS ================= -->
<div id="admin-tab-inventory" class="<?= $activeTab === 'inventory' ? '' : 'hidden' ?> space-y-6">
<?php
use ClinicFlow\Services\InventorySetup;
$invCats = InventorySetup::parseList($settings['inventory_categories'] ?? '', InventorySetup::DEFAULT_CATEGORIES);
$invUnits = InventorySetup::parseList($settings['inventory_units'] ?? '', InventorySetup::DEFAULT_UNITS);
$invFields = InventorySetup::fields($settings['inventory_custom_fields_def'] ?? '[]');
$invCanEdit = nxCan('settings', 'edit');
?>
    <form action="actions/admin_save_settings.php" method="POST" id="inv-setup-form" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
        <input type="hidden" name="inventory_categories" id="inv-categories-value">
        <input type="hidden" name="inventory_units" id="inv-units-value">
        <input type="hidden" name="inventory_custom_fields_def" id="inv-fields-value">

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5">
            <h2 class="text-base font-semibold text-slate-900">Categories</h2>
            <p class="text-[13px] text-slate-500 mt-0.5 mb-3">How <?= htmlspecialchars(nxClinicName()) ?> groups its stock. These appear in the filters and when adding an item.</p>
            <div class="flex flex-wrap gap-2 mb-3" id="inv-categories" data-chips></div>
            <div class="flex gap-2 max-w-md">
                <label for="inv-cat-new" class="sr-only">New category</label>
                <input id="inv-cat-new" type="text" maxlength="60" placeholder="Add a category, e.g. Vaccines" class="form-input" <?= $invCanEdit ? '' : 'disabled' ?>>
                <button type="button" class="btn-secondary shrink-0" data-chip-add="inv-categories" <?= $invCanEdit ? '' : 'disabled' ?>>Add</button>
            </div>
            <p class="text-xs text-slate-500 mt-2" data-chip-warn="inv-categories" hidden>Items already filed under a category you remove keep it until you edit them.</p>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5">
            <h2 class="text-base font-semibold text-slate-900">Units</h2>
            <p class="text-[13px] text-slate-500 mt-0.5 mb-3">Offered when someone types a unit on an item. They can still enter their own.</p>
            <div class="flex flex-wrap gap-2 mb-3" id="inv-units" data-chips></div>
            <div class="flex gap-2 max-w-md">
                <label for="inv-unit-new" class="sr-only">New unit</label>
                <input id="inv-unit-new" type="text" maxlength="60" placeholder="Add a unit, e.g. Sachet" class="form-input" <?= $invCanEdit ? '' : 'disabled' ?>>
                <button type="button" class="btn-secondary shrink-0" data-chip-add="inv-units" <?= $invCanEdit ? '' : 'disabled' ?>>Add</button>
            </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5">
            <h2 class="text-base font-semibold text-slate-900">Stock warnings</h2>
            <p class="text-[13px] text-slate-500 mt-0.5 mb-4">When the dashboard and stock list should start flagging an item.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-xl">
                <div>
                    <label class="form-label" for="inv-threshold">Reorder level for new items</label>
                    <input id="inv-threshold" type="number" name="inventory_default_min_threshold" min="0" max="100000"
                           value="<?= (int) ($settings['inventory_default_min_threshold'] ?? 10) ?>" class="form-input" <?= $invCanEdit ? '' : 'disabled' ?>>
                    <p class="text-xs text-slate-500 mt-1">Pre-filled when adding an item; each item can be set on its own.</p>
                </div>
                <div>
                    <label class="form-label" for="inv-expiry">Warn about expiry this many days ahead</label>
                    <input id="inv-expiry" type="number" name="inventory_expiry_warning_days" min="0" max="730"
                           value="<?= (int) ($settings['inventory_expiry_warning_days'] ?? 30) ?>" class="form-input" <?= $invCanEdit ? '' : 'disabled' ?>>
                    <p class="text-xs text-slate-500 mt-1">Shown on the dashboard as "expiring soon".</p>
                </div>
            </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5">
            <div class="flex items-start justify-between gap-3 mb-1">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Extra details to record</h2>
                    <p class="text-[13px] text-slate-500 mt-0.5">Add your own fields to the item form — manufacturer, supplier, storage temperature, anything you track.</p>
                </div>
                <button type="button" class="btn-secondary shrink-0" id="inv-add-field" <?= $invCanEdit ? '' : 'disabled' ?>>
                    <span class="material-symbols-outlined text-[18px]">add</span> Add field
                </button>
            </div>
            <div id="inv-fields" class="space-y-3 mt-4"></div>
            <p class="nx-empty !py-6" id="inv-fields-empty" hidden>No extra fields yet. Items record name, SKU, category, stock, price, batch and expiry already.</p>
            <p class="text-xs text-slate-500 mt-3">Up to <?= InventorySetup::MAX_FIELDS ?> fields. Removing one hides it from the form; values already saved on items are kept.</p>
        </section>

        <?php if ($invCanEdit): ?>
        <div class="sticky bottom-3 z-10 flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white/95 backdrop-blur px-4 py-3 shadow-pop">
            <span class="text-[13px] text-slate-500" id="inv-status">Applies to <?= htmlspecialchars(nxClinicName()) ?> only</span>
            <button type="submit" class="btn-primary">Save inventory set-up</button>
        </div>
        <?php else: ?>
            <p class="text-[13px] text-slate-500">Changing the inventory set-up needs Edit access to Clinic settings.</p>
        <?php endif; ?>
    </form>

    <script>
    (function () {
        var form = document.getElementById('inv-setup-form');
        if (!form) return;
        var canEdit = <?= $invCanEdit ? 'true' : 'false' ?>;
        var state = {
            'inv-categories': <?= json_encode(array_values($invCats)) ?>,
            'inv-units': <?= json_encode(array_values($invUnits)) ?>
        };
        var fields = <?= json_encode(array_values($invFields)) ?>;
        var TYPES = <?= json_encode(InventorySetup::FIELD_TYPES) ?>;
        var MAX = <?= InventorySetup::MAX_FIELDS ?>;

        function el(tag, attrs, kids) {
            var n = document.createElement(tag);
            Object.keys(attrs || {}).forEach(function (k) {
                if (k === 'class') n.className = attrs[k];
                else if (k === 'text') n.textContent = attrs[k];
                else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), attrs[k]);
                else n.setAttribute(k, attrs[k]);
            });
            (kids || []).forEach(function (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
            return n;
        }
        function icon(name) { return el('span', { class: 'material-symbols-outlined text-[16px]', text: name, 'aria-hidden': 'true' }); }

        function drawChips(id) {
            var box = document.getElementById(id);
            box.innerHTML = '';
            if (!state[id].length) box.appendChild(el('span', { class: 'text-[13px] text-slate-500', text: 'None yet — add one below.' }));
            state[id].forEach(function (value, i) {
                var chip = el('span', { class: 'inline-flex items-center gap-1 pl-3 pr-1 py-1 rounded-full bg-slate-100 border border-slate-200 text-[13px] text-slate-800' }, [value]);
                if (canEdit) {
                    chip.appendChild(el('button', {
                        type: 'button', class: 'w-6 h-6 inline-flex items-center justify-center rounded-full text-slate-500 hover:bg-white hover:text-red-600',
                        'aria-label': 'Remove ' + value,
                        onclick: function () {
                            state[id].splice(i, 1);
                            drawChips(id);
                            var warn = document.querySelector('[data-chip-warn="' + id + '"]');
                            if (warn) warn.hidden = false;
                            touch();
                        }
                    }, [icon('close')]));
                }
                box.appendChild(chip);
            });
        }
        document.querySelectorAll('[data-chip-add]').forEach(function (btn) {
            var id = btn.getAttribute('data-chip-add');
            var input = btn.parentNode.querySelector('input');
            function add() {
                var v = input.value.trim().replace(/\s+/g, ' ').replace(/,/g, '');
                if (!v) return;
                var dupe = state[id].some(function (x) { return x.toLowerCase() === v.toLowerCase(); });
                if (dupe) {
                    if (window.NX) NX.toast('Already in the list', '“' + v + '” is there already.', 'info');
                } else {
                    state[id].push(v);
                    drawChips(id);
                    touch();
                }
                input.value = '';
                input.focus();
            }
            btn.addEventListener('click', add);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(); }
            });
        });

        function drawFields() {
            var box = document.getElementById('inv-fields');
            box.innerHTML = '';
            document.getElementById('inv-fields-empty').hidden = fields.length > 0;
            document.getElementById('inv-add-field').disabled = !canEdit || fields.length >= MAX;
            fields.forEach(function (f, i) {
                var row = el('div', { class: 'rounded-xl border border-slate-200 bg-slate-50/60 p-3' });
                var grid = el('div', { class: 'grid grid-cols-1 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] gap-2 items-end' });

                var lbl = el('div', {}, []);
                lbl.appendChild(el('label', { class: 'block text-xs font-medium text-slate-600 mb-1', text: 'Label shown on the form' }));
                var lblIn = el('input', { type: 'text', value: f.label || '', maxlength: '60', class: 'form-input', placeholder: 'e.g. Manufacturer' });
                lblIn.disabled = !canEdit;
                lblIn.addEventListener('input', function () { f.label = lblIn.value; touch(); });
                lbl.appendChild(lblIn);

                var typ = el('div', {}, []);
                typ.appendChild(el('label', { class: 'block text-xs font-medium text-slate-600 mb-1', text: 'Type' }));
                var sel = el('select', { class: 'form-select' });
                Object.keys(TYPES).forEach(function (k) {
                    var o = el('option', { value: k, text: TYPES[k] });
                    if ((f.type || 'text') === k) o.selected = true;
                    sel.appendChild(o);
                });
                sel.disabled = !canEdit;
                sel.addEventListener('change', function () { f.type = sel.value; drawFields(); touch(); });
                typ.appendChild(sel);

                var del = el('button', {
                    type: 'button', class: 'nx-icon-btn hover:!bg-red-50 hover:!text-red-600 mb-0.5',
                    'aria-label': 'Remove ' + (f.label || 'field'),
                    onclick: function () { fields.splice(i, 1); drawFields(); touch(); }
                }, [icon('delete')]);
                del.disabled = !canEdit;

                grid.appendChild(lbl); grid.appendChild(typ); grid.appendChild(del);
                row.appendChild(grid);

                var extra = el('div', { class: 'grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2' });
                if (f.type === 'select') {
                    var ow = el('div', {}, []);
                    ow.appendChild(el('label', { class: 'block text-xs font-medium text-slate-600 mb-1', text: 'Choices, separated by commas' }));
                    var oIn = el('input', { type: 'text', class: 'form-input', placeholder: 'e.g. Room temperature, 2–8 °C, Frozen',
                        value: Array.isArray(f.options) ? f.options.join(', ') : (f.options || '') });
                    oIn.disabled = !canEdit;
                    oIn.addEventListener('input', function () {
                        f.options = oIn.value.split(',').map(function (x) { return x.trim(); }).filter(Boolean);
                        touch();
                    });
                    ow.appendChild(oIn);
                    extra.appendChild(ow);
                } else {
                    var pw = el('div', {}, []);
                    pw.appendChild(el('label', { class: 'block text-xs font-medium text-slate-600 mb-1', text: 'Hint text (optional)' }));
                    var pIn = el('input', { type: 'text', class: 'form-input', maxlength: '80', placeholder: 'e.g. 2–8 °C', value: f.placeholder || '' });
                    pIn.disabled = !canEdit;
                    pIn.addEventListener('input', function () { f.placeholder = pIn.value; touch(); });
                    pw.appendChild(pIn);
                    extra.appendChild(pw);
                }
                var req = el('label', { class: 'flex items-center gap-2 text-[13px] text-slate-700 sm:self-end sm:pb-2' });
                var cb = el('input', { type: 'checkbox', class: 'rounded border-slate-300 text-blue-700 focus:ring-blue-500/30' });
                cb.checked = !!f.required;
                cb.disabled = !canEdit;
                cb.addEventListener('change', function () { f.required = cb.checked; touch(); });
                req.appendChild(cb);
                req.appendChild(document.createTextNode('Must be filled in'));
                extra.appendChild(req);
                row.appendChild(extra);
                box.appendChild(row);
            });
        }
        document.getElementById('inv-add-field').addEventListener('click', function () {
            if (fields.length >= MAX) return;
            fields.push({ label: '', type: 'text', placeholder: '', required: false });
            drawFields();
            var inputs = document.querySelectorAll('#inv-fields input[type=text]');
            if (inputs.length) inputs[inputs.length - 1].focus();
            touch();
        });

        var status = document.getElementById('inv-status');
        function touch() { if (status) status.textContent = 'Unsaved changes'; }

        form.addEventListener('submit', function (e) {
            var named = fields.filter(function (f) { return (f.label || '').trim() !== ''; });
            var emptyChoice = named.filter(function (f) { return f.type === 'select' && !(f.options || []).length; });
            if (emptyChoice.length) {
                e.preventDefault();
                if (window.NX) NX.toast('Add some choices', 'A choice list needs at least one option.', 'error');
                return;
            }
            document.getElementById('inv-categories-value').value = state['inv-categories'].join(', ');
            document.getElementById('inv-units-value').value = state['inv-units'].join(', ');
            document.getElementById('inv-fields-value').value = JSON.stringify(named);
        });

        drawChips('inv-categories');
        drawChips('inv-units');
        drawFields();
    })();
    </script>
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
                        <?php foreach (nxAccess()->roles(nxCurrentRole() === 'Developer') as $roleOpt): ?>
                            <option value="<?= htmlspecialchars($roleOpt) ?>" title="<?= htmlspecialchars(nxAccess()->describe($roleOpt)) ?>"><?= htmlspecialchars($roleOpt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[11px] text-slate-500 mt-1">What each role can do is set in <a href="access.php" class="text-blue-700 font-semibold hover:underline">Access &amp; roles</a>.</p>
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
