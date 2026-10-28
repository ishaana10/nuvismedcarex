<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';

require_once __DIR__ . '/core/SessionBootstrap.php';

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
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// Settings for the active clinic: its own values over the shared defaults
$settings = nxSettings();

$tenantService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\TenantService::class);
$tenantsList = $tenantService->getAllTenants();
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$labOrderService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\LabOrderService::class);
$uploadService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\FileUploadService::class);
$cloudSettings = $uploadService->getCloudSettings();
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
    <button type="button" onclick="switchDevTab('cloud')" id="dev-tab-btn-cloud" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high">
        <span class="material-symbols-outlined text-base">cloud</span>
        <span>Cloud Storage & Drive</span>
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
    <button type="button" onclick="switchDevTab('redis')" id="dev-tab-btn-redis" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high">
        <span class="material-symbols-outlined text-base">memory</span>
        <span>Redis Cache & Sessions</span>
    </button>
    <button type="button" onclick="switchDevTab('logs')" id="dev-tab-btn-logs" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high">
        <span class="material-symbols-outlined text-base">bug_report</span>
        <span>Error Logs & Diagnostics</span>
    </button>
    <button type="button" onclick="switchDevTab('integrations')" id="dev-tab-btn-integrations" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high">
        <span class="material-symbols-outlined text-base">api</span>
        <span>API Integration & Webhooks</span>
    </button>
    <button type="button" onclick="switchDevTab('database')" id="dev-tab-btn-database" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high">
        <span class="material-symbols-outlined text-base">database</span>
        <span>Database &amp; Migrations</span>
        <?php
        $nxPendingCount = 0;
        try {
            $nxPendingCount = count((new \ClinicFlow\Services\MigrationRunner($pdo))->pending());
        } catch (\Throwable $e) {
            error_log('[developer] pending migrations: ' . $e->getMessage());
        }
        if ($nxPendingCount > 0): ?>
            <span class="px-1.5 py-0.5 rounded-full bg-amber-500 text-white text-[10px] font-bold"><?= $nxPendingCount ?></span>
        <?php endif; ?>
    </button>
    <button type="button" onclick="switchDevTab('vsdc')" id="dev-tab-btn-vsdc" class="px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high">
        <span class="material-symbols-outlined text-base">verified</span>
        <span>FRCS VSDC & EFD Accreditation</span>
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

    <!-- 5. FRCS VSDC & EFD Accreditation Module -->
    <div id="dev-panel-vsdc" class="hidden space-y-6">
        <?php
        $certPath = $settings['vms_cert_file_path'] ?? '';
        $certDetails = json_decode($settings['vms_cert_details'] ?? '{}', true) ?: [];
        $vmsLogs = [];
        try {
            $logStmt = $pdo->prepare("SELECT * FROM vms_logs WHERE tenant_id = ? OR tenant_id = 'default-clinic' ORDER BY created_at DESC LIMIT 15");
            $logStmt->execute([$currentTenantId]);
            $vmsLogs = $logStmt->fetchAll();
        } catch (\Throwable $e) {}
        ?>
        <!-- Section 1: Digital Certificate (.pfx / .p12 / .pem) Upload & Key Store Inspector -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
            <div class="border-b border-outline-variant/20 pb-4">
                <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">verified</span>
                    <span>1. FRCS Accredited VSDC Digital Certificate (.pfx / .p12 / .pem) Upload</span>
                </h2>
                <p class="text-xs text-outline font-medium mt-0.5">Upload official FRCS V-SDC client certificate issued by Fiji Revenue and Customs Service for mutual TLS (mTLS) authentication.</p>
            </div>

            <form id="vsdcCertForm" onsubmit="uploadVsdcCertificate(event)" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div class="md:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">PKCS#12 Certificate File (.pfx, .p12, .crt, .pem) <span class="text-red-500">*</span></label>
                    <input type="file" name="vms_cert_file" required accept=".pfx,.p12,.crt,.pem,.cer" class="w-full bg-slate-50 p-2.5 rounded-xl border border-slate-300 font-mono text-xs">
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Certificate Private Key Passphrase <span class="text-red-500">*</span></label>
                    <input type="password" name="vms_cert_passphrase" placeholder="FRCS Certificate Passphrase" class="w-full bg-slate-50 px-3.5 py-2.5 rounded-xl border border-slate-300 font-mono font-medium">
                </div>

                <div class="flex items-end">
                    <button type="submit" class="w-full py-2.5 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 transition shadow-xs flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-base">upload_file</span>
                        <span>Upload & Validate Certificate</span>
                    </button>
                </div>
            </form>

            <!-- Certificate Inspector & V3.0 Submitter Test Controls Box -->
            <div class="p-4 rounded-2xl bg-surface-container-low border border-outline-variant/30 space-y-2 text-xs">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <span class="font-bold text-on-surface flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base text-emerald-600">workspace_premium</span>
                        <span>VSDC Submitter V3.0 Status: <?= !empty($certPath) ? '<span class="text-emerald-700 font-bold">Certificate Active & Installed</span>' : '<span class="text-amber-700 font-bold">No Accredited Certificate Uploaded</span>' ?></span>
                    </span>
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <button type="button" onclick="testSdcPing('test_sdc_ping')" class="px-2.5 py-1 bg-amber-700 hover:bg-amber-800 text-white font-bold text-xs rounded-xl transition flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">speed</span>
                            <span>/api/v3/status</span>
                        </button>
                        <button type="button" onclick="testSdcPing('get_environment')" class="px-2.5 py-1 bg-blue-700 hover:bg-blue-800 text-white font-bold text-xs rounded-xl transition flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">dns</span>
                            <span>/api/v3/environment</span>
                        </button>
                        <button type="button" onclick="testSdcPing('get_taxrates')" class="px-2.5 py-1 bg-purple-700 hover:bg-purple-800 text-white font-bold text-xs rounded-xl transition flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">percent</span>
                            <span>/api/v3/taxrates</span>
                        </button>
                    </div>
                </div>

                <?php if (!empty($certDetails)): ?>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 pt-2 border-t border-outline-variant/20 font-mono text-[11px] text-slate-600">
                        <div><span class="text-slate-400">Subject:</span> <?= htmlspecialchars($certDetails['subject'] ?? 'N/A') ?></div>
                        <div><span class="text-slate-400">Issuer:</span> <?= htmlspecialchars($certDetails['issuer'] ?? 'N/A') ?></div>
                        <div><span class="text-slate-400">Valid From:</span> <?= htmlspecialchars($certDetails['valid_from'] ?? 'N/A') ?></div>
                        <div><span class="text-slate-400">Valid To:</span> <?= htmlspecialchars($certDetails['valid_to'] ?? 'N/A') ?></div>
                    </div>
                <?php endif; ?>

                <div id="sdc-ping-console" class="font-mono text-xs bg-slate-950 text-amber-300 p-3 rounded-xl border border-slate-800 max-h-36 overflow-y-auto leading-relaxed space-y-1 shadow-inner hidden mt-2"></div>
            </div>
        </div>

        <!-- Section 2: FRCS EFD Accreditation Checklist Matrix -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
            <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-base">fact_check</span>
                <span>2. FRCS EFD Accreditation Requirements Checklist Matrix</span>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 flex items-start gap-2.5">
                    <span class="material-symbols-outlined text-emerald-600 text-lg">check_circle</span>
                    <div>
                        <p class="font-bold text-on-surface">SDC Request/Response Schema Compliance</p>
                        <p class="text-[11px] text-outline mt-0.5">Supports VMS Phase 3 specification JSON structures with 4-decimal tax precision.</p>
                    </div>
                </div>

                <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 flex items-start gap-2.5">
                    <span class="material-symbols-outlined text-emerald-600 text-lg">check_circle</span>
                    <div>
                        <p class="font-bold text-on-surface">Fiji VAT Tax Labels (A, E, F, P)</p>
                        <p class="text-[11px] text-outline mt-0.5">Applies standard VAT (15%), Exempt (0%), Zero-rated (0%), and Special Tax (0.25%).</p>
                    </div>
                </div>

                <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 flex items-start gap-2.5">
                    <span class="material-symbols-outlined text-emerald-600 text-lg">check_circle</span>
                    <div>
                        <p class="font-bold text-on-surface">Cancellation Rules (Section 10.2)</p>
                        <p class="text-[11px] text-outline mt-0.5">Automatically sets Buyer TIN equal to Seller TIN on refund and cancellation invoices.</p>
                    </div>
                </div>

                <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 flex items-start gap-2.5">
                    <span class="material-symbols-outlined text-emerald-600 text-lg">check_circle</span>
                    <div>
                        <p class="font-bold text-on-surface">Daily Fiscal Summary (Z-Report)</p>
                        <p class="text-[11px] text-outline mt-0.5">Generates PDF reports breakdown by transaction type and payment methods.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 3: FRCS Audit Trail Log Payload Inspector -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
            <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-base">data_object</span>
                <span>3. FRCS V-SDC Raw Payload Audit Trail Log</span>
            </h2>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border border-outline-variant/30 rounded-xl overflow-hidden">
                    <thead class="bg-surface-container text-outline uppercase font-semibold text-[10px]">
                        <tr>
                            <th class="py-2.5 px-3">Timestamp</th>
                            <th class="py-2.5 px-3">Invoice Ref</th>
                            <th class="py-2.5 px-3">Event Type</th>
                            <th class="py-2.5 px-3">Payload Inspector</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20 font-mono text-[11px]">
                        <?php if (empty($vmsLogs)): ?>
                            <tr>
                                <td colspan="4" class="py-6 text-center text-slate-400 italic font-sans">No V-SDC audit log entries recorded.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($vmsLogs as $vl): ?>
                                <tr class="hover:bg-surface-container-low/50 transition">
                                    <td class="py-2.5 px-3 text-slate-500 whitespace-nowrap"><?= htmlspecialchars($vl['created_at']) ?></td>
                                    <td class="py-2.5 px-3 font-bold text-primary"><?= htmlspecialchars($vl['invoice_id'] ?? 'N/A') ?></td>
                                    <td class="py-2.5 px-3 font-bold text-on-surface"><?= htmlspecialchars($vl['event_type']) ?></td>
                                    <td class="py-2.5 px-3">
                                        <details class="cursor-pointer">
                                            <summary class="text-slate-600 underline text-[10px] font-sans">View Payload JSON</summary>
                                            <pre class="mt-1 p-2 bg-slate-950 text-emerald-400 rounded text-[10px] overflow-x-auto whitespace-pre-wrap"><?= htmlspecialchars($vl['request_payload'] ?: $vl['response_payload']) ?></pre>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div> <!-- End dev-panel-vsdc -->

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
                                <div class="col-span-2"><span class="text-slate-400">Drive Storage Provider:</span> <span class="font-bold text-amber-700 uppercase"><?= htmlspecialchars($tnt['storage_provider'] ?? 'server') ?></span></div>
                                <div class="col-span-2 font-bold text-emerald-800"><span class="text-slate-400 font-normal">Pricing:</span> <?= htmlspecialchars($tnt['currency'] ?? 'FJD') ?> $<?= number_format((float)($tnt['plan_price'] ?? 0), 2) ?> / <?= htmlspecialchars(ucfirst($tnt['billing_cycle'] ?? 'monthly')) ?></div>
                                <?php if (!empty($tnt['nuvis_erp_client_id'])): ?>
                                    <div class="col-span-2 text-primary font-bold"><span class="text-slate-400 font-normal">Nuvis ERP ID:</span> <?= htmlspecialchars($tnt['nuvis_erp_client_id']) ?></div>
                                <?php endif; ?>
                                <div class="col-span-2"><span class="text-slate-400">Tenant ID:</span> <?= htmlspecialchars($tnt['id']) ?></div>
                            </div>

                            <p class="text-[11px] text-slate-600 flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs text-slate-400">location_on</span>
                                <span><?= htmlspecialchars($tnt['address'] ?: 'No address specified') ?></span>
                            </p>
                        </div>

                        <div class="pt-3 border-t border-outline-variant/20 flex items-center justify-between gap-2">
                            <form action="actions/tenant_actions.php" method="POST" class="flex-1">
                                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                <input type="hidden" name="action" value="switch_tenant">
                                <input type="hidden" name="tenant_id" value="<?= htmlspecialchars($tnt['id']) ?>">
                                <button type="submit" <?= $isCurrent ? 'disabled' : '' ?> class="w-full py-2 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 <?= $isCurrent ? 'bg-slate-200 text-slate-500 cursor-not-allowed' : 'bg-primary text-white hover:bg-primary/90' ?>">
                                    <span class="material-symbols-outlined text-base">swap_horiz</span>
                                    <span><?= $isCurrent ? 'Current Active Clinic' : 'Switch' ?></span>
                                </button>
                            </form>
                            <button type="button" onclick='openTenantModal(<?= htmlspecialchars(json_encode($tnt), ENT_QUOTES, "UTF-8") ?>)' class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold rounded-xl transition flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">edit</span>
                                <span>Edit</span>
                            </button>
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

    $currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
    $usersStmt = $pdo->prepare("SELECT id, name, email, role, is_active FROM doctors WHERE tenant_id = ? OR tenant_id IS NULL ORDER BY name ASC");
    $usersStmt->execute([$currentTenantId]);
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

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-xl border border-blue-200 bg-blue-50 text-[13px] text-blue-900">
            <p>Role permissions are now set per clinic and enforced on every page and action. Manage them in <span class="font-semibold">Administration → Access &amp; roles</span>.</p>
            <a href="access.php" class="btn-primary !text-[13px] !py-1.5 shrink-0">Open Access &amp; roles</a>
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

    <!-- Cloud Storage & Drive Integration Panel -->
    <!-- Database schema and migrations -->
    <div id="dev-panel-database" class="hidden space-y-4">
        <?php
        $nxRunner = null;
        $nxApplied = [];
        $nxPending = [];
        try {
            $nxRunner = new \ClinicFlow\Services\MigrationRunner($pdo);
            $nxApplied = $nxRunner->getAppliedMigrations();
            $nxPending = $nxRunner->pending();
        } catch (\Throwable $e) {
            error_log('[developer] migrations: ' . $e->getMessage());
        }
        $nxSigReady = \ClinicFlow\Services\InsuranceService::hasSignatureSupport($pdo);
        ?>

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-on-surface">Database schema</h2>
                <p class="text-xs text-outline mt-0.5">
                    Schema changes ship with the code and are applied automatically. One that cannot be applied is listed here
                    rather than leaving the application running against a database missing what it expects.
                </p>
            </div>
            <?php if ($nxPending): ?>
                <form action="actions/run_migrations.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                    <button type="submit" class="px-4 py-2 bg-primary text-white rounded-xl text-xs font-semibold hover:bg-primary/90 transition flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">play_arrow</span>
                        <span>Apply <?= count($nxPending) ?> pending change<?= count($nxPending) === 1 ? '' : 's' ?></span>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($nxPending): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
                <h3 class="text-xs font-bold text-amber-900 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">pending_actions</span>
                    <span>Not applied yet</span>
                </h3>
                <ul class="mt-2 space-y-1 font-mono text-[11px] text-amber-900">
                    <?php foreach ($nxPending as $version): ?>
                        <li>&bull; <?= htmlspecialchars($version) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 text-xs text-emerald-900 flex items-center gap-2">
                <span class="material-symbols-outlined text-base">check_circle</span>
                <span>Every schema change has been applied.</span>
            </div>
        <?php endif; ?>

        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xs p-4">
            <h3 class="text-xs font-bold text-on-surface mb-2">What the features need</h3>
            <ul class="space-y-1.5 text-xs">
                <li class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-base <?= $nxSigReady ? 'text-emerald-600' : 'text-amber-600' ?>"><?= $nxSigReady ? 'check_circle' : 'error' ?></span>
                    <span class="text-on-surface">Insurance claim signatures</span>
                    <span class="text-outline font-mono text-[11px]">insurance_claims.patient_signature</span>
                    <?php if (!$nxSigReady): ?>
                        <span class="text-amber-700">— claims cannot be signed until this is applied</span>
                    <?php endif; ?>
                </li>
            </ul>
        </div>

        <details class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xs p-4">
            <summary class="text-xs font-bold text-on-surface cursor-pointer">Applied changes (<?= count($nxApplied) ?>)</summary>
            <ul class="mt-3 space-y-1 font-mono text-[11px] text-outline max-h-64 overflow-y-auto">
                <?php foreach (array_reverse($nxApplied) as $version): ?>
                    <li><?= htmlspecialchars((string) $version) ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
    </div>

    <div id="dev-panel-cloud" class="hidden space-y-4">
        <?php
        // How many documents each drive is holding, so the list says something
        // about what is actually in use rather than only what is configured.
        $driveCounts = ['server' => 0, 'onedrive' => 0, 'googledrive' => 0];
        try {
            $dcStmt = $pdo->prepare("SELECT storage_provider, COUNT(*) AS n FROM uploaded_files WHERE tenant_id = ? GROUP BY storage_provider");
            $dcStmt->execute([\ClinicFlow\Shared\TenantContext::getTenantId()]);
            foreach ($dcStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $dcRow) {
                $driveCounts[(string) $dcRow['storage_provider']] = (int) $dcRow['n'];
            }
        } catch (\Throwable $e) {
            error_log('[developer] drive counts: ' . $e->getMessage());
        }
        $serverUsage = $uploadService->getStorageUsage();
        ?>

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-on-surface">Document storage</h2>
                <p class="text-xs text-outline mt-0.5">
                    Where uploaded documents are kept. Each drive is configured and tested on its own.
                </p>
            </div>
            <a href="uploads.php" class="text-xs font-medium text-primary hover:underline">Open the document centre</a>
        </div>

        <!-- Local server: always present, nothing to configure -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xs p-4 flex flex-wrap items-center gap-3">
            <span class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined">dns</span>
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <h3 class="text-xs font-bold text-on-surface">This server</h3>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Always on</span>
                </div>
                <p class="text-[11px] text-outline mt-0.5">
                    <?= number_format($driveCounts['server']) ?> document<?= $driveCounts['server'] === 1 ? '' : 's' ?> ·
                    <?= htmlspecialchars($serverUsage['formatted_used'] ?? '0 B') ?> of
                    <?= htmlspecialchars($serverUsage['formatted_limit'] ?? '') ?> used
                </p>
            </div>
            <span class="text-[11px] text-outline">No credentials needed</span>
        </div>

        <?php foreach (\ClinicFlow\Services\CloudDrives::DRIVES as $driveKey => $drive):
            $status = \ClinicFlow\Services\CloudDrives::status($driveKey, $cloudSettings);
            $count = $driveCounts[$driveKey] ?? 0;
            $tone = $drive['tone']; ?>
            <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xs overflow-hidden" data-drive="<?= $driveKey ?>">
                <div class="p-4 flex flex-wrap items-center gap-3">
                    <span class="w-10 h-10 rounded-xl bg-<?= $tone ?>-50 text-<?= $tone ?>-700 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined"><?= $drive['icon'] ?></span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="text-xs font-bold text-on-surface"><?= htmlspecialchars($drive['label']) ?></h3>
                            <?php if ($status['configured']): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Configured</span>
                            <?php elseif ($status['filled'] > 0): ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">Incomplete</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200">Not set up</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-[11px] text-outline mt-0.5">
                            <?= number_format($count) ?> document<?= $count === 1 ? '' : 's' ?> ·
                            <?= $status['filled'] ?> of <?= $status['total'] ?> settings filled in
                            <?php if ($status['missing']): ?>
                                · <span class="text-amber-700">still needs <?= htmlspecialchars(implode(', ', $status['missing'])) ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="testDrive('<?= $driveKey ?>')"
                                class="px-3 py-1.5 bg-surface-container border border-outline-variant/40 text-on-surface rounded-xl text-xs font-semibold hover:bg-surface-container-high transition flex items-center gap-1.5"
                                id="drive-test-btn-<?= $driveKey ?>" <?= $status['filled'] === 0 ? 'disabled' : '' ?>>
                            <span class="material-symbols-outlined text-base">network_check</span>
                            <span>Test</span>
                        </button>
                        <button type="button" onclick="toggleDrive('<?= $driveKey ?>')"
                                class="px-3 py-1.5 bg-primary text-white rounded-xl text-xs font-semibold hover:bg-primary/90 transition flex items-center gap-1.5"
                                aria-expanded="false" aria-controls="drive-body-<?= $driveKey ?>" id="drive-toggle-<?= $driveKey ?>">
                            <span class="material-symbols-outlined text-base">tune</span>
                            <span>Configure</span>
                        </button>
                    </div>
                </div>

                <!-- Test output -->
                <pre id="drive-test-out-<?= $driveKey ?>" class="hidden mx-4 mb-4 p-3 bg-slate-900 text-slate-100 rounded-xl text-[11px] leading-relaxed overflow-x-auto max-h-56"></pre>

                <!-- Configuration -->
                <div id="drive-body-<?= $driveKey ?>" class="hidden border-t border-outline-variant/20 bg-surface-container-low/50 p-4">
                    <p class="text-[11px] text-outline mb-3">
                        <?= htmlspecialchars($drive['blurb']) ?>
                        <a href="<?= htmlspecialchars($drive['docs']) ?>" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline">Set-up guide</a>
                    </p>
                    <form action="actions/cloud_storage_settings.php" method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <input type="hidden" name="drive" value="<?= $driveKey ?>">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                            <?php foreach ($drive['fields'] as $fieldKey => $meta):
                                $stored = trim((string) ($cloudSettings[$fieldKey] ?? ''));
                                $isSecret = $meta['type'] !== 'text'; ?>
                                <div class="<?= $meta['type'] === 'textarea' ? 'md:col-span-3' : '' ?>">
                                    <label class="block font-bold text-on-surface mb-1" for="f-<?= $fieldKey ?>">
                                        <?= htmlspecialchars($meta['label']) ?>
                                        <?php if (empty($meta['required'])): ?><span class="font-normal text-outline">(optional)</span><?php endif; ?>
                                    </label>
                                    <?php if ($meta['type'] === 'textarea'): ?>
                                        <textarea id="f-<?= $fieldKey ?>" name="<?= $fieldKey ?>" rows="3"
                                                  placeholder="<?= $stored !== '' ? 'Stored — paste a new one to replace it' : htmlspecialchars($meta['placeholder']) ?>"
                                                  class="w-full bg-surface-container-low p-2.5 rounded-xl border border-outline-variant/40 font-mono text-[11px]"></textarea>
                                    <?php else: ?>
                                        <input id="f-<?= $fieldKey ?>" type="<?= $isSecret ? 'password' : 'text' ?>" name="<?= $fieldKey ?>"
                                               autocomplete="off" spellcheck="false"
                                               value="<?= $isSecret ? '' : htmlspecialchars($stored) ?>"
                                               placeholder="<?= $isSecret && $stored !== '' ? htmlspecialchars(\ClinicFlow\Services\CloudDrives::mask($stored)) : htmlspecialchars($meta['placeholder']) ?>"
                                               class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-mono text-xs">
                                    <?php endif; ?>
                                    <p class="text-[10px] text-outline mt-1">
                                        <?= htmlspecialchars($meta['hint']) ?>
                                        <?php if ($isSecret && $stored !== ''): ?>
                                            <span class="text-emerald-700">Stored — leave blank to keep it.</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex flex-wrap items-center justify-end gap-2 pt-3 border-t border-outline-variant/20">
                            <?php if ($status['filled'] > 0): ?>
                                <button type="submit" name="action" value="clear"
                                        class="mr-auto text-xs font-medium text-red-700 hover:underline"
                                        data-nx-confirm="Clear every <?= htmlspecialchars($drive['label']) ?> credential? Documents already stored there stay where they are.">
                                    Clear these credentials
                                </button>
                            <?php endif; ?>
                            <button type="button" onclick="toggleDrive('<?= $driveKey ?>')" class="px-4 py-2 bg-surface-container rounded-xl text-xs font-semibold text-on-surface">Close</button>
                            <button type="submit" name="action" value="save" class="px-5 py-2 bg-primary text-white text-xs font-semibold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-base">save</span>
                                <span>Save <?= htmlspecialchars($drive['label']) ?></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div> <!-- End dev-panel-cloud -->

    <!-- Redis Cache & Sessions Diagnostics Panel -->
    <div id="dev-panel-redis" class="hidden space-y-6">
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-outline-variant/20 pb-4">
                <div>
                    <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">memory</span>
                        <span>Redis High-Performance Cache & Session Management Status</span>
                    </h2>
                    <p class="text-xs text-outline font-medium mt-0.5">Live memory diagnostics, latency benchmark, active sessions, and key metrics</p>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" onclick="fetchRedisDiagnostics()" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold rounded-xl transition flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">refresh</span>
                        <span>Refresh Metrics</span>
                    </button>
                    <button type="button" onclick="testRedisPing()" class="px-3.5 py-1.5 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition shadow-xs flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">speed</span>
                        <span>Test Latency (Benchmark)</span>
                    </button>
                </div>
            </div>

            <!-- Top Metric Cards Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Card 1: Connection Status -->
                <div class="p-4 rounded-2xl bg-surface-container-low border border-outline-variant/30 space-y-1">
                    <p class="text-[11px] font-bold text-outline uppercase tracking-wider">Connection Status</p>
                    <div id="redis-card-status" class="text-sm font-bold flex items-center gap-2 mt-1">
                        <span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span>
                        <span class="text-slate-600">Checking connection...</span>
                    </div>
                    <p id="redis-card-scheme" class="text-[10px] text-slate-500 font-mono mt-0.5">Scheme: unix socket</p>
                </div>

                <!-- Card 2: Server Memory & Version -->
                <div class="p-4 rounded-2xl bg-surface-container-low border border-outline-variant/30 space-y-1">
                    <p class="text-[11px] font-bold text-outline uppercase tracking-wider">Redis Memory & Engine</p>
                    <div id="redis-card-memory" class="text-sm font-bold text-on-surface mt-1">--</div>
                    <p id="redis-card-version" class="text-[10px] text-slate-500 font-mono mt-0.5">Version: --</p>
                </div>

                <!-- Card 3: Active Cache Keys -->
                <div class="p-4 rounded-2xl bg-surface-container-low border border-outline-variant/30 space-y-1">
                    <p class="text-[11px] font-bold text-outline uppercase tracking-wider">Cached App Objects</p>
                    <div id="redis-card-keys" class="text-xl font-bold text-primary mt-1">0</div>
                    <p class="text-[10px] text-slate-500 font-mono mt-0.5">Prefix: nuvis:*</p>
                </div>

                <!-- Card 4: Active Sessions -->
                <div class="p-4 rounded-2xl bg-surface-container-low border border-outline-variant/30 space-y-1">
                    <p class="text-[11px] font-bold text-outline uppercase tracking-wider">Redis Active Sessions</p>
                    <div id="redis-card-sessions" class="text-xl font-bold text-emerald-600 mt-1">0</div>
                    <p class="text-[10px] text-slate-500 font-mono mt-0.5">Prefix: nuvis:sess:*</p>
                </div>
            </div>

            <!-- Benchmark Console / Notification Card -->
            <div id="redis-benchmark-result" class="hidden p-4 rounded-2xl bg-blue-50 border border-blue-200 text-xs font-mono text-blue-900 space-y-1">
                <div class="font-bold text-blue-900 flex items-center gap-1">
                    <span class="material-symbols-outlined text-base text-blue-700">timer</span>
                    <span>Latency Benchmark Results:</span>
                </div>
                <div id="redis-benchmark-text"></div>
            </div>

            <!-- A2 Hosting Socket & Daemon Controls -->
            <div class="p-4 rounded-2xl bg-surface-container-low border border-outline-variant/30 space-y-3 text-xs">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <h3 class="font-bold text-on-surface text-xs flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base text-amber-600">extension</span>
                            <span>A2 Hosting Turbo Max Redis Daemon Initialization</span>
                        </h3>
                        <p class="text-[11px] text-slate-500 mt-0.5">
                            Socket Path: <code id="redis-socket-path-display" class="bg-white px-1.5 py-0.5 rounded font-mono border border-slate-200 text-slate-800">/home/ictfjcom/.redis/redis.sock</code>
                        </p>
                    </div>

                    <div class="flex gap-2">
                        <button type="button" onclick="triggerStartRedisDaemon()" class="px-4 py-2 bg-amber-700 hover:bg-amber-800 text-white font-bold text-xs rounded-xl transition flex items-center gap-1 shadow-xs">
                            <span class="material-symbols-outlined text-sm">power_settings_new</span>
                            <span>Start A2 Hosting Redis Daemon (~/.redis.on)</span>
                        </button>
                        <button type="button" onclick="flushRedisCache()" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs rounded-xl transition flex items-center gap-1 shadow-xs">
                            <span class="material-symbols-outlined text-sm">cleaning_services</span>
                            <span>Flush Nuvis Cache</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sample Cached Keys List -->
            <div class="space-y-3">
                <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider">Sample Cached Keys & Time To Live (TTL)</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border border-outline-variant/30 rounded-xl overflow-hidden">
                        <thead class="bg-surface-container text-outline uppercase font-semibold text-[10px]">
                            <tr>
                                <th class="py-2.5 px-3">Cache Key Name</th>
                                <th class="py-2.5 px-3">Full Redis Key</th>
                                <th class="py-2.5 px-3 text-right">TTL (Time to Live)</th>
                            </tr>
                        </thead>
                        <tbody id="redis-keys-table-body" class="divide-y divide-outline-variant/20 font-mono text-[11px]">
                            <tr>
                                <td colspan="3" class="py-6 text-center text-slate-400 font-sans italic">Loading cached keys...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div> <!-- End dev-panel-redis -->

    <!-- 3. Developer Error Logger Module -->
    <div id="dev-panel-logs" class="hidden space-y-6">
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-outline-variant/20 pb-3">
            <div>
                <h2 class="text-xs font-bold text-rose-700 uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">bug_report</span>
                    <span>Developer PHP & JS Error Logger & Diagnostics</span>
                </h2>
                <p class="text-xs text-outline font-medium mt-0.5">Captures uncaught JavaScript errors, promise rejections, PHP exceptions, and runtime errors in real-time.</p>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                <select id="dev-log-type-filter" onchange="fetchDeveloperErrorLogs()" class="bg-surface-container-low px-3 py-1.5 rounded-xl border border-outline-variant/40 font-bold text-xs">
                    <option value="">All Errors (PHP & JS & Audit)</option>
                    <option value="PHP">PHP Server Errors</option>
                    <option value="JS">JavaScript Browser Errors</option>
                    <option value="AUDIT">Audit Activity Logs</option>
                </select>

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
            <div id="developer-error-console" class="font-mono text-xs bg-slate-950 text-slate-200 p-4 rounded-xl border border-slate-800 min-h-[300px] max-h-[500px] overflow-y-auto leading-relaxed space-y-2 shadow-inner">
                <p class="text-slate-500 italic">Initializing developer error logger...</p>
            </div>
        </div>
    </div>
    </div> <!-- End dev-panel-logs -->

    <!-- 4. App Integrations & Webhooks Module -->
    <div id="dev-panel-integrations" class="hidden space-y-6">
        <?php
        $webhookSubscriptions = [];
        $apiIntegrations = [];
        try {
            $webhookService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\WebhookService::class);
            $webhookSubscriptions = $webhookService->getSubscriptions();
            $apiStmt = $pdo->prepare("SELECT * FROM app_integrations WHERE tenant_id = ? ORDER BY created_at DESC");
            $apiStmt->execute([$currentTenantId]);
            $apiIntegrations = $apiStmt->fetchAll();
        } catch (\Throwable $e) {}
        ?>
        <!-- Section 1: Webhook Subscriptions -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-outline-variant/20 pb-4">
                <div>
                    <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">webhook</span>
                        <span>Third-Party Application Webhook Subscriptions</span>
                    </h2>
                    <p class="text-xs text-outline font-medium mt-0.5">Subscribe external laboratory, billing, insurance, or pharmacy systems to real-time clinic events.</p>
                </div>

                <button type="button" onclick="openWebhookModal()" class="px-4 py-2 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition shadow-xs flex items-center gap-1.5 shrink-0">
                    <span class="material-symbols-outlined text-base">add_link</span>
                    <span>New Webhook Subscription</span>
                </button>
            </div>

            <!-- Webhooks Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border border-outline-variant/30 rounded-xl overflow-hidden">
                    <thead class="bg-surface-container text-outline uppercase font-semibold text-[10px]">
                        <tr>
                            <th class="py-3 px-4">Application Name</th>
                            <th class="py-3 px-4">Event Trigger</th>
                            <th class="py-3 px-4">Target Webhook URL</th>
                            <th class="py-3 px-4">Secret Token</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20 font-medium">
                        <?php if (empty($webhookSubscriptions)): ?>
                            <tr>
                                <td colspan="6" class="py-8 text-center text-slate-400 italic">No webhook subscriptions registered for tenant <code class="font-mono text-primary font-bold"><?= htmlspecialchars($currentTenantId) ?></code>.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($webhookSubscriptions as $sub): ?>
                                <tr class="hover:bg-surface-container-low/50 transition">
                                    <td class="py-3 px-4 font-bold text-on-surface"><?= htmlspecialchars($sub['app_name']) ?></td>
                                    <td class="py-3 px-4 font-mono text-[11px] text-primary">
                                        <span class="px-2 py-0.5 rounded bg-primary/10 text-primary font-bold text-[10px]"><?= htmlspecialchars($sub['event_type']) ?></span>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-[11px] text-slate-600 max-w-xs truncate" title="<?= htmlspecialchars($sub['target_url']) ?>">
                                        <?= htmlspecialchars($sub['target_url']) ?>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-[11px] text-slate-500">
                                        <?= htmlspecialchars(substr($sub['secret_token'] ?? '••••••••', 0, 8)) ?>...
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Active</span>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <div class="inline-flex items-center gap-2">
                                            <button type="button" onclick="testWebhookEndpoint('<?= htmlspecialchars($sub['target_url']) ?>', '<?= htmlspecialchars($sub['event_type']) ?>')" class="px-2.5 py-1 bg-blue-50 text-blue-700 hover:bg-blue-100 rounded-lg text-xs font-semibold transition flex items-center gap-1">
                                                <span class="material-symbols-outlined text-sm">send</span>
                                                <span>Test Dispatch</span>
                                            </button>
                                            <button type="button" onclick="deleteWebhookSubscription('<?= htmlspecialchars($sub['id']) ?>')" class="p-1 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Delete Webhook">
                                                <span class="material-symbols-outlined text-base">delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section 3: REST API Endpoint Reference & Interactive Explorer -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
            <div class="border-b border-outline-variant/20 pb-4">
                <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">code_off</span>
                    <span>REST API Endpoint Reference & Integration Directory</span>
                </h2>
                <p class="text-xs text-outline font-medium mt-0.5">Explore standard endpoints provided for external client integration, electronic health records (EHR), and lab automation.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <!-- Endpoint 1 -->
                <div class="p-4 rounded-xl bg-surface-container-low border border-outline-variant/30 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-mono font-bold text-[10px]">GET</span>
                        <span class="font-mono text-[10px] text-slate-500">JSON API</span>
                    </div>
                    <code class="block font-mono font-bold text-primary text-[11px]">/actions/load_more_activities.php</code>
                    <p class="text-slate-600 text-[11px]">Retrieves paginated clinic activity feed and system log events scoped to the active tenant.</p>
                </div>

                <!-- Endpoint 2 -->
                <div class="p-4 rounded-xl bg-surface-container-low border border-outline-variant/30 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded bg-blue-100 text-blue-800 font-mono font-bold text-[10px]">SSE</span>
                        <span class="font-mono text-[10px] text-slate-500">Real-time Stream</span>
                    </div>
                    <code class="block font-mono font-bold text-primary text-[11px]">/api/notifications/stream.php</code>
                    <p class="text-slate-600 text-[11px]">Server-Sent Events (SSE) stream pushing instant alerts for newly registered patients and scheduled appointments.</p>
                </div>

                <!-- Endpoint 3 -->
                <div class="p-4 rounded-xl bg-surface-container-low border border-outline-variant/30 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800 font-mono font-bold text-[10px]">POST</span>
                        <span class="font-mono text-[10px] text-slate-500">JSON Endpoint</span>
                    </div>
                    <code class="block font-mono font-bold text-primary text-[11px]">/actions/log_js_error.php</code>
                    <p class="text-slate-600 text-[11px]">Submits client-side JavaScript runtime errors and promise rejections for central developer diagnostics.</p>
                </div>

                <!-- Endpoint 4 -->
                <div class="p-4 rounded-xl bg-surface-container-low border border-outline-variant/30 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded bg-purple-100 text-purple-800 font-mono font-bold text-[10px]">POST</span>
                        <span class="font-mono text-[10px] text-slate-500">Webhooks API</span>
                    </div>
                    <code class="block font-mono font-bold text-primary text-[11px]">/actions/integration_actions.php</code>
                    <p class="text-slate-600 text-[11px]">Manages webhook subscriptions, test payload dispatches, and external client API key generation.</p>
                </div>
            </div>
        </div>

        <!-- Section 2: External API Access Keys -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-outline-variant/20 pb-4">
                <div>
                    <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">vpn_key</span>
                        <span>External API Access Keys & EHR Integration Credentials</span>
                    </h2>
                    <p class="text-xs text-outline font-medium mt-0.5">Generate scoped API keys for external medical applications, REST APIs, or FHIR data pipelines.</p>
                </div>

                <button type="button" onclick="generateApiKeyPrompt()" class="px-4 py-2 bg-emerald-700 text-white text-xs font-bold rounded-xl hover:bg-emerald-800 transition shadow-xs flex items-center gap-1.5 shrink-0">
                    <span class="material-symbols-outlined text-base">key_visualizer</span>
                    <span>Generate API Key</span>
                </button>
            </div>

            <!-- API Keys Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border border-outline-variant/30 rounded-xl overflow-hidden">
                    <thead class="bg-surface-container text-outline uppercase font-semibold text-[10px]">
                        <tr>
                            <th class="py-3 px-4">Integrated Client Application</th>
                            <th class="py-3 px-4">API Key Token</th>
                            <th class="py-3 px-4">Permissions</th>
                            <th class="py-3 px-4">Created Date</th>
                            <th class="py-3 px-4 text-right">Revoke Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20 font-medium">
                        <?php if (empty($apiIntegrations)): ?>
                            <tr>
                                <td colspan="5" class="py-8 text-center text-slate-400 italic">No external API access keys issued for tenant <code class="font-mono text-primary font-bold"><?= htmlspecialchars($currentTenantId) ?></code>.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($apiIntegrations as $keyRow): ?>
                                <tr class="hover:bg-surface-container-low/50 transition">
                                    <td class="py-3 px-4 font-bold text-on-surface"><?= htmlspecialchars($keyRow['app_name']) ?></td>
                                    <td class="py-3 px-4 font-mono text-[11px] text-slate-700">
                                        <?= htmlspecialchars(substr($keyRow['api_key'], 0, 12)) ?>...<?= htmlspecialchars(substr($keyRow['api_key'], -4)) ?>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-[11px] text-primary">
                                        <span class="px-2 py-0.5 rounded bg-surface-container-high text-on-surface font-semibold text-[10px]"><?= htmlspecialchars($keyRow['permissions'] ?? 'read,write') ?></span>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-[11px] text-slate-500">
                                        <?= htmlspecialchars($keyRow['created_at']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <button type="button" onclick="revokeApiKey('<?= htmlspecialchars($keyRow['id']) ?>', '<?= htmlspecialchars(addslashes($keyRow['app_name'])) ?>')" class="px-2.5 py-1 bg-rose-50 text-rose-700 hover:bg-rose-100 rounded-lg text-xs font-semibold transition flex items-center gap-1 ml-auto">
                                            <span class="material-symbols-outlined text-sm">block</span>
                                            <span>Revoke Key</span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div> <!-- End dev-panel-integrations -->

<!-- Modal: New Webhook Subscription -->
<div id="webhookModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl max-w-lg w-full shadow-2xl border border-slate-200 overflow-hidden my-8">
        <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
            <h3 class="font-bold text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-base">webhook</span>
                <span>Configure Webhook Subscription</span>
            </h3>
            <button type="button" onclick="closeWebhookModal()" class="text-slate-400 hover:text-white text-lg">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form id="webhookForm" onsubmit="submitWebhookForm(event)" class="p-6 space-y-4 text-xs">
            <div>
                <label class="block font-bold text-slate-700 mb-1">Integrating Application Name <span class="text-red-500">*</span></label>
                <input type="text" name="app_name" required placeholder="e.g. Suva Central Lab System" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Event Trigger <span class="text-red-500">*</span></label>
                <select name="event_type" required class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold text-primary">
                    <option value="PATIENT_REGISTERED">PATIENT_REGISTERED (New Patient Created)</option>
                    <option value="APPOINTMENT_BOOKED">APPOINTMENT_BOOKED (New Appointment Scheduled)</option>
                    <option value="ENCOUNTER_FINALIZED">ENCOUNTER_FINALIZED (Clinical Visit Finalized)</option>
                    <option value="INVOICE_CREATED">INVOICE_CREATED (VMS / Medical Invoice Issued)</option>
                    <option value="LAB_ORDER_SUBMITTED">LAB_ORDER_SUBMITTED (Laboratory Requisition Created)</option>
                    <option value="*">* (All System Events)</option>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Target Webhook HTTPS Endpoint URL <span class="text-red-500">*</span></label>
                <input type="url" name="target_url" required placeholder="https://api.partnerapp.com/webhooks/clinicflow" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-mono text-xs">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">HMAC Signature Secret Token (Optional)</label>
                <input type="text" name="secret_token" placeholder="Leave blank to auto-generate secret" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-mono text-xs">
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-slate-200">
                <button type="button" onclick="closeWebhookModal()" class="px-4 py-2 bg-slate-200 text-slate-700 font-semibold rounded-xl hover:bg-slate-300 transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">save</span>
                    <span>Save Webhook</span>
                </button>
            </div>
        </form>
    </div>
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

function switchDevTab(tab) {
    const panels = ['git', 'tenants', 'vms', 'cloud', 'database', 'lab', 'pagination', 'rbac', 'redis', 'logs', 'integrations', 'vsdc'];
    panels.forEach(p => {
        const el = document.getElementById('dev-panel-' + p);
        if (el) el.classList.add('hidden');
        const btn = document.getElementById('dev-tab-btn-' + p);
        if (btn) btn.className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 text-on-surface-variant hover:bg-surface-container-high';
    });

    const activeEl = document.getElementById('dev-panel-' + tab);
    if (activeEl) activeEl.classList.remove('hidden');
    const activeBtn = document.getElementById('dev-tab-btn-' + tab);
    if (activeBtn) activeBtn.className = 'px-4 py-2.5 rounded-t-xl transition flex items-center gap-1.5 bg-primary text-white';

    if (tab === 'git') {
        if (typeof refreshGitStatus === 'function') refreshGitStatus();
    } else if (tab === 'redis') {
        if (typeof fetchRedisDiagnostics === 'function') fetchRedisDiagnostics();
    } else if (tab === 'logs') {
        if (typeof fetchDeveloperErrorLogs === 'function') fetchDeveloperErrorLogs();
    }
}

function fetchRedisDiagnostics() {
    const statusCard = document.getElementById('redis-card-status');
    if (!statusCard) return;

    fetch('actions/redis_actions.php?action=get_redis_status')
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            const socketDisplay = document.getElementById('redis-socket-path-display');
            if (socketDisplay) socketDisplay.textContent = d.socket_path || '/home/ictfjcom/.redis/redis.sock';

            const schemeEl = document.getElementById('redis-card-scheme');
            if (schemeEl) schemeEl.textContent = 'Scheme: ' + (d.scheme || 'unix socket');

            if (d.is_connected) {
                statusCard.innerHTML = `<span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span><span class="text-emerald-700">Connected & Operational</span>`;
            } else if (d.socket_exists) {
                statusCard.innerHTML = `
                    <div class="space-y-1">
                        <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span><span class="text-amber-800">Socket Present (Config Disabled)</span></div>
                        <button type="button" onclick="toggleRedisConfig(true)" class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-[10px] font-bold shadow-xs">Enable Redis in Config</button>
                    </div>`;
            } else {
                statusCard.innerHTML = `<span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span><span class="text-amber-800">Socket Offline (~/.redis.on required)</span>`;
            }

            document.getElementById('redis-card-memory').textContent = d.used_memory || '--';
            document.getElementById('redis-card-version').textContent = 'Version: ' + (d.redis_version || '--');
            document.getElementById('redis-card-keys').textContent = d.cached_keys_count || 0;
            document.getElementById('redis-card-sessions').textContent = d.active_sessions_count || 0;

            const tbody = document.getElementById('redis-keys-table-body');
            if (tbody) {
                if (d.keys_sample && d.keys_sample.length > 0) {
                    tbody.innerHTML = d.keys_sample.map(k => `
                        <tr class="hover:bg-surface-container-low/50">
                            <td class="py-2.5 px-3 font-bold text-primary">${escapeHtml(k.key)}</td>
                            <td class="py-2.5 px-3 text-slate-600">${escapeHtml(k.full_key)}</td>
                            <td class="py-2.5 px-3 text-right text-emerald-700 font-bold">${escapeHtml(String(k.ttl))}</td>
                        </tr>
                    `).join('');
                } else {
                    tbody.innerHTML = `<tr><td colspan="3" class="py-6 text-center text-slate-400 font-sans italic">No active keys currently cached under nuvis:* prefix.</td></tr>`;
                }
            }
        }
    })
    .catch(err => {
        statusCard.innerHTML = `<span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span><span class="text-rose-700">Diagnostics Error</span>`;
    });
}

