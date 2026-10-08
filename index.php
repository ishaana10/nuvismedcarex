<?php
// Serve dist static assets if requested (handles relative or absolute /assets/ path requests)
$uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
if (str_contains($uri, 'assets/')) {
    $assetPos = strpos($uri, 'assets/');
    $assetRel = '/' . substr($uri, $assetPos);
    $assetFile = __DIR__ . '/dist' . $assetRel;
    if (!file_exists($assetFile) || !is_file($assetFile)) {
        $assetFile = __DIR__ . '/' . substr($uri, $assetPos);
    }
    if (file_exists($assetFile) && is_file($assetFile)) {
        $mime = str_ends_with($assetFile, '.css') ? 'text/css' : (str_ends_with($assetFile, '.js') ? 'application/javascript' : 'text/plain');
        header("Content-Type: $mime");
        readfile($assetFile);
        exit;
    }
}

// Serve React SPA only if view=react is explicitly requested; default to dynamic PHP EHR application
$viewMode = $_GET['view'] ?? 'classic';
if ($viewMode === 'react' && file_exists(__DIR__ . '/dist/index.html')) {
    header('Content-Type: text/html; charset=UTF-8');
    header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");
    readfile(__DIR__ . '/dist/index.html');
    exit;
}

$pageTitle = "Dashboard - NuvisMedcareX";
$activePage = "dashboard";
include __DIR__ . '/includes/header.php';

// Fetch key dashboard statistics safely
$todayDate = date('Y-m-d');
$queueItems = [];
$todayApptsCount = 0;
$totalPatientsCount = 0;
$pendingBillingSum = 0.0;
$overdueInvoicesCount = 0;
$lowStockCount = 0;
$appointments = [];
$activities = [];

$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

try {
    // 1. Queue Items (Tenant Scoped)
    $queueStmt = $pdo->prepare("SELECT * FROM queue WHERE tenant_id = ? ORDER BY id ASC");
    if ($queueStmt) {
        $queueStmt->execute([$currentTenantId]);
        $queueItems = $queueStmt->fetchAll() ?: [];
    }
} catch (\Throwable $e) {
    error_log("Dashboard queue query error: " . $e->getMessage());
}

// Metrics calculation
$waitingCount = count(array_filter($queueItems, fn($q) => ($q['status'] ?? '') === 'Waiting'));
$inRoomCount = count(array_filter($queueItems, fn($q) => ($q['status'] ?? '') === 'In Room'));

try {
    $todayApptsStmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND appointment_date = ?");
    if ($todayApptsStmt) {
        $todayApptsStmt->execute([$currentTenantId, $todayDate]);
        $todayApptsCount = (int) $todayApptsStmt->fetchColumn();
    }
} catch (\Throwable $e) {
    error_log("Dashboard appts count query error: " . $e->getMessage());
}

try {
    $patientsCountStmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE tenant_id = ?");
    if ($patientsCountStmt) {
        $patientsCountStmt->execute([$currentTenantId]);
        $totalPatientsCount = (int) $patientsCountStmt->fetchColumn();
    }
} catch (\Throwable $e) {
    error_log("Dashboard patients count query error: " . $e->getMessage());
}

try {
    // Pending Billing Metrics (Tenant Scoped)
    $billingStmt = $pdo->prepare("SELECT COALESCE(SUM(patient_owed), 0) FROM invoices WHERE tenant_id = ? AND status = 'Pending'");
    if ($billingStmt) {
        $billingStmt->execute([$currentTenantId]);
        $pendingBillingSum = (float) $billingStmt->fetchColumn();
    }

    $overdueStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND status = 'Pending' AND due_date < ?");
    if ($overdueStmt) {
        $overdueStmt->execute([$currentTenantId, $todayDate]);
        $overdueInvoicesCount = (int) $overdueStmt->fetchColumn();
    }
} catch (\Throwable $e) {
    error_log("Dashboard billing metrics query error: " . $e->getMessage());
}

