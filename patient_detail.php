<?php
$patientId = $_GET['id'] ?? 'pat-1';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/pagination.php';
$pdo = getDB();

$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
$stmt->execute([$patientId]);
$patient = $stmt->fetch();

if (!$patient) {
    header("Location: patients.php");
    exit;
}

// Immutable Read Audit Log
try {
    $auditService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\AuditService::class);
    $auditService->logPatientRead($patient['id'], "Viewed patient medical chart for " . $patient['first_name'] . " " . $patient['last_name']);
} catch (\Throwable $e) {
    // Ignore audit log error if container unavailable
}

$pageTitle = htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) . " - Patient Chart";
$activePage = "patients";
include __DIR__ . '/includes/header.php';

// Fetch active inventory items for VMS invoice creation in past encounters
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$inventoryStmt = $pdo->prepare("SELECT id, name, sku, current_stock, unit_price, vms_tax_code FROM inventory WHERE tenant_id = ? AND is_active = 1 ORDER BY name ASC");
$inventoryStmt->execute([$currentTenantId]);
$inventoryList = $inventoryStmt->fetchAll();

// Fetch past visits with linked invoice statuses
$pvStmt = $pdo->prepare("
    SELECT pv.*, i.id as invoice_id, i.invoice_number, i.status as invoice_status, i.amount as invoice_amount, i.patient_owed as invoice_patient_owed, i.is_fiscalized
    FROM past_visits pv
    LEFT JOIN invoices i ON (pv.visit_id = i.ref_no OR pv.visit_id = i.id OR (i.patient_id = pv.patient_id AND i.service_date = pv.visit_date)) AND i.tenant_id = pv.tenant_id
    WHERE pv.patient_id = ? AND pv.tenant_id = ?
    GROUP BY pv.id
    ORDER BY pv.id DESC
");
$pvStmt->execute([$patientId, $currentTenantId]);
$pastVisits = $pvStmt->fetchAll();

$totalPastVisitsCount = count($pastVisits);
$paginationPV = getPaginationParams(5, [5, 10, 25], $pdo);
$paginatedPastVisits = array_slice($pastVisits, $paginationPV['offset'], $paginationPV['limit']);

// Fetch appointments
$aptStmt = $pdo->prepare("SELECT * FROM appointments WHERE patient_id = ? ORDER BY appointment_date DESC");
$aptStmt->execute([$patientId]);
$patientAppts = $aptStmt->fetchAll();

// Fetch Lab Orders
$labOrderService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\LabOrderService::class);
$patientLabOrders = $labOrderService->getOrdersByPatient($patientId);

$totalLabOrdersCount = count($patientLabOrders);
$paginationLab = getPaginationParams(5, [5, 10, 25], $pdo);
$paginatedLabOrders = array_slice($patientLabOrders, $paginationLab['offset'], $paginationLab['limit']);

// Fetch Medical Certificates
$mcStmt = $pdo->prepare("SELECT * FROM medical_certificates WHERE patient_id = ? ORDER BY issue_date DESC, created_at DESC");
$mcStmt->execute([$patientId]);
$medCerts = $mcStmt->fetchAll();

$totalMedCertsCount = count($medCerts);
$paginationMC = getPaginationParams(5, [5, 10, 25], $pdo);
$paginatedMedCerts = array_slice($medCerts, $paginationMC['offset'], $paginationMC['limit']);

// Fetch default settings for Medical Certificate modal defaults
$settingsRows = $pdo->query("SELECT * FROM clinic_settings")->fetchAll();
$clinicSettings = [];
foreach ($settingsRows as $sr) {
    $clinicSettings[$sr['setting_key']] = $sr['setting_value'];
}
?>

<!-- Patient Header Card -->
<div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 mb-6 shadow-xs">
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
        <div class="flex items-center gap-4">
            <?php if (!empty($patient['avatar'])): ?>
                <img src="<?= htmlspecialchars($patient['avatar']) ?>" class="w-16 h-16 rounded-2xl object-cover border-2 border-primary/20 shadow-xs" alt="Avatar">
            <?php else: ?>
                <div class="w-16 h-16 rounded-2xl bg-primary text-white font-bold text-2xl flex items-center justify-center shadow-xs">
                    <?= htmlspecialchars($patient['initials'] ?: 'P') ?>
                </div>
            <?php endif; ?>
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-on-surface"><?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></h1>
                    <span class="px-2.5 py-0.5 rounded-full bg-primary-fixed text-on-primary-fixed text-xs font-bold font-mono">
                        <?= htmlspecialchars($patient['mrn']) ?>
                    </span>
                </div>
                <p class="text-xs text-outline mt-1 font-medium">
                    <?= htmlspecialchars($patient['age']) ?> Yrs • <?= htmlspecialchars($patient['gender']) ?> • DOB: <?= htmlspecialchars($patient['dob']) ?> • Blood: <?= htmlspecialchars($patient['blood_group'] ?: 'O+') ?>
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button onclick="document.getElementById('editPatientModal').classList.remove('hidden')" class="px-4 py-2 bg-slate-800 text-white text-xs font-semibold rounded-xl hover:bg-slate-900 transition shadow-xs flex items-center gap-2">
                <span class="material-symbols-outlined text-base">edit</span>
                <span>Edit Patient Info</span>
            </button>
            <button onclick="document.getElementById('issueMedCertModal').classList.remove('hidden')" class="px-4 py-2 bg-emerald-600 text-white text-xs font-semibold rounded-xl hover:bg-emerald-700 transition shadow-xs flex items-center gap-2">
                <span class="material-symbols-outlined text-base">workspace_premium</span>
                <span>Issue Certificate</span>
            </button>
            <a href="clinical_visit.php?patient_id=<?= htmlspecialchars($patient['id']) ?>" class="px-4 py-2 bg-primary text-white text-xs font-semibold rounded-xl hover:bg-primary/90 transition shadow-xs flex items-center gap-2">
                <span class="material-symbols-outlined text-base">clinical_notes</span>
                <span>Start Encounter</span>
            </a>
            <a href="calendar.php?action=book&patient_id=<?= htmlspecialchars($patient['id']) ?>" class="px-4 py-2 bg-primary-container text-white text-xs font-semibold rounded-xl hover:bg-primary-container/90 transition shadow-xs flex items-center gap-2">
                <span class="material-symbols-outlined text-base">calendar_add_on</span>
                <span>Schedule Visit</span>
            </a>
        </div>
    </div>
