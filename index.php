<?php
require_once __DIR__ . '/core/Cache.php';
require_once __DIR__ . '/core/SessionBootstrap.php';

// Serve static assets if requested (handles relative or absolute /assets/ path requests)
$uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
if (str_contains($uri, 'assets/')) {
    $assetPos = strpos($uri, 'assets/');
    $assetRel = '/' . substr($uri, $assetPos);
    $assetFile = __DIR__ . $assetRel;
    if (!file_exists($assetFile) || !is_file($assetFile)) {
        $assetFile = __DIR__ . '/dist' . $assetRel;
    }
    if (file_exists($assetFile) && is_file($assetFile)) {
        $mime = str_ends_with($assetFile, '.css') ? 'text/css' : (str_ends_with($assetFile, '.js') ? 'application/javascript' : (str_ends_with($assetFile, '.png') ? 'image/png' : 'text/plain'));
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

$todayDate = date('Y-m-d');
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

$queueItems = [];
$todayAppointments = [];
$upcomingAppointments = [];
$seenToday = [];
$totalPatientsCount = 0;
$newPatientsThisMonth = 0;
$pendingBillingSum = 0.0;
$pendingInvoicesCount = 0;
$overdueInvoicesCount = 0;
$lowStockItems = [];
$lowStockCount = 0;
$expiringCount = 0;
$activities = [];
$totalActivitiesCount = 0;
$currencyCode = 'FJD';

if (!function_exists('formatRelativeTime')) {
    function formatRelativeTime($datetime): string {
        if (empty($datetime)) return 'Recently';
        $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        if (!$timestamp) return htmlspecialchars((string)$datetime);
        $diff = time() - $timestamp;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' h ago';
        if ($diff < 604800) return floor($diff / 86400) . ' d ago';
        return date('j M Y', $timestamp);
    }
}

/** Appointment times are stored as display strings ("9:30 AM"); sort them as times. */
$byTime = static function (array $a, array $b): int {
    $ta = strtotime(($a['appointment_date'] ?? '') . ' ' . ($a['time'] ?? '')) ?: 0;
    $tb = strtotime(($b['appointment_date'] ?? '') . ' ' . ($b['time'] ?? '')) ?: 0;
    return $ta <=> $tb;
};

$dashQuery = static function (string $label, callable $fn) {
    try { $fn(); } catch (\Throwable $e) { error_log("Dashboard {$label} query error: " . $e->getMessage()); }
};

$dashQuery('queue', function () use ($pdo, $currentTenantId, &$queueItems) {
    $s = $pdo->prepare("SELECT * FROM queue WHERE tenant_id = ? ORDER BY created_at ASC, id ASC");
    $s->execute([$currentTenantId]);
    $queueItems = $s->fetchAll() ?: [];
});

$dashQuery('appointments', function () use ($pdo, $currentTenantId, $todayDate, $byTime, &$todayAppointments, &$upcomingAppointments, &$seenToday) {
    $s = $pdo->prepare("SELECT * FROM appointments WHERE tenant_id = ? AND appointment_date = ?");
    $s->execute([$currentTenantId, $todayDate]);
    $all = $s->fetchAll() ?: [];
    usort($all, $byTime);
    foreach ($all as $a) {
        if (($a['status'] ?? '') === 'Completed') { $seenToday[] = $a; }
        elseif (($a['status'] ?? '') !== 'Cancelled') { $todayAppointments[] = $a; }
    }
    $s = $pdo->prepare("SELECT * FROM appointments WHERE tenant_id = ? AND appointment_date > ? AND status NOT IN ('Completed','Cancelled') ORDER BY appointment_date ASC LIMIT 30");
    $s->execute([$currentTenantId, $todayDate]);
    $upcomingAppointments = $s->fetchAll() ?: [];
    usort($upcomingAppointments, $byTime);
    $upcomingAppointments = array_slice($upcomingAppointments, 0, 5);
});

$dashQuery('patients', function () use ($pdo, $currentTenantId, &$totalPatientsCount, &$newPatientsThisMonth) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE tenant_id = ?");
    $s->execute([$currentTenantId]);
    $totalPatientsCount = (int) $s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE tenant_id = ? AND registration_date >= ?");
    $s->execute([$currentTenantId, date('Y-m-01')]);
    $newPatientsThisMonth = (int) $s->fetchColumn();
});

$dashQuery('billing', function () use ($pdo, $currentTenantId, $todayDate, &$pendingBillingSum, &$pendingInvoicesCount, &$overdueInvoicesCount) {
    $s = $pdo->prepare("SELECT COALESCE(SUM(patient_owed), 0), COUNT(*) FROM invoices WHERE tenant_id = ? AND status = 'Pending'");
    $s->execute([$currentTenantId]);
    [$sum, $cnt] = $s->fetch(PDO::FETCH_NUM) ?: [0, 0];
    $pendingBillingSum = (float) $sum;
    $pendingInvoicesCount = (int) $cnt;
    $s = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND status = 'Pending' AND due_date < ?");
    $s->execute([$currentTenantId, $todayDate]);
    $overdueInvoicesCount = (int) $s->fetchColumn();
});

$dashQuery('stock', function () use ($pdo, $currentTenantId, $todayDate, &$lowStockItems, &$lowStockCount, &$expiringCount) {
    $s = $pdo->prepare("SELECT name, current_stock, min_threshold, unit FROM inventory WHERE tenant_id = ? AND is_active = 1 AND current_stock <= min_threshold ORDER BY current_stock ASC");
    $s->execute([$currentTenantId]);
    $lowStockItems = $s->fetchAll() ?: [];
    $lowStockCount = count($lowStockItems);
    $s = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE tenant_id = ? AND is_active = 1 AND expiry_date IS NOT NULL AND expiry_date <= ?");
    $s->execute([$currentTenantId, date('Y-m-d', strtotime('+30 days'))]);
    $expiringCount = (int) $s->fetchColumn();
});

$dashQuery('activities', function () use ($pdo, $currentTenantId, &$activities, &$totalActivitiesCount) {
    $s = $pdo->prepare("SELECT * FROM activities WHERE tenant_id = ? ORDER BY created_at DESC, id DESC LIMIT 6");
    $s->execute([$currentTenantId]);
    $activities = $s->fetchAll() ?: [];
    $s = $pdo->prepare("SELECT COUNT(*) FROM activities WHERE tenant_id = ?");
    $s->execute([$currentTenantId]);
    $totalActivitiesCount = (int) $s->fetchColumn();
});

$dashQuery('currency', function () use ($pdo, $currentTenantId, &$currencyCode) {
    $s = $pdo->prepare("SELECT currency FROM tenants WHERE id = ?");
    $s->execute([$currentTenantId]);
    $currencyCode = (string) ($s->fetchColumn() ?: 'FJD');
});
$currencySymbol = ['FJD' => 'FJ$', 'USD' => 'US$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'PHP' => '₱', 'EUR' => '€', 'GBP' => '£'][$currencyCode] ?? ($currencyCode . ' ');

$waiting = array_values(array_filter($queueItems, fn($q) => ($q['status'] ?? 'Waiting') !== 'In Room'));
$inRoom  = array_values(array_filter($queueItems, fn($q) => ($q['status'] ?? '') === 'In Room'));

// Suggest the first free room when calling a patient in
$roomsInUse = array_map(fn($q) => (string) ($q['room'] ?? ''), $inRoom);
$nextRoom = 'Room 1';
for ($r = 1; $r <= 12; $r++) {
    if (!in_array("Room {$r}", $roomsInUse, true)) { $nextRoom = "Room {$r}"; break; }
}

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$displayName = $currentDoctor['name'] ?? ($_SESSION['user_name'] ?? '');
$inClinicNow = count($queueItems);

$initialsOf = static function (string $name): string {
    $name = preg_replace('/^(Dr\.?|Mr\.?|Mrs\.?|Ms\.?)\s+/i', '', trim($name));
    $parts = preg_split('/\s+/', $name) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) { $out .= mb_strtoupper(mb_substr($p, 0, 1)); }
    return $out ?: '?';
};

$statusStyle = static function (string $status): array {
    return match ($status) {
        'Arrived', 'Checked In' => ['bg-emerald-50 text-emerald-800 ring-emerald-200', 'bg-emerald-500'],
        'In Progress'           => ['bg-blue-50 text-blue-800 ring-blue-200', 'bg-blue-500'],
        'Waiting'               => ['bg-amber-50 text-amber-800 ring-amber-200', 'bg-amber-500'],
        'No Show', 'Cancelled'  => ['bg-red-50 text-red-700 ring-red-200', 'bg-red-500'],
        default                 => ['bg-slate-100 text-slate-700 ring-slate-200', 'bg-slate-400'],
    };
};
$csrf = htmlspecialchars(getCsrfToken());
?>

<!-- Greeting -->
<div class="mb-6 flex flex-col lg:flex-row lg:items-end justify-between gap-4">
    <div>
        <h1 class="nx-page-title"><?= htmlspecialchars($greeting . ($displayName ? ', ' . $displayName : '')) ?></h1>
        <p class="nx-page-sub">
            <?= date('l j F') ?> ·
            <?php if ($inClinicNow > 0): ?>
                <?= $inClinicNow ?> <?= $inClinicNow === 1 ? 'patient' : 'patients' ?> in the clinic right now
            <?php else: ?>
                No one is waiting right now
            <?php endif; ?>
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="appointment.php?action=book" class="btn-secondary">
            <span class="material-symbols-outlined text-[18px]">calendar_add_on</span> Book appointment
        </a>
        <a href="register_patient.php" class="btn-primary">
            <span class="material-symbols-outlined text-[18px]">person_add</span> Register patient
        </a>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="xl:col-span-2 space-y-6 min-w-0">

        <!-- Clinic flow board -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs" aria-labelledby="flow-title">
            <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-4">
                <div>
                    <h2 id="flow-title" class="text-base font-semibold text-slate-900">Clinic flow</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Who is waiting, who is being seen, and who has finished today.</p>
                </div>
                <a href="appointment.php" class="text-[13px] font-medium text-blue-700 hover:underline whitespace-nowrap">Open schedule</a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 border-t border-slate-200 md:divide-x divide-slate-200">
                <!-- Waiting -->
                <div class="p-4 border-b md:border-b-0 border-slate-200 min-w-0">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="nx-dot bg-amber-500"></span>
                        <h3 class="text-[13px] font-semibold text-slate-800">Waiting</h3>
                        <span class="ml-auto text-xl font-semibold tabular text-slate-900"><?= count($waiting) ?></span>
                    </div>
                    <ul class="space-y-2">
                        <?php foreach ($waiting as $q): ?>
                            <li class="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <a href="patient_detail.php?id=<?= urlencode($q['patient_id'] ?? '') ?>" class="block text-[13px] font-semibold text-slate-900 hover:text-blue-700 truncate"><?= htmlspecialchars($q['patient_name'] ?? 'Patient') ?></a>
                                        <div class="text-[11px] text-slate-500 truncate"><span class="font-mono"><?= htmlspecialchars($q['mrn'] ?? '') ?></span> · <?= htmlspecialchars($q['doctor_name'] ?? '') ?></div>
                                    </div>
                                    <span class="text-[11px] font-medium text-slate-500 whitespace-nowrap tabular" title="Appointment time"><?= htmlspecialchars($q['time'] ?? '') ?></span>
                                </div>
                                <form action="actions/queue_update.php" method="POST" class="flex items-center gap-1.5 mt-2.5">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="queue_id" value="<?= htmlspecialchars($q['id'] ?? '') ?>">
                                    <input type="hidden" name="action" value="check_in">
                                    <label class="sr-only" for="room-<?= htmlspecialchars($q['id'] ?? '') ?>">Room</label>
                                    <input id="room-<?= htmlspecialchars($q['id'] ?? '') ?>" name="room" value="<?= htmlspecialchars($nextRoom) ?>" list="nx-rooms" class="w-[84px] h-8 text-xs px-2 py-1 rounded-lg border border-slate-200 bg-white text-slate-800 focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500">
                                    <button type="submit" class="h-8 flex-1 inline-flex items-center justify-center gap-1 rounded-lg bg-blue-700 hover:bg-blue-800 text-white text-xs font-semibold">
                                        <span class="material-symbols-outlined text-[16px]">meeting_room</span> Call in
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                        <?php if (!$waiting): ?>
                            <li class="text-xs text-slate-500 py-6 text-center">Nobody waiting.</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- In room -->
                <div class="p-4 border-b md:border-b-0 border-slate-200 min-w-0">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="nx-dot bg-blue-600"></span>
                        <h3 class="text-[13px] font-semibold text-slate-800">With a clinician</h3>
                        <span class="ml-auto text-xl font-semibold tabular text-slate-900"><?= count($inRoom) ?></span>
                    </div>
                    <ul class="space-y-2">
                        <?php foreach ($inRoom as $q): ?>
                            <li class="rounded-xl border border-blue-200 bg-blue-50/50 p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <a href="patient_detail.php?id=<?= urlencode($q['patient_id'] ?? '') ?>" class="block text-[13px] font-semibold text-slate-900 hover:text-blue-700 truncate"><?= htmlspecialchars($q['patient_name'] ?? 'Patient') ?></a>
                                        <div class="text-[11px] text-slate-500 truncate"><?= htmlspecialchars($q['doctor_name'] ?? '') ?><?= !empty($q['check_in_time']) ? ' · since ' . htmlspecialchars($q['check_in_time']) : '' ?></div>
                                    </div>
                                    <span class="text-[11px] font-semibold text-blue-800 bg-white border border-blue-200 rounded-md px-1.5 py-0.5 whitespace-nowrap"><?= htmlspecialchars(($q['room'] ?? '') ?: 'In room') ?></span>
                                </div>
                                <div class="flex items-center gap-1.5 mt-2.5">
                                    <a href="clinical_visit.php?patient_id=<?= urlencode($q['patient_id'] ?? '') ?>" class="h-8 flex-1 inline-flex items-center justify-center gap-1 rounded-lg bg-blue-700 hover:bg-blue-800 text-white text-xs font-semibold">
                                        <span class="material-symbols-outlined text-[16px]">stethoscope</span> Encounter
                                    </a>
                                    <form action="actions/queue_update.php" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="queue_id" value="<?= htmlspecialchars($q['id'] ?? '') ?>">
                                        <input type="hidden" name="action" value="send_back">
                                        <button type="submit" class="h-8 w-8 inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-100" title="Send back to waiting" aria-label="Send <?= htmlspecialchars($q['patient_name'] ?? 'patient') ?> back to waiting">
                                            <span class="material-symbols-outlined text-[16px]">undo</span>
                                        </button>
                                    </form>
                                    <form action="actions/queue_update.php" method="POST" data-nx-confirm="Mark the visit for <?= htmlspecialchars($q['patient_name'] ?? 'this patient') ?> as finished and remove them from the queue?">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="queue_id" value="<?= htmlspecialchars($q['id'] ?? '') ?>">
                                        <input type="hidden" name="action" value="complete">
                                        <button type="submit" class="h-8 w-8 inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white text-emerald-700 hover:bg-emerald-50" title="Finish visit" aria-label="Finish visit for <?= htmlspecialchars($q['patient_name'] ?? 'patient') ?>">
                                            <span class="material-symbols-outlined text-[16px]">task_alt</span>
                                        </button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php if (!$inRoom): ?>
                            <li class="text-xs text-slate-500 py-6 text-center">No rooms in use.</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Seen today -->
                <div class="p-4 min-w-0">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="nx-dot bg-emerald-500"></span>
                        <h3 class="text-[13px] font-semibold text-slate-800">Seen today</h3>
                        <span class="ml-auto text-xl font-semibold tabular text-slate-900"><?= count($seenToday) ?></span>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <?php foreach (array_slice($seenToday, -6) as $a): ?>
                            <li class="py-2 flex items-center gap-2.5">
                                <span class="material-symbols-outlined text-[18px] text-emerald-600 fill">check_circle</span>
                                <a href="patient_detail.php?id=<?= urlencode($a['patient_id'] ?? '') ?>" class="min-w-0 flex-1 text-[13px] text-slate-800 hover:text-blue-700 truncate"><?= htmlspecialchars($a['patient_name'] ?? '') ?></a>
                                <span class="text-[11px] text-slate-500 tabular"><?= htmlspecialchars($a['time'] ?? '') ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if (!$seenToday): ?>
                            <li class="text-xs text-slate-500 py-6 text-center">Completed visits appear here.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <datalist id="nx-rooms"><?php for ($r = 1; $r <= 8; $r++): ?><option value="Room <?= $r ?>"><?php endfor; ?><option value="Triage"><option value="Procedure room"></datalist>
        </section>

        <!-- Today's schedule -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs" aria-labelledby="sched-title">
            <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-3">
                <div>
                    <h2 id="sched-title" class="text-base font-semibold text-slate-900">Today's schedule</h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        <?= count($todayAppointments) ?> still to see<?= $seenToday ? ' · ' . count($seenToday) . ' done' : '' ?>
                    </p>
                </div>
                <a href="appointment.php" class="text-[13px] font-medium text-blue-700 hover:underline">All appointments</a>
            </div>

            <?php
            $renderRows = function (array $rows, bool $showDate) use ($statusStyle, $initialsOf) {
                foreach ($rows as $a):
                    [$badge, $dot] = $statusStyle($a['status'] ?? 'Scheduled'); ?>
                    <tr class="hover:bg-slate-50 transition">
                        <td class="py-3 pl-5 pr-3 whitespace-nowrap align-top">
                            <div class="text-[13px] font-semibold text-slate-900 tabular"><?= htmlspecialchars($a['time'] ?? '') ?></div>
                            <?php if ($showDate): ?><div class="text-[11px] text-slate-500"><?= htmlspecialchars(date('D j M', strtotime($a['appointment_date'] ?? 'now'))) ?></div><?php endif; ?>
                        </td>
                        <td class="py-3 px-3">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span class="nx-avatar w-8 h-8 text-[11px]"><?= htmlspecialchars($initialsOf($a['patient_name'] ?? '')) ?></span>
                                <div class="min-w-0">
                                    <a href="patient_detail.php?id=<?= urlencode($a['patient_id'] ?? '') ?>" class="block text-[13px] font-semibold text-slate-900 hover:text-blue-700 truncate">
                                        <?= htmlspecialchars($a['patient_name'] ?? '') ?>
                                        <?php if (!empty($a['is_urgent'])): ?><span class="ml-1 align-middle inline-block text-[10px] font-semibold text-red-700 bg-red-50 ring-1 ring-red-200 rounded px-1">Urgent</span><?php endif; ?>
                                    </a>
                                    <div class="text-[11px] text-slate-500 font-mono truncate"><?= htmlspecialchars($a['patient_mrn'] ?? '') ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3 px-3 text-[13px] text-slate-600 hidden md:table-cell"><?= htmlspecialchars($a['doctor_name'] ?? '') ?></td>
                        <td class="py-3 px-3 text-[13px] text-slate-600 hidden sm:table-cell"><?= htmlspecialchars($a['type'] ?? '') ?></td>
                        <td class="py-3 pl-3 pr-5 text-right">
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-semibold ring-1 <?= $badge ?>">
                                <span class="nx-dot !w-1.5 !h-1.5 <?= $dot ?>"></span><?= htmlspecialchars($a['status'] ?? 'Scheduled') ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach;
            };
            ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-y border-slate-200 bg-slate-50 text-[11px] font-semibold text-slate-500">
                            <th class="py-2 pl-5 pr-3">Time</th>
                            <th class="py-2 px-3">Patient</th>
                            <th class="py-2 px-3 hidden md:table-cell">Clinician</th>
                            <th class="py-2 px-3 hidden sm:table-cell">Visit type</th>
                            <th class="py-2 pl-3 pr-5 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if ($todayAppointments): ?>
                            <?php $renderRows($todayAppointments, false); ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="px-5 py-6">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <p class="text-[13px] text-slate-600"><?= $seenToday ? 'Everyone booked for today has been seen.' : 'No appointments booked for today.' ?></p>
                                    <a href="appointment.php?action=book" class="btn-outline-blue !text-[13px] !py-1.5">Book an appointment</a>
                                </div>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($upcomingAppointments): ?>
                        <tbody class="divide-y divide-slate-100 border-t-4 border-slate-100">
                            <tr><td colspan="5" class="pt-4 pb-1 px-5 text-xs font-semibold text-slate-500">Coming up</td></tr>
                            <?php $renderRows($upcomingAppointments, true); ?>
                        </tbody>
                    <?php endif; ?>
                </table>
            </div>
        </section>
    </div>

    <!-- Right column -->
    <div class="space-y-6 min-w-0">
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs" aria-labelledby="glance-title">
            <h2 id="glance-title" class="text-base font-semibold text-slate-900 px-5 pt-5 pb-2">At a glance</h2>
            <ul class="px-2 pb-2">
                <?php
                $glance = [
                    [
                        'icon' => 'event', 'tone' => 'bg-blue-50 text-blue-700', 'url' => 'appointment.php',
                        'label' => 'Appointments today',
                        'value' => (string) (count($todayAppointments) + count($seenToday)),
                        'note' => count($todayAppointments) . ' remaining',
                    ],
                    [
                        'icon' => 'payments', 'tone' => $overdueInvoicesCount ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600', 'url' => 'billing.php',
                        'label' => 'Unpaid invoices',
                        'value' => $currencySymbol . number_format($pendingBillingSum, 2),
                        'note' => $overdueInvoicesCount ? $overdueInvoicesCount . ' overdue' : ($pendingInvoicesCount ? $pendingInvoicesCount . ' open, none overdue' : 'All settled'),
                        'warn' => $overdueInvoicesCount > 0,
                    ],
                    [
                        'icon' => 'inventory_2', 'tone' => $lowStockCount ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600', 'url' => 'inventory.php',
                        'label' => 'Stock to reorder',
                        'value' => (string) $lowStockCount,
                        'note' => $lowStockCount ? implode(', ', array_map(fn($i) => $i['name'], array_slice($lowStockItems, 0, 2))) . ($lowStockCount > 2 ? ' and ' . ($lowStockCount - 2) . ' more' : '') : 'Stock levels are fine',
                        'warn' => $lowStockCount > 0,
                    ],
                    [
                        'icon' => 'event_busy', 'tone' => $expiringCount ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600', 'url' => 'inventory.php',
                        'label' => 'Expiring within 30 days',
                        'value' => (string) $expiringCount,
                        'note' => $expiringCount ? 'Use first or return to supplier' : 'Nothing close to expiry',
                        'warn' => $expiringCount > 0,
                    ],
                    [
                        'icon' => 'groups', 'tone' => 'bg-emerald-50 text-emerald-700', 'url' => 'patients.php',
                        'label' => 'Registered patients',
                        'value' => number_format($totalPatientsCount),
                        'note' => $newPatientsThisMonth . ' new this month',
                    ],
                ];
                foreach ($glance as $g): ?>
                    <li>
                        <a href="<?= $g['url'] ?>" class="flex items-center gap-3 rounded-xl px-3 py-2.5 hover:bg-slate-50 transition group">
                            <span class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 <?= $g['tone'] ?>"><span class="material-symbols-outlined text-[20px]"><?= $g['icon'] ?></span></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[13px] text-slate-600"><?= htmlspecialchars($g['label']) ?></span>
                                <span class="block text-[11px] truncate <?= !empty($g['warn']) ? 'text-amber-700 font-medium' : 'text-slate-500' ?>"><?= htmlspecialchars($g['note']) ?></span>
                            </span>
                            <span class="text-lg font-semibold text-slate-900 tabular whitespace-nowrap"><?= htmlspecialchars($g['value']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs" aria-labelledby="activity-title">
            <h2 id="activity-title" class="text-base font-semibold text-slate-900 px-5 pt-5 pb-3">Recent activity</h2>
            <div id="activity-stream-container" class="px-5 space-y-4">
                <?php if (empty($activities)): ?>
                    <p id="activity-empty-msg" class="text-[13px] text-slate-500 pb-2">Registrations, completed visits and lab results will be listed here as they happen.</p>
                <?php else: ?>
                    <?php foreach ($activities as $act):
                        $badgeBg = match($act['badge_type'] ?? 'blue') {
                            'emerald', 'green' => 'bg-emerald-50 text-emerald-700',
                            'amber', 'yellow' => 'bg-amber-50 text-amber-700',
                            'red' => 'bg-red-50 text-red-700',
                            default => 'bg-blue-50 text-blue-700'
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
                            <span class="w-8 h-8 rounded-full <?= $badgeBg ?> flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[18px]"><?= $icon ?></span></span>
                            <div class="min-w-0">
                                <p class="text-[13px] text-slate-800"><span class="font-semibold"><?= htmlspecialchars($act['title'] ?? 'Activity') ?></span> <span class="text-slate-600"><?= htmlspecialchars($act['detail'] ?? '') ?></span></p>
                                <p class="text-[11px] text-slate-500 mt-0.5"><?= formatRelativeTime($act['created_at'] ?? $act['timestamp'] ?? '') ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div id="load-more-activities-wrapper" class="px-5 py-3 mt-4 border-t border-slate-100 <?= count($activities) >= $totalActivitiesCount ? 'hidden' : '' ?>">
                <button id="btn-load-more-activities" type="button" class="w-full text-center text-[13px] font-medium text-blue-700 hover:underline">Show older activity</button>
            </div>
            <?php if (count($activities) >= $totalActivitiesCount): ?><div class="h-5"></div><?php endif; ?>
        </section>
    </div>
</div>

<script>
(function () {
    var offset = <?= count($activities) ?>, loading = false;
    var btn = document.getElementById('btn-load-more-activities');
    if (!btn) return;
    var tones = { emerald: 'bg-emerald-50 text-emerald-700', green: 'bg-emerald-50 text-emerald-700', amber: 'bg-amber-50 text-amber-700', yellow: 'bg-amber-50 text-amber-700', red: 'bg-red-50 text-red-700' };
    var icons = { patient_registration: 'person_add', encounter_complete: 'check_circle', lab_results: 'science', appointment_cancel: 'event_busy' };
    function node(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
    btn.addEventListener('click', function () {
        if (loading) return;
        loading = true;
        btn.textContent = 'Loading…';
        fetch('actions/load_more_activities.php?offset=' + offset + '&limit=5', { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) throw new Error(data.error);
                var box = document.getElementById('activity-stream-container');
                var empty = document.getElementById('activity-empty-msg'); if (empty) empty.remove();
                (data.activities || []).forEach(function (act) {
                    var row = node('div', 'flex items-start gap-3');
                    var badge = node('span', 'w-8 h-8 rounded-full flex items-center justify-center shrink-0 ' + (tones[act.badge_type] || 'bg-blue-50 text-blue-700'));
                    badge.appendChild(node('span', 'material-symbols-outlined text-[18px]', icons[act.type] || 'notifications'));
                    var body = node('div', 'min-w-0');
                    var p = node('p', 'text-[13px] text-slate-800');
                    p.appendChild(node('span', 'font-semibold', act.title || 'Activity'));
                    p.appendChild(document.createTextNode(' '));
                    p.appendChild(node('span', 'text-slate-600', act.detail || ''));
                    body.appendChild(p);
                    body.appendChild(node('p', 'text-[11px] text-slate-500 mt-0.5', act.formatted_time || act.timestamp || 'Recently'));
                    row.appendChild(badge); row.appendChild(body);
                    box.appendChild(row);
                });
                offset = data.next_offset || (offset + (data.activities || []).length);
                if (!data.has_more) document.getElementById('load-more-activities-wrapper').classList.add('hidden');
            })
            .catch(function () { if (window.NX) NX.toast("Couldn't load older activity", 'Check your connection and try again.', 'error'); })
            .finally(function () { loading = false; btn.textContent = 'Show older activity'; });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