try {
    // Low Stock Alerts (Tenant Scoped)
    $stockStmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE tenant_id = ? AND is_active = 1 AND current_stock <= min_threshold");
    if ($stockStmt) {
        $stockStmt->execute([$currentTenantId]);
        $lowStockCount = (int) $stockStmt->fetchColumn();
    }
} catch (\Throwable $e) {
    error_log("Dashboard low stock query error: " . $e->getMessage());
}

try {
    // 3. Today's Scheduled / Active Appointments (Tenant Scoped, Excludes Completed)
    $apptsStmt = $pdo->prepare("SELECT * FROM appointments WHERE tenant_id = ? AND status != 'Completed' ORDER BY time ASC LIMIT 6");
    if ($apptsStmt) {
        $apptsStmt->execute([$currentTenantId]);
        $appointments = $apptsStmt->fetchAll() ?: [];
    }
} catch (\Throwable $e) {
    error_log("Dashboard appointments query error: " . $e->getMessage());
}

try {
    // 4. Recent Activities (Tenant Scoped)
    $actStmt = $pdo->prepare("SELECT * FROM activities WHERE tenant_id = ? ORDER BY id DESC LIMIT 5");
    if ($actStmt) {
        $actStmt->execute([$currentTenantId]);
        $activities = $actStmt->fetchAll() ?: [];
    }
} catch (\Throwable $e) {
    error_log("Dashboard activities query error: " . $e->getMessage());
}
?>

<!-- Dashboard Welcome Banner -->
<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <p class="text-xs font-medium text-slate-500">Welcome back, <?= htmlspecialchars($currentDoctor['name'] ?? 'Dr. Sarah Jenkins') ?>. Here is today's summary.</p>
    </div>
    <div class="flex items-center gap-2">
        <div class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 shadow-2xs">
            <span class="material-symbols-outlined text-base text-slate-400">calendar_today</span>
            <span><?= date('F j, Y') ?></span>
        </div>
    </div>
</div>

<!-- 4 Top Metric Cards (Tenant Scoped Dynamic Real Data) -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Today's Appointments -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col justify-between">
        <div class="flex items-start justify-between">
            <div class="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center text-white">
                <span class="material-symbols-outlined text-xl">group</span>
            </div>
            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700">Today</span>
        </div>
        <div class="mt-4">
            <p class="text-xs font-medium text-slate-500">Today's Appointments</p>
            <div class="flex items-baseline gap-1 mt-1">
                <span class="text-2xl font-bold text-slate-900"><?= $todayApptsCount ?></span>
            </div>
            <!-- Dynamic Progress Bar -->
            <div class="w-full bg-slate-100 h-1.5 rounded-full mt-3 overflow-hidden">
                <div class="bg-blue-600 h-full rounded-full" style="width: <?= min(100, $todayApptsCount * 10) ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- New Patients -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col justify-between">
        <div class="flex items-start justify-between">
            <div class="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center text-white">
                <span class="material-symbols-outlined text-xl">person_add</span>
            </div>
            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700">Total</span>
        </div>
        <div class="mt-4">
            <p class="text-xs font-medium text-slate-500">Total Patients</p>
            <span class="text-2xl font-bold text-slate-900 mt-1 block"><?= $totalPatientsCount ?></span>
        </div>
    </div>

    <!-- Pending Billing -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col justify-between">
        <div class="flex items-start justify-between">
            <div class="w-10 h-10 rounded-xl bg-amber-500 flex items-center justify-center text-white">
                <span class="material-symbols-outlined text-xl">receipt_long</span>
            </div>
        </div>
        <div class="mt-4">
            <p class="text-xs font-medium text-slate-500">Pending Billing</p>
            <span class="text-2xl font-bold text-slate-900 mt-1 block">$<?= number_format($pendingBillingSum, 2) ?></span>
            <p class="text-[11px] <?= $overdueInvoicesCount > 0 ? 'text-amber-600 font-semibold' : 'text-slate-400' ?> mt-1 flex items-center gap-1">
                <span class="material-symbols-outlined text-xs"><?= $overdueInvoicesCount > 0 ? 'warning' : 'check_circle' ?></span>
                <span><?= $overdueInvoicesCount ?> invoices overdue</span>
            </p>
        </div>
    </div>

    <!-- Low Stock Alerts -->
    <div class="bg-white p-5 rounded-2xl border <?= $lowStockCount > 0 ? 'border-red-200' : 'border-slate-200/80' ?> shadow-2xs flex flex-col justify-between">
        <div class="flex items-start justify-between">
            <div class="w-10 h-10 rounded-xl <?= $lowStockCount > 0 ? 'bg-red-100 text-red-600' : 'bg-slate-100 text-slate-600' ?> flex items-center justify-center">
                <span class="material-symbols-outlined text-xl">inventory_2</span>
            </div>
            <?php if ($lowStockCount > 0): ?>
                <span class="w-2 h-2 rounded-full bg-red-500"></span>
            <?php endif; ?>
        </div>
        <div class="mt-4">
            <p class="text-xs font-medium text-slate-500">Low Stock Alerts</p>
            <span class="text-2xl font-bold <?= $lowStockCount > 0 ? 'text-red-600' : 'text-slate-900' ?> mt-1 block"><?= $lowStockCount ?></span>
            <p class="text-[11px] <?= $lowStockCount > 0 ? 'text-red-600 font-semibold' : 'text-slate-400' ?> mt-1">
                <?= $lowStockCount > 0 ? 'Items need reorder' : 'All stock levels normal' ?>
            </p>
        </div>
    </div>