</div>

<!-- Grid Layout: Overview & History -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Left Column: Patient Details & Medical Alert -->
    <div class="space-y-6">
        <!-- Medical Overview -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-5 shadow-xs">
            <h2 class="text-sm font-bold text-on-surface mb-3 uppercase tracking-wider text-outline">Clinical Overview</h2>

            <div class="space-y-4 text-xs">
                <div>
                    <label class="font-bold text-slate-500 block mb-1">Known Allergies</label>
                    <?php if (!empty($patient['known_allergies']) && $patient['known_allergies'] !== 'None'): ?>
                        <div class="p-2.5 rounded-xl bg-amber-50 text-amber-900 border border-amber-200 font-medium flex items-center gap-2">
                            <span class="material-symbols-outlined text-amber-600 text-base">warning</span>
                            <span><?= htmlspecialchars($patient['known_allergies']) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="text-slate-600">No known drug allergies (NKDA)</div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="font-bold text-slate-500 block mb-1">Chronic Conditions</label>
                    <div class="p-2.5 rounded-xl bg-surface-container-low font-medium text-on-surface">
                        <?= htmlspecialchars($patient['chronic_conditions'] ?: 'None documented') ?>
                    </div>
                </div>

                <div>
                    <label class="font-bold text-slate-500 block mb-1">Clinical Notes</label>
                    <div class="p-2.5 rounded-xl bg-surface-container-low font-medium text-on-surface-variant">
                        <?= htmlspecialchars($patient['clinical_notes'] ?: 'No additional notes.') ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Insurance & Contact Info -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-5 shadow-xs">
            <h2 class="text-sm font-bold text-on-surface mb-3 uppercase tracking-wider text-outline">Contact & Insurance</h2>

            <div class="space-y-3 text-xs">
                <div>
                    <span class="text-slate-500 font-semibold block">Phone</span>
                    <span class="font-bold text-on-surface"><?= htmlspecialchars($patient['phone']) ?></span>
                </div>
                <div>
                    <span class="text-slate-500 font-semibold block">Email</span>
                    <span class="font-medium text-on-surface"><?= htmlspecialchars($patient['email']) ?></span>
                </div>
                <div>
                    <span class="text-slate-500 font-semibold block">Address</span>
                    <span class="font-medium text-on-surface"><?= htmlspecialchars($patient['address']) ?></span>
                </div>
                <div class="pt-2 border-t border-slate-100">
                    <span class="text-slate-500 font-semibold block">Insurance Provider</span>
                    <span class="font-bold text-primary"><?= htmlspecialchars($patient['insurance_provider'] ?: 'Self Pay') ?></span>
                    <p class="text-[11px] text-slate-500 font-mono">Policy: <?= htmlspecialchars($patient['insurance_policy_number'] ?: 'N/A') ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Past Encounters & Medical Certificates -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Lab & Diagnostic Orders Component -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-5 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-base">science</span>
                    <span>Lab & Diagnostic Orders</span>
                </h3>
                <button type="button" onclick="openModal('modal-add-lab-order-pd')" class="px-2.5 py-1 bg-primary text-white text-xs font-semibold rounded-lg hover:bg-primary/90 flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">add</span>
                    <span>Order Test</span>
                </button>
            </div>
            <?php if (empty($patientLabOrders)): ?>
                <p class="text-xs text-outline italic">No lab or diagnostic orders recorded for this patient.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($paginatedLabOrders as $lo): ?>
                        <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 text-xs space-y-2">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span class="font-bold text-on-surface text-sm"><?= htmlspecialchars($lo['test_name']) ?></span>
                                    <span class="text-outline text-[11px] block"><?= htmlspecialchars($lo['category']) ?> • Ordered by <?= htmlspecialchars($lo['ordered_by']) ?> on <?= date('M d, Y', strtotime($lo['created_at'])) ?></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $lo['status'] === 'Completed' ? ($lo['is_abnormal'] ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800') : 'bg-amber-100 text-amber-800' ?>">
                                        <?= htmlspecialchars($lo['status']) ?> <?= !empty($lo['is_abnormal']) ? '(Abnormal)' : '' ?>
                                    </span>
                                    <button type="button" onclick='openEditLabModal(<?= htmlspecialchars(json_encode($lo), ENT_QUOTES) ?>)' class="px-2 py-1 bg-primary text-white hover:bg-primary/90 rounded-lg text-[11px] font-bold flex items-center gap-1 transition">
                                        <span class="material-symbols-outlined text-xs">edit</span> View / Edit
                                    </button>
                                    <a href="print_lab_order.php?id=<?= htmlspecialchars($lo['id']) ?>" target="_blank" class="px-2 py-1 bg-slate-200 hover:bg-slate-300 text-slate-800 rounded-lg text-[11px] font-bold flex items-center gap-1 transition">
                                        <span class="material-symbols-outlined text-xs">print</span> Print
                                    </a>
                                </div>
                            </div>

                            <?php if (!empty($lo['items'])): ?>
                                <div class="pl-2 border-l-2 border-primary/30 space-y-1 my-1">
                                    <p class="text-[10px] font-bold text-outline uppercase">Order Items (<?= count($lo['items']) ?>)</p>
                                    <?php foreach ($lo['items'] as $item): ?>
                                        <div class="flex items-center justify-between text-[11px]">
                                            <span class="font-medium text-slate-700">• <?= htmlspecialchars($item['test_name']) ?> <span class="text-slate-400">(<?= htmlspecialchars($item['category']) ?>)</span></span>
                                            <span class="text-slate-500 italic"><?= htmlspecialchars($item['instructions'] ?: 'Standard') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($lo['results'])): ?>
                                <div class="p-2 bg-white rounded-lg border border-outline-variant/20 text-[11px]">
                                    <span class="font-bold text-slate-700">Results:</span>
                                    <span class="text-slate-800 font-mono"><?= htmlspecialchars($lo['results']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?= renderPagination($totalLabOrdersCount, $paginationLab['page'], $paginationLab['limit'], 'patient_detail.php', ['id' => $patientId], [5, 10, 25]) ?>
            <?php endif; ?>
        </div>

        <!-- Medical Certificates Card -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-5 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-emerald-600">workspace_premium</span>
                    <span>Issued Medical Certificates</span>
                </h2>
                <button onclick="document.getElementById('issueMedCertModal').classList.remove('hidden')" class="text-xs font-bold text-emerald-600 hover:text-emerald-700 flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">add</span> Issue New Certificate
                </button>
            </div>

            <?php if (empty($medCerts)): ?>
                <div class="p-6 text-center text-outline text-xs bg-surface-container-low/50 rounded-xl">
                    No medical certificates have been issued for this patient yet.
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($paginatedMedCerts as $cert): ?>
                        <div class="p-4 rounded-xl bg-surface-container-low/40 border border-outline-variant/20 flex flex-col md:flex-row md:items-center justify-between gap-3">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-xs text-on-surface"><?= htmlspecialchars($cert['certificate_number']) ?></span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                        <?= htmlspecialchars($cert['fitness_status']) ?>
                                    </span>
                                </div>
                                <p class="text-xs text-on-surface-variant font-medium">
                                    <strong>Diagnosis:</strong> <?= htmlspecialchars($cert['diagnosis']) ?>
                                </p>
                                <p class="text-[11px] text-outline">
                                    Issued on <?= htmlspecialchars($cert['issue_date']) ?> • Attending: <?= htmlspecialchars($cert['doctor_name']) ?>
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="print_medical_certificate.php?id=<?= urlencode($cert['id']) ?>" target="_blank" class="px-3 py-1.5 bg-surface-container-highest text-on-surface text-xs font-bold rounded-lg hover:bg-surface-variant transition flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">print</span> Print / View
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?= renderPagination($totalMedCertsCount, $paginationMC['page'], $paginationMC['limit'], 'patient_detail.php', ['id' => $patientId], [5, 10, 25]) ?>
            <?php endif; ?>
        </div>

        <!-- Past Encounters -->
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-5 shadow-xs">
            <h2 class="text-base font-bold text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">history_edu</span>
                <span>Past Clinical Visits & Encounters</span>
            </h2>

            <?php if (empty($pastVisits)): ?>
                <div class="p-6 text-center text-outline text-xs bg-surface-container-low/50 rounded-xl">
                    No past visits archived for this patient yet.
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($paginatedPastVisits as $pv): ?>
                        <div class="p-4 rounded-xl bg-surface-container-low/40 border border-outline-variant/20">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-bold text-xs text-on-surface"><?= htmlspecialchars($pv['title']) ?></h3>
                                    <?php if (!empty($pv['invoice_status'])): ?>
                                        <?php
                                        $invBadgeStyle = match($pv['invoice_status']) {
                                            'Paid' => 'bg-emerald-100 text-emerald-800',
                                            'Pending' => 'bg-amber-100 text-amber-800',
                                            'Overdue' => 'bg-red-100 text-red-800',
                                            default => 'bg-slate-200 text-slate-700'
                                        };
                                        ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $invBadgeStyle ?>">
                                            Invoiced (<?= htmlspecialchars($pv['invoice_status']) ?> - $<?= number_format((float)($pv['invoice_amount'] ?? 0), 2) ?>)
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <span class="text-[11px] text-outline font-medium"><?= htmlspecialchars($pv['visit_date']) ?></span>
                            </div>
                            <p class="text-xs text-on-surface-variant mb-2"><?= htmlspecialchars($pv['summary']) ?></p>
                            <div class="flex items-center justify-between text-[11px] text-outline pt-2 border-t border-outline-variant/20">
                                <span>Attending: <strong class="text-slate-700"><?= htmlspecialchars($pv['doctor_name']) ?></strong></span>
                                <div class="flex items-center gap-2">
                                    <?php if (!empty($pv['invoice_id'])): ?>
                                        <button type="button" disabled class="px-3 py-1 bg-slate-300 text-slate-600 cursor-not-allowed font-bold text-xs rounded-lg flex items-center gap-1 shadow-xs opacity-75">
                                            <span class="material-symbols-outlined text-sm">check_circle</span>
                                            <span>Invoiced</span>
                                        </button>
                                        <a href="print_invoice.php?id=<?= htmlspecialchars($pv['invoice_id']) ?>" target="_blank" class="px-3 py-1 bg-emerald-600 text-white font-bold text-xs rounded-lg hover:bg-emerald-700 transition flex items-center gap-1 shadow-xs">
                                            <span class="material-symbols-outlined text-sm">receipt</span>
                                            <span>View Invoice</span>
                                        </a>
                                    <?php else: ?>
                                        <button type="button" onclick='openCreateInvoiceForVisit(<?= htmlspecialchars(json_encode($pv), ENT_QUOTES, "UTF-8") ?>)' class="px-3 py-1 bg-blue-700 text-white font-bold text-xs rounded-lg hover:bg-blue-800 transition flex items-center gap-1 shadow-xs">
                                            <span class="material-symbols-outlined text-sm">receipt_long</span>
                                            <span>Create Invoice</span>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" onclick='openPastVisitModal(<?= htmlspecialchars(json_encode($pv), ENT_QUOTES, "UTF-8") ?>)' class="px-3 py-1 bg-primary text-white font-bold text-xs rounded-lg hover:bg-primary/90 transition flex items-center gap-1 shadow-xs">
                                        <span class="material-symbols-outlined text-sm">visibility</span>
                                        <span>View Details</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?= renderPagination($totalPastVisitsCount, $paginationPV['page'], $paginationPV['limit'], 'patient_detail.php', ['id' => $patientId], [5, 10, 25]) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: Edit Patient Info -->
<div id="editPatientModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 flex items-center justify-center p-4 hidden overflow-y-auto">
    <div class="bg-white rounded-3xl max-w-2xl w-full shadow-2xl border border-slate-200 overflow-hidden my-8">
        <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
            <h3 class="font-bold text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-base">person_edit</span>
                <span>Edit Patient Information</span>
            </h3>
            <button onclick="document.getElementById('editPatientModal').classList.add('hidden')" class="text-slate-400 hover:text-white">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/patient_update.php" method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patient['id']) ?>">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="first_name" value="<?= htmlspecialchars($patient['first_name']) ?>" required class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Last Name <span class="text-red-500">*</span></label>
                    <input type="text" name="last_name" value="<?= htmlspecialchars($patient['last_name']) ?>" required class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Date of Birth <span class="text-red-500">*</span></label>
                    <input type="date" name="dob" value="<?= htmlspecialchars($patient['dob']) ?>" required class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Gender</label>
                    <select name="gender" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-semibold">
                        <option value="Female" <?= $patient['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                        <option value="Male" <?= $patient['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                        <option value="Other" <?= $patient['gender'] === 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Blood Group</label>
                    <input type="text" name="blood_group" value="<?= htmlspecialchars($patient['blood_group'] ?? '') ?>" placeholder="e.g. O+, A-, B+" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-bold text-primary">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Phone Number</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($patient['phone'] ?? '') ?>" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>
                <div class="md:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">Email Address</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($patient['email'] ?? '') ?>" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>
                <div class="md:col-span-3">
                    <label class="block font-bold text-slate-700 mb-1">Address</label>
                    <input type="text" name="address" value="<?= htmlspecialchars($patient['address'] ?? '') ?>" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>
            </div>

            <hr class="border-slate-200">

            <!-- Emergency Contact -->
            <p class="font-bold text-slate-800 text-xs">Emergency Contact Details</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Contact Name</label>
                    <input type="text" name="emergency_contact_name" value="<?= htmlspecialchars($patient['emergency_contact_name'] ?? '') ?>" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Relationship</label>
                    <input type="text" name="emergency_contact_relationship" value="<?= htmlspecialchars($patient['emergency_contact_relationship'] ?? '') ?>" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Phone Number</label>
                    <input type="text" name="emergency_contact_phone" value="<?= htmlspecialchars($patient['emergency_contact_phone'] ?? '') ?>" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>
            </div>

            <hr class="border-slate-200">

            <!-- Insurance Info -->
            <p class="font-bold text-slate-800 text-xs">Insurance Information</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Insurance Provider</label>
                    <input type="text" name="insurance_provider" value="<?= htmlspecialchars($patient['insurance_provider'] ?? '') ?>" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-medium">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Policy Number</label>
                    <input type="text" name="insurance_policy_number" value="<?= htmlspecialchars($patient['insurance_policy_number'] ?? '') ?>" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-mono font-medium">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Group Number</label>
                    <input type="text" name="insurance_group_number" value="<?= htmlspecialchars($patient['insurance_group_number'] ?? '') ?>" class="w-full bg-slate-50 px-3 py-2 rounded-xl border border-slate-300 font-mono font-medium">
                </div>
            </div>

            <hr class="border-slate-200">

            <!-- Clinical Alerts -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Known Allergies</label>
                    <textarea name="known_allergies" rows="2" class="w-full bg-slate-50 p-2.5 rounded-xl border border-slate-300 font-medium"><?= htmlspecialchars($patient['known_allergies'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Chronic Conditions</label>
                    <textarea name="chronic_conditions" rows="2" class="w-full bg-slate-50 p-2.5 rounded-xl border border-slate-300 font-medium"><?= htmlspecialchars($patient['chronic_conditions'] ?? '') ?></textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="block font-bold text-slate-700 mb-1">Clinical Notes</label>
                    <textarea name="clinical_notes" rows="2" class="w-full bg-slate-50 p-2.5 rounded-xl border border-slate-300 font-medium"><?= htmlspecialchars($patient['clinical_notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
                <button type="button" onclick="document.getElementById('editPatientModal').classList.add('hidden')" class="px-4 py-2 bg-slate-200 text-slate-700 font-semibold rounded-xl hover:bg-slate-300 transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 transition shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">save</span>
                    <span>Save Patient Changes</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: View Past Encounter Record -->
<div id="viewPastVisitModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 flex items-center justify-center p-4 hidden overflow-y-auto">
    <div class="bg-surface-container-lowest rounded-3xl max-w-2xl w-full shadow-2xl border border-outline-variant/30 overflow-hidden my-8">
        <div class="px-6 py-4 bg-primary text-white flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">history_edu</span>
                <h3 class="font-bold text-sm" id="pv_modal_title">Past Clinical Encounter Details</h3>
            </div>
            <button type="button" onclick="closePastVisitModal()" class="text-white/80 hover:text-white">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <div class="p-6 space-y-5 text-xs">
            <!-- Header Meta -->
            <div class="flex items-center justify-between pb-3 border-b border-slate-200">
                <div>
                    <span class="text-slate-500 font-semibold block text-[11px]">Encounter Date</span>
                    <span class="font-bold text-slate-800 text-sm" id="pv_modal_date">-</span>
                </div>
                <div class="text-right">
                    <span class="text-slate-500 font-semibold block text-[11px]">Attending Physician</span>
                    <span class="font-bold text-primary text-sm" id="pv_modal_doctor">-</span>
                </div>
            </div>

            <!-- Vitals Record -->
            <div id="pv_vitals_section" class="bg-surface-container-low p-4 rounded-2xl border border-outline-variant/30 space-y-2">
                <h4 class="font-bold text-slate-700 uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-base">vital_signs</span>
                    <span>Vitals Summary</span>
                </h4>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 font-mono font-medium text-slate-800" id="pv_vitals_content">
                    <!-- Populated dynamically -->
                </div>
            </div>

            <!-- SOAP Documentation -->
            <div class="bg-surface-container-low p-4 rounded-2xl border border-outline-variant/30 space-y-3">
                <h4 class="font-bold text-slate-700 uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-base">edit_note</span>
                    <span>Clinical Notes & SOAP</span>
                </h4>
                <div class="space-y-2 text-slate-800">
                    <div>
                        <span class="font-bold text-slate-600 block">Subjective (Chief Complaint & History):</span>
                        <p class="mt-0.5 font-medium" id="pv_soap_subjective">None recorded.</p>
                    </div>
                    <div>
                        <span class="font-bold text-slate-600 block">Objective (Physical Exam):</span>
                        <p class="mt-0.5 font-medium" id="pv_soap_objective">None recorded.</p>
                    </div>
                    <div>
                        <span class="font-bold text-slate-600 block">Assessment / ICD Diagnosis:</span>
                        <span class="inline-block mt-0.5 px-2.5 py-0.5 rounded bg-primary-fixed text-on-primary-fixed font-bold font-mono text-[11px]" id="pv_soap_assessment">J01.90</span>
                    </div>
                    <div>
                        <span class="font-bold text-slate-600 block">Plan & Recommendations:</span>
                        <p class="mt-0.5 font-medium" id="pv_soap_plan">None recorded.</p>
                    </div>
                </div>
            </div>

            <!-- Prescriptions Issued -->
            <div class="bg-surface-container-low p-4 rounded-2xl border border-outline-variant/30 space-y-3">
                <h4 class="font-bold text-slate-700 uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-base">prescriptions</span>
                    <span>Prescriptions Issued</span>
                </h4>
                <div class="space-y-2" id="pv_prescriptions_list">
                    <!-- Populated dynamically -->
                </div>
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-slate-200">
                <button type="button" id="pv_create_invoice_btn" onclick="" class="px-4 py-2 bg-blue-700 text-white font-bold text-xs rounded-xl hover:bg-blue-800 transition flex items-center gap-1.5 shadow-xs">
                    <span class="material-symbols-outlined text-sm">receipt_long</span>
                    <span>Create Invoice for Visit</span>
                </button>
                <button type="button" onclick="closePastVisitModal()" class="px-5 py-2 bg-slate-200 text-slate-800 font-bold rounded-xl hover:bg-slate-300 transition">
                    Close Record
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openPastVisitModal(pv) {
    document.getElementById('pv_modal_title').innerText = pv.title || 'Past Clinical Encounter';
    document.getElementById('pv_modal_date').innerText = pv.visit_date || 'N/A';
    document.getElementById('pv_modal_doctor').innerText = pv.doctor_name || 'N/A';

    // Parse Vitals
    let vitalsHtml = '';
    let vitals = null;
    try {
        if (pv.vitals) {
            vitals = typeof pv.vitals === 'string' ? JSON.parse(pv.vitals) : pv.vitals;
        }
    } catch(e) {}

    if (vitals && Object.keys(vitals).length > 0) {
        if (vitals.blood_pressure) vitalsHtml += `<div><span class="text-[10px] text-slate-400 block font-sans font-semibold">BP</span><span>${vitals.blood_pressure}</span></div>`;
        if (vitals.heart_rate) vitalsHtml += `<div><span class="text-[10px] text-slate-400 block font-sans font-semibold">HR</span><span>${vitals.heart_rate} bpm</span></div>`;
        if (vitals.temperature) vitalsHtml += `<div><span class="text-[10px] text-slate-400 block font-sans font-semibold">Temp</span><span>${vitals.temperature} °F</span></div>`;
        if (vitals.oxygen_sat) vitalsHtml += `<div><span class="text-[10px] text-slate-400 block font-sans font-semibold">SpO2</span><span>${vitals.oxygen_sat}%</span></div>`;
    } else {
        vitalsHtml = '<p class="col-span-4 text-slate-400 font-sans italic text-xs">No vitals saved for this visit record.</p>';
    }
    document.getElementById('pv_vitals_content').innerHTML = vitalsHtml;

    // Parse SOAP Notes
    let soap = null;
    try {
        if (pv.soap_notes) {
            soap = typeof pv.soap_notes === 'string' ? JSON.parse(pv.soap_notes) : pv.soap_notes;
        }
    } catch(e) {}

    document.getElementById('pv_soap_subjective').innerText = (soap && soap.subjective) ? soap.subjective : (pv.summary || 'N/A');
    document.getElementById('pv_soap_objective').innerText = (soap && soap.objective) ? soap.objective : 'N/A';
    document.getElementById('pv_soap_assessment').innerText = (soap && soap.assessment_code) ? soap.assessment_code : (pv.title || 'Clinical Encounter');
    document.getElementById('pv_soap_plan').innerText = (soap && soap.plan) ? soap.plan : (pv.summary || 'N/A');

    // Parse Prescriptions
    let prescriptions = null;
    try {
        if (pv.prescriptions) {
            prescriptions = typeof pv.prescriptions === 'string' ? JSON.parse(pv.prescriptions) : pv.prescriptions;
        }
    } catch(e) {}

    let rxHtml = '';
    if (prescriptions && prescriptions.length > 0) {
        prescriptions.forEach(rx => {
            rxHtml += `
                <div class="p-2.5 rounded-xl bg-white border border-slate-200">
                    <p class="font-bold text-slate-800">${rx.medication_name || ''} <span class="text-primary font-mono">${rx.dosage || ''}</span></p>
                    <p class="text-[11px] text-slate-500 mt-0.5">${rx.frequency || ''} • ${rx.duration || ''}</p>
                    ${rx.instructions ? `<p class="text-[10px] text-slate-600 italic mt-0.5">${rx.instructions}</p>` : ''}
                </div>
            `;
        });
    } else {
        rxHtml = '<p class="text-slate-400 italic text-xs">No prescriptions associated with this encounter.</p>';
    }
    document.getElementById('pv_prescriptions_list').innerHTML = rxHtml;

    const invBtn = document.getElementById('pv_create_invoice_btn');
    if (invBtn) {
        if (pv.invoice_id) {
            invBtn.outerHTML = `
                <div class="flex items-center gap-2">
                    <button type="button" disabled class="px-4 py-2 bg-slate-300 text-slate-600 cursor-not-allowed font-bold text-xs rounded-xl flex items-center gap-1.5 shadow-xs opacity-75">
                        <span class="material-symbols-outlined text-sm">check_circle</span>
                        <span>Invoiced</span>
                    </button>
                    <a href="print_invoice.php?id=${pv.invoice_id}" target="_blank" class="px-4 py-2 bg-emerald-600 text-white font-bold text-xs rounded-xl hover:bg-emerald-700 transition flex items-center gap-1.5 shadow-xs">
                        <span class="material-symbols-outlined text-sm">receipt</span>
                        <span>View Invoice</span>
                    </a>
                </div>
            `;
        } else {
            invBtn.outerHTML = `
                <button type="button" id="pv_create_invoice_btn" class="px-4 py-2 bg-blue-700 text-white font-bold text-xs rounded-xl hover:bg-blue-800 transition flex items-center gap-1.5 shadow-xs">
                    <span class="material-symbols-outlined text-sm">receipt_long</span>
                    <span>Create Invoice for Visit</span>
                </button>
            `;
            document.getElementById('pv_create_invoice_btn').onclick = function() {
                closePastVisitModal();
                openCreateInvoiceForVisit(pv);
            };
        }
    }

    document.getElementById('viewPastVisitModal').classList.remove('hidden');
}

function closePastVisitModal() {
    document.getElementById('viewPastVisitModal').classList.add('hidden');
}
</script>

<!-- Modal: Create VMS Fiscal Invoice for Past Visit -->
<div id="createInvoiceModal" class="fixed inset-0 bg-black/50 backdrop-blur-xs z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-surface-container-lowest rounded-2xl max-w-2xl w-full p-6 shadow-2xl border border-outline-variant/30 space-y-4 max-h-[90vh] overflow-y-auto text-xs">
        <div class="flex items-center justify-between pb-3 border-b border-outline-variant/20">
            <h3 class="text-base font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-blue-600">receipt_long</span>
                <span>Issue VMS Fiscal Invoice for Encounter</span>
            </h3>
            <button type="button" onclick="document.getElementById('createInvoiceModal').classList.add('hidden')" class="text-outline hover:text-on-surface">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/encounter_save.php" method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="action" value="create_invoice">
            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patient['id']) ?>">
            <input type="hidden" name="visit_id" id="inv_visit_id" value="">
            <input type="hidden" name="redirect_to" value="patient_detail.php">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Patient Name & MRN</label>
                <input type="text" readonly value="<?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name'] . ' (' . $patient['mrn'] . ')') ?>" class="w-full bg-slate-100 px-3 py-2 rounded-xl border border-slate-300 font-bold text-slate-700">
            </div>

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
                    <label class="font-bold text-on-surface">VMS Line Items</label>
                    <button type="button" onclick="addPastInvoiceRow()" class="px-2 py-0.5 bg-emerald-600 text-white rounded-lg text-[10px] font-bold hover:bg-emerald-700 transition">
                        + Add Line Item
                    </button>
                </div>

                <div class="border border-outline-variant/30 rounded-xl overflow-hidden bg-white">
                    <table class="w-full text-left" id="pastInvoiceItemsTable">
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
                                    <select onchange="onPastInventorySelect(this)" class="w-full px-1.5 py-1 border border-outline-variant/40 rounded-lg font-semibold text-[11px] text-primary bg-white">
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
                                    <input type="text" name="item_name[]" id="inv_service_desc" value="Clinical Consultation & Examination" required placeholder="Item / Service Name" class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg text-xs font-medium">
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

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-outline-variant/20">
                <button type="button" onclick="document.getElementById('createInvoiceModal').classList.add('hidden')" class="px-4 py-2 bg-surface-container-high text-on-surface font-semibold rounded-xl hover:bg-surface-variant transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-blue-700 text-white font-bold rounded-xl hover:bg-blue-800 transition shadow-xs flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">receipt_long</span>
                    <span>Generate VMS Fiscal Invoice</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const pastInventoryList = <?= json_encode($inventoryList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function onPastInventorySelect(select) {
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

function addPastInvoiceRow() {
    const tbody = document.querySelector('#pastInvoiceItemsTable tbody');
    const tr = document.createElement('tr');

    let invOptions = '<option value="">-- Choose Inventory Stock Item (Optional) --</option>';
    if (Array.isArray(pastInventoryList)) {
        pastInventoryList.forEach(item => {
            const price = parseFloat(item.unit_price) || 0;
            const tax = item.vms_tax_code || 'A';
            invOptions += `<option value="${item.id}" data-name="${item.name}" data-sku="${item.sku}" data-price="${price}" data-tax="${tax}">${item.name} ($${price.toFixed(2)})</option>`;
        });
    }

    tr.innerHTML = `
        <td class="py-1.5 px-2 space-y-1">
            <select onchange="onPastInventorySelect(this)" class="w-full px-1.5 py-1 border border-outline-variant/40 rounded-lg font-semibold text-[11px] text-primary bg-white">
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

function openCreateInvoiceForVisit(pv) {
    document.getElementById('inv_visit_id').value = pv.visit_id || '';
    if (pv.title) {
        document.getElementById('inv_service_desc').value = pv.title;
    }
    document.getElementById('createInvoiceModal').classList.remove('hidden');
}
</script>

<!-- Modal: Issue Medical Certificate -->
<div id="issueMedCertModal" class="fixed inset-0 bg-black/50 backdrop-blur-xs z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-surface-container-lowest rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-outline-variant/30 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4 pb-3 border-b border-outline-variant/20">
            <h3 class="text-base font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-600">workspace_premium</span>
                <span>Issue Medical Certificate</span>
            </h3>
            <button onclick="document.getElementById('issueMedCertModal').classList.add('hidden')" class="text-outline hover:text-on-surface">
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
                <textarea name="diagnosis" rows="2" placeholder="e.g. Acute Upper Respiratory Tract Infection" class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 font-medium" required></textarea>
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
                <input type="text" name="fit_status_details" placeholder="e.g. Advised medical leave for 3 days (Oct 24 - Oct 26)" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Remarks & Recommendations</label>
                <textarea name="recommendations" rows="2" placeholder="e.g. Hydration, completion of prescribed antibiotics, and avoidance of heavy exertion." class="w-full bg-surface-container-low p-3 rounded-xl border border-outline-variant/40 font-medium"></textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2 border-t border-outline-variant/20">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Attending Physician</label>
                    <input type="text" name="doctor_name" value="<?= htmlspecialchars($_SESSION['user_name'] ?? 'Dr. Sarah Jenkins') ?>" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium" required>
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

<!-- Modal Edit Lab Order (Multi-Item & Results Supported) -->
<div id="modal-edit-lab-order" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/30 shadow-2xl max-w-2xl w-full overflow-hidden">
        <div class="p-5 bg-slate-900 text-white flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-xl">science</span>
                <h3 class="font-bold text-sm">View & Edit Lab Request / Attach Results</h3>
            </div>
            <button type="button" onclick="closeModal('modal-edit-lab-order')" class="text-slate-400 hover:text-white">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form action="actions/lab_order_save.php" method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="edit_lab_order">
            <input type="hidden" name="order_id" id="edit-lab-order-id">
            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patientId) ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Order Status</label>
                    <select name="status" id="edit-lab-status" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-bold text-primary">
                        <option value="Ordered">Ordered (Pending)</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="flex items-center gap-2 pt-5">
                    <input type="checkbox" id="edit-lab-is-abnormal" name="is_abnormal" value="1" class="w-4 h-4 text-red-600 rounded focus:ring-red-500">
                    <label for="edit-lab-is-abnormal" class="font-bold text-rose-700 text-xs">Mark as Abnormal / High Concern Result</label>
                </div>
            </div>

            <div class="flex items-center justify-between pt-2 border-t border-outline-variant/20">
                <span class="font-bold text-slate-800">Requested Test Items</span>
                <button type="button" onclick="addEditLabRow()" class="px-2.5 py-1 bg-primary/10 text-primary hover:bg-primary/20 rounded-lg font-bold text-[11px] flex items-center gap-1 transition">
                    <span class="material-symbols-outlined text-xs">add</span> Add Test Item
                </button>
            </div>

            <div id="edit-lab-items-container" class="space-y-3 max-h-52 overflow-y-auto pr-1">
                <!-- Dynamically populated via JS -->
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Clinical Notes / Indications</label>
                <textarea name="clinical_notes" id="edit-lab-notes" rows="2" placeholder="Clinical reasons for requesting lab tests..." class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary"></textarea>
            </div>

            <div class="p-3 bg-amber-50 border border-amber-200 rounded-2xl space-y-2">
                <label class="block font-bold text-amber-900 text-xs">Laboratory Results & Findings Summary</label>
                <textarea name="results" id="edit-lab-results" rows="3" placeholder="Enter test results, numerical values, findings, or radiologist report..." class="w-full bg-white p-2.5 rounded-xl border border-amber-300 font-mono text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500"></textarea>
                <p class="text-[10px] text-amber-800">Updating results will allow doctors and clinical staff to view findings directly in patient chart.</p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-outline-variant/20">
                <button type="button" onclick="closeModal('modal-edit-lab-order')" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl font-semibold text-on-surface">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 shadow-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span>Save Changes & Results</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Add Lab Order Patient Detail (Multi-Item Supported) -->
<div id="modal-add-lab-order-pd" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/30 shadow-2xl max-w-xl w-full overflow-hidden">
        <div class="p-5 bg-slate-900 text-white flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-xl">science</span>
                <h3 class="font-bold text-sm">Order Lab Tests & Diagnostics</h3>
            </div>
            <button type="button" onclick="closeModal('modal-add-lab-order-pd')" class="text-slate-400 hover:text-white">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form action="actions/lab_order_save.php" method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_lab_order">
            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patientId) ?>">

            <div class="flex items-center justify-between">
                <span class="font-bold text-slate-800">Requested Test Items / Panels</span>
                <button type="button" onclick="addLabRowPd()" class="px-2.5 py-1 bg-primary/10 text-primary hover:bg-primary/20 rounded-lg font-bold text-[11px] flex items-center gap-1 transition">
                    <span class="material-symbols-outlined text-xs">add</span> Add Test Item
                </button>
            </div>

            <div id="lab-items-container-pd" class="space-y-3 max-h-60 overflow-y-auto pr-1">
                <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 grid grid-cols-1 md:grid-cols-12 gap-2 items-center">
                    <div class="md:col-span-5">
                        <label class="block font-bold text-slate-600 text-[10px] mb-0.5">Test Name *</label>
                        <input type="text" name="items[0][test_name]" required placeholder="e.g. Complete Blood Count (CBC)" class="w-full bg-white px-3 py-1.5 rounded-lg border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary">
                    </div>
                    <div class="md:col-span-4">
                        <label class="block font-bold text-slate-600 text-[10px] mb-0.5">Category</label>
                        <select name="items[0][category]" class="w-full bg-white px-3 py-1.5 rounded-lg border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary">
                            <option value="General">General</option>
                            <option value="Hematology">Hematology</option>
                            <option value="Biochemistry">Biochemistry</option>
                            <option value="Microbiology">Microbiology</option>
                            <option value="Serology">Serology</option>
                            <option value="Imaging">Imaging / Radiology</option>
                        </select>
                    </div>
                    <div class="md:col-span-3">
                        <label class="block font-bold text-slate-600 text-[10px] mb-0.5">Instructions</label>
                        <input type="text" name="items[0][instructions]" placeholder="Fasting 8h, etc." class="w-full bg-white px-2.5 py-1.5 rounded-lg border border-outline-variant/40 font-medium">
                    </div>
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Clinical Notes / Indications</label>
                <textarea name="clinical_notes" rows="2" placeholder="Clinical reasons for requesting lab tests..." class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-outline-variant/20">
                <button type="button" onclick="closeModal('modal-add-lab-order-pd')" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl font-semibold text-on-surface">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 shadow-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">send</span>
                    <span>Submit Order</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let editLabItemCount = 0;

function openEditLabModal(order) {
    document.getElementById('edit-lab-order-id').value = order.id || '';
    document.getElementById('edit-lab-status').value = order.status || 'Ordered';
    document.getElementById('edit-lab-notes').value = order.clinical_notes || '';
    document.getElementById('edit-lab-results').value = order.results || '';
    document.getElementById('edit-lab-is-abnormal').checked = Boolean(order.is_abnormal && order.is_abnormal != '0');

    const container = document.getElementById('edit-lab-items-container');
    container.innerHTML = '';
    editLabItemCount = 0;

    if (order.items && order.items.length > 0) {
        order.items.forEach(item => {
            addEditLabRow(item.test_name, item.category, item.instructions);
        });
    } else {
        addEditLabRow(order.test_name, order.category, '');
    }

    openModal('modal-edit-lab-order');
}

function addEditLabRow(testName = '', category = 'General', instructions = '') {
    const container = document.getElementById('edit-lab-items-container');
    const div = document.createElement('div');
    div.className = 'p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 grid grid-cols-1 md:grid-cols-12 gap-2 items-center relative';
    div.innerHTML = `
        <div class="md:col-span-5">
            <label class="block font-bold text-slate-600 text-[10px] mb-0.5">Test Name *</label>
            <input type="text" name="items[${editLabItemCount}][test_name]" value="${escapeHtml(testName)}" required placeholder="e.g. Complete Blood Count" class="w-full bg-white px-3 py-1.5 rounded-lg border border-outline-variant/40 font-medium text-on-surface">
        </div>
        <div class="md:col-span-4">
            <label class="block font-bold text-slate-600 text-[10px] mb-0.5">Category</label>
            <select name="items[${editLabItemCount}][category]" class="w-full bg-white px-2 py-1.5 rounded-lg border border-outline-variant/40 font-medium">
                <option value="General" ${category === 'General' ? 'selected' : ''}>General / Other</option>
                <option value="Hematology" ${category === 'Hematology' ? 'selected' : ''}>Hematology</option>
                <option value="Biochemistry" ${category === 'Biochemistry' ? 'selected' : ''}>Biochemistry</option>
                <option value="Microbiology" ${category === 'Microbiology' ? 'selected' : ''}>Microbiology</option>
                <option value="Serology" ${category === 'Serology' ? 'selected' : ''}>Serology</option>
                <option value="Imaging" ${category === 'Imaging' ? 'selected' : ''}>Imaging / Radiology</option>
            </select>
        </div>
        <div class="md:col-span-3">
            <label class="block font-bold text-slate-600 text-[10px] mb-0.5">Instructions</label>
            <div class="flex items-center gap-1">
                <input type="text" name="items[${editLabItemCount}][instructions]" value="${escapeHtml(instructions)}" placeholder="Fasting, etc." class="w-full bg-white px-2.5 py-1.5 rounded-lg border border-outline-variant/40 font-medium">
                <button type="button" onclick="this.closest('.relative').remove()" class="text-rose-600 hover:text-rose-800 p-1 rounded">
                    <span class="material-symbols-outlined text-base">delete</span>
                </button>
            </div>
        </div>
    `;
    container.appendChild(div);
    editLabItemCount++;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

let pdLabItemCount = 1;
function addLabRowPd() {
    const container = document.getElementById('lab-items-container-pd');
    const div = document.createElement('div');
    div.className = 'p-3 bg-surface-container-low rounded-xl border border-outline-variant/30 grid grid-cols-1 md:grid-cols-12 gap-2 items-center relative';
    div.innerHTML = `
        <div class="md:col-span-5">
            <label class="block font-bold text-slate-600 text-[10px] mb-0.5">Test Name *</label>
            <input type="text" name="items[${pdLabItemCount}][test_name]" required placeholder="e.g. Lipid Profile" class="w-full bg-white px-3 py-1.5 rounded-lg border border-outline-variant/40 font-medium text-on-surface">
        </div>
        <div class="md:col-span-4">
            <label class="block font-bold text-slate-600 text-[10px] mb-0.5">Category</label>
            <select name="items[${pdLabItemCount}][category]" class="w-full bg-white px-3 py-1.5 rounded-lg border border-outline-variant/40 font-medium text-on-surface">
                <option value="General">General</option>
                <option value="Hematology">Hematology</option>
                <option value="Biochemistry">Biochemistry</option>
                <option value="Microbiology">Microbiology</option>
                <option value="Serology">Serology</option>
                <option value="Imaging">Imaging / Radiology</option>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="block font-bold text-slate-600 text-[10px] mb-0.5">Instructions</label>
            <input type="text" name="items[${pdLabItemCount}][instructions]" placeholder="e.g. Fasting" class="w-full bg-white px-2.5 py-1.5 rounded-lg border border-outline-variant/40 font-medium">
        </div>
        <div class="md:col-span-1 flex items-end justify-center pt-3">
            <button type="button" onclick="this.closest('.grid').remove()" class="text-rose-600 hover:text-rose-800 p-1">
                <span class="material-symbols-outlined text-base">delete</span>
            </button>
        </div>
    `;
    container.appendChild(div);
    pdLabItemCount++;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