function testRedisPing() {
    const resBox = document.getElementById('redis-benchmark-result');
    const resText = document.getElementById('redis-benchmark-text');
    if (resBox) resBox.classList.remove('hidden');
    if (resText) resText.innerHTML = '<span class="text-blue-700">Running SET / GET / DEL latency benchmark...</span>';

    fetch('actions/redis_actions.php?action=test_redis_ping')
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            resText.innerHTML = `<span class="text-emerald-700 font-bold">✔ ${escapeHtml(d.message)}</span>`;
            fetchRedisDiagnostics();
        } else {
            resText.innerHTML = `<span class="text-rose-600 font-bold">❌ Benchmark Failed: ${escapeHtml(d.error || 'Connection error')}</span>`;
        }
    })
    .catch(err => {
        if (resText) resText.innerHTML = `<span class="text-rose-600 font-bold">❌ ${escapeHtml(String(err))}</span>`;
    });
}

function toggleRedisConfig(enable) {
    fetch('actions/redis_actions.php?action=toggle_redis_config', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({enable: enable})
    })
    .then(r => r.json())
    .then(d => {
        alert(d.message || 'Redis configuration updated.');
        fetchRedisDiagnostics();
    });
}

function triggerStartRedisDaemon() {
    fetch('actions/redis_actions.php?action=start_redis_daemon')
    .then(r => r.json())
    .then(d => {
        alert(d.message || 'Created ~/.redis.on flag.');
        fetchRedisDiagnostics();
    })
    .catch(err => {
        alert('Error touching ~/.redis.on: ' + err);
    });
}

