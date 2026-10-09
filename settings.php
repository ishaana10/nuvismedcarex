<?php
$pageTitle = "Settings - NuvisMedcareX";
$activePage = "settings";
include __DIR__ . '/includes/header.php';

// Fetch current clinic and tenant settings
$settings = [];
try {
    $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $cStmt = $pdo->query("SELECT setting_key, setting_value FROM clinic_settings");
    while ($row = $cStmt->fetch()) {
        if (!isset($settings[$row['setting_key']])) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (\Throwable $e) {
    error_log("Settings page query error: " . $e->getMessage());
}

$tab = $_GET['tab'] ?? 'general';
?>

<div class="max-w-5xl mx-auto space-y-6">
    <!-- Header Title Bar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">System & Account Settings</h1>
            <p class="text-xs text-slate-500 font-medium">Manage user preferences, practice details, real-time alerts, and system configurations</p>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center gap-2 border-b border-outline-variant/30 pb-2 overflow-x-auto">
        <a href="settings.php?tab=general" class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shrink-0 <?= $tab === 'general' ? 'bg-primary text-white shadow-xs' : 'bg-surface-container-low text-slate-600 hover:bg-surface-container' ?>">
            <span class="material-symbols-outlined text-sm">tune</span>
            <span>General & Branding</span>
        </a>
        <a href="settings.php?tab=notifications" class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shrink-0 <?= $tab === 'notifications' ? 'bg-primary text-white shadow-xs' : 'bg-surface-container-low text-slate-600 hover:bg-surface-container' ?>">
            <span class="material-symbols-outlined text-sm">notifications</span>
            <span>Real-Time Notifications</span>
        </a>
        <a href="settings.php?tab=display" class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shrink-0 <?= $tab === 'display' ? 'bg-primary text-white shadow-xs' : 'bg-surface-container-low text-slate-600 hover:bg-surface-container' ?>">
            <span class="material-symbols-outlined text-sm">display_settings</span>
            <span>Display & Pagination</span>
        </a>
        <a href="settings.php?tab=security" class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shrink-0 <?= $tab === 'security' ? 'bg-primary text-white shadow-xs' : 'bg-surface-container-low text-slate-600 hover:bg-surface-container' ?>">
            <span class="material-symbols-outlined text-sm">shield</span>
            <span>Security & Access</span>
        </a>
    </div>

    <!-- TAB 1: General & Branding Settings -->
    <?php if ($tab === 'general'): ?>
    <form action="actions/admin_save_settings.php" method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="section" value="general">

        <div>
            <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-base">domain</span>
                <span>Practice & Branding Information</span>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div class="md:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">Clinic / Practice Name *</label>
                    <input type="text" name="clinic_name" value="<?= htmlspecialchars($settings['clinic_name'] ?? 'Nuvis Medico Healthcare') ?>" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-bold text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Subtitle / Tagline</label>
                    <input type="text" name="clinic_subtitle" value="<?= htmlspecialchars($settings['clinic_subtitle'] ?? 'Specialist Medical & Surgical Center') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Contact Phone</label>
                    <input type="text" name="clinic_phone" value="<?= htmlspecialchars($settings['clinic_phone'] ?? '+679 666 1234') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Contact Email</label>
                    <input type="email" name="clinic_email" value="<?= htmlspecialchars($settings['clinic_email'] ?? 'care@nuvismedico.com.fj') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Default Physician Medical License No.</label>
                    <input type="text" name="doc_prc_no" value="<?= htmlspecialchars($settings['doc_prc_no'] ?? 'LIC-0098412') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                </div>

                <div class="md:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">Physical Practice Address</label>
                    <input type="text" name="clinic_address" value="<?= htmlspecialchars($settings['clinic_address'] ?? '15 Vitogo Parade, Lautoka, Fiji') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-4 border-t border-outline-variant/20">
            <button type="submit" class="px-5 py-2.5 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition shadow-xs flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">save</span>
                <span>Save Practice Settings</span>
            </button>
        </div>
    </form>
    <?php endif; ?>

    <!-- TAB 2: Notifications & SSE Settings -->
    <?php if ($tab === 'notifications'): ?>
    <form action="actions/admin_save_settings.php" method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="sse_setting_submitted" value="1">

        <div>
            <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-base">notifications_active</span>
                <span>Real-Time Notifications & SSE Alert Preferences</span>
            </h2>

            <?php $sseEnabled = ($settings['sse_notifications_enabled'] ?? '1') === '1'; ?>

            <div class="p-4 rounded-xl bg-surface-container-low border border-outline-variant/30 space-y-3 text-xs">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-bold text-slate-900">Real-Time Patient Registration Toasts (SSE)</p>
                        <p class="text-slate-500 font-medium text-[11px]">Receive instant audio and toast alerts whenever a new patient is registered in the clinic</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="sse_notifications_enabled" value="1" <?= $sseEnabled ? 'checked' : '' ?> class="sr-only peer">
                        <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
                    </label>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-4 border-t border-outline-variant/20">
            <button type="submit" class="px-5 py-2.5 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition shadow-xs flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">save</span>
                <span>Save Notification Preferences</span>
            </button>
        </div>
    </form>
    <?php endif; ?>

    <!-- TAB 3: Display & Pagination Settings -->
    <?php if ($tab === 'display'): ?>
    <form action="actions/admin_save_settings.php" method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="section" value="display">

        <div>
            <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-base">format_list_numbered</span>
                <span>Default Pagination & Table Display Limits</span>
            </h2>

            <?php $defaultLimit = (int)($settings['default_pagination_limit'] ?? 10); ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Default Table Rows Per Page</label>
                    <select name="default_pagination_limit" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-bold text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                        <option value="5" <?= $defaultLimit === 5 ? 'selected' : '' ?>>5 Records per Page</option>
                        <option value="10" <?= $defaultLimit === 10 ? 'selected' : '' ?>>10 Records per Page</option>
                        <option value="25" <?= $defaultLimit === 25 ? 'selected' : '' ?>>25 Records per Page</option>
                        <option value="50" <?= $defaultLimit === 50 ? 'selected' : '' ?>>50 Records per Page</option>
                        <option value="100" <?= $defaultLimit === 100 ? 'selected' : '' ?>>100 Records per Page</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-4 border-t border-outline-variant/20">
            <button type="submit" class="px-5 py-2.5 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition shadow-xs flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">save</span>
                <span>Save Display Preferences</span>
            </button>
        </div>
    </form>
    <?php endif; ?>

    <!-- TAB 4: Security & Access Quick Links -->
    <?php if ($tab === 'security'): ?>
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6 text-xs">
        <div>
            <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-base">security</span>
                <span>Security Policies & Account Access</span>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 bg-surface-container-low rounded-xl border border-outline-variant/30 space-y-2">
                    <p class="font-bold text-slate-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-primary">key</span>
                        <span>Account Password</span>
                    </p>
                    <p class="text-slate-500 font-medium">Update your account password following compliance requirements (min 8 chars, 1 uppercase, 1 digit).</p>
                    <button type="button" onclick="openChangePasswordModal()" class="px-3.5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 transition shadow-2xs inline-flex items-center gap-1 mt-2">
                        <span>Change Password</span>
                    </button>
                </div>

                <div class="p-4 bg-surface-container-low rounded-xl border border-outline-variant/30 space-y-2">
                    <p class="font-bold text-slate-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-primary">admin_panel_settings</span>
                        <span>User Role & Administrative Access</span>
                    </p>
                    <p class="text-slate-500 font-medium">Role-based permissions and user directory management are controlled via the Administrator portal.</p>
                    <a href="admin.php?tab=users" class="px-3.5 py-2 bg-surface-container-high text-on-surface font-semibold rounded-xl hover:bg-surface-variant transition inline-flex items-center gap-1 mt-2">
                        <span>Open User Directory</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
