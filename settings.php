<?php
$pageTitle = "Settings - NuvisMedcareX";
$activePage = "settings";
include __DIR__ . '/includes/header.php';

// Fetch current clinic and tenant settings
$settings = [];
try {
    $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
    // Fetch settings explicitly for active tenant first
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    // Fallback to clinic_settings if not defined in tenant_settings
    $cStmt = $pdo->query("SELECT setting_key, setting_value FROM clinic_settings");
    while ($row = $cStmt->fetch()) {
        if (!isset($settings[$row['setting_key']])) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    // Default branding fallback if clinic_name is empty for tenant
    if (empty($settings['clinic_name'])) {
        $tStmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ? LIMIT 1");
        $tStmt->execute([$tenantId]);
        $tName = $tStmt->fetchColumn();
        if ($tName) {
            $settings['clinic_name'] = $tName;
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
    <div class="flex flex-wrap items-center gap-2 border-b border-outline-variant/30 pb-2">
        <a href="settings.php?tab=general" class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shrink-0 <?= $tab === 'general' ? 'bg-primary text-white shadow-xs' : 'bg-surface-container-low text-slate-600 hover:bg-surface-container' ?>">
            <span class="material-symbols-outlined text-sm">tune</span>
            <span>General & Branding</span>
        </a>
        <a href="settings.php?tab=email" class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shrink-0 <?= $tab === 'email' ? 'bg-primary text-white shadow-xs' : 'bg-surface-container-low text-slate-600 hover:bg-surface-container' ?>">
            <span class="material-symbols-outlined text-sm">mail</span>
            <span>Tenant Email SMTP Setup</span>
        </a>
        <a href="settings.php?tab=email_logs" class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shrink-0 <?= $tab === 'email_logs' ? 'bg-primary text-white shadow-xs' : 'bg-surface-container-low text-slate-600 hover:bg-surface-container' ?>">
            <span class="material-symbols-outlined text-sm">mark_email_read</span>
            <span>Email Audit Logs</span>
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

    <!-- TAB: Tenant Email SMTP Setup -->
    <?php if ($tab === 'email'): ?>
    <div class="space-y-6">
        <form action="actions/admin_save_settings.php" method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="section" value="email">

            <div>
                <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2 mb-2">
                    <span class="material-symbols-outlined text-base">mail</span>
                    <span>Tenant Outgoing Email Server (SMTP) Settings</span>
                </h2>
                <p class="text-xs text-slate-500 mb-4">Configure dedicated outgoing SMTP mail server credentials for tenant <code class="font-mono font-bold text-primary"><?= htmlspecialchars(\ClinicFlow\Shared\TenantContext::getTenantId()) ?></code> to send invoices, receipts, and clinical prescriptions.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">SMTP Host Server</label>
                        <input type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="e.g. smtp.a2hosting.com or smtp.gmail.com" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">SMTP Port</label>
                        <input type="number" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>" placeholder="587" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">SMTP Username / Login</label>
                        <input type="text" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>" placeholder="user@clinicdomain.com" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">SMTP Password</label>
                        <input type="password" name="smtp_pass" value="<?= htmlspecialchars($settings['smtp_pass'] ?? '') ?>" placeholder="••••••••" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Sender Email ("From" Address)</label>
                        <input type="email" name="smtp_from" value="<?= htmlspecialchars($settings['smtp_from'] ?? '') ?>" placeholder="no-reply@clinicdomain.com" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-mono font-medium text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Encryption Protocol</label>
                        <select name="smtp_secure" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-bold text-on-surface focus:outline-none focus:border-primary focus:bg-white transition">
                            <option value="tls" <?= ($settings['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Port 587 - Recommended)</option>
                            <option value="ssl" <?= ($settings['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                            <option value="none" <?= ($settings['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-outline-variant/20">
                <button type="submit" class="px-5 py-2.5 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition shadow-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span>Save SMTP Settings</span>
                </button>
            </div>
        </form>

        <!-- Test Email Connection Panel -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-4">
            <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-base text-amber-600">mark_email_unread</span>
                <span>Test Email Settings Connection</span>
            </h3>
            <p class="text-xs text-slate-500">Send a test dispatch email to verify that your configured SMTP server credentials above can successfully establish a TLS connection and deliver messages.</p>

            <form id="test-email-form" onsubmit="executeTestEmail(event)" class="flex flex-col md:flex-row items-end gap-3 text-xs">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <div class="grow w-full">
                    <label class="block font-bold text-slate-700 mb-1">Recipient Email Address for Test Message</label>
                    <input type="email" id="test_email_recipient" name="test_email_recipient" required placeholder="doctor@clinic.com" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium text-on-surface">
                </div>
                <button type="submit" id="btn-test-email" class="px-5 py-2.5 bg-slate-900 text-white font-bold rounded-xl hover:bg-slate-800 transition shadow-xs shrink-0 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">send</span>
                    <span>Send Test Email</span>
                </button>
            </form>

            <div id="test-email-status-card" class="p-3 bg-slate-900 text-white rounded-xl space-y-1.5 hidden text-xs">
                <div class="flex items-center justify-between">
                    <span id="test-email-status-text" class="font-bold text-amber-300 flex items-center gap-1">
                        <span class="material-symbols-outlined text-xs animate-spin">sync</span>
                        <span>Testing SMTP Connection...</span>
                    </span>
                </div>
                <div id="test-email-console" class="font-mono text-[11px] text-slate-300 bg-slate-950 p-2 rounded border border-slate-800 h-16 overflow-y-auto leading-relaxed">
                    [SMTP Test Console] Connecting to SMTP server...
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- TAB: Email Audit Logs -->
    <?php if ($tab === 'email_logs'): ?>
    <?php
    $emailService = $container->get(\ClinicFlow\Services\EmailService::class);
    $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

    $logSearch = trim($_GET['search'] ?? '');
    $statusFilter = trim($_GET['status'] ?? 'all');
    $logPage = max((int)($_GET['page'] ?? 1), 1);
    $logLimit = 15;
    $logOffset = ($logPage - 1) * $logLimit;

    $logsList = $emailService->getEmailLogs($tenantId, $logLimit, $logOffset, $statusFilter, $logSearch);
    $totalLogsCount = $emailService->getEmailLogsCount($tenantId, $statusFilter, $logSearch);
    $totalLogPages = ceil($totalLogsCount / $logLimit);
    ?>
    <div class="space-y-4">
        <!-- Log Search & Filter Bar -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-4 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-3 text-xs">
            <form method="GET" action="settings.php" class="flex flex-wrap items-center gap-2 grow">
                <input type="hidden" name="tab" value="email_logs">
                <div class="relative grow max-w-sm">
                    <span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-base">search</span>
                    <input type="text" name="search" value="<?= htmlspecialchars($logSearch) ?>" placeholder="Search recipient, subject, or type..." class="w-full bg-surface-container-low pl-9 pr-3.5 py-2 rounded-xl border border-outline-variant/40 font-medium">
                </div>
                <select name="status" onchange="this.form.submit()" class="bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-bold">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Delivery Statuses</option>
                    <option value="Sent" <?= $statusFilter === 'Sent' ? 'selected' : '' ?>>Sent / Delivered</option>
                    <option value="Failed" <?= $statusFilter === 'Failed' ? 'selected' : '' ?>>Failed / Rejected</option>
                </select>
                <button type="submit" class="px-3.5 py-2 bg-primary text-white font-bold rounded-xl shadow-2xs">Filter</button>
            </form>

            <a href="settings.php?tab=email" class="px-3.5 py-2 bg-surface-container-high hover:bg-surface-variant font-bold text-on-surface rounded-xl flex items-center gap-1.5 transition shrink-0">
                <span class="material-symbols-outlined text-base">settings</span>
                <span>Configure SMTP Settings</span>
            </a>
        </div>

        <!-- Logs Table -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-surface-container-low/70 border-b border-outline-variant/30 text-slate-500 font-bold uppercase tracking-wider">
                            <th class="py-3 px-4">Recipient</th>
                            <th class="py-3 px-4">Subject</th>
                            <th class="py-3 px-4">Document / Type</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4">Dispatch Time</th>
                            <th class="py-3 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20 font-medium text-slate-800">
                        <?php if (empty($logsList)): ?>
                        <tr>
                            <td colspan="6" class="py-8 text-center text-slate-400">
                                <span class="material-symbols-outlined text-3xl block mb-1">mark_email_read</span>
                                <span>No email dispatch logs found for tenant.</span>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($logsList as $log): ?>
                        <tr class="hover:bg-surface-container-low/40 transition">
                            <td class="py-3 px-4 font-bold font-mono text-slate-900"><?= htmlspecialchars($log['recipient']) ?></td>
                            <td class="py-3 px-4 max-w-xs truncate" title="<?= htmlspecialchars($log['subject']) ?>"><?= htmlspecialchars($log['subject']) ?></td>
                            <td class="py-3 px-4">
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase <?= $log['document_type'] === 'invoice' ? 'bg-sky-100 text-sky-800' : ($log['document_type'] === 'prescription' ? 'bg-indigo-100 text-indigo-800' : ($log['document_type'] === 'receipt' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700')) ?>">
                                    <?= htmlspecialchars($log['document_type']) ?>
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <?php if ($log['status'] === 'Sent'): ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>
                                    <span>Sent</span>
                                </span>
                                <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-red-100 text-red-800" title="<?= htmlspecialchars($log['error_message'] ?? '') ?>">
                                    <span class="w-1.5 h-1.5 rounded-full bg-red-600"></span>
                                    <span>Failed</span>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 text-slate-500 font-mono text-[11px]"><?= htmlspecialchars($log['sent_at']) ?></td>
                            <td class="py-3 px-4 text-right space-x-1">
                                <button type="button" onclick='viewEmailLogDetail(<?= json_encode($log) ?>)' class="px-2.5 py-1 bg-surface-container-high hover:bg-surface-variant font-bold text-[11px] rounded-lg text-slate-700 transition">
                                    Details
                                </button>
                                <?php if ($log['status'] !== 'Sent'): ?>
                                <button type="button" onclick="resendEmailLog('<?= htmlspecialchars($log['id']) ?>')" class="px-2.5 py-1 bg-primary text-white font-bold text-[11px] rounded-lg hover:bg-primary/90 transition shadow-2xs">
                                    Retry
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <?php if ($totalLogPages > 1): ?>
            <div class="p-4 border-t border-outline-variant/30 flex items-center justify-between text-xs">
                <span class="text-slate-500">Showing page <?= $logPage ?> of <?= $totalLogPages ?> (<?= $totalLogsCount ?> logs)</span>
                <div class="flex items-center gap-1">
                    <?php if ($logPage > 1): ?>
                    <a href="settings.php?tab=email_logs&page=<?= $logPage - 1 ?>&search=<?= urlencode($logSearch) ?>&status=<?= urlencode($statusFilter) ?>" class="px-3 py-1.5 bg-surface-container-high rounded-lg font-bold text-slate-700">Previous</a>
                    <?php endif; ?>
                    <?php if ($logPage < $totalLogPages): ?>
                    <a href="settings.php?tab=email_logs&page=<?= $logPage + 1 ?>&search=<?= urlencode($logSearch) ?>&status=<?= urlencode($statusFilter) ?>" class="px-3 py-1.5 bg-surface-container-high rounded-lg font-bold text-slate-700">Next</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Email Log Detail Modal -->
    <div id="modal-email-log-detail" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-surface-container-lowest rounded-2xl max-w-lg w-full p-6 shadow-xl border border-outline-variant/30 space-y-4 text-xs">
            <div class="flex items-center justify-between border-b border-outline-variant/20 pb-3">
                <h3 class="font-bold text-slate-900 text-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary">mark_email_read</span>
                    <span>Email Dispatch Audit Details</span>
                </h3>
                <button type="button" onclick="closeModal('modal-email-log-detail')" class="text-slate-400 hover:text-slate-600">
                    <span class="material-symbols-outlined text-base">close</span>
                </button>
            </div>

            <div class="space-y-2.5 font-medium text-slate-800">
                <div>
                    <span class="text-slate-500 font-bold block text-[11px]">Recipient Address</span>
                    <code id="log-detail-recipient" class="font-mono text-xs font-bold text-slate-900 bg-slate-100 px-2 py-0.5 rounded"></code>
                </div>
                <div>
                    <span class="text-slate-500 font-bold block text-[11px]">Subject Line</span>
                    <p id="log-detail-subject" class="font-bold text-slate-900"></p>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <span class="text-slate-500 font-bold block text-[11px]">Document Type</span>
                        <span id="log-detail-type" class="font-mono text-xs"></span>
                    </div>
                    <div>
                        <span class="text-slate-500 font-bold block text-[11px]">Delivery Status</span>
                        <span id="log-detail-status" class="font-bold"></span>
                    </div>
                </div>
                <div>
                    <span class="text-slate-500 font-bold block text-[11px]">Sent Timestamp</span>
                    <span id="log-detail-time" class="font-mono text-slate-600 text-[11px]"></span>
                </div>
                <div id="log-detail-error-box" class="p-3 bg-red-50 border border-red-200 rounded-xl text-red-900 text-[11px] font-mono hidden">
                    <strong class="font-bold block mb-0.5">SMTP Delivery Error Trace:</strong>
                    <span id="log-detail-error"></span>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-outline-variant/20">
                <button type="button" onclick="closeModal('modal-email-log-detail')" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl font-bold text-slate-700">Close</button>
            </div>
        </div>
    </div>

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

<script>
function executeTestEmail(e) {
    e.preventDefault();
    const recipientInput = document.getElementById('test_email_recipient');
    const recipient = recipientInput ? recipientInput.value : '';
    const card = document.getElementById('test-email-status-card');
    const statusText = document.getElementById('test-email-status-text');
    const consoleBox = document.getElementById('test-email-console');
    const btn = document.getElementById('btn-test-email');

    if (card) card.classList.remove('hidden');
    if (btn) btn.disabled = true;

    if (statusText) {
        statusText.innerHTML = `<span class="material-symbols-outlined text-xs animate-spin">sync</span> <span>Testing SMTP Connection...</span>`;
    }

    const time = new Date().toLocaleTimeString();
    if (consoleBox) consoleBox.innerText = `[${time}] Connecting & dispatching test email to ${recipient}...`;

    const formData = new FormData();
    const formEl = document.getElementById('test-email-form');
    if (formEl) {
        const formFields = new FormData(formEl);
        for (let [k, v] of formFields.entries()) {
            formData.append(k, v);
        }
    }

    // Attach SMTP form values directly if available on tab=email page
    ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_secure'].forEach(field => {
        const input = document.querySelector(`[name="${field}"]`);
        if (input) {
            formData.append(field, input.value);
        }
    });

    fetch('actions/test_email.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(res => res.json())
    .then(data => {
        if (btn) btn.disabled = false;
        const t2 = new Date().toLocaleTimeString();
        if (data.success) {
            if (statusText) statusText.innerHTML = `<span class="material-symbols-outlined text-xs text-emerald-400">check_circle</span> <span class="text-emerald-400 font-bold">Test Email Delivered Successfully!</span>`;
            if (consoleBox) consoleBox.innerText += `\n[${t2}] ✔ Success: ${data.message || 'Email sent via SMTP.'}`;
        } else {
            if (statusText) statusText.innerHTML = `<span class="material-symbols-outlined text-xs text-red-400">error</span> <span class="text-red-400 font-bold">SMTP Connection Test Failed</span>`;
            if (consoleBox) consoleBox.innerText += `\n[${t2}] ❌ Error: ${data.error || 'Failed to deliver test email.'}`;
        }
    })
    .catch(err => {
        if (btn) btn.disabled = false;
        const t3 = new Date().toLocaleTimeString();
        if (statusText) statusText.innerHTML = `<span class="material-symbols-outlined text-xs text-red-400">error</span> <span class="text-red-400 font-bold">Request Exception</span>`;
        if (consoleBox) consoleBox.innerText += `\n[${t3}] ❌ Exception: ${err.message}`;
    });
}

function viewEmailLogDetail(log) {
    const elRec = document.getElementById('log-detail-recipient');
    if (elRec) elRec.innerText = log.recipient || '';
    const elSub = document.getElementById('log-detail-subject');
    if (elSub) elSub.innerText = log.subject || '';
    const elTyp = document.getElementById('log-detail-type');
    if (elTyp) elTyp.innerText = log.document_type || '';
    const elSta = document.getElementById('log-detail-status');
    if (elSta) elSta.innerText = log.status || '';
    const elTim = document.getElementById('log-detail-time');
    if (elTim) elTim.innerText = log.sent_at || '';

    const errorBox = document.getElementById('log-detail-error-box');
    if (log.error_message) {
        if (errorBox) errorBox.classList.remove('hidden');
        const elErr = document.getElementById('log-detail-error');
        if (elErr) elErr.innerText = log.error_message;
    } else {
        if (errorBox) errorBox.classList.add('hidden');
    }

    if (typeof openModal === 'function') {
        openModal('modal-email-log-detail');
    }
}

function resendEmailLog(logId) {
    if (!confirm('Resend this email dispatch to recipient now?')) return;

    const formData = new FormData();
    formData.append('action', 'resend_email');
    formData.append('log_id', logId);
    formData.append('csrf_token', '<?= getCsrfToken() ?>');

    fetch('actions/email_actions.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Email re-sent successfully.');
            location.reload();
        } else {
            alert('Retry failed: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => alert('Exception: ' + err.message));
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