function flushRedisCache() {
    if (!confirm('Are you sure you want to flush all Nuvis cached objects in Redis? Session keys will be preserved.')) return;

    fetch('actions/redis_actions.php?action=flush_cache')
    .then(r => r.json())
    .then(d => {
        alert(d.message || 'Cache flushed.');
        fetchRedisDiagnostics();
    });
}

function toggleDrive(key) {
    const body = document.getElementById('drive-body-' + key);
    const btn = document.getElementById('drive-toggle-' + key);
    if (!body) return;
    const open = body.classList.toggle('hidden') === false;
    if (btn) {
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        const label = btn.querySelector('span:last-child');
        if (label) label.textContent = open ? 'Close' : 'Configure';
    }
    if (open) {
        const first = body.querySelector('input, textarea');
        if (first) first.focus();
        body.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

// Each drive is tested on its own, so a broken OneDrive setup says nothing
// about Google Drive and the other way round.
function testDrive(key) {
    const out = document.getElementById('drive-test-out-' + key);
    const btn = document.getElementById('drive-test-btn-' + key);
    if (!out) return;
    out.classList.remove('hidden');
    out.textContent = 'Testing the connection…';
    if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }

    fetch('actions/cloud_storage_settings.php?action=test_connection&drive=' + encodeURIComponent(key), {
        headers: { 'Accept': 'application/json' }
    })
        .then(r => r.json())
        .then(d => {
            // Three outcomes, not two: "warn" means nothing could be proved
            // from the server, which is not the same as a working connection.
            const state = d.status || (d.success ? 'ok' : 'fail');
            const mark = state === 'ok' ? '\u2714 ' : (state === 'warn' ? '\u26a0 ' : '\u2716 ');
            const lines = (d.logs || []).slice();
            lines.push('');
            lines.push(mark + (state === 'ok' ? (d.message || 'Connected.') : (d.error || 'The test failed.')));
            out.textContent = lines.join('\n');
            out.classList.toggle('text-emerald-200', state === 'ok');
            out.classList.toggle('text-amber-200', state === 'warn');
            out.classList.toggle('text-rose-200', state === 'fail');
        })
        .catch(err => {
            out.textContent = 'The test could not run: ' + String(err);
            out.classList.add('text-rose-200');
        })
        .finally(() => {
            if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
        });
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', function() {
    refreshGitStatus();
    fetchDeveloperErrorLogs();
    const urlParams = new URLSearchParams(window.location.search);
    const activeDevTab = urlParams.get('dev_tab') || urlParams.get('tab');
    if (activeDevTab) {
        switchDevTab(activeDevTab);
    }
});

