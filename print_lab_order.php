<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';

if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit;
}

$orderId = $_GET['id'] ?? $_GET['order_id'] ?? '';
$patientId = $_GET['patient_id'] ?? '';
$csrfToken = generateCsrfToken();
$pdo = getDB();

$labOrderService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\LabOrderService::class);
$order = null;

if (!empty($orderId)) {
    $order = $labOrderService->getOrderWithItems($orderId);
}

if (!$order && !empty($patientId)) {
    $patientOrders = $labOrderService->getOrdersByPatient($patientId);
    if (!empty($patientOrders)) {
        $order = $patientOrders[0];
    }
}

if (!$order) {
    die("Lab order not found.");
}

$patientId = $order['patient_id'];

// Fetch Clinic Settings
$settingsRows = $pdo->query("SELECT * FROM clinic_settings")->fetchAll();
$settings = [];
foreach ($settingsRows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$clinicName = $settings['clinic_name'] ?? 'Nuvis Medico Healthcare';
$clinicSubtitle = $settings['clinic_subtitle'] ?? 'Integrated Healthcare Center';
$clinicAddress = $settings['clinic_address'] ?? '100 Healthcare Way, Suite 400, Springfield, OR 97477';
$clinicPhone = $settings['clinic_phone'] ?? '(555) 019-2831';
$clinicEmail = $settings['clinic_email'] ?? 'medico@nuvistechnologies.com.fj';
$clinicDea = $settings['clinic_dea'] ?? 'FC9823019';
$clinicNpi = $settings['clinic_npi'] ?? '1092830192';

$labHeaderTitle = $settings['lab_header_title'] ?? 'OFFICIAL LABORATORY & DIAGNOSTIC REQUEST';

// Fetch patient info
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
$stmt->execute([$patientId]);
$patient = $stmt->fetch();

if (!$patient) {
    die("Patient record not found.");
}

// Get attending doctor details
$docId = $_SESSION['current_doctor_id'] ?? 'doc-2';
$docStmt = $pdo->prepare("SELECT * FROM doctors WHERE id = ? OR role = 'Doctor'");
$docStmt->execute([$docId]);
$attendingDoc = $docStmt->fetch() ?: ['name' => $order['ordered_by'] ?? 'Dr. Sarah Jenkins', 'specialty' => 'Internal Medicine', 'prc_number' => 'PRC-0098412', 'ptr_number' => 'PTR-8842109'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Request - <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
        }
    </style>
</head>
<body class="bg-slate-100 p-8 min-h-screen text-slate-800 font-sans">

<div class="max-w-2xl mx-auto bg-white p-8 rounded-2xl shadow-lg border border-slate-200">
    <!-- Action buttons -->
    <div class="no-print mb-6 flex flex-wrap justify-between items-center gap-2 bg-slate-50 p-4 rounded-xl border border-slate-200">
        <a href="patient_detail.php?id=<?= htmlspecialchars($patient['id']) ?>" class="text-xs font-semibold text-slate-600 hover:text-slate-900">&larr; Back to Patient File</a>
        <div class="flex gap-2 items-center">
            <form action="actions/send_email.php" method="POST" class="inline-flex items-center gap-1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="document_type" value="lab_request">
                <input type="hidden" name="document_id" value="LAB-<?= htmlspecialchars($order['id']) ?>">
                <input type="email" name="email" value="<?= htmlspecialchars($patient['email'] ?? '') ?>" placeholder="patient@example.com" required class="px-2.5 py-1.5 text-xs rounded-lg border border-slate-300 focus:outline-none">
                <button type="submit" class="px-3 py-2 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700 transition">Email Request</button>
            </form>
            <button onclick="window.print()" class="px-4 py-2 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 transition">Print Lab Request</button>
        </div>
    </div>

    <!-- Customized Clinic Header -->
    <div class="border-b-2 border-blue-900 pb-4 mb-6 flex justify-between items-start">
        <div>
            <h1 class="text-2xl font-bold text-blue-900 uppercase tracking-tight"><?= htmlspecialchars($clinicName) ?></h1>
            <p class="text-xs text-blue-700 font-medium"><?= htmlspecialchars($clinicSubtitle) ?></p>
            <p class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($clinicAddress) ?> • Phone: <?= htmlspecialchars($clinicPhone) ?></p>
            <p class="text-xs text-slate-500">DEA: <?= htmlspecialchars($clinicDea) ?> • NPI: <?= htmlspecialchars($clinicNpi) ?></p>
        </div>
        <div class="text-right">
            <span class="text-xl font-bold text-blue-900 tracking-wider">LAB REQ</span>
            <p class="text-xs text-slate-500 font-mono mt-1">Order ID: <?= htmlspecialchars(substr($order['id'], 0, 12)) ?></p>
            <p class="text-xs text-slate-500 font-mono">Date: <?= date('M d, Y', strtotime($order['created_at'])) ?></p>
        </div>
    </div>

    <!-- Header Title -->
    <div class="text-center mb-6">
        <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500 border-y border-slate-200 py-1.5"><?= htmlspecialchars($labHeaderTitle) ?></h2>
    </div>

    <!-- Patient Header -->
    <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 mb-6 text-xs grid grid-cols-2 gap-4">
        <div>
            <p class="text-slate-500 font-semibold uppercase text-[10px]">Patient Name</p>
            <p class="font-bold text-sm text-slate-900"><?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></p>
            <p class="text-slate-500 mt-1">DOB: <?= htmlspecialchars($patient['dob']) ?> (<?= htmlspecialchars($patient['age']) ?> Yrs) • Gender: <?= htmlspecialchars($patient['gender']) ?></p>
        </div>
        <div>
            <p class="text-slate-500 font-semibold uppercase text-[10px]">Medical Record No.</p>
            <p class="font-bold text-sm font-mono text-slate-900"><?= htmlspecialchars($patient['mrn']) ?></p>
            <p class="text-slate-500 mt-1">Request Status: <strong class="text-blue-700 uppercase"><?= htmlspecialchars($order['status']) ?></strong></p>
        </div>
    </div>

    <!-- Clinical Notes / Indications -->
    <?php if (!empty($order['clinical_notes'])): ?>
    <div class="mb-6 text-xs bg-amber-50/60 p-3 rounded-xl border border-amber-200/60">
        <p class="text-amber-900 font-bold uppercase text-[10px] mb-0.5">Clinical Indications / Notes</p>
        <p class="text-slate-800 font-medium"><?= nl2br(htmlspecialchars($order['clinical_notes'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- Multi-Item Lab Test Table -->
    <div class="mb-8">
        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200 pb-2 mb-4">Ordered Test Procedures & Panels</h2>

        <table class="w-full text-left text-xs border border-slate-200 rounded-xl overflow-hidden">
            <thead class="bg-slate-100 text-slate-600 font-bold uppercase text-[10px]">
                <tr>
                    <th class="py-2.5 px-3">#</th>
                    <th class="py-2.5 px-3">Test Procedure Name</th>
                    <th class="py-2.5 px-3">Category</th>
                    <th class="py-2.5 px-3">Special Instructions</th>
                    <th class="py-2.5 px-3 text-right">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php
                $items = !empty($order['items']) ? $order['items'] : [
                    ['test_name' => $order['test_name'], 'category' => $order['category'], 'instructions' => '', 'status' => $order['status']]
                ];
                foreach ($items as $idx => $item):
                ?>
                    <tr class="hover:bg-slate-50">
                        <td class="py-3 px-3 font-mono font-bold text-slate-500"><?= ($idx + 1) ?></td>
                        <td class="py-3 px-3 font-bold text-slate-900"><?= htmlspecialchars($item['test_name']) ?></td>
                        <td class="py-3 px-3 font-medium text-slate-600"><?= htmlspecialchars($item['category'] ?? 'General') ?></td>
                        <td class="py-3 px-3 text-slate-500 italic"><?= htmlspecialchars($item['instructions'] ?: 'Standard collection') ?></td>
                        <td class="py-3 px-3 text-right font-bold text-blue-800"><?= htmlspecialchars($item['status'] ?? 'Ordered') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Results section if available -->
    <?php if (!empty($order['results'])): ?>
    <div class="mb-8 p-4 bg-slate-50 rounded-xl border border-slate-200 text-xs">
        <h3 class="font-bold text-slate-900 mb-1 flex items-center justify-between">
            <span>Laboratory Results & Findings</span>
            <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= !empty($order['is_abnormal']) ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800' ?>">
                <?= !empty($order['is_abnormal']) ? 'ABNORMAL FINDINGS' : 'NORMAL' ?>
            </span>
        </h3>
        <p class="text-slate-700 whitespace-pre-wrap font-mono mt-2 bg-white p-3 rounded-lg border border-slate-200"><?= htmlspecialchars($order['results']) ?></p>
    </div>
    <?php endif; ?>

    <!-- Laboratory Notice -->
    <div class="mb-8 p-3 rounded-xl bg-slate-50 border border-slate-200 text-[11px] text-slate-600 space-y-1">
        <p><strong>Notice to Patient:</strong> Fasting or special prep instructions should be followed as indicated prior to specimen collection.</p>
        <p class="italic text-slate-500">Please present this requisition form at the laboratory reception desktop.</p>
    </div>

    <!-- Physician Signature Block -->
    <div class="pt-6 border-t border-slate-300 flex justify-between items-end text-xs">
        <div>
            <?php if (!empty($attendingDoc['digital_stamp'])): ?>
                <img src="<?= $attendingDoc['digital_stamp'] ?>" class="h-20 object-contain opacity-90 mb-1" alt="Official Stamp">
            <?php endif; ?>
            <p class="text-slate-500 text-[10px] uppercase font-semibold">Requesting Facility</p>
            <p class="font-bold text-slate-800"><?= htmlspecialchars($clinicName) ?></p>
        </div>
        <div class="text-right w-64 relative">
            <?php if (!empty($attendingDoc['esignature'])): ?>
                <img src="<?= $attendingDoc['esignature'] ?>" class="h-14 object-contain ml-auto -mb-3 relative z-10" alt="E-Signature">
            <?php endif; ?>
            <div class="border-b border-slate-400 mb-1 pb-1 font-serif text-lg font-bold text-blue-900 italic"><?= htmlspecialchars($attendingDoc['name']) ?></div>
            <p class="font-bold text-slate-800"><?= htmlspecialchars($attendingDoc['name']) ?></p>
            <p class="text-slate-500"><?= htmlspecialchars($attendingDoc['specialty']) ?></p>
            <p class="text-[10px] font-mono text-slate-500 mt-0.5">PRC No: <?= htmlspecialchars($attendingDoc['prc_number'] ?? $settings['doc_prc_no'] ?? 'N/A') ?></p>
            <p class="text-[10px] font-mono text-slate-500">PTR No: <?= htmlspecialchars($attendingDoc['ptr_number'] ?? $settings['doc_ptr_no'] ?? 'N/A') ?></p>
        </div>
    </div>
</div>

</body>
</html>
