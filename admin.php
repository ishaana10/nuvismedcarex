<?php
require_once __DIR__ . '/includes/security.php';
$pageTitle = "Administrator Settings - ClinicFlow";
$activePage = "admin";
include __DIR__ . '/includes/header.php';

// Fetch current clinic settings
$settingsRows = $pdo->query("SELECT * FROM clinic_settings")->fetchAll();
$settings = [];
foreach ($settingsRows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

// Fetch user/doctor staff
$usersList = $pdo->query("SELECT * FROM doctors ORDER BY name ASC")->fetchAll();

$currentSessionUserRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Doctor';
$isDeveloper = ($currentSessionUserRole === 'Developer');

// Determine active tab from query parameter (default: users)
$activeTab = $_GET['tab'] ?? 'users';
if ($activeTab === 'developer' && !$isDeveloper) {
    $activeTab = 'users';
}
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-on-surface">System Administration</h1>
        <p class="text-xs text-outline font-medium">Manage user accounts, clinic profile, prescription templates, and system updates</p>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center gap-2 bg-surface-container-low p-1.5 rounded-2xl border border-outline-variant/30 text-xs font-bold flex-wrap">
        <button type="button" onclick="switchAdminTab('users')" id="tab-btn-users" class="px-4 py-2 rounded-xl transition flex items-center gap-1.5 <?= $activeTab === 'users' ? 'bg-primary text-white shadow-xs' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">
            <span class="material-symbols-outlined text-base">group</span>
            <span>User Management</span>
        </button>
        <button type="button" onclick="switchAdminTab('clinic')" id="tab-btn-clinic" class="px-4 py-2 rounded-xl transition flex items-center gap-1.5 <?= $activeTab === 'clinic' ? 'bg-primary text-white shadow-xs' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">
            <span class="material-symbols-outlined text-base">domain</span>
            <span>Clinic & Branding Settings</span>
        </button>
        <button type="button" onclick="switchAdminTab('vms')" id="tab-btn-vms" class="px-4 py-2 rounded-xl transition flex items-center gap-1.5 <?= $activeTab === 'vms' ? 'bg-primary text-white shadow-xs' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">
            <span class="material-symbols-outlined text-base">point_of_sale</span>
            <span>VMS Fiscal Settings</span>
        </button>
        <button type="button" onclick="switchAdminTab('inventory')" id="tab-btn-inventory" class="px-4 py-2 rounded-xl transition flex items-center gap-1.5 <?= $activeTab === 'inventory' ? 'bg-primary text-white shadow-xs' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">
            <span class="material-symbols-outlined text-base">inventory_2</span>
            <span>Inventory Settings</span>
        </button>
        <?php if ($isDeveloper): ?>
        <button type="button" onclick="switchAdminTab('developer')" id="tab-btn-developer" class="px-4 py-2 rounded-xl transition flex items-center gap-1.5 <?= $activeTab === 'developer' ? 'bg-primary text-white shadow-xs' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">
            <span class="material-symbols-outlined text-base">code</span>
            <span>Developer Options</span>
        </button>
        <?php endif; ?>
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

        <!-- User Cards Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="users-card-grid">
            <?php foreach ($usersList as $usr):
                $isActive = (isset($usr['is_active']) && (int)$usr['is_active'] === 0) ? false : true;
                $userRole = $usr['role'] ?? 'Doctor';
            ?>
                <div class="user-card p-4 rounded-2xl bg-surface-container-low border border-outline-variant/30 flex flex-col justify-between gap-3 text-xs transition hover:shadow-md" data-role="<?= htmlspecialchars($userRole) ?>" data-search="<?= htmlspecialchars(strtolower($usr['name'] . ' ' . $usr['email'] . ' ' . $usr['role'] . ' ' . $usr['specialty'])) ?>">

                    <div class="space-y-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-3">
                                <img src="<?= htmlspecialchars($usr['avatar'] ?: 'https://images.unsplash.com/photo-1537368910025-700350fe46c7?auto=format&fit=crop&q=80&w=200') ?>" class="w-12 h-12 rounded-2xl object-cover border border-slate-200 shadow-2xs shrink-0" alt="Avatar">
                                <div>
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <h3 class="font-bold text-on-surface text-sm"><?= htmlspecialchars($usr['name']) ?></h3>
                                    </div>
                                    <p class="text-[11px] text-outline font-medium truncate max-w-[180px]"><?= htmlspecialchars($usr['email']) ?></p>
                                </div>
                            </div>

                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold inline-flex items-center gap-1 <?= $isActive ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?= $isActive ? 'bg-emerald-500' : 'bg-rose-500' ?>"></span>
                                <span><?= $isActive ? 'Active' : 'Inactive' ?></span>
                            </span>
                        </div>

                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="px-2.5 py-0.5 rounded-lg bg-primary/10 text-primary font-bold text-[11px] uppercase tracking-wider">
                                <?= htmlspecialchars($userRole) ?>
                            </span>
                            <?php if (!empty($usr['specialty'])): ?>
                                <span class="px-2.5 py-0.5 rounded-lg bg-surface-container-high text-on-surface-variant font-medium text-[11px]">
                                    <?= htmlspecialchars($usr['specialty']) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="p-2.5 rounded-xl bg-surface-container-lowest border border-outline-variant/20 grid grid-cols-2 gap-2 text-[10px] font-mono text-slate-600">
                            <div><span class="text-slate-400">PRC:</span> <?= htmlspecialchars($usr['prc_number'] ?: 'N/A') ?></div>
                            <div><span class="text-slate-400">PTR:</span> <?= htmlspecialchars($usr['ptr_number'] ?: 'N/A') ?></div>
                        </div>

                        <div class="flex items-center gap-2">
                            <?php if (!empty($usr['esignature'])): ?>
                                <span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[12px]">draw</span> Signature
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($usr['digital_stamp'])): ?>
                                <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-bold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[12px]">verified</span> Digital Stamp
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-outline-variant/20 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick='openUserModal(<?= htmlspecialchars(json_encode($usr), ENT_QUOTES, "UTF-8") ?>)' class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-800 rounded-lg text-xs font-bold transition flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">edit</span>
                                <span>Edit</span>
                            </button>

                            <form action="actions/doctor_actions.php" method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="user_id" value="<?= htmlspecialchars($usr['id']) ?>">
                                <input type="hidden" name="status" value="<?= $isActive ? 0 : 1 ?>">
                                <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1 <?= $isActive ? 'bg-amber-100 text-amber-800 hover:bg-amber-200' : 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' ?>">
                                    <span class="material-symbols-outlined text-sm"><?= $isActive ? 'block' : 'check_circle' ?></span>
                                    <span><?= $isActive ? 'Deactivate' : 'Activate' ?></span>
                                </button>
                            </form>
                        </div>

                        <a href="actions/doctor_actions.php?action=delete&id=<?= urlencode($usr['id']) ?>" onclick="return confirm('Are you sure you want to permanently delete user account \'<?= htmlspecialchars(addslashes($usr['name'])) ?>\'?')" class="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Delete User Account">
                            <span class="material-symbols-outlined text-base">delete</span>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
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
                <input type="text" name="clinic_name" value="<?= htmlspecialchars($settings['clinic_name'] ?? 'ClinicFlow Medical Center') ?>" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-bold text-on-surface">
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
                <input type="email" name="clinic_email" value="<?= htmlspecialchars($settings['clinic_email'] ?? 'contact@clinicflow.com') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">DEA License Number</label>
                <input type="text" name="clinic_dea" value="<?= htmlspecialchars($settings['clinic_dea'] ?? 'FC9823019') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">NPI Number</label>
                <input type="text" name="clinic_npi" value="<?= htmlspecialchars($settings['clinic_npi'] ?? '1092830192') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Default Physician PRC License No.</label>
                <input type="text" name="doc_prc_no" value="<?= htmlspecialchars($settings['doc_prc_no'] ?? 'PRC-0098412') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Default Physician PTR No.</label>
                <input type="text" name="doc_ptr_no" value="<?= htmlspecialchars($settings['doc_ptr_no'] ?? 'PTR-8842109') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium">
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
                <textarea name="invoice_footer_note" rows="2" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 font-medium"><?= htmlspecialchars($settings['invoice_footer_note'] ?? 'Thank you for choosing ClinicFlow Medical Center for your care.') ?></textarea>
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

        <!-- Fully Customisable Medical Certificate Settings -->
        <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
            <span class="material-symbols-outlined text-base">badge</span>
            <span>4. Medical Certificate Customization</span>
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