function fetchDeveloperErrorLogs() {
    const consoleBox = document.getElementById('developer-error-console');
    if (!consoleBox) return;

    const filterSelect = document.getElementById('dev-log-type-filter');
    const filterType = filterSelect ? filterSelect.value : '';

    fetch('actions/git_actions.php?action=get_error_logs&type=' + encodeURIComponent(filterType))
    .then(r => r.json())
    .then(d => {
        if (d.success && d.logs && d.logs.length > 0) {
            consoleBox.innerHTML = d.logs.map(log => {
                let badgeClass = 'bg-slate-800 text-slate-300';
                if (log.type === 'JS') badgeClass = 'bg-amber-950 text-amber-300 border border-amber-800';
                else if (log.type === 'PHP') badgeClass = 'bg-rose-950 text-rose-300 border border-rose-800';
                else if (log.type === 'AUDIT') badgeClass = 'bg-emerald-950 text-emerald-300 border border-emerald-800';

                let fileLineHtml = log.file_line ? `<div class="text-[10px] text-amber-200/80 font-mono mt-0.5">${escapeHtml(log.file_line)}</div>` : '';
                let stackHtml = log.stack_trace ? `<details class="mt-1 cursor-pointer"><summary class="text-[10px] text-slate-400 underline hover:text-slate-200">View Stack Trace</summary><pre class="text-[10px] text-slate-300 bg-slate-900 p-2 rounded mt-1 overflow-x-auto font-mono whitespace-pre-wrap">${escapeHtml(log.stack_trace)}</pre></details>` : '';

                return `
                    <div class="p-2.5 border-b border-slate-800/80 hover:bg-slate-900/50 transition rounded-lg">
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <div class="flex items-center gap-1.5">
                                <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase ${badgeClass}">${escapeHtml(log.type || 'SYS')}</span>
                                <span class="text-[10px] text-slate-400 font-mono">[${escapeHtml(log.timestamp || '')}]</span>
                            </div>
                            <span class="text-[10px] text-slate-500 font-mono">Tenant: ${escapeHtml(log.tenant_id || 'default')}</span>
                        </div>
                        <div class="font-mono text-slate-200 text-xs font-semibold">${escapeHtml(log.message || '')}</div>
                        ${fileLineHtml}
                        ${stackHtml}
                    </div>
                `;
            }).join('');
        } else {
            consoleBox.innerHTML = '<p class="text-slate-400 italic p-2">No system error log entries found matching selected filter.</p>';
        }
    })
    .catch(err => {
        consoleBox.innerHTML = '<p class="text-rose-400 font-bold p-2">Error retrieving developer logs: ' + escapeHtml(String(err)) + '</p>';
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

function openWebhookModal() {
    const modal = document.getElementById('webhookModal');
    if (modal) {
        modal.classList.remove('hidden');
        document.getElementById('webhookForm').reset();
    }
}

function closeWebhookModal() {
    const modal = document.getElementById('webhookModal');
    if (modal) modal.classList.add('hidden');
}

function submitWebhookForm(e) {
    e.preventDefault();
    const form = e.target;
    const data = {
        app_name: form.elements['app_name'].value,
        event_type: form.elements['event_type'].value,
        target_url: form.elements['target_url'].value,
        secret_token: form.elements['secret_token'].value
    };

    fetch('actions/integration_actions.php?action=save_webhook', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert(d.message || 'Webhook subscription saved.');
            closeWebhookModal();
            window.location.reload();
        } else {
            alert('Failed to save webhook: ' + (d.error || 'Unknown error'));
        }
    });
}

function deleteWebhookSubscription(id) {
    if (!confirm('Are you sure you want to delete this webhook subscription?')) return;

    fetch('actions/integration_actions.php?action=delete_webhook', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('Webhook subscription deleted.');
            window.location.reload();
        } else {
            alert('Failed to delete webhook: ' + (d.error || 'Unknown error'));
        }
    });
}

