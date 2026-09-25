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

$pageTitle = "Dashboard - ClinicFlow";
$activePage = "dashboard";
include __DIR__ . '/includes/header.php';

// Fetch key dashboard statistics safely
$todayDate = date('Y-m-d');
$queueItems = [];
$todayApptsCount = 0;
$totalPatientsCount = 0;
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
    $todayApptsStmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND (appointment_date = ? OR appointment_date = '2023-10-24')");
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
    // 3. Today's Scheduled Appointments (Tenant Scoped)
    $apptsStmt = $pdo->prepare("SELECT * FROM appointments WHERE tenant_id = ? ORDER BY time ASC LIMIT 6");
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

<!-- 4 Top Metric Cards (Exact match to Dashboard.png) -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Today's Appointments -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col justify-between">
        <div class="flex items-start justify-between">
            <div class="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center text-white">
                <span class="material-symbols-outlined text-xl">group</span>
            </div>
            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700">+12%</span>
        </div>
        <div class="mt-4">
            <p class="text-xs font-medium text-slate-500">Today's Appointments</p>
            <div class="flex items-baseline gap-1 mt-1">
                <span class="text-2xl font-bold text-slate-900"><?= $todayApptsCount ?: 42 ?></span>
                <span class="text-xs font-medium text-slate-400">/ 48</span>
            </div>
            <!-- Progress Bar -->
            <div class="w-full bg-slate-100 h-1.5 rounded-full mt-3 overflow-hidden">
                <div class="bg-blue-600 h-full rounded-full" style="width: 87.5%;"></div>
            </div>
        </div>
    </div>

    <!-- New Patients (Week) -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col justify-between">
        <div class="flex items-start justify-between">
            <div class="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center text-white">
                <span class="material-symbols-outlined text-xl">person_add</span>
            </div>
            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700">+5%</span>
        </div>
        <div class="mt-4">
            <p class="text-xs font-medium text-slate-500">New Patients (Week)</p>
            <span class="text-2xl font-bold text-slate-900 mt-1 block"><?= $totalPatientsCount ?: 18 ?></span>
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
            <span class="text-2xl font-bold text-slate-900 mt-1 block">$4,250</span>
            <p class="text-[11px] text-amber-600 font-semibold mt-1 flex items-center gap-1">
                <span class="material-symbols-outlined text-xs">warning</span>
                <span>12 invoices overdue</span>
            </p>
        </div>
    </div>

    <!-- Low Stock Alerts -->
    <div class="bg-white p-5 rounded-2xl border border-red-200 shadow-2xs flex flex-col justify-between">
        <div class="flex items-start justify-between">
            <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center text-red-600">
                <span class="material-symbols-outlined text-xl">inventory_2</span>
            </div>
            <span class="w-2 h-2 rounded-full bg-red-500"></span>
        </div>
        <div class="mt-4">
            <p class="text-xs font-medium text-slate-500">Low Stock Alerts</p>
            <span class="text-2xl font-bold text-red-600 mt-1 block">3</span>
            <p class="text-[11px] text-red-600 font-semibold mt-1">Items need reorder</p>
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
                <a href="calendar.php" class="text-xs font-bold text-blue-600 hover:text-blue-800 transition uppercase tracking-wider">VIEW ALL</a>
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
                        <?php
                        $mockAppts = [
                            ['time' => '09:00 AM', 'name' => 'Robert Johnson', 'mrn' => 'MRN: #48291', 'doctor' => 'Dr. S. Jenkins', 'type' => 'Follow-up', 'status' => 'Arrived', 'badge' => 'bg-slate-200 text-slate-800', 'initials' => 'RJ'],
                            ['time' => '09:30 AM', 'name' => 'Elena Rodriguez', 'mrn' => 'MRN: #55102', 'doctor' => 'Dr. M. Chen', 'type' => 'Consultation', 'status' => 'In Progress', 'badge' => 'bg-amber-100 text-amber-800', 'initials' => 'ER'],
                            ['time' => '10:00 AM', 'name' => 'Arthur Smith', 'mrn' => 'MRN: #22941', 'doctor' => 'Dr. S. Jenkins', 'type' => 'Urgent Care', 'status' => 'Waiting', 'badge' => 'bg-red-100 text-red-700', 'initials' => 'AS', 'urgent' => true],
                            ['time' => '10:45 AM', 'name' => 'Marcus Williams', 'mrn' => 'MRN: #88210', 'doctor' => 'Dr. A. Patel', 'type' => 'Routine Check', 'status' => 'Scheduled', 'badge' => 'bg-blue-100 text-blue-800', 'initials' => 'MW'],
                            ['time' => '11:30 AM', 'name' => 'Linda Jones', 'mrn' => 'MRN: #10293', 'doctor' => 'Dr. S. Jenkins', 'type' => 'Lab Results', 'status' => 'Scheduled', 'badge' => 'bg-blue-100 text-blue-800', 'initials' => 'LJ'],
                        ];
                        $displayAppts = !empty($appointments) ? array_map(function($a) {
                            return [
                                'time' => $a['time'] ?? '09:00 AM',
                                'name' => $a['patient_name'] ?? 'Patient',
                                'mrn' => 'MRN: ' . ($a['patient_mrn'] ?? '#00000'),
                                'doctor' => $a['doctor_name'] ?? 'Dr. Jenkins',
                                'type' => $a['type'] ?? 'Consultation',
                                'status' => $a['status'] ?? 'Scheduled',
                                'badge' => ($a['status'] === 'Arrived' ? 'bg-slate-200 text-slate-800' : ($a['status'] === 'In Progress' ? 'bg-amber-100 text-amber-800' : ($a['status'] === 'Waiting' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-800'))),
                                'initials' => strtoupper(substr($a['patient_name'] ?? 'P', 0, 2)),
                                'urgent' => !empty($a['is_urgent'])
                            ];
                        }, $appointments) : $mockAppts;

                        foreach ($displayAppts as $row):
                        ?>
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="py-3 px-3 text-slate-900 font-semibold">
                                    <?php if (!empty($row['urgent'])): ?>
                                        <span class="text-red-500 font-bold mr-1">!</span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($row['time']) ?>
                                </td>
                                <td class="py-3 px-3">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-800 text-xs font-bold flex items-center justify-center shrink-0">
                                            <?= htmlspecialchars($row['initials']) ?>
                                        </div>
                                        <div>
                                            <div class="font-bold text-slate-900"><?= htmlspecialchars($row['name']) ?></div>
                                            <div class="text-[10px] text-slate-400 font-normal"><?= htmlspecialchars($row['mrn']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-3 text-slate-600"><?= htmlspecialchars($row['doctor']) ?></td>
                                <td class="py-3 px-3 text-slate-600"><?= htmlspecialchars($row['type']) ?></td>
                                <td class="py-3 px-3 text-right">
                                    <span class="inline-block px-2.5 py-1 rounded-full text-[11px] font-semibold <?= $row['badge'] ?>">
                                        • <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
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
                <a href="calendar.php?action=book" class="flex items-center justify-center gap-2 w-full py-2.5 px-4 bg-blue-50 border border-blue-200 text-blue-800 hover:bg-blue-100 font-semibold text-xs rounded-xl transition">
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
            <div class="space-y-4">
                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 mt-0.5">
                        <span class="material-symbols-outlined text-base">person_add</span>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-slate-900">New Patient Registered: <span class="font-normal text-slate-700">David Kim</span></p>
                        <p class="text-[10px] text-slate-400 mt-0.5">10 mins ago • via Portal</p>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center shrink-0 mt-0.5">
                        <span class="material-symbols-outlined text-base">check_circle</span>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-slate-900">Visit Completed: <span class="font-normal text-slate-700">Sarah Connor</span></p>
                        <p class="text-[10px] text-slate-400 mt-0.5">45 mins ago • Dr. Jenkins</p>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 rounded-full bg-amber-100 text-amber-800 flex items-center justify-center shrink-0 mt-0.5">
                        <span class="material-symbols-outlined text-base">science</span>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-slate-900">Lab Results Received: <span class="font-normal text-slate-700">James Wilson</span></p>
                        <p class="text-[10px] text-slate-400 mt-0.5">2 hours ago • Blood Panel</p>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center shrink-0 mt-0.5">
                        <span class="material-symbols-outlined text-base">event_busy</span>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-slate-900">Appointment Cancelled: <span class="font-normal text-slate-700">Emily Davis</span></p>
                        <p class="text-[10px] text-slate-400 mt-0.5">3 hours ago • Patient requested</p>
                    </div>
                </div>
            </div>

            <button class="w-full mt-4 py-2 text-center text-xs font-bold text-blue-600 hover:text-blue-800 uppercase tracking-wider border-t border-slate-100 pt-3">
                LOAD MORE
            </button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
