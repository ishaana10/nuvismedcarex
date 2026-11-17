<?php
require_once __DIR__ . '/core/SessionBootstrap.php';
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
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// Settings for the active clinic (its own values over the shared defaults)
$settings = nxSettings();

$clinicProfile = nxClinic();
$clinicName = $clinicProfile['name'];
$clinicSubtitle = $clinicProfile['subtitle'];
$clinicAddress = $clinicProfile['address'];
$clinicPhone = $settings['clinic_phone'] ?? '+679 331 2233';
$clinicEmail = $settings['clinic_email'] ?? 'medico@nuvistechnologies.com.fj';
$clinicWebsite = $settings['clinic_website'] ?? '';

// Per-clinic prescription template customisation
$rxCfg = (new \ClinicFlow\Services\DocumentTemplateSettings($pdo, $currentTenantId))->load('prescription');
// Live preview (document_preview.php): the unsaved settings, and a sample patient
$nxPreview = defined('NX_DOC_PREVIEW');
if ($nxPreview) { $rxCfg = $GLOBALS['nxPreviewCfg']; }
$rxAccent = $rxCfg['accent'];
$rxFontStack = \ClinicFlow\Services\DocumentTemplateSettings::fontStack($rxCfg['font']);
$rxLogo = !empty($rxCfg['show_logo']) ? ($rxCfg['logo'] ?: ($clinicProfile['logo'] ?? '')) : '';
$rxLogoW = \ClinicFlow\Services\DocumentTemplateSettings::logoWidth($rxCfg['logo_size']);
$rxDateFmt = $rxCfg['date_format'] ?: 'M d, Y';
$rxHeaderTitle = $rxCfg['header_title'] ?: 'OFFICIAL MEDICAL PRESCRIPTION';
$rxSubtitle = $rxCfg['header_subtitle'] ?: $clinicSubtitle;
$rxDisclaimer = $rxCfg['disclaimer'];
$rxFooterNote = $rxCfg['footer_note'];