function testWebhookEndpoint(targetUrl, eventType) {
    alert('Executing test webhook payload dispatch to: ' + targetUrl);

    fetch('actions/integration_actions.php?action=test_webhook', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({target_url: targetUrl, event_type: eventType})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('✔ Test Webhook Payload Dispatched Successfully!\nEvent: ' + eventType + '\nURL: ' + targetUrl);
        } else {
            alert('❌ Webhook Dispatch Error: ' + (d.error || 'Test failed'));
        }
    });
}

function generateApiKeyPrompt() {
    const appName = prompt('Enter the name of the external client application (e.g. Laboratory Portal / Pharmacy Integration):', 'External Client App');
    if (!appName) return;

    fetch('actions/integration_actions.php?action=generate_api_key', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({app_name: appName})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            prompt('New API Key Generated! Copy and store this key safely (it will not be shown in plain text again):', d.api_key);
            window.location.reload();
        } else {
            alert('Failed to generate API Key: ' + (d.error || 'Unknown error'));
        }
    });
}

function revokeApiKey(id, appName) {
    if (!confirm(`Are you sure you want to revoke the API key for '${appName}'? External requests using this key will immediately fail.`)) return;

    fetch('actions/integration_actions.php?action=revoke_api_key', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('API Access Key revoked.');
            window.location.reload();
        } else {
            alert('Failed to revoke API Key: ' + (d.error || 'Unknown error'));
        }
    });
}