<!-- ================= TAB: VMS FISCAL SETTINGS ================= -->
<div id="admin-tab-vms" class="<?= $activeTab === 'vms' ? '' : 'hidden' ?> space-y-6">
    <form action="actions/admin_save_settings.php" method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
            <span class="material-symbols-outlined text-base">point_of_sale</span>
            <span>FRCS VAT Monitoring System (VMS Phase 3) Configuration</span>
        </h2>

        <div class="flex items-center gap-2 p-3 bg-slate-50 border border-slate-200 rounded-xl">
            <input type="checkbox" id="vms_enabled_admin" name="vms_enabled" value="1" <?= ($settings['vms_enabled'] ?? '1') === '1' ? 'checked' : '' ?> class="w-4 h-4 text-primary rounded focus:ring-primary">
            <label for="vms_enabled_admin" class="font-bold text-on-surface text-xs">Enable FRCS VMS Fiscalization for all created invoices</label>
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

        <div class="flex justify-end pt-3 border-t border-outline-variant/20">
            <button type="submit" class="px-6 py-2.5 bg-primary text-white text-xs font-semibold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-base">save</span>
                <span>Save VMS Fiscal Configuration</span>
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

<?php if ($isDeveloper): ?>
<!-- ================= TAB: DEVELOPER OPTIONS & LOGS ================= -->
<div id="admin-tab-developer" class="<?= $activeTab === 'developer' ? '' : 'hidden' ?> space-y-6">
    <!-- 1. System Updates (Git Updater) -->
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
                    <input type="text" id="git_repo_dir" value="<?= htmlspecialchars($settings['git_repo_dir'] ?? dirname(__DIR__)) ?>" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-mono text-xs">
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

    <!-- 2. Developer Error Logger Module -->
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
<?php endif; ?>

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

                <div>
                    <label class="block font-bold text-slate-700 mb-1">PRC License Number</label>
                    <input type="text" name="prc_number" id="usr_prc" placeholder="PRC-0098412" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-mono font-medium">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">PTR Number</label>
                    <input type="text" name="ptr_number" id="usr_ptr" placeholder="PTR-8842109" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-mono font-medium">
                </div>

                <div class="md:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">Profile Avatar Image URL</label>
                    <input type="text" name="avatar" id="usr_avatar" placeholder="https://images.unsplash.com/photo-..." class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>
            </div>

            <hr class="border-slate-200">

            <!-- E-Signature Section -->
            <div class="space-y-2">
                <label class="block font-bold text-slate-700">Official E-Signature (Image File or Data URL)</label>
                <div class="flex items-center gap-3">
                    <input type="file" name="esignature_file" accept="image/*" class="text-xs text-slate-600">
                    <span class="text-xs text-slate-400 font-semibold">OR Data String:</span>
                </div>
                <textarea name="esignature_data" id="usr_esignature_data" rows="2" placeholder="data:image/png;base64,..." class="w-full bg-slate-50 p-2.5 rounded-xl border border-slate-300 font-mono text-[11px]"></textarea>
                <div id="esignature_preview" class="p-2 border border-slate-200 rounded-xl bg-slate-50 hidden">
                    <p class="text-[10px] text-slate-500 font-bold mb-1">E-Signature Preview:</p>
                    <img id="img_esignature_preview" src="" class="h-12 object-contain" alt="Signature Preview">
                </div>
            </div>

            <!-- Digital Stamp Section -->
            <div class="space-y-2">
                <label class="block font-bold text-slate-700">Official Digital Stamp (Image File or Data URL)</label>
                <div class="flex items-center gap-3">
                    <input type="file" name="stamp_file" accept="image/*" class="text-xs text-slate-600">
                    <span class="text-xs text-slate-400 font-semibold">OR Data String:</span>
                </div>
                <textarea name="digital_stamp_data" id="usr_digital_stamp_data" rows="2" placeholder="data:image/png;base64,..." class="w-full bg-slate-50 p-2.5 rounded-xl border border-slate-300 font-mono text-[11px]"></textarea>
                <div id="stamp_preview" class="p-2 border border-slate-200 rounded-xl bg-slate-50 hidden">
                    <p class="text-[10px] text-slate-500 font-bold mb-1">Digital Stamp Preview:</p>
                    <img id="img_stamp_preview" src="" class="h-16 object-contain" alt="Stamp Preview">
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
function switchAdminTab(tab) {
    document.getElementById('admin-tab-users').classList.add('hidden');
    document.getElementById('admin-tab-clinic').classList.add('hidden');
    if (document.getElementById('admin-tab-vms')) document.getElementById('admin-tab-vms').classList.add('hidden');
    if (document.getElementById('admin-tab-inventory')) document.getElementById('admin-tab-inventory').classList.add('hidden');
    if (document.getElementById('admin-tab-developer')) document.getElementById('admin-tab-developer').classList.add('hidden');

    document.getElementById('tab-btn-users').className = 'px-4 py-2 rounded-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';
    document.getElementById('tab-btn-clinic').className = 'px-4 py-2 rounded-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';
    if (document.getElementById('tab-btn-vms')) document.getElementById('tab-btn-vms').className = 'px-4 py-2 rounded-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';
    if (document.getElementById('tab-btn-inventory')) document.getElementById('tab-btn-inventory').className = 'px-4 py-2 rounded-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';
    if (document.getElementById('tab-btn-developer')) document.getElementById('tab-btn-developer').className = 'px-4 py-2 rounded-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';

    if (tab === 'users') {
        document.getElementById('admin-tab-users').classList.remove('hidden');
        document.getElementById('tab-btn-users').className = 'px-4 py-2 rounded-xl transition flex items-center gap-1.5 bg-primary text-white shadow-xs';
    } else if (tab === 'clinic') {
        document.getElementById('admin-tab-clinic').classList.remove('hidden');
        document.getElementById('tab-btn-clinic').className = 'px-4 py-2 rounded-xl transition flex items-center gap-1.5 bg-primary text-white shadow-xs';
    } else if (tab === 'vms') {
        if (document.getElementById('admin-tab-vms')) document.getElementById('admin-tab-vms').classList.remove('hidden');
        if (document.getElementById('tab-btn-vms')) document.getElementById('tab-btn-vms').className = 'px-4 py-2 rounded-xl transition flex items-center gap-1.5 bg-primary text-white shadow-xs';
    } else if (tab === 'inventory') {
        if (document.getElementById('admin-tab-inventory')) document.getElementById('admin-tab-inventory').classList.remove('hidden');
        if (document.getElementById('tab-btn-inventory')) document.getElementById('tab-btn-inventory').className = 'px-4 py-2 rounded-xl transition flex items-center gap-1.5 bg-primary text-white shadow-xs';
    } else if (tab === 'developer') {
        if (document.getElementById('admin-tab-developer')) document.getElementById('admin-tab-developer').classList.remove('hidden');
        if (document.getElementById('tab-btn-developer')) document.getElementById('tab-btn-developer').className = 'px-4 py-2 rounded-xl transition flex items-center gap-1.5 bg-primary text-white shadow-xs';
        refreshGitStatus();
        fetchDeveloperErrorLogs();
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
    const cards = document.querySelectorAll('.user-card');

    cards.forEach(card => {
        const cardRole = card.getAttribute('data-role');
        const cardSearchText = card.getAttribute('data-search') || '';

        const matchesRole = (activeRoleFilter === 'all' || cardRole === activeRoleFilter);
        const matchesQuery = (!query || cardSearchText.includes(query));

        if (matchesRole && matchesQuery) {
            card.classList.remove('hidden');
        } else {
            card.classList.add('hidden');
        }
    });
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
        document.getElementById('usr_ptr').value = usr.ptr_number || '';
        document.getElementById('usr_avatar').value = usr.avatar || '';

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
        document.getElementById('usr_ptr').value = '';
        document.getElementById('usr_avatar').value = '';
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
