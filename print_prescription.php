<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/autoloader.php';

use ClinicFlow\Services\PrescriptionVerificationService;

if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit;
}

$patientId = $_GET['patient_id'] ?? 'pat-1';
$csrfToken = generateCsrfToken();
$pdo = getDB();

// Fetch Clinic Settings
$settingsRows = $pdo->query("SELECT * FROM clinic_settings")->fetchAll();
$settings = [];
foreach ($settingsRows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$clinicName = $settings['clinic_name'] ?? 'Nuvis Medico Healthcare';
$clinicSubtitle = $settings['clinic_subtitle'] ?? 'EHR & Clinical Management System';
$clinicAddress = $settings['clinic_address'] ?? '100 Healthcare Way, Suite 400, Springfield, OR 97477';
$clinicPhone = $settings['clinic_phone'] ?? '(555) 019-2831';
$clinicEmail = $settings['clinic_email'] ?? 'medico@nuvistechnologies.com.fj';
$clinicDea = $settings['clinic_dea'] ?? 'FC9823019';
$clinicNpi = $settings['clinic_npi'] ?? '1092830192';

$rxHeaderTitle = $settings['rx_header_title'] ?? 'OFFICIAL MEDICAL PRESCRIPTION';
$rxDisclaimer = $settings['rx_disclaimer'] ?? 'Notice: This prescription is valid for 30 days from date of issue unless specified otherwise.';
$rxFooterNote = $settings['rx_footer_note'] ?? 'Substitution Permitted unless DAW (Dispense As Written) is indicated.';

// Fetch patient info
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? OR mrn = ?");
$stmt->execute([$patientId, $patientId]);
$patient = $stmt->fetch();

if (!$patient) {
    $stmt = $pdo->query("SELECT * FROM patients LIMIT 1");
    $patient = $stmt->fetch();
}

if (!$patient) {
    die("Patient file not found.");
}

$visitId = $_GET['visit_id'] ?? '';

if (!empty($visitId)) {
    $rxStmt = $pdo->prepare("SELECT * FROM prescriptions WHERE patient_id = ? AND visit_id = ? ORDER BY created_at ASC");
    $rxStmt->execute([$patient['id'], $visitId]);
} else {
    $rxStmt = $pdo->prepare("SELECT * FROM prescriptions WHERE patient_id = ? ORDER BY created_at ASC");
    $rxStmt->execute([$patient['id']]);
}
$prescriptions = $rxStmt->fetchAll();

$soapStmt = $pdo->prepare("SELECT * FROM soap_notes WHERE patient_id = ? ORDER BY updated_at DESC LIMIT 1");
$soapStmt->execute([$patient['id']]);
$soap = $soapStmt->fetch();
$assessmentCodes = json_decode($soap['assessment_codes'] ?? '[]', true) ?: [];

// Get attending doctor details
$docId = $_SESSION['current_doctor_id'] ?? 'doc-2';
$docStmt = $pdo->prepare("SELECT * FROM doctors WHERE id = ? OR role = 'Doctor'");
$docStmt->execute([$docId]);
$attendingDoc = $docStmt->fetch() ?: ['name' => 'Dr. Sarah Jenkins', 'specialty' => 'Internal Medicine', 'prc_number' => 'PRC-0098412', 'ptr_number' => 'PTR-8842109'];

// Generate Verification Token & Public Link
$tenantId = $_SESSION['tenant_id'] ?? 'tenant-default';
$rxVerificationService = new PrescriptionVerificationService();
$verificationToken = $rxVerificationService->getOrCreateToken($pdo, $tenantId, $patient['id'], $visitId);
$verificationUrl = $rxVerificationService->getVerificationUrl($verificationToken);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription - <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />
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
        <a href="clinical_visit.php?patient_id=<?= htmlspecialchars($patient['id']) ?>" class="text-xs font-semibold text-slate-600 hover:text-slate-900">&larr; Back to Encounter</a>
        <div class="flex gap-2 items-center">
            <form action="actions/send_email.php" method="POST" class="inline-flex items-center gap-1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="document_type" value="prescription">
                <input type="hidden" name="document_id" value="RX-<?= htmlspecialchars($patient['mrn']) ?>">
                <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patient['id']) ?>">
                <input type="hidden" name="visit_id" value="<?= htmlspecialchars($visitId) ?>">
                <input type="email" name="email" value="<?= htmlspecialchars($patient['email'] ?? '') ?>" placeholder="patient@example.com" required class="px-2.5 py-1.5 text-xs rounded-lg border border-slate-300 focus:outline-none">
                <button type="submit" class="px-3 py-2 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700 transition">Email Rx</button>
            </form>
            <button onclick="window.print()" class="px-4 py-2 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 transition">Print Rx</button>
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
            <span class="text-2xl font-serif font-bold text-blue-900">Rx</span>
            <p class="text-xs text-slate-500 font-mono mt-1">Date: <?= date('M d, Y') ?></p>
        </div>
    </div>

    <!-- Header Prescription Title -->
    <div class="text-center mb-6">
        <h2 class="text-xs font-bold uppercase tracking-widest text-slate-500 border-y border-slate-200 py-1.5"><?= htmlspecialchars($rxHeaderTitle) ?></h2>
    </div>

    <!-- Patient Header -->
    <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 mb-6 text-xs grid grid-cols-2 gap-4">
        <div>
            <p class="text-slate-500 font-semibold uppercase text-[10px]">Patient Name</p>
            <p class="font-bold text-sm text-slate-900"><?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></p>
            <p class="text-slate-500 mt-1">DOB: <?= htmlspecialchars($patient['dob']) ?> (<?= htmlspecialchars($patient['age']) ?> Yrs)</p>
        </div>
        <div>
            <p class="text-slate-500 font-semibold uppercase text-[10px]">Medical Record No.</p>
            <p class="font-bold text-sm font-mono text-slate-900"><?= htmlspecialchars($patient['mrn']) ?></p>
            <p class="text-slate-500 mt-1">Allergies: <strong class="text-red-600"><?= htmlspecialchars($patient['known_allergies'] ?: 'NKDA') ?></strong></p>
        </div>
    </div>

    <!-- Diagnosis / Assessment -->
    <?php if (!empty($assessmentCodes)): ?>
    <div class="mb-6 text-xs">
        <p class="text-slate-500 font-semibold uppercase text-[10px] mb-1">ICD-10 Diagnosis</p>
        <p class="font-medium bg-blue-50 text-blue-900 p-2 rounded-lg inline-block border border-blue-100">
            <?= htmlspecialchars($assessmentCodes[0]['code'] ?? '') ?> - <?= htmlspecialchars($assessmentCodes[0]['label'] ?? '') ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Prescription Lines -->
    <div class="mb-8">
        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200 pb-2 mb-4">Prescribed Medication Details</h2>
        <div class="space-y-4">
            <?php foreach ($prescriptions as $index => $rx): ?>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200">
                    <div class="flex justify-between items-baseline">
                        <span class="font-bold text-base text-slate-900"><?= ($index + 1) ?>. <?= htmlspecialchars($rx['medication_name']) ?></span>
                        <span class="font-mono font-bold text-blue-800 text-sm"><?= htmlspecialchars($rx['dosage']) ?></span>
                    </div>
                    <div class="mt-2 text-xs text-slate-700 flex gap-6">
                        <span><strong>Frequency:</strong> <?= htmlspecialchars($rx['frequency']) ?></span>
                        <span><strong>Duration:</strong> <?= htmlspecialchars($rx['duration']) ?></span>
                    </div>
                    <?php if (!empty($rx['instructions'])): ?>
                        <p class="mt-2 text-xs text-slate-600 bg-white p-2 rounded border border-slate-100">
                            <strong>Sig / Instructions:</strong> <?= htmlspecialchars($rx['instructions']) ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Pharmacy QR Code Verification Block -->
    <div class="mb-8 p-4 rounded-xl bg-slate-50 border border-slate-200 flex items-center gap-4">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode($verificationUrl) ?>" alt="Scan to Verify Prescription" class="w-20 h-20 rounded border border-slate-300 shrink-0">
        <div class="text-xs text-slate-700">
            <p class="font-bold text-slate-900 flex items-center gap-1">
                <span class="material-symbols-outlined text-sm text-emerald-600">verified</span> Pharmacy Verification QR Code
            </p>
            <p class="text-[11px] text-slate-500 mt-0.5">Scan with mobile camera or barcode reader to verify official authenticity with dispensing pharmacy.</p>
            <p class="font-mono text-[11px] text-slate-800 mt-1">Token: <strong class="text-blue-900 font-bold"><?= htmlspecialchars($verificationToken) ?></strong></p>
            <a href="<?= htmlspecialchars($verificationUrl) ?>" target="_blank" class="text-blue-600 hover:underline text-[10px] font-mono no-print inline-block mt-0.5">
                <?= htmlspecialchars($verificationUrl) ?>
            </a>
        </div>
    </div>

    <!-- Custom Disclaimer & Refill Notes -->
    <div class="mb-8 p-3 rounded-xl bg-slate-50 border border-slate-200 text-[11px] text-slate-600 space-y-1">
        <p><strong>Refill Policy:</strong> <?= htmlspecialchars($rxFooterNote) ?></p>
        <p class="italic text-slate-500"><?= htmlspecialchars($rxDisclaimer) ?></p>
    </div>

    <!-- Physician Signature Block -->
    <div class="pt-6 border-t border-slate-300 flex justify-between items-end text-xs">
        <div>
            <?php if (!empty($attendingDoc['digital_stamp'])): ?>
                <img src="<?= htmlspecialchars($attendingDoc['digital_stamp']) ?>" class="max-h-32 max-w-[180px] object-contain opacity-95 mb-1" alt="Official Stamp">
            <?php endif; ?>
            <p class="text-slate-500 text-[10px] uppercase font-semibold">Substitution</p>
            <p class="font-medium text-slate-700">Refill: [ ] 0  [ ] 1  [ ] 2  [ ] 3  [ ] PRN</p>
        </div>
        <div class="text-right w-64 relative">
            <?php if (!empty($attendingDoc['esignature'])): ?>
                <img src="<?= htmlspecialchars($attendingDoc['esignature']) ?>" class="max-h-20 max-w-[200px] object-contain ml-auto -mb-3 relative z-10" alt="E-Signature">
            <?php endif; ?>
            <div class="border-b border-slate-400 mb-1 pb-1 font-serif text-lg font-bold text-blue-900 italic"><?= htmlspecialchars($attendingDoc['name']) ?></div>
            <p class="font-bold text-slate-800"><?= htmlspecialchars($attendingDoc['name']) ?></p>
            <p class="text-slate-500"><?= htmlspecialchars($attendingDoc['specialty']) ?></p>
            <p class="text-[10px] font-mono text-slate-500 mt-0.5">Medical License No: <?= htmlspecialchars($attendingDoc['prc_number'] ?? $settings['doc_prc_no'] ?? 'N/A') ?></p>
        </div>
    </div>
</div>

</body>
</html>