function uploadVsdcCertificate(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'upload_certificate');

    fetch('actions/vms_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('✔ ' + d.message);
            window.location.reload();
        } else {
            alert('❌ Certificate Upload Failed: ' + (d.error || 'Unknown error'));
        }
    });
}

function testSdcPing(actionType = 'test_sdc_ping') {
    const consoleBox = document.getElementById('sdc-ping-console');
    if (consoleBox) {
        consoleBox.classList.remove('hidden');
        consoleBox.innerHTML = '<p class="text-amber-300 animate-pulse">Executing FRCS VSDC Submitter V3.0 Test (' + actionType + ')...</p>';
    }

    fetch('actions/vms_actions.php?action=' + encodeURIComponent(actionType))
    .then(r => r.json())
    .then(d => {
        if (consoleBox) {
            let logHtml = '';
            if (d.logs && d.logs.length > 0) {
                logHtml = d.logs.map(l => `<div class="p-0.5 border-b border-slate-900">${escapeHtml(l)}</div>`).join('');
            }
            if (d.success) {
                logHtml += `<div class="p-1 text-emerald-400 font-bold mt-1">✔ ${escapeHtml(d.message)}</div>`;
            } else {
                logHtml += `<div class="p-1 text-rose-400 font-bold mt-1">❌ ${escapeHtml(d.error || 'Ping Failed')}</div>`;
            }
            consoleBox.innerHTML = logHtml;
        }
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