</div>

<!-- Main Content Grid (Upcoming Appointments + Live Patient Queue + Right Sidebar) -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Upcoming Appointments Table & Live Queue (2 Cols) -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Live Patient Queue Card -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-base font-bold text-slate-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-blue-600 text-lg">groups</span>
                        <span>Live Patient Queue</span>
                    </h2>
                    <p class="text-xs text-slate-500">Active arrivals and room assignments</p>
                </div>
                <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800">
                    <?= count($queueItems) ?> Active
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 text-slate-400 font-semibold uppercase text-[10px] tracking-wider">
                            <th class="py-2.5 px-3">Patient</th>
                            <th class="py-2.5 px-3">MRN</th>
                            <th class="py-2.5 px-3">Time</th>
                            <th class="py-2.5 px-3">Doctor</th>
                            <th class="py-2.5 px-3">Status / Room</th>
                            <th class="py-2.5 px-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php if (empty($queueItems)): ?>
                            <tr>
                                <td colspan="6" class="py-6 text-center text-slate-400 text-xs">No patients currently in queue.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($queueItems as $q): ?>
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="py-3 px-3 font-bold text-slate-900">
                                    <a href="clinical_visit.php?patient_id=<?= htmlspecialchars($q['patient_id'] ?? '') ?>" class="hover:underline text-blue-600">
                                        <?= htmlspecialchars($q['patient_name'] ?? 'Patient') ?>
                                    </a>
                                </td>
                                <td class="py-3 px-3 font-mono text-slate-600"><?= htmlspecialchars($q['mrn'] ?? '#00000') ?></td>
                                <td class="py-3 px-3 text-slate-500"><?= htmlspecialchars($q['time'] ?? '00:00') ?></td>
                                <td class="py-3 px-3 text-slate-600"><?= htmlspecialchars($q['doctor_name'] ?? 'Doctor') ?></td>
                                <td class="py-3 px-3">
                                    <?php if (($q['status'] ?? '') === 'In Room'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-blue-100 text-blue-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span>
                                            <?= htmlspecialchars($q['room'] ?: 'In Room') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-amber-100 text-amber-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>
                                            Waiting
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-right space-x-1">
                                    <?php if (($q['status'] ?? '') === 'Waiting'): ?>
                                        <form action="actions/queue_update.php" method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                            <input type="hidden" name="queue_id" value="<?= htmlspecialchars($q['id'] ?? '') ?>">
                                            <input type="hidden" name="action" value="check_in">
                                            <button type="submit" class="px-2.5 py-1 bg-emerald-600 text-white rounded-lg text-[11px] font-semibold hover:bg-emerald-700 transition">
                                                Check In
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <a href="clinical_visit.php?patient_id=<?= htmlspecialchars($q['patient_id'] ?? '') ?>" class="inline-block px-2.5 py-1 bg-blue-600 text-white rounded-lg text-[11px] font-semibold hover:bg-blue-700 transition">
                                            Start Encounter
                                        </a>
                                    <?php endif; ?>
                                    <form action="actions/queue_update.php" method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                        <input type="hidden" name="queue_id" value="<?= htmlspecialchars($q['id'] ?? '') ?>">
                                        <input type="hidden" name="action" value="complete">
                                        <button type="submit" class="px-2.5 py-1 bg-slate-200 text-slate-700 rounded-lg text-[11px] font-semibold hover:bg-slate-300 transition">
                                            Complete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Upcoming Appointments Table Card -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-bold text-slate-900 flex items-center gap-2">
                    <span class="material-symbols-outlined text-blue-600 text-lg">calendar_month</span>
                    <span>Upcoming Appointments</span>
                </h2>
                <a href="appointment.php" class="text-xs font-bold text-blue-600 hover:text-blue-800 transition uppercase tracking-wider">VIEW ALL</a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 text-slate-400 font-semibold uppercase text-[10px] tracking-wider">
                            <th class="py-3 px-3">Time</th>
                            <th class="py-3 px-3">Patient</th>
                            <th class="py-3 px-3">Doctor</th>
                            <th class="py-3 px-3">Type</th>
                            <th class="py-3 px-3 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php if (empty($appointments)): ?>
                            <tr>
                                <td colspan="5" class="py-6 text-center text-slate-400 text-xs">No upcoming scheduled appointments for this clinic.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($appointments as $a):
                                $statusBadge = match($a['status'] ?? '') {
                                    'Arrived' => 'bg-slate-200 text-slate-800',
                                    'In Progress' => 'bg-amber-100 text-amber-800',
                                    'Waiting' => 'bg-red-100 text-red-700',
                                    default => 'bg-blue-100 text-blue-800'
                                };
                                $initials = strtoupper(substr($a['patient_name'] ?? 'P', 0, 2));
                            ?>
                                <tr class="hover:bg-slate-50/80 transition">
                                    <td class="py-3 px-3 text-slate-900 font-semibold">
                                        <?php if (!empty($a['is_urgent'])): ?>
                                            <span class="text-red-500 font-bold mr-1">!</span>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($a['time'] ?? '') ?>
                                    </td>
                                    <td class="py-3 px-3">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-800 text-xs font-bold flex items-center justify-center shrink-0">
                                                <?= htmlspecialchars($initials) ?>
                                            </div>
                                            <div>
                                                <div class="font-bold text-slate-900"><?= htmlspecialchars($a['patient_name'] ?? '') ?></div>
                                                <div class="text-[10px] text-slate-400 font-normal">MRN: <?= htmlspecialchars($a['patient_mrn'] ?? '') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3 text-slate-600"><?= htmlspecialchars($a['doctor_name'] ?? '') ?></td>
                                    <td class="py-3 px-3 text-slate-600"><?= htmlspecialchars($a['type'] ?? '') ?></td>
                                    <td class="py-3 px-3 text-right">
                                        <span class="inline-block px-2.5 py-1 rounded-full text-[11px] font-semibold <?= $statusBadge ?>">
                                            • <?= htmlspecialchars($a['status'] ?? 'Scheduled') ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right Sidebar Stack (Quick Actions + Recent Activity) -->
    <div class="space-y-6">
        <!-- Quick Actions Card -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs">
            <h2 class="text-base font-bold text-slate-900 flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-blue-600 text-lg">bolt</span>
                <span>Quick Actions</span>
            </h2>
            <div class="space-y-3">
                <a href="register_patient.php" class="flex items-center justify-center gap-2 w-full py-2.5 px-4 bg-blue-800 hover:bg-blue-900 text-white font-semibold text-xs rounded-xl shadow-md transition">
                    <span class="material-symbols-outlined text-base">person_add</span>
                    <span>Register New Patient</span>
                </a>
                <a href="appointment.php?action=book" class="flex items-center justify-center gap-2 w-full py-2.5 px-4 bg-blue-50 border border-blue-200 text-blue-800 hover:bg-blue-100 font-semibold text-xs rounded-xl transition">
                    <span class="material-symbols-outlined text-base">calendar_add_on</span>
                    <span>Book Appointment</span>
                </a>
            </div>
        </div>

        <!-- Recent Activity Stream -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs">
            <h2 class="text-base font-bold text-slate-900 flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-blue-600 text-lg">history</span>
                <span>Recent Activity</span>
            </h2>
            <div id="activity-stream-container" class="space-y-4">
                <?php if (empty($activities)): ?>
                    <p id="activity-empty-msg" class="text-xs text-slate-400 italic text-center py-4">No recent activity recorded for this clinic.</p>
                <?php else: ?>
                    <?php foreach ($activities as $act):
                        $badgeBg = match($act['badge_type'] ?? 'blue') {
                            'emerald', 'green' => 'bg-emerald-100 text-emerald-700',
                            'amber', 'yellow' => 'bg-amber-100 text-amber-800',
                            'red' => 'bg-red-100 text-red-700',
                            default => 'bg-blue-100 text-blue-700'
                        };
                        $icon = match($act['type'] ?? '') {
                            'patient_registration' => 'person_add',
                            'encounter_complete' => 'check_circle',
                            'lab_results' => 'science',
                            'appointment_cancel' => 'event_busy',
                            default => 'notifications'
                        };
                    ?>
                        <div class="flex items-start gap-3">
                            <div class="w-7 h-7 rounded-full <?= $badgeBg ?> flex items-center justify-center shrink-0 mt-0.5">
                                <span class="material-symbols-outlined text-base"><?= $icon ?></span>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-900"><?= htmlspecialchars($act['title'] ?? 'Activity') ?>: <span class="font-normal text-slate-700"><?= htmlspecialchars($act['detail'] ?? '') ?></span></p>
                                <p class="text-[10px] text-slate-400 mt-0.5"><?= htmlspecialchars($act['timestamp'] ?? 'Recently') ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="load-more-activities-wrapper" class="pt-3 border-t border-slate-100 mt-4 <?= count($activities) < 5 ? 'hidden' : '' ?>">
                <button id="btn-load-more-activities" onclick="loadMoreActivities()" type="button" class="w-full py-2 text-center text-xs font-bold text-blue-600 hover:text-blue-800 uppercase tracking-wider transition flex items-center justify-center gap-1">
                    <span>LOAD MORE</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Real-Time SSE Notification Container for Toast Alerts -->
<div id="sse-toast-container" class="fixed top-5 right-5 z-50 space-y-2 max-w-sm pointer-events-none"></div>

<!-- SSE Doctor Dashboard EventSource Listener -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (!window.EventSource) {
        console.warn('Browser does not support Server-Sent Events (SSE).');
        return;
    }

    function playNotificationSound() {
        try {
            const audio = new Audio('assets/notification.mp3');
            audio.play().catch(function() {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(587.33, ctx.currentTime);
                osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1);
                gain.gain.setValueAtTime(0.1, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.5);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.5);
            });
        } catch (e) {}
    }

    function showSseToast(notification) {
        const container = document.getElementById('sse-toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'pointer-events-auto p-4 bg-white border border-emerald-200 rounded-2xl shadow-xl flex items-start gap-3 transition-all duration-300 transform translate-y-2 opacity-0 animate-bounce-in';
        toast.innerHTML = `
            <div class="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-lg">person_add</span>
            </div>
            <div class="flex-1 text-xs">
                <div class="font-bold text-slate-900">${notification.title || 'New Patient Registered'}</div>
                <div class="text-slate-600 mt-0.5">${notification.message || 'A new patient has been registered.'}</div>
                <div class="text-[10px] text-slate-400 mt-1">${notification.timestamp || 'Just now'} • Real-Time Alert</div>
            </div>
            <button type="button" class="text-slate-400 hover:text-slate-700" onclick="this.parentElement.remove()">
                <span class="material-symbols-outlined text-base">close</span>
            </button>
        `;

        container.appendChild(toast);
        setTimeout(function() {
            toast.classList.remove('translate-y-2', 'opacity-0');
        }, 10);

        setTimeout(function() {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 6000);
    }

    const eventSource = new EventSource('/api/notifications/stream');

    eventSource.onmessage = function(event) {
        try {
            const notification = JSON.parse(event.data);

            if (notification.status === 'disabled' || notification.enabled === false) {
                console.log('SSE Real-time notifications are disabled for this tenant.');
                eventSource.close();
                return;
            }

            if (notification.title) {
                playNotificationSound();
                showSseToast(notification);
            }
        } catch (e) {
            console.error('Failed to parse SSE payload:', e);
        }
    };

    eventSource.onerror = function(err) {
        console.warn('SSE EventSource connection error or reconnecting:', err);
    };

    window.addEventListener('beforeunload', function() {
        eventSource.close();
    });
});

