<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDB();

$patientId = $_GET['patient_id'] ?? null;
$visitId = $_GET['visit_id'] ?? null;
$appointmentId = $_GET['appointment_id'] ?? $_POST['appointment_id'] ?? null;
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// If appointment_id is passed when launching visit, mark appointment as "In Progress"
if ($patientId && $appointmentId) {
    try {
        $updateApptStmt = $pdo->prepare("UPDATE appointments SET status = 'In Progress', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ? AND status != 'Completed'");
        $updateApptStmt->execute([$appointmentId, $currentTenantId]);
    } catch (\Throwable $e) {
        error_log("Error updating appointment status to In Progress: " . $e->getMessage());
    }
}

require_once __DIR__ . '/includes/pagination.php';

// IF NO PATIENT ID IS SUPPLIED -> SHOW ENCOUNTER DIRECTORY LIST VIEW
if (empty($patientId)) {
    $pageTitle = "Clinical Encounters - NuvisMedcareX";
    $activePage = "clinical-visit";
    include __DIR__ . '/includes/header.php';

    $search = trim($_GET['q'] ?? '');

    // Pagination for Active Queue Encounters
    $qPagination = getPaginationParams(10, [5, 10, 25, 50, 100], $pdo, 'q_page', 'q_limit');
    $qLimit = $qPagination['limit'];
    $qOffset = $qPagination['offset'];
    $qCurrentPage = $qPagination['page'];

    // Count Active Queue
    $qCountSql = "SELECT COUNT(*) FROM queue q WHERE q.tenant_id = :tid";
    if ($search !== '') {
        $qCountSql .= " AND (q.patient_name LIKE :s1 OR q.mrn LIKE :s2 OR q.doctor_name LIKE :s3)";
    }
    $qCountStmt = $pdo->prepare($qCountSql);
    $qCountParams = ['tid' => $currentTenantId];
    if ($search !== '') {
        $qCountParams['s1'] = "%$search%";
        $qCountParams['s2'] = "%$search%";
        $qCountParams['s3'] = "%$search%";
    }
    $qCountStmt->execute($qCountParams);
    $totalActiveEncounters = (int)$qCountStmt->fetchColumn();

    // 1. Fetch active queue items (waiting/in room encounters)
    $queueQuery = "SELECT q.*, p.dob, p.age, p.gender, p.known_allergies
                   FROM queue q
                   LEFT JOIN patients p ON q.patient_id = p.id AND q.tenant_id = p.tenant_id
                   WHERE q.tenant_id = :tid";
    if ($search !== '') {
        $queueQuery .= " AND (q.patient_name LIKE :s1 OR q.mrn LIKE :s2 OR q.doctor_name LIKE :s3)";
    }
    $queueQuery .= " ORDER BY q.created_at ASC LIMIT :q_limit OFFSET :q_offset";
    $qStmt = $pdo->prepare($queueQuery);
    $qStmt->bindValue(':tid', $currentTenantId);
    if ($search !== '') {
        $qStmt->bindValue(':s1', "%$search%");
        $qStmt->bindValue(':s2', "%$search%");
        $qStmt->bindValue(':s3', "%$search%");
    }
    $qStmt->bindValue(':q_limit', $qLimit, PDO::PARAM_INT);
    $qStmt->bindValue(':q_offset', $qOffset, PDO::PARAM_INT);
    $qStmt->execute();
    $activeEncounters = $qStmt->fetchAll() ?: [];

    // Pagination for Finalized Encounters
    $pPagination = getPaginationParams(10, [5, 10, 25, 50, 100], $pdo, 'p_page', 'p_limit');
    $pLimit = $pPagination['limit'];
    $pOffset = $pPagination['offset'];
    $pCurrentPage = $pPagination['page'];

    // Count Finalized Encounters
    $pCountSql = "SELECT COUNT(*) FROM past_visits pv
                  LEFT JOIN patients p ON pv.patient_id = p.id AND pv.tenant_id = p.tenant_id
                  WHERE pv.tenant_id = :tid";
    if ($search !== '') {
        $pCountSql .= " AND (p.first_name LIKE :ps1 OR p.last_name LIKE :ps2 OR p.mrn LIKE :ps3 OR pv.title LIKE :ps4 OR pv.doctor_name LIKE :ps5)";
    }
    $pCountStmt = $pdo->prepare($pCountSql);
    $pCountParams = ['tid' => $currentTenantId];
    if ($search !== '') {
        $pCountParams['ps1'] = "%$search%";
        $pCountParams['ps2'] = "%$search%";
        $pCountParams['ps3'] = "%$search%";
        $pCountParams['ps4'] = "%$search%";
        $pCountParams['ps5'] = "%$search%";
    }
    $pCountStmt->execute($pCountParams);
    $totalPastEncounters = (int)$pCountStmt->fetchColumn();

    // 2. Fetch finalized past visits
    $pastQuery = "SELECT pv.*, p.first_name, p.last_name, p.mrn, p.age, p.gender, p.known_allergies
                  FROM past_visits pv
                  LEFT JOIN patients p ON pv.patient_id = p.id AND pv.tenant_id = p.tenant_id
                  WHERE pv.tenant_id = :tid";
    if ($search !== '') {
        $pastQuery .= " AND (p.first_name LIKE :ps1 OR p.last_name LIKE :ps2 OR p.mrn LIKE :ps3 OR pv.title LIKE :ps4 OR pv.doctor_name LIKE :ps5)";
    }
    $pastQuery .= " ORDER BY pv.created_at DESC LIMIT :p_limit OFFSET :p_offset";
    $pStmt = $pdo->prepare($pastQuery);
    $pStmt->bindValue(':tid', $currentTenantId);
    if ($search !== '') {
        $pStmt->bindValue(':ps1', "%$search%");
        $pStmt->bindValue(':ps2', "%$search%");
        $pStmt->bindValue(':ps3', "%$search%");
        $pStmt->bindValue(':ps4', "%$search%");
        $pStmt->bindValue(':ps5', "%$search%");
    }
    $pStmt->bindValue(':p_limit', $pLimit, PDO::PARAM_INT);
    $pStmt->bindValue(':p_offset', $pOffset, PDO::PARAM_INT);
    $pStmt->execute();
    $pastEncounters = $pStmt->fetchAll() ?: [];

    // 3. Fetch all active patients for "Start New Encounter" modal select
    $allPatientsStmt = $pdo->prepare("SELECT id, first_name, last_name, mrn, dob, age, gender FROM patients WHERE tenant_id = :tid ORDER BY last_name ASC");
    $allPatientsStmt->execute(['tid' => $currentTenantId]);
    $patientList = $allPatientsStmt->fetchAll() ?: [];
    ?>

    <div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Clinical Encounters</h1>
            <p class="text-xs text-slate-500 font-medium">Manage active clinical visits, review patient encounters, and document SOAP notes</p>
        </div>
        <button type="button" onclick="document.getElementById('startEncounterModal').classList.remove('hidden')" class="btn-primary">
            <span class="material-symbols-outlined text-base">add_notes</span>
            <span>Start New Encounter</span>
        </button>
    </div>

    <!-- Search Bar -->
    <div class="card-container mb-6 flex flex-col md:flex-row gap-4 justify-between items-center">
        <form action="clinical_visit.php" method="GET" class="relative w-full md:w-96">
            <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search encounter by patient, MRN, doctor..." class="form-input pl-10">
        </form>
        <p class="text-xs text-slate-500 font-medium">
            Active Encounters: <span class="font-bold text-primary"><?= $totalActiveEncounters ?></span> &nbsp;|&nbsp; Finalized: <span class="font-bold text-slate-700"><?= $totalPastEncounters ?></span>
        </p>
    </div>

    <!-- Active Queue Encounters Section -->
    <div class="mb-8 space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-lg">p2p</span>
                <span>Active Visits & Queue</span>
            </h2>
        </div>

        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="bg-surface-container-low/60 border-b border-outline-variant/30 text-outline uppercase text-[10px] font-bold tracking-wider">
                            <th class="py-3 px-4">Patient Name & MRN</th>
                            <th class="py-3 px-4">Check-in Time</th>
                            <th class="py-3 px-4">Assigned Doctor</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20">
                        <?php if (empty($activeEncounters)): ?>
                            <tr>
                                <td colspan="5" class="py-8 text-center text-outline text-xs">No active encounters in queue right now. Click "Start New Encounter" to select a patient.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($activeEncounters as $ae): ?>
                            <tr class="hover:bg-surface-container-low/50 transition">
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-on-surface"><?= htmlspecialchars($ae['patient_name']) ?></div>
                                    <div class="text-[11px] font-mono text-slate-500">MRN: <?= htmlspecialchars($ae['mrn']) ?></div>
                                </td>
                                <td class="py-3.5 px-4 font-medium text-slate-600">
                                    <?= htmlspecialchars($ae['check_in_time'] ?? $ae['time'] ?? 'Just now') ?>
                                </td>
                                <td class="py-3.5 px-4 font-medium text-slate-800">
                                    <?= htmlspecialchars($ae['doctor_name'] ?? 'Attending Physician') ?>
                                </td>
                                <td class="py-3.5 px-4">
                                    <?php if (($ae['status'] ?? '') === 'In Room'): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-bold">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-600 animate-pulse"></span>
                                            In Room
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-800 text-[10px] font-bold">
                                            Waiting
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 text-right space-x-1">
                                    <a href="clinical_visit.php?patient_id=<?= htmlspecialchars($ae['patient_id']) ?>" class="inline-flex items-center gap-1 px-3.5 py-1.5 bg-primary text-white rounded-xl text-xs font-semibold hover:bg-primary/90 transition">
                                        <span class="material-symbols-outlined text-sm">edit_note</span>
                                        <span>Open Encounter</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= renderPagination($totalActiveEncounters, $qCurrentPage, $qLimit, 'clinical_visit.php', array_filter(['q' => $search]), [5, 10, 25, 50, 100], 'q_page', 'q_limit') ?>
        </div>
    </div>

    <!-- Finalized Encounter History Section -->
    <div class="space-y-3">
        <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
            <span class="material-symbols-outlined text-emerald-600 text-lg">history</span>
            <span>Finalized Encounters</span>
        </h2>

        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="bg-surface-container-low/60 border-b border-outline-variant/30 text-outline uppercase text-[10px] font-bold tracking-wider">
                            <th class="py-3 px-4">Visit Date</th>
                            <th class="py-3 px-4">Patient Name & MRN</th>
                            <th class="py-3 px-4">Title / Reason</th>
                            <th class="py-3 px-4">Attending Doctor</th>
                            <th class="py-3 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20">
                        <?php if (empty($pastEncounters)): ?>
                            <tr>
                                <td colspan="5" class="py-8 text-center text-outline text-xs">No past encounter history recorded yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($pastEncounters as $pe): ?>
                            <tr class="hover:bg-surface-container-low/50 transition">
                                <td class="py-3.5 px-4 font-mono font-medium text-slate-600">
                                    <?= htmlspecialchars($pe['visit_date']) ?>
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-on-surface"><?= htmlspecialchars(($pe['first_name'] ?? 'Patient') . ' ' . ($pe['last_name'] ?? '')) ?></div>
                                    <div class="text-[11px] font-mono text-slate-500">MRN: <?= htmlspecialchars($pe['mrn'] ?? '') ?></div>
                                </td>
                                <td class="py-3.5 px-4 font-medium text-slate-800">
                                    <?= htmlspecialchars($pe['title'] ?? 'Clinical Encounter') ?>
                                    <?php if (!empty($pe['summary'])): ?>
                                        <p class="text-[11px] text-slate-500 font-normal truncate max-w-xs"><?= htmlspecialchars($pe['summary']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 font-medium text-slate-700">
                                    <?= htmlspecialchars($pe['doctor_name'] ?? 'Physician') ?>
                                </td>
                                <td class="py-3.5 px-4 text-right space-x-1">
                                    <a href="patient_detail.php?id=<?= htmlspecialchars($pe['patient_id']) ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-surface-container-high text-primary rounded-xl text-xs font-semibold hover:bg-surface-container-highest transition">
                                        <span class="material-symbols-outlined text-sm">visibility</span>
                                        <span>View Chart</span>
                                    </a>
                                    <a href="clinical_visit.php?patient_id=<?= htmlspecialchars($pe['patient_id']) ?>&visit_id=<?= htmlspecialchars($pe['visit_id'] ?? '') ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-primary/10 text-primary rounded-xl text-xs font-semibold hover:bg-primary/20 transition">
                                        <span>Re-open</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= renderPagination($totalPastEncounters, $pCurrentPage, $pLimit, 'clinical_visit.php', array_filter(['q' => $search]), [5, 10, 25, 50, 100], 'p_page', 'p_limit') ?>
        </div>
    </div>

    <!-- Modal: Start New Encounter -->
    <div id="startEncounterModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <h3 class="font-bold text-base text-slate-900 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-xl">add_notes</span>
                    <span>Start New Clinical Encounter</span>
                </h3>
                <button type="button" onclick="document.getElementById('startEncounterModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <p class="text-xs text-slate-600">
                Select a registered patient to begin a new clinical documentation session.
            </p>

            <form action="clinical_visit.php" method="GET" class="space-y-4 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 mb-1.5">Select Patient <span class="text-red-500">*</span></label>
                    <select name="patient_id" required class="w-full bg-slate-50 p-3 rounded-xl border border-slate-200 font-medium text-slate-800 focus:bg-white focus:outline-none focus:border-primary">
                        <option value="">-- Choose Patient --</option>
                        <?php foreach ($patientList as $pl): ?>
                            <option value="<?= htmlspecialchars($pl['id']) ?>">
                                <?= htmlspecialchars($pl['first_name'] . ' ' . $pl['last_name']) ?> (MRN: <?= htmlspecialchars($pl['mrn']) ?>, DOB: <?= htmlspecialchars($pl['dob']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="p-3 bg-blue-50/70 border border-blue-100 rounded-xl text-blue-800 text-[11px] leading-relaxed">
                    <strong>Note:</strong> Starting an encounter will allow you to record vitals, SOAP notes, issue prescriptions, generate invoices, and produce medical certificates.
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" onclick="document.getElementById('startEncounterModal').classList.add('hidden')" class="px-4 py-2 bg-slate-100 text-slate-700 font-semibold rounded-xl hover:bg-slate-200 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 transition shadow-xs flex items-center gap-1.5">
                        <span>Begin Encounter</span>
                        <span class="material-symbols-outlined text-sm">arrow_forward</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------------------------------------------------------
// IF PATIENT ID IS SUPPLIED -> SHOW ACTIVE ENCOUNTER FORM
// ---------------------------------------------------------

if (empty($visitId)) {
    $visitId = 'visit-' . date('Ymd') . '-' . substr(md5($patientId . time()), 0, 6);
}

// Fetch patient info
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND tenant_id = ?");
$stmt->execute([$patientId, $currentTenantId]);
$patient = $stmt->fetch();

if (!$patient) {
    header("Location: clinical_visit.php");
    exit;
}

// Fetch vitals
$vitalsStmt = $pdo->prepare("SELECT * FROM vitals WHERE patient_id = ? ORDER BY updated_at DESC LIMIT 1");
$vitalsStmt->execute([$patientId]);
$vitals = $vitalsStmt->fetch() ?: [
    'blood_pressure' => '120/80',
    'heart_rate' => 72,
    'temperature' => 98.6,
    'weight' => 145,
    'height' => 66,
    'bmi' => 23.4,
    'oxygen_sat' => 99
];

// Fetch SOAP notes
$soapStmt = $pdo->prepare("SELECT * FROM soap_notes WHERE patient_id = ? ORDER BY updated_at DESC LIMIT 1");
$soapStmt->execute([$patientId]);
$rawSoap = $soapStmt->fetch();

if ($rawSoap) {
    $soap = [
        'subjective' => \ClinicFlow\Utils\Encryption::decrypt($rawSoap['subjective'] ?? ''),
        'objective' => \ClinicFlow\Utils\Encryption::decrypt($rawSoap['objective'] ?? ''),
        'assessment_codes' => $rawSoap['assessment_codes'] ?? '',
        'plan' => \ClinicFlow\Utils\Encryption::decrypt($rawSoap['plan'] ?? '')
    ];
} else {
    $defaultEncNo = 'ENC-' . date('Ymd') . '-' . sprintf('%04d', rand(1, 9999));
    $soap = [
        'subjective' => 'Patient reports for clinical evaluation.',
        'objective' => 'Vitals stable. Alert and oriented x4.',
        'assessment_codes' => json_encode([['code' => $defaultEncNo, 'label' => $defaultEncNo]]),
        'plan' => 'Advised rest and hydration. Follow up PRN.'
    ];
}

$assessmentCodes = json_decode($soap['assessment_codes'] ?? '[]', true) ?: [];

// Fetch Lab Orders for Patient
$labOrderService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\LabOrderService::class);
$patientLabOrders = $labOrderService->getOrdersByPatient($patientId);

// Fetch Prescriptions for THIS encounter only (visit_id)
$rxStmt = $pdo->prepare("SELECT * FROM prescriptions WHERE patient_id = ? AND visit_id = ? ORDER BY created_at ASC");
$rxStmt->execute([$patientId, $visitId]);
$prescriptions = $rxStmt->fetchAll();

// Fetch Historical Prescriptions for Patient History (excluding current encounter visit_id)
$historicalRxStmt = $pdo->prepare("SELECT * FROM prescriptions WHERE patient_id = ? AND (visit_id IS NULL OR visit_id != ?) ORDER BY created_at DESC");
$historicalRxStmt->execute([$patientId, $visitId]);
$historicalPrescriptions = $historicalRxStmt->fetchAll();

$pageTitle = "Clinical Encounter - " . htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']);
$activePage = "clinical-visit";
include __DIR__ . '/includes/header.php';
?>

<!-- Back to Encounters List -->
<div class="mb-4">
    <a href="clinical_visit.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 hover:text-primary transition">
        <span class="material-symbols-outlined text-base">arrow_back</span>
        <span>Back to All Encounters</span>
    </a>
</div>

<!-- Patient Encounter Header Bar -->
<div class="card-container mb-6">
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-700 text-white font-bold text-lg flex items-center justify-center shadow-md">
                <?= htmlspecialchars($patient['initials'] ?: substr($patient['first_name'],0,1) . substr($patient['last_name'],0,1)) ?>
            </div>
            <div>
                <div class="flex items-center gap-2 flex-wrap">
                    <h1 class="text-xl font-bold text-slate-900"><?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></h1>
                    <?php
                    $patientAllergies = $patient['known_allergies'] ?? $patient['allergies'] ?? '';
                    if (!empty($patientAllergies) && $patientAllergies !== 'None' && $patientAllergies !== 'None reported'):
                    ?>
                        <span class="badge-chip badge-allergy">
                            <span class="material-symbols-outlined text-xs">warning</span>
                            <?= htmlspecialchars($patientAllergies) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <p class="text-xs text-slate-500 font-medium mt-1">
                    DOB: <?= htmlspecialchars($patient['dob']) ?> (<?= htmlspecialchars($patient['age']) ?>Y) &nbsp;|&nbsp; MRN: <span class="font-mono font-bold text-slate-700"><?= htmlspecialchars($patient['mrn']) ?></span> &nbsp;|&nbsp; <?= htmlspecialchars($patient['gender']) ?>
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <button type="button" onclick="document.getElementById('createInvoiceModal').classList.remove('hidden')" class="btn-secondary text-xs py-2 px-3">
                <span class="material-symbols-outlined text-base">receipt_long</span>
                <span>Invoice</span>
            </button>
            <button type="button" onclick="document.getElementById('issueMedCertModal').classList.remove('hidden')" class="btn-secondary text-xs py-2 px-3">
                <span class="material-symbols-outlined text-base">workspace_premium</span>
                <span>Med Cert</span>
            </button>
            <a href="print_prescription.php?patient_id=<?= htmlspecialchars($patient['id']) ?>&visit_id=<?= htmlspecialchars($visitId) ?>" target="_blank" class="btn-outline-blue text-xs py-2 px-3.5">
                <span class="material-symbols-outlined text-base">print</span>
                <span>Print Prescription</span>
            </a>
            <button type="button" onclick="document.getElementById('finalizeModal').classList.remove('hidden')" class="btn-primary text-xs py-2 px-4">
                <span class="material-symbols-outlined text-base">check_circle</span>
                <span>Finish Visit</span>
            </button>
        </div>
    </div>
</div>

<form action="actions/encounter_save.php" method="POST" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
    <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patient['id']) ?>">
    <input type="hidden" name="visit_id" value="<?= htmlspecialchars($visitId) ?>">
    <input type="hidden" name="appointment_id" value="<?= htmlspecialchars($appointmentId ?? '') ?>">
    <input type="hidden" name="action" value="save">

    <!-- Left Column (2 Cols): Vitals & SOAP Notes -->
    <div class="lg:col-span-2 space-y-6">

        <!-- Vitals Summary Bar -->
        <div class="vitals-summary-bar">
            <div class="vital-metric border-r border-slate-200 pr-4">
                <span class="vital-label">BLOOD PRESSURE</span>
                <div class="vital-value">
                    <input type="text" name="blood_pressure" value="<?= htmlspecialchars($vitals['blood_pressure']) ?>" placeholder="120/80" class="border-none p-0 focus:ring-0 text-xl font-bold w-24 bg-transparent text-slate-900">
                    <span class="vital-unit">mmHg</span>
                </div>
            </div>

            <div class="vital-metric border-r border-slate-200 pr-4">
                <span class="vital-label">HEART RATE</span>
                <div class="vital-value">
                    <input type="number" name="heart_rate" value="<?= htmlspecialchars($vitals['heart_rate']) ?>" placeholder="72" class="border-none p-0 focus:ring-0 text-xl font-bold w-16 bg-transparent text-slate-900">
                    <span class="vital-unit">bpm</span>
                </div>
            </div>

            <div class="vital-metric border-r border-slate-200 pr-4">
                <span class="vital-label">TEMPERATURE</span>
                <div class="vital-value">
                    <input type="text" name="temperature" value="<?= htmlspecialchars($vitals['temperature']) ?>" placeholder="98.6" class="border-none p-0 focus:ring-0 text-xl font-bold w-16 bg-transparent text-slate-900">
                    <span class="vital-unit">°F</span>
                </div>
            </div>

            <div class="vital-metric">
                <span class="vital-label">WEIGHT</span>
                <div class="vital-value">
                    <input type="text" name="weight" value="<?= htmlspecialchars($vitals['weight']) ?>" placeholder="145" class="border-none p-0 focus:ring-0 text-xl font-bold w-16 bg-transparent text-slate-900">
                    <span class="vital-unit">lbs</span>
                </div>
            </div>
        </div>

        <!-- SOAP Notes Form -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-5 shadow-xs space-y-4">
            <h2 class="text-xs font-bold text-outline uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-base">edit_note</span>
                <span>SOAP Encounter Documentation</span>
            </h2>

            <div class="text-xs space-y-4">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Subjective (Chief Complaint & History)</label>
                    <textarea name="subjective" rows="3" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-medium"><?= htmlspecialchars($soap['subjective']) ?></textarea>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Objective (Physical Exam & Diagnostic Data)</label>
                    <textarea name="objective" rows="3" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-medium"><?= htmlspecialchars($soap['objective']) ?></textarea>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Encounter Number / Assessment ICD-10 Code</label>
                    <input type="text" name="icd_code" value="<?= htmlspecialchars($assessmentCodes[0]['code'] ?? ('ENC-' . date('Ymd') . '-' . sprintf('%04d', rand(1, 9999)))) ?>" placeholder="e.g. ENC-20260320-1042 or J01.90" class="w-full bg-surface-container-low px-3.5 py-2 rounded-xl border border-outline-variant/40 font-medium font-mono text-primary">
                    <p class="text-[10px] text-slate-500 mt-1">Generated automatically for new encounters. Doctors can enter custom encounter numbers or ICD-10 codes.</p>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Plan (Treatment & Follow-up)</label>
                    <textarea name="plan" rows="3" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-medium"><?= htmlspecialchars($soap['plan']) ?></textarea>
                </div>
            </div>

            <div class="pt-2 flex justify-end">
                <button type="submit" class="px-5 py-2 bg-primary text-white text-xs font-semibold rounded-xl hover:bg-primary/90 transition shadow-xs">
                    Save Vitals & SOAP Notes
                </button>
            </div>
        </div>
    </div>

    <!-- Right Column (1 Col): Prescriptions Section & History Tab -->
    <div class="space-y-6">
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-5 shadow-xs">
            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-2">
                <h2 class="text-xs font-bold text-outline uppercase tracking-wider flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-base">prescriptions</span>
                    <span>Current Encounter Prescription</span>
                </h2>
                <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[10px] font-bold">Active Encounter</span>
            </div>

            <!-- Current Encounter Prescriptions List -->
            <div class="space-y-3 mb-4">
                <?php if (empty($prescriptions)): ?>
                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-500 italic">
                        Prescription form is blank for this encounter. Add new medication lines below.
                    </div>
                <?php endif; ?>

                <?php foreach ($prescriptions as $rx): ?>
                    <div class="p-3 rounded-xl bg-surface-container-low border border-outline-variant/30 flex items-start justify-between gap-2 text-xs">
                        <div class="flex-1">
                            <p class="font-bold text-on-surface"><?= htmlspecialchars($rx['medication_name']) ?> <span class="text-primary font-mono"><?= htmlspecialchars($rx['dosage']) ?></span></p>
                            <p class="text-[11px] text-outline mt-0.5"><?= htmlspecialchars($rx['frequency']) ?> • <?= htmlspecialchars($rx['duration']) ?></p>
                            <?php if (!empty($rx['instructions'])): ?>
                                <p class="text-[10px] text-slate-600 mt-1 italic"><?= htmlspecialchars($rx['instructions']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="flex items-center gap-1.5 shrink-0">
                            <button type="button" onclick='openEditRxModal(<?= htmlspecialchars(json_encode($rx), ENT_QUOTES, "UTF-8") ?>)' class="p-1 text-slate-600 hover:text-primary hover:bg-slate-200 rounded-md transition" title="Edit Medication">
                                <span class="material-symbols-outlined text-base">edit</span>
                            </button>
                            <a href="actions/encounter_save.php?action=delete_rx&rx_id=<?= htmlspecialchars($rx['id']) ?>&patient_id=<?= htmlspecialchars($patient['id']) ?>&visit_id=<?= htmlspecialchars($visitId) ?>" onclick="return confirm('Remove this medication line?')" class="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-md transition" title="Delete Medication">
                                <span class="material-symbols-outlined text-base">delete</span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pt-3 border-t border-outline-variant/20">
                <button type="button" onclick="openAddRxModal()" class="w-full py-2.5 bg-blue-700 hover:bg-blue-800 text-white text-xs font-bold rounded-xl shadow-md transition flex items-center justify-center gap-1.5">
                    <span class="material-symbols-outlined text-base">add</span>
                    <span>Add Medication Line</span>
                </button>
            </div>
        </div>

        <!-- Lab & Diagnostic Orders Section -->
        <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/30 p-5 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-base">science</span>
                    <span>Lab & Diagnostic Orders</span>
                </h3>
                <button type="button" onclick="openModal('modal-add-lab-order')" class="px-2.5 py-1 bg-primary text-white text-xs font-semibold rounded-lg hover:bg-primary/90 flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">add</span>
                    <span>Order Test</span>
                </button>
            </div>
            <?php if (empty($patientLabOrders)): ?>
                <p class="text-xs text-outline italic">No lab or diagnostic orders recorded for this patient.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($patientLabOrders as $lo): ?>
                        <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 text-xs space-y-2">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span class="font-bold text-on-surface text-sm"><?= e($lo['test_name']) ?></span>
                                    <span class="text-outline text-[11px] block"><?= e($lo['category']) ?> • Ordered by <?= e($lo['ordered_by']) ?></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $lo['status'] === 'Completed' ? ($lo['is_abnormal'] ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800') : 'bg-amber-100 text-amber-800' ?>">
                                        <?= e($lo['status']) ?> <?= !empty($lo['is_abnormal']) ? '(Abnormal)' : '' ?>
                                    </span>
                                    <button type="button" onclick='openEditLabModal(<?= htmlspecialchars(json_encode($lo), ENT_QUOTES) ?>)' class="px-2 py-1 bg-primary text-white hover:bg-primary/90 rounded-lg text-[11px] font-bold flex items-center gap-1 transition">
                                        <span class="material-symbols-outlined text-xs">edit</span> View / Edit
                                    </button>
                                    <?php if (!empty($lo['result_file_path']) || !empty($lo['result_file_id'])): ?>
                                        <a href="<?= htmlspecialchars(!empty($lo['result_file_path']) ? $lo['result_file_path'] : ('actions/download_file.php?id=' . $lo['result_file_id'])) ?>" target="_blank" class="px-2 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-[11px] font-bold flex items-center gap-1 transition shadow-2xs">
                                            <span class="material-symbols-outlined text-xs">attachment</span> View Result File
                                        </a>
                                    <?php endif; ?>
                                    <a href="print_lab_order.php?id=<?= e($lo['id']) ?>" target="_blank" class="px-2 py-1 bg-slate-200 hover:bg-slate-300 text-slate-800 rounded-lg text-[11px] font-bold flex items-center gap-1 transition">
                                        <span class="material-symbols-outlined text-xs">print</span> Print
                                    </a>
                                </div>
                            </div>

                            <?php if (!empty($lo['items'])): ?>
                                <div class="pl-2 border-l-2 border-primary/30 space-y-1 my-1">
                                    <p class="text-[10px] font-bold text-outline uppercase">Order Items (<?= count($lo['items']) ?>)</p>
                                    <?php foreach ($lo['items'] as $item): ?>
                                        <div class="flex items-center justify-between text-[11px]">
                                            <span class="font-medium text-slate-700">• <?= e($item['test_name']) ?> <span class="text-slate-400">(<?= e($item['category']) ?>)</span></span>
                                            <span class="text-slate-500 italic"><?= e($item['instructions'] ?: 'Standard') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Prescription History Tab (Previous Prescriptions) -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-5 shadow-xs">
            <h2 class="text-xs font-bold text-outline uppercase tracking-wider mb-3 flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-600 text-base">history</span>
                <span>Prescription History</span>
            </h2>

            <?php if (empty($historicalPrescriptions)): ?>
                <p class="text-xs text-outline italic">No past prescription history found.</p>
            <?php else: ?>
                <div class="space-y-2 max-h-60 overflow-y-auto pr-1">
                    <?php foreach ($historicalPrescriptions as $hrx): ?>
                        <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs space-y-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-bold text-slate-800"><?= htmlspecialchars($hrx['medication_name']) ?> <span class="text-primary font-mono">(<?= htmlspecialchars($hrx['dosage']) ?>)</span></span>
                                <span class="text-[10px] text-slate-400 font-mono"><?= date('M d, Y', strtotime($hrx['created_at'])) ?></span>
                            </div>
                            <p class="text-[11px] text-slate-600"><?= htmlspecialchars($hrx['frequency']) ?> • <?= htmlspecialchars($hrx['duration']) ?></p>
                            <?php if (!empty($hrx['instructions'])): ?>
                                <p class="text-[10px] text-slate-500 italic"><?= htmlspecialchars($hrx['instructions']) ?></p>
                            <?php endif; ?>

                            <div class="pt-1.5 border-t border-slate-200/60 flex items-center justify-between">
                                <a href="actions/encounter_save.php?action=copy_rx&rx_id=<?= htmlspecialchars($hrx['id']) ?>&patient_id=<?= htmlspecialchars($patient['id']) ?>&visit_id=<?= htmlspecialchars($visitId) ?>" class="text-[11px] font-bold text-primary hover:underline flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">content_copy</span>
                                    <span>Copy to Current Encounter</span>
                                </a>

                                <a href="actions/encounter_save.php?action=delete_rx&rx_id=<?= htmlspecialchars($hrx['id']) ?>&patient_id=<?= htmlspecialchars($patient['id']) ?>&visit_id=<?= htmlspecialchars($visitId) ?>" onclick="return confirm('Permanently delete this historical prescription record?')" class="text-rose-500 hover:text-rose-700" title="Delete Historical Record">
                                    <span class="material-symbols-outlined text-sm">delete</span>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- Modal: Finalize Encounter & VMS Fiscal Invoice Options -->
<div id="finalizeModal" class="fixed inset-0 bg-black/50 backdrop-blur-xs z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-surface-container-lowest rounded-2xl max-w-2xl w-full p-6 shadow-2xl border border-outline-variant/30 space-y-4 max-h-[90vh] overflow-y-auto text-xs">
        <div class="flex items-center justify-between pb-3 border-b border-outline-variant/20">
            <h3 class="text-base font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-600">task_alt</span>
                <span>Finalize Encounter & Issue VMS Fiscal Invoice</span>
            </h3>
            <button type="button" onclick="document.getElementById('finalizeModal').classList.add('hidden')" class="text-outline hover:text-on-surface">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <p class="text-xs text-on-surface-variant">
            You are finalizing the clinical encounter for <strong><?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></strong>. This will archive the visit record, deduct stock, and generate an itemized FRCS VMS Fiscal Invoice.
        </p>

        <form action="actions/encounter_save.php" method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patient['id']) ?>">
            <input type="hidden" name="visit_id" value="<?= htmlspecialchars($visitId) ?>">
            <input type="hidden" name="appointment_id" value="<?= htmlspecialchars($appointmentId ?? '') ?>">
            <input type="hidden" name="action" value="finish">

            <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 space-y-3">
                <label class="flex items-center gap-2 font-bold text-slate-800 cursor-pointer">
                    <input type="checkbox" name="create_invoice_on_finalize" value="1" id="create_inv_chk" onchange="toggleFinalizeInvoiceFields()" checked class="rounded border-slate-300 text-primary focus:ring-primary">
                    <span>Generate VMS Itemized Fiscal Invoice Now</span>
                </label>

                <div id="finalize_invoice_fields" class="space-y-3 pt-2 border-t border-slate-200">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-outline mb-1">Invoice Type</label>
                            <select name="invoice_type" class="w-full px-2.5 py-1.5 border border-outline-variant/40 rounded-xl font-bold text-primary bg-white">
                                <option value="Normal">Normal Invoice</option>
                                <option value="Advance">Advance Invoice</option>
                                <option value="Proforma">Proforma Invoice</option>
                                <option value="Copy">Copy Invoice</option>
                                <option value="Training">Training Invoice</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-outline mb-1">Transaction Type</label>
                            <select name="transaction_type" class="w-full px-2.5 py-1.5 border border-outline-variant/40 rounded-xl font-bold bg-white">
                                <option value="Sale">Sale (+)</option>
                                <option value="Refund">Refund (-)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="font-bold text-on-surface">VMS Invoice Line Items</label>
                            <button type="button" onclick="addFinalizeRow()" class="px-2 py-0.5 bg-emerald-600 text-white rounded-lg text-[10px] font-bold hover:bg-emerald-700 transition">
                                + Add Line Item
                            </button>
                        </div>

                        <div class="border border-outline-variant/30 rounded-xl overflow-hidden bg-white">
                            <table class="w-full text-left" id="finalizeItemsTable">
                                <thead class="bg-surface-container-high text-[10px] font-bold uppercase text-outline">
                                    <tr>
                                        <th class="py-1.5 px-2">Item / Service Name</th>
                                        <th class="py-1.5 px-1 w-12">Qty</th>
                                        <th class="py-1.5 px-1 w-20">Price ($)</th>
                                        <th class="py-1.5 px-1 w-16">Tax</th>
                                        <th class="py-1.5 px-1 w-8"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-outline-variant/20 text-xs">
                                    <tr>
                                        <td class="py-1.5 px-2 space-y-1">
                                            <select onchange="onFinalizeInventorySelect(this)" class="w-full px-1.5 py-1 border border-outline-variant/40 rounded-lg font-semibold text-[11px] text-primary bg-white">
                                                <option value="">-- Choose Inventory Stock Item (Optional) --</option>
                                                <?php foreach ($inventoryList as $invItem): ?>
                                                    <option value="<?= htmlspecialchars($invItem['id']) ?>"
                                                            data-name="<?= htmlspecialchars($invItem['name']) ?>"
                                                            data-sku="<?= htmlspecialchars($invItem['sku']) ?>"
                                                            data-price="<?= htmlspecialchars($invItem['unit_price']) ?>"
                                                            data-tax="<?= htmlspecialchars($invItem['vms_tax_code'] ?: 'A') ?>">
                                                        <?= htmlspecialchars($invItem['name']) ?> ($<?= number_format($invItem['unit_price'], 2) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="hidden" name="inventory_id[]" value="">
                                            <input type="text" name="item_name[]" value="Clinical Consultation & Examination" required placeholder="Item / Service Name" class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg text-xs font-medium">
                                        </td>
                                        <td class="py-1.5 px-1">
                                            <input type="number" step="0.5" name="quantity[]" value="1" required class="w-full px-1.5 py-1 border border-outline-variant/40 rounded-lg font-mono">
                                        </td>
                                        <td class="py-1.5 px-1">
                                            <input type="number" step="0.01" name="unit_price[]" value="150.00" required class="w-full px-1.5 py-1 border border-outline-variant/40 rounded-lg font-mono">
                                        </td>
                                        <td class="py-1.5 px-1">
                                            <select name="tax_label[]" class="w-full px-1 py-1 border border-outline-variant/40 rounded-lg font-bold text-[10px]">
                                                <option value="A" selected>A (15%)</option>
                                                <option value="E">E (0%)</option>
                                                <option value="F">F (0%)</option>
                                                <option value="P">P (0.25%)</option>
                                            </select>
                                        </td>
                                        <td class="py-1.5 px-1 text-center">
                                            <button type="button" onclick="this.closest('tr').remove()" class="text-red-500 font-bold">&times;</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-outline mb-1">Payment Method</label>
                            <select name="payment_type" class="w-full px-2.5 py-1.5 border border-outline-variant/40 rounded-xl font-bold bg-white">
                                <option value="Cash">Cash</option>
                                <option value="Card">Card</option>
                                <option value="Check">Check</option>
                                <option value="Wire Transfer">Wire Transfer</option>
                                <option value="Mobile Money">Mobile Money</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-outline mb-1">Insurance Portion ($)</label>
                            <input type="number" step="0.01" min="0" name="insurance_covered" value="0.00" class="w-full px-2.5 py-1.5 border border-outline-variant/40 rounded-xl font-mono font-bold bg-white">
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('finalizeModal').classList.add('hidden')" class="px-4 py-2 bg-surface-container-high text-on-surface font-semibold rounded-xl hover:bg-surface-variant transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 transition shadow-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">task_alt</span>
                    <span>Finalize Visit & Fiscalize Invoice</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function onFinalizeInventorySelect(select) {
    const tr = select.closest('tr');
    const opt = select.options[select.selectedIndex];

    const invIdInput = tr.querySelector('input[name="inventory_id[]"]');
    const itemNameInput = tr.querySelector('input[name="item_name[]"]');
    const unitPriceInput = tr.querySelector('input[name="unit_price[]"]');
    const taxSelect = tr.querySelector('select[name="tax_label[]"]');

    if (opt.value) {
        invIdInput.value = opt.value;
        itemNameInput.value = opt.getAttribute('data-name') || '';
        unitPriceInput.value = parseFloat(opt.getAttribute('data-price') || 0).toFixed(2);
        const taxCode = opt.getAttribute('data-tax') || 'A';
        if (taxSelect) {
            taxSelect.value = taxCode;
        }
    } else {
        invIdInput.value = '';
    }
}

function addFinalizeRow() {
    const tbody = document.querySelector('#finalizeItemsTable tbody');
    const tr = document.createElement('tr');

    let invOptions = '<option value="">-- Choose Inventory Stock Item (Optional) --</option>';
    if (Array.isArray(inventoryList)) {
        inventoryList.forEach(item => {
            const price = parseFloat(item.unit_price) || 0;
            const tax = item.vms_tax_code || 'A';
            invOptions += `<option value="${item.id}" data-name="${item.name}" data-sku="${item.sku}" data-price="${price}" data-tax="${tax}">${item.name} ($${price.toFixed(2)})</option>`;
        });
    }

    tr.innerHTML = `
        <td class="py-1.5 px-2 space-y-1">
            <select onchange="onFinalizeInventorySelect(this)" class="w-full px-1.5 py-1 border border-outline-variant/40 rounded-lg font-semibold text-[11px] text-primary bg-white">
                ${invOptions}
            </select>
            <input type="hidden" name="inventory_id[]" value="">
            <input type="text" name="item_name[]" placeholder="Item / Service Name" required class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg text-xs font-medium">
        </td>
        <td class="py-1.5 px-1">
            <input type="number" step="0.5" name="quantity[]" value="1" required class="w-full px-1.5 py-1 border border-outline-variant/40 rounded-lg font-mono">
        </td>
        <td class="py-1.5 px-1">
            <input type="number" step="0.01" name="unit_price[]" value="0.00" required class="w-full px-1.5 py-1 border border-outline-variant/40 rounded-lg font-mono">
        </td>
        <td class="py-1.5 px-1">
            <select name="tax_label[]" class="w-full px-1 py-1 border border-outline-variant/40 rounded-lg font-bold text-[10px]">
                <option value="A">A (15%)</option>
                <option value="E">E (0%)</option>
                <option value="F">F (0%)</option>
                <option value="P">P (0.25%)</option>
            </select>
        </td>
        <td class="py-1.5 px-1 text-center">
            <button type="button" onclick="this.closest('tr').remove()" class="text-red-500 font-bold">&times;</button>
        </td>
    `;
    tbody.appendChild(tr);
}
</script>

<script>
function toggleFinalizeInvoiceFields() {
    const chk = document.getElementById('create_inv_chk');
    const fields = document.getElementById('finalize_invoice_fields');
    if (chk.checked) {
        fields.classList.remove('hidden');
    } else {
        fields.classList.add('hidden');
    }
}
</script>

<!-- Modal: Add New Medication Line -->
<div id="addRxModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 space-y-4">
        <div class="flex items-center justify-between pb-2 border-b border-slate-100">
            <h3 class="font-bold text-sm text-slate-900 flex items-center gap-2">
                <span class="material-symbols-outlined text-blue-600 text-base">add_circle</span>
                <span>Add Prescription Line</span>
            </h3>
            <button type="button" onclick="closeAddRxModal()" class="text-slate-400 hover:text-slate-700">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/encounter_save.php" method="POST" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="action" value="add_rx">
            <input type="hidden" name="add_rx" value="1">
            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patient['id']) ?>">
            <input type="hidden" name="visit_id" value="<?= htmlspecialchars($visitId) ?>">

            <p class="font-bold text-slate-900 text-xs">Add New Medication Line</p>

            <div>
                <input type="text" name="medication_name" placeholder="Medication Name (e.g. Amoxicillin)" required class="w-full bg-slate-50 px-3.5 py-2.5 rounded-xl border border-slate-200/80 text-xs font-medium focus:bg-white focus:outline-none focus:border-blue-500 transition">
            </div>

            <div class="grid grid-cols-2 gap-2.5">
                <div>
                    <input type="text" name="dosage" placeholder="Dosage (500mg)" class="w-full bg-slate-50 px-3.5 py-2.5 rounded-xl border border-slate-200/80 text-xs font-medium focus:bg-white focus:outline-none focus:border-blue-500 transition">
                </div>
                <div>
                    <input type="text" name="frequency" placeholder="Freq (BID)" class="w-full bg-slate-50 px-3.5 py-2.5 rounded-xl border border-slate-200/80 text-xs font-medium focus:bg-white focus:outline-none focus:border-blue-500 transition">
                </div>
            </div>

            <div>
                <input type="text" name="duration" placeholder="Duration (7 days)" class="w-full bg-slate-50 px-3.5 py-2.5 rounded-xl border border-slate-200/80 text-xs font-medium focus:bg-white focus:outline-none focus:border-blue-500 transition">
            </div>

            <div>
                <input type="text" name="instructions" placeholder="Instructions (Take with food)" class="w-full bg-slate-50 px-3.5 py-2.5 rounded-xl border border-slate-200/80 text-xs font-medium focus:bg-white focus:outline-none focus:border-blue-500 transition">
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeAddRxModal()" class="px-4 py-2 bg-slate-100 text-slate-700 font-semibold rounded-xl hover:bg-slate-200 transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-blue-700 text-white font-bold rounded-xl hover:bg-blue-800 transition shadow-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">add</span>
                    <span>Add Line</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Medication Line -->
<div id="editRxModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="font-bold text-sm text-slate-900 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-base">edit_note</span>
                <span>Edit Prescription Line</span>
            </h3>
            <button type="button" onclick="closeEditRxModal()" class="text-slate-400 hover:text-slate-700">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/encounter_save.php" method="POST" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="action" value="edit_rx">
            <input type="hidden" name="rx_id" id="edit_rx_id">
            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patient['id']) ?>">
            <input type="hidden" name="visit_id" value="<?= htmlspecialchars($visitId) ?>">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Medication Name</label>
                <input type="text" name="medication_name" id="edit_rx_name" required class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Dosage</label>
                    <input type="text" name="dosage" id="edit_rx_dosage" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Frequency</label>
                    <input type="text" name="frequency" id="edit_rx_frequency" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Duration</label>
                <input type="text" name="duration" id="edit_rx_duration" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Instructions</label>
                <input type="text" name="instructions" id="edit_rx_instructions" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeEditRxModal()" class="px-4 py-2 bg-slate-200 text-slate-700 font-semibold rounded-xl hover:bg-slate-300 transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">save</span>
                    <span>Update Line</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Create Invoice for Current Encounter -->
<div id="createInvoiceModal" class="fixed inset-0 bg-black/50 backdrop-blur-xs z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-surface-container-lowest rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-outline-variant/30">
        <div class="flex items-center justify-between mb-4 pb-3 border-b border-outline-variant/20">
            <h3 class="text-base font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-blue-600">receipt_long</span>
                <span>Create Invoice for Current Encounter</span>
            </h3>
            <button type="button" onclick="document.getElementById('createInvoiceModal').classList.add('hidden')" class="text-outline hover:text-on-surface">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/encounter_save.php" method="POST" class="space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="action" value="create_invoice">
            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patient['id']) ?>">
            <input type="hidden" name="visit_id" value="<?= htmlspecialchars($visitId) ?>">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Patient Name & MRN</label>
                <input type="text" readonly value="<?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name'] . ' (' . $patient['mrn'] . ')') ?>" class="w-full bg-slate-100 px-3 py-2 rounded-xl border border-slate-300 font-bold text-slate-700">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Service Description <span class="text-red-500">*</span></label>
                <input type="text" name="service_description" value="Clinical Consultation & Examination" required class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Total Amount ($)</label>
                    <input type="number" step="0.01" min="0" name="amount" id="inv_amount" value="150.00" oninput="calculateInvoiceOwed()" required class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-mono font-bold text-on-surface">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Insurance ($)</label>
                    <input type="number" step="0.01" min="0" name="insurance_covered" id="inv_insurance" value="100.00" oninput="calculateInvoiceOwed()" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-mono font-bold text-on-surface">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Patient Owed ($)</label>
                    <input type="number" step="0.01" min="0" name="patient_owed" id="inv_patient_owed" value="50.00" readonly class="w-full bg-slate-100 px-3 py-2 rounded-xl border border-slate-300 font-mono font-bold text-blue-700">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Service Date</label>
                    <input type="date" name="service_date" value="<?= date('Y-m-d') ?>" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium" required>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Due Date</label>
                    <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium" required>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-outline-variant/20">
                <button type="button" onclick="document.getElementById('createInvoiceModal').classList.add('hidden')" class="px-4 py-2 bg-surface-container-high text-on-surface font-semibold rounded-xl hover:bg-surface-variant transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-blue-700 text-white font-bold rounded-xl hover:bg-blue-800 transition shadow-xs flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">receipt_long</span>
                    <span>Generate Invoice</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function calculateInvoiceOwed() {
    const amount = parseFloat(document.getElementById('inv_amount').value) || 0;
    const ins = parseFloat(document.getElementById('inv_insurance').value) || 0;
    const owed = Math.max(0, amount - ins);
    document.getElementById('inv_patient_owed').value = owed.toFixed(2);
}

function openAddRxModal() {
    document.getElementById('addRxModal').classList.remove('hidden');
}

function closeAddRxModal() {
    document.getElementById('addRxModal').classList.add('hidden');
}

function openEditRxModal(rx) {
    document.getElementById('edit_rx_id').value = rx.id || '';
    document.getElementById('edit_rx_name').value = rx.medication_name || '';
    document.getElementById('edit_rx_dosage').value = rx.dosage || '';
    document.getElementById('edit_rx_frequency').value = rx.frequency || '';
    document.getElementById('edit_rx_duration').value = rx.duration || '';
    document.getElementById('edit_rx_instructions').value = rx.instructions || '';
    document.getElementById('editRxModal').classList.remove('hidden');
}

function closeEditRxModal() {
    document.getElementById('editRxModal').classList.add('hidden');
}
</script>

<!-- Modal: Issue Medical Certificate in Clinical Visit -->
<?php
$settingsRows = $pdo->query("SELECT * FROM clinic_settings")->fetchAll();
$clinicSettings = [];
foreach ($settingsRows as $sr) {
    $clinicSettings[$sr['setting_key']] = $sr['setting_value'];
}
?>
<div id="issueMedCertModal" class="fixed inset-0 bg-black/50 backdrop-blur-xs z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-surface-container-lowest rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-outline-variant/30 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4 pb-3 border-b border-outline-variant/20">
            <h3 class="text-base font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-600">workspace_premium</span>
                <span>Issue Medical Certificate</span>
            </h3>
            <button type="button" onclick="document.getElementById('issueMedCertModal').classList.add('hidden')" class="text-outline hover:text-on-surface">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/medical_certificate_save.php" method="POST" class="space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patient['id']) ?>">
            <input type="hidden" name="print_immediately" value="1">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Issue Date</label>
                <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium" required>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Diagnosis / Clinical Impression <span class="text-red-500">*</span></label>
                <textarea name="diagnosis" rows="2" placeholder="e.g. Acute Upper Respiratory Tract Infection" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 font-medium" required><?= htmlspecialchars($soap['subjective'] ?? '') ?></textarea>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Fitness Status / Classification</label>
                <select name="fitness_status" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-semibold">
                    <option value="Fit for Work / School">Fit for Work / School</option>
                    <option value="Fit to Resume Normal Duties">Fit to Resume Normal Duties</option>
                    <option value="Unfit for Physical Activity">Unfit for Physical Activity</option>
                    <option value="Needs Medical Rest">Needs Medical Rest</option>
                    <option value="Fit with Restrictions">Fit with Restrictions</option>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Rest Duration / Fit Details</label>
                <input type="text" name="fit_status_details" placeholder="e.g. Advised medical leave for 3 days" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Remarks & Recommendations</label>
                <textarea name="recommendations" rows="2" placeholder="e.g. Continuous rest, medications as prescribed." class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 font-medium"><?= htmlspecialchars($soap['plan'] ?? '') ?></textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2 border-t border-outline-variant/20">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Attending Physician</label>
                    <input type="text" name="doctor_name" value="<?= htmlspecialchars($_SESSION['user_name'] ?? $currentDoctor['name'] ?? 'Dr. Sarah Jenkins') ?>" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium" required>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">PRC License No.</label>
                    <input type="text" name="prc_number" value="<?= htmlspecialchars($clinicSettings['doc_prc_no'] ?? 'PRC-0098412') ?>" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-mono font-medium">
                </div>
                <div class="md:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">PTR License No.</label>
                    <input type="text" name="ptr_number" value="<?= htmlspecialchars($clinicSettings['doc_ptr_no'] ?? 'PTR-8842109') ?>" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-mono font-medium">
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-outline-variant/20">
                <button type="button" onclick="document.getElementById('issueMedCertModal').classList.add('hidden')" class="px-4 py-2 bg-surface-container-high text-on-surface font-semibold rounded-xl hover:bg-surface-variant transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 transition shadow-xs flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">print</span>
                    <span>Issue & Print Certificate</span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/lab_order_modal.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