if ($nxPreview) {
    $child = !empty($_POST['sample_child']);
    $today = new DateTimeImmutable('today');
    $patient = [
        'id' => 'preview', 'mrn' => '#00000', 'first_name' => 'Sample', 'last_name' => $child ? 'Child' : 'Patient',
        'dob' => $today->modify($child ? '-5 years -6 months' : '-34 years')->format('Y-m-d'),
        'age' => $child ? 5 : 34, 'gender' => $child ? 'Male' : 'Female', 'email' => '',
        'known_allergies' => 'Penicillin',
    ];
    $prescriptions = [
        ['medication_name' => 'Amoxicillin 500 mg capsules', 'dosage' => '500 mg', 'frequency' => 'Three times daily', 'duration' => '7 days', 'instructions' => 'Take with food. Finish the whole course.'],
        ['medication_name' => 'Paracetamol 500 mg tablets', 'dosage' => '500 mg', 'frequency' => 'Every 6 hours if needed', 'duration' => '3 days', 'instructions' => ''],
    ];
    $assessmentCodes = [['code' => 'J06.9', 'label' => 'Acute upper respiratory infection, unspecified']];
    $visitId = '';
    $attendingDoc = nxAttendingDoctor($pdo) ?: ['name' => $_SESSION['user_name'] ?? 'Attending Physician', 'specialty' => '', 'prc_number' => ''];
    $verificationToken = 'SAMPLE-TOKEN';
    $verificationUrl = 'https://example.invalid/verify_prescription.php?token=SAMPLE-TOKEN';
} else {
// Fetch patient info
$stmt = $pdo->prepare("SELECT * FROM patients WHERE (id = ? OR mrn = ?) AND tenant_id = ?");
$stmt->execute([$patientId, $patientId, $currentTenantId]);
$patient = $stmt->fetch();

// No silent fallback: printing someone else's prescription would be unsafe
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
// Scoped to this clinic, and never a made-up fallback doctor.
$attendingDoc = nxAttendingDoctor($pdo) ?: ['name' => $_SESSION['user_name'] ?? '', 'specialty' => '', 'prc_number' => '', 'ptr_number' => ''];

// Generate Verification Token & Public Link
$tenantId = $currentTenantId;
$rxVerificationService = new PrescriptionVerificationService();
$verificationToken = $rxVerificationService->getOrCreateToken($pdo, $tenantId, $patient['id'], $visitId, $attendingDoc['id'] ?? null);
$verificationUrl = $rxVerificationService->getVerificationUrl($verificationToken);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription - <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></title>
    <link rel="stylesheet" href="assets/css/fonts.css"/>
    <link rel="stylesheet" href="assets/css/tailwind-public.css"/>
    <style>
        @page { size: A4; margin: 10mm; }
        @media print {
            .no-print { display: none !important; }
            body { background: white; padding: 0 !important; min-height: 0 !important; }
            /* Keep the whole prescription on one sheet */
            .rx-sheet { box-shadow: none !important; border: 0 !important; padding: 0 !important; max-width: none !important; border-radius: 0 !important; }
            .rx-sheet .mb-8 { margin-bottom: 12px !important; }
            .rx-sheet .mb-6 { margin-bottom: 10px !important; }
            .rx-sheet .mb-5 { margin-bottom: 8px !important; }
        }
    </style>
</head>
<body class="bg-slate-100 p-8 min-h-screen text-slate-800 font-sans">

<div class="rx-sheet max-w-2xl mx-auto bg-white p-8 rounded-2xl shadow-lg border border-slate-200">
<?php if (!$nxPreview): ?>
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

<?php endif; ?>

    <!-- Customized Clinic Header -->
    <div class="pb-4 mb-6 flex justify-between items-start" style="border-bottom: 2px solid <?= htmlspecialchars($rxAccent) ?>; font-family: <?= $rxFontStack ?>;">
        <div class="flex items-center gap-3 <?= $rxCfg['logo_align'] === 'center' ? 'mx-auto text-center flex-col' : '' ?>">
            <?php if ($rxLogo): ?>
                <img src="<?= htmlspecialchars($rxLogo) ?>" alt="Clinic logo" style="width: <?= $rxLogoW ?>px" class="object-contain shrink-0">
            <?php endif; ?>
            <div>
                <h1 class="text-2xl font-bold uppercase tracking-tight" style="color: <?= htmlspecialchars($rxAccent) ?>"><?= htmlspecialchars($clinicName) ?></h1>
                <?php if (!empty($rxSubtitle)): ?><p class="text-xs font-medium" style="color: <?= htmlspecialchars($rxAccent) ?>"><?= htmlspecialchars($rxSubtitle) ?></p><?php endif; ?>
                <?php
                $rxContact = [];
                if (!empty($rxCfg['show_address']) && $clinicAddress) $rxContact[] = htmlspecialchars($clinicAddress);
                if (!empty($rxCfg['show_phone']) && $clinicPhone) $rxContact[] = 'Phone: ' . htmlspecialchars($clinicPhone);
                if (!empty($rxCfg['show_email']) && $clinicEmail) $rxContact[] = htmlspecialchars($clinicEmail);
                if (!empty($rxCfg['show_website']) && $clinicWebsite) $rxContact[] = htmlspecialchars($clinicWebsite);
                if ($rxContact): ?><p class="text-xs text-slate-500 mt-1"><?= implode(' • ', $rxContact) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="text-right shrink-0">
            <?php if (!empty($rxCfg['show_rx_symbol'])): ?><span class="text-2xl font-serif font-bold" style="color: <?= htmlspecialchars($rxAccent) ?>">℞</span><?php endif; ?>
            <p class="text-xs text-slate-500 font-mono mt-1">Date: <?= date($rxDateFmt) ?></p>
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
            <p class="text-slate-500 mt-1">DOB: <?= htmlspecialchars($patient['dob']) ?></p>
            <?php if (\ClinicFlow\Services\PatientAge::isChild((string) $patient['dob'])): ?>
                <p class="text-slate-900 mt-0.5 text-sm font-bold">Age: <?= htmlspecialchars(\ClinicFlow\Services\PatientAge::exact((string) $patient['dob'])) ?></p>
            <?php endif; ?>
        </div>
        <div>
            <p class="text-slate-500 font-semibold uppercase text-[10px]">Medical Record No.</p>
            <p class="font-bold text-sm font-mono text-slate-900"><?= htmlspecialchars($patient['mrn']) ?></p>
            <?php if (!empty($rxCfg['show_allergies'])): ?><p class="text-slate-500 mt-1">Allergies: <strong class="text-red-600"><?= htmlspecialchars($patient['known_allergies'] ?: 'NKDA') ?></strong></p><?php endif; ?>
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
    <div class="mb-5">
        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200 pb-2 mb-3">Prescribed Medication Details</h2>
        <?php $rxDense = count($prescriptions) > 5; // more than 5 medicines: tighten further so it stays on one page ?>
        <div style="display:flex;flex-direction:column;gap:<?= $rxDense ? '4px' : '8px' ?>">
            <?php foreach ($prescriptions as $index => $rx): ?>
                <div class="rounded-lg bg-slate-50 border border-slate-200" style="break-inside:avoid;padding:<?= $rxDense ? '4px 10px' : '8px 12px' ?>">
                    <div class="flex justify-between items-baseline gap-3">
                        <span class="font-bold text-slate-900" style="font-size:<?= $rxDense ? '12px' : '14px' ?>"><?= ($index + 1) ?>. <?= htmlspecialchars($rx['medication_name']) ?></span>
                        <span class="font-mono font-bold text-blue-800 shrink-0" style="font-size:<?= $rxDense ? '11px' : '13px' ?>"><?= htmlspecialchars($rx['dosage']) ?></span>
                    </div>
                    <div class="text-slate-700" style="font-size:<?= $rxDense ? '10px' : '11px' ?>;margin-top:2px;display:flex;flex-wrap:wrap;column-gap:14px">
                        <span><strong>Frequency:</strong> <?= htmlspecialchars($rx['frequency']) ?></span>
                        <span><strong>Duration:</strong> <?= htmlspecialchars($rx['duration']) ?></span>
                        <?php if (!empty($rx['instructions'])): ?>
                            <span class="text-slate-600"><strong>Sig:</strong> <?= htmlspecialchars($rx['instructions']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Pharmacy QR Code Verification Block -->
    <?php if (!empty($rxCfg['show_verification_qr'])): ?>
    <div class="mb-8 p-4 rounded-xl bg-slate-50 border border-slate-200 flex items-center gap-4">
        <img src="<?= htmlspecialchars(\ClinicFlow\Utils\QrCode::svgDataUri($verificationUrl, 120), ENT_QUOTES) ?>" alt="Scan to Verify Prescription" class="w-20 h-20 rounded border border-slate-300 shrink-0">
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
    <?php endif; ?>

    <!-- Custom Disclaimer & Refill Notes -->
    <div class="mb-8 p-3 rounded-xl bg-slate-50 border border-slate-200 text-[11px] text-slate-600 space-y-1">
        <p><strong>Refill Policy:</strong> <?= htmlspecialchars($rxFooterNote) ?></p>
        <p class="italic text-slate-500"><?= htmlspecialchars($rxDisclaimer) ?></p>
    </div>

    <!-- Physician Signature Block -->
    <div class="pt-6 border-t border-slate-300 flex justify-between items-end text-xs">
        <div>
            <?php if (!empty($rxCfg['show_stamp']) && !empty($attendingDoc['digital_stamp'])): ?>
                <img src="<?= htmlspecialchars(nxMediaUrl($attendingDoc['digital_stamp'])) ?>" class="max-h-32 max-w-[180px] object-contain opacity-95 mb-1" alt="Official Stamp">
            <?php endif; ?>
            <p class="text-slate-500 text-[10px] uppercase font-semibold">Substitution</p>
            <p class="font-medium text-slate-700">Refill: [ ] 0  [ ] 1  [ ] 2  [ ] 3  [ ] PRN</p>
        </div>
        <div class="text-right w-64 relative">
            <?php if (!empty($rxCfg['show_signature']) && !empty($attendingDoc['esignature'])): ?>
                <img src="<?= htmlspecialchars(nxMediaUrl($attendingDoc['esignature'])) ?>" class="max-h-20 max-w-[200px] object-contain ml-auto -mb-3 relative z-10" alt="E-Signature">
            <?php endif; ?>
            <div class="border-b border-slate-400 mb-1 pb-1 font-serif text-lg font-bold italic" style="color: <?= htmlspecialchars($rxAccent) ?>"><?= htmlspecialchars($attendingDoc['name']) ?></div>
            <p class="font-bold text-slate-800"><?= htmlspecialchars($attendingDoc['name']) ?></p>
            <p class="text-slate-500"><?= htmlspecialchars($attendingDoc['specialty']) ?></p>
            <?php if (!empty($rxCfg['show_license'])): ?><p class="text-[10px] font-mono text-slate-500 mt-0.5">Medical Licence No: <?= htmlspecialchars($attendingDoc['prc_number'] ?? $settings['doc_prc_no'] ?? 'N/A') ?></p><?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
