<?php
$pageTitle = "Appointments & Calendar - NuvisMedcareX";
$activePage = "calendar";
include __DIR__ . '/includes/header.php';

$appts = [];
$patients = [];
$doctorsList = [];

try {
    // Fetch all appointments
    $apptsStmt = $pdo->query("SELECT a.*, p.first_name, p.last_name, p.mrn, p.avatar, p.initials
                              FROM appointments a
                              LEFT JOIN patients p ON a.patient_id = p.id
                              ORDER BY a.appointment_date DESC, a.time ASC");
    if ($apptsStmt) {
        $appts = $apptsStmt->fetchAll() ?: [];
    }
} catch (\Throwable $e) {
    error_log("Calendar appts query error: " . $e->getMessage());
}

try {
    $patientsStmt = $pdo->query("SELECT * FROM patients ORDER BY last_name ASC");
    if ($patientsStmt) {
        $patients = $patientsStmt->fetchAll() ?: [];
    }
} catch (\Throwable $e) {
    error_log("Calendar patients query error: " . $e->getMessage());
}

try {
    $doctorsStmt = $pdo->query("SELECT * FROM doctors ORDER BY name ASC");
    if ($doctorsStmt) {
        $doctorsList = $doctorsStmt->fetchAll() ?: [];
    }
} catch (\Throwable $e) {
    error_log("Calendar doctors query error: " . $e->getMessage());
}

$action = $_GET['action'] ?? '';
$selectedPatientId = $_GET['patient_id'] ?? '';
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Appointments & Calendar</h1>
        <p class="text-xs text-slate-500 font-medium">Schedule, track, and manage patient visits across attending physicians</p>
    </div>
    <a href="calendar.php?action=book" class="btn-primary">
        <span class="material-symbols-outlined text-base">calendar_add_on</span>
        <span>Book Appointment</span>
    </a>
</div>

<!-- Modal Dialog for Booking Appointment -->
<?php if ($action === 'book'): ?>
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 w-full max-w-lg shadow-xl p-6">
        <div class="flex items-center justify-between mb-4 pb-3 border-b border-outline-variant/30">
            <h2 class="text-base font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">event</span>
                <span>Schedule Patient Appointment</span>
            </h2>
            <a href="calendar.php" class="text-outline hover:text-on-surface">
                <span class="material-symbols-outlined">close</span>
            </a>
        </div>

        <form action="actions/appointment_add.php" method="POST" class="space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <div>
                <label class="block font-bold text-slate-700 mb-1">Select Patient <span class="text-red-500">*</span></label>
                <select name="patient_id" required class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-medium">
                    <option value="">-- Choose Patient --</option>
                    <?php foreach ($patients as $p): ?>
                        <option value="<?= htmlspecialchars($p['id'] ?? '') ?>" <?= $selectedPatientId === ($p['id'] ?? '') ? 'selected' : '' ?>>
                            <?= htmlspecialchars(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?> (<?= htmlspecialchars($p['mrn'] ?? '') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Attending Physician <span class="text-red-500">*</span></label>
                <select name="doctor_id" required class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-medium">
                    <?php foreach ($doctorsList as $doc): ?>
                        <option value="<?= htmlspecialchars($doc['id'] ?? '') ?>" <?= ($currentDoctor['id'] ?? '') === ($doc['id'] ?? '') ? 'selected' : '' ?>>
                            <?= htmlspecialchars($doc['name'] ?? 'Doctor') ?> - <?= htmlspecialchars($doc['specialty'] ?? 'General') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Appointment Date <span class="text-red-500">*</span></label>
                    <input type="date" name="appointment_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-medium">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Time Slot <span class="text-red-500">*</span></label>
                    <input type="text" name="time" value="09:30 AM" required placeholder="e.g. 09:30 AM" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-medium">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Visit Type <span class="text-red-500">*</span></label>
                <select name="type" required class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-medium">
                    <option value="Consultation">Consultation</option>
                    <option value="Follow-up">Follow-up</option>
                    <option value="Routine Check">Routine Check</option>
                    <option value="Urgent Care">Urgent Care</option>
                    <option value="Lab Results">Lab Results</option>
                    <option value="Physical Exam">Physical Exam</option>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Clinical / Booking Notes</label>
                <textarea name="notes" rows="2" placeholder="Reason for visit or special instructions..." class="w-full bg-surface-container-low p-2.5 rounded-xl border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-medium"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-outline-variant/30">
                <a href="calendar.php" class="px-4 py-2 bg-slate-100 text-slate-700 font-semibold rounded-xl hover:bg-slate-200 transition">Cancel</a>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-semibold rounded-xl hover:bg-primary/90 transition shadow-xs">Confirm Schedule</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Appointments Overview List -->
<div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-5 shadow-xs">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="border-b border-outline-variant/30 text-outline uppercase text-[10px] font-bold tracking-wider">
                    <th class="py-2.5 px-3">Date & Time</th>
                    <th class="py-2.5 px-3">Patient</th>
                    <th class="py-2.5 px-3">Doctor</th>
                    <th class="py-2.5 px-3">Visit Type</th>
                    <th class="py-2.5 px-3">Status</th>
                    <th class="py-2.5 px-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/20">
                <?php if (empty($appts)): ?>
                    <tr>
                        <td colspan="6" class="py-6 text-center text-outline text-xs">No scheduled appointments found.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($appts as $a): ?>
                    <tr class="hover:bg-surface-container-low transition">
                        <td class="py-3 px-3 font-semibold text-on-surface">
                            <div><?= htmlspecialchars($a['appointment_date'] ?? '') ?></div>
                            <div class="text-[11px] text-outline font-mono"><?= htmlspecialchars($a['time'] ?? '') ?></div>
                        </td>
                        <td class="py-3 px-3 font-bold text-on-surface">
                            <a href="patient_detail.php?id=<?= htmlspecialchars($a['patient_id'] ?? '') ?>" class="hover:underline text-primary">
                                <?= htmlspecialchars(($a['first_name'] ?? 'Patient') . ' ' . ($a['last_name'] ?? '')) ?>
                            </a>
                            <p class="text-[10px] font-mono font-medium text-slate-500"><?= htmlspecialchars($a['mrn'] ?? '#00000') ?></p>
                        </td>
                        <td class="py-3 px-3 font-medium text-on-surface-variant"><?= htmlspecialchars($a['doctor_name'] ?? 'Doctor') ?></td>
                        <td class="py-3 px-3 font-medium text-slate-700">
                            <span class="px-2.5 py-0.5 rounded-md bg-slate-100 font-semibold text-[11px]">
                                <?= htmlspecialchars($a['type'] ?? 'Consultation') ?>
                            </span>
                        </td>
                        <td class="py-3 px-3">
                            <?php
                            $status = $a['status'] ?? 'Scheduled';
                            $statusBg = match($status) {
                                'Arrived' => 'bg-emerald-100 text-emerald-800',
                                'In Progress' => 'bg-blue-100 text-blue-800',
                                'Waiting' => 'bg-amber-100 text-amber-800',
                                'Completed' => 'bg-slate-100 text-slate-700',
                                default => 'bg-indigo-50 text-indigo-700'
                            };
                            ?>
                            <span class="inline-flex px-2.5 py-1 rounded-full text-[10px] font-bold <?= $statusBg ?>">
                                <?= htmlspecialchars($status) ?>
                            </span>
                        </td>
                        <td class="py-3 px-3 text-right">
                            <a href="clinical_visit.php?patient_id=<?= htmlspecialchars($a['patient_id'] ?? '') ?>" class="px-2.5 py-1 bg-primary text-white rounded-lg text-[11px] font-semibold hover:bg-primary/90 transition">
                                Launch Visit
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