let activityOffset = <?= count($activities) ?>;
let activityLoading = false;

function loadMoreActivities() {
    if (activityLoading) return;
    activityLoading = true;

    const btn = document.getElementById('btn-load-more-activities');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) btn.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">progress_activity</span> Loading...';

    fetch('actions/load_more_activities.php?offset=' + activityOffset + '&limit=5')
        .then(res => res.json())
        .then(data => {
            activityLoading = false;
            if (btn) btn.innerHTML = origHtml;

            if (data.error) {
                console.error(data.error);
                return;
            }

            const container = document.getElementById('activity-stream-container');
            const emptyMsg = document.getElementById('activity-empty-msg');
            if (emptyMsg) emptyMsg.remove();

            if (data.activities && data.activities.length > 0) {
                data.activities.forEach(act => {
                    const div = document.createElement('div');
                    div.className = 'flex items-start gap-3';

                    let badgeBg = 'bg-blue-100 text-blue-700';
                    if (act.badge_type === 'emerald' || act.badge_type === 'green') badgeBg = 'bg-emerald-100 text-emerald-700';
                    else if (act.badge_type === 'amber' || act.badge_type === 'yellow') badgeBg = 'bg-amber-100 text-amber-800';
                    else if (act.badge_type === 'red') badgeBg = 'bg-red-100 text-red-700';

                    let icon = 'notifications';
                    if (act.type === 'patient_registration') icon = 'person_add';
                    else if (act.type === 'encounter_complete') icon = 'check_circle';
                    else if (act.type === 'lab_results') icon = 'science';
                    else if (act.type === 'appointment_cancel') icon = 'event_busy';

                    div.innerHTML = `
                        <div class="w-7 h-7 rounded-full ${badgeBg} flex items-center justify-center shrink-0 mt-0.5">
                            <span class="material-symbols-outlined text-base">${icon}</span>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-900">${escapeHtml(act.title || 'Activity')}: <span class="font-normal text-slate-700">${escapeHtml(act.detail || '')}</span></p>
                            <p class="text-[10px] text-slate-400 mt-0.5">${escapeHtml(act.timestamp || 'Recently')}</p>
                        </div>
                    `;
                    container.appendChild(div);
                });

                activityOffset = data.next_offset || (activityOffset + data.activities.length);
            }

            const wrapper = document.getElementById('load-more-activities-wrapper');
            if (wrapper) {
                if (!data.has_more) {
                    wrapper.classList.add('hidden');
                } else {
                    wrapper.classList.remove('hidden');
                }
            }
        })
        .catch(err => {
            activityLoading = false;
            if (btn) btn.innerHTML = origHtml;
            console.error('Error loading activities:', err);
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
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
