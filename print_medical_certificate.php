<?php
session_start();
if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit;
}

$certId = $_GET['id'] ?? '';

require_once __DIR__ . '/config/database.php';
$pdo = getDB();

// Fetch Clinic Settings
$settingsRows = $pdo->query("SELECT * FROM clinic_settings")->fetchAll();
$settings = [];
foreach ($settingsRows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$clinicName = $settings['clinic_name'] ?? 'ClinicFlow Medical Center';
$clinicName = $settings['clinic_name'] ?? 'Nuvis Medico Healthcare';
$clinicAddress = $settings['clinic_address'] ?? '100 Healthcare Way, Suite 400, Springfield, OR 97477';
$clinicPhone = $settings['clinic_phone'] ?? '(555) 019-2831';
$clinicEmail = $settings['clinic_email'] ?? 'medico@nuvistechnologies.com.fj';

$certHeaderTitle = $settings['cert_header_title'] ?? 'OFFICIAL MEDICAL CERTIFICATE';
$certDisclaimer = $settings['cert_disclaimer'] ?? 'This medical certificate is issued upon request of the patient for whatever legal or administrative purpose it may serve.';
$certFooterNote = $settings['cert_footer_note'] ?? 'Valid with official clinic digital stamp and physician e-signature.';

// Fetch Certificate details
$cert = null;
if ($certId) {
    $stmt = $pdo->prepare("SELECT * FROM medical_certificates WHERE id = ? OR certificate_number = ?");
    $stmt->execute([$certId, $certId]);
    $cert = $stmt->fetch();
}

if (!$cert) {
    // Fallback or latest
    $stmt = $pdo->query("SELECT * FROM medical_certificates ORDER BY created_at DESC LIMIT 1");
    $cert = $stmt->fetch();
}

if (!$cert) {
    die("Medical Certificate not found.");
}

// Fetch Patient details
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
$stmt->execute([$cert['patient_id']]);
$patient = $stmt->fetch();

if (!$patient) {
    die("Patient profile not found.");
}

// Fetch Attending Doctor details for e-signature, stamp, PRC, PTR
$doctor = null;
if (!empty($cert['doctor_name'])) {
    $stmt = $pdo->prepare("SELECT * FROM doctors WHERE name = ? OR name LIKE ?");
    $stmt->execute([$cert['doctor_name'], '%' . $cert['doctor_name'] . '%']);
    $doctor = $stmt->fetch();
}
if (!$doctor) {
    $doctor = $pdo->query("SELECT * FROM doctors WHERE role = 'Doctor' LIMIT 1")->fetch();
}

$prcNo = $cert['prc_number'] ?: ($doctor['prc_number'] ?? $settings['doc_prc_no'] ?? 'PRC-0098412');
$ptrNo = $cert['ptr_number'] ?: ($doctor['ptr_number'] ?? $settings['doc_ptr_no'] ?? 'PTR-8842109');
$esignature = $doctor['esignature'] ?? '';
$digitalStamp = $doctor['digital_stamp'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Certificate - <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; padding: 0; }
            .cert-box { border: 2px solid #1e293b !important; shadow: none !important; }
        }
    </style>
</head>
<body class="bg-slate-100 p-6 min-h-screen text-slate-800 font-sans">

<div class="max-w-3xl mx-auto">

    <!-- Action Bar -->
    <div class="no-print mb-6 bg-white p-4 rounded-xl border border-slate-200 shadow-sm space-y-3">
        <div class="flex justify-between items-center">
            <a href="patient_detail.php?id=<?= htmlspecialchars($patient['id']) ?>" class="text-xs font-semibold text-slate-600 hover:text-slate-900">&larr; Back to Patient Chart</a>
            <div class="flex gap-2">
                <a href="admin.php" class="px-3 py-2 bg-slate-100 text-slate-700 text-xs font-semibold rounded-lg hover:bg-slate-200 transition">Customize Settings</a>
                <button onclick="window.print()" class="px-4 py-2 bg-emerald-600 text-white text-xs font-bold rounded-lg hover:bg-emerald-700 transition flex items-center gap-1.5 shadow-sm">
                    <span class="material-symbols-outlined text-sm">print</span> Print Official Certificate
                </button>
            </div>
        </div>

        <!-- Direct Email Document Action -->
        <form action="actions/send_email.php" method="POST" class="flex gap-2 pt-2 border-t border-slate-100">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <input type="hidden" name="document_type" value="Medical Certificate">
            <input type="hidden" name="document_id" value="<?= htmlspecialchars($cert['certificate_number']) ?>">
            <input type="email" name="email" required value="<?= htmlspecialchars($patient['email'] ?? '') ?>" placeholder="patient@example.com" class="flex-1 px-3 py-1.5 border border-slate-300 rounded-lg text-xs">
            <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white font-semibold text-xs rounded-lg hover:bg-blue-700 transition flex items-center gap-1">
                <span class="material-symbols-outlined text-sm">mail</span> Email Certificate
            </button>
        </form>
    </div>

    <!-- Official Certificate Body -->
    <div class="cert-box bg-white p-10 rounded-2xl shadow-xl border-2 border-slate-900 relative">

        <!-- Clinic Branding Header -->
        <div class="text-center border-b-2 border-slate-900 pb-6 mb-8">
            <h1 class="text-3xl font-extrabold text-slate-900 uppercase tracking-tight"><?= htmlspecialchars($clinicName) ?></h1>
            <p class="text-sm font-semibold text-slate-700 mt-1"><?= htmlspecialchars($clinicSubtitle) ?></p>
            <p class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($clinicAddress) ?> • Tel: <?= htmlspecialchars($clinicPhone) ?> • <?= htmlspecialchars($clinicEmail) ?></p>
        </div>

        <!-- Document Title & Control No -->
        <div class="flex justify-between items-end mb-8">
            <div>
                <span class="text-xs text-slate-500 font-mono block">Certificate No: <strong class="text-slate-900"><?= htmlspecialchars($cert['certificate_number']) ?></strong></span>
            </div>
            <div class="text-center">
                <h2 class="text-xl font-bold uppercase tracking-widest text-slate-900 border-b-2 border-slate-900 pb-1 px-4"><?= htmlspecialchars($certHeaderTitle) ?></h2>
            </div>
            <div class="text-right">
                <span class="text-xs text-slate-500 font-mono block">Date: <strong class="text-slate-900"><?= htmlspecialchars(date('F d, Y', strtotime($cert['issue_date']))) ?></strong></span>
            </div>
        </div>

        <!-- Certification Body Statement -->
        <div class="text-sm leading-relaxed text-slate-800 space-y-6 mb-12">
            <p class="font-serif italic text-base text-slate-700">To Whom It May Concern:</p>

            <p class="text-justify leading-7">
                This is to certify that
                <strong class="font-bold text-slate-900 underline underline-offset-4 px-1"><?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></strong>,
                <strong class="font-semibold text-slate-900"><?= htmlspecialchars($patient['age']) ?></strong> years of age,
                <strong class="font-semibold text-slate-900"><?= htmlspecialchars($patient['gender']) ?></strong>,
                residing at <span class="font-medium text-slate-800"><?= htmlspecialchars($patient['address'] ?: 'N/A') ?></span>,
                was examined and evaluated at this clinic on
                <strong class="font-semibold text-slate-900"><?= htmlspecialchars(date('F d, Y', strtotime($cert['issue_date']))) ?></strong>.
            </p>

            <!-- Diagnosis Block -->
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-300">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Clinical Impression / Diagnosis:</p>
                <p class="text-base font-bold text-slate-900"><?= htmlspecialchars($cert['diagnosis']) ?></p>
            </div>

            <!-- Fitness Status & Assessment -->
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-300 space-y-2">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Fitness Assessment & Classification:</p>
                <div class="flex items-center gap-2">
                    <span class="inline-block px-3 py-1 rounded-lg bg-emerald-100 text-emerald-900 font-bold text-sm border border-emerald-300">
                        <?= htmlspecialchars($cert['fitness_status']) ?>
                    </span>
                </div>
                <?php if (!empty($cert['fit_status_details'])): ?>
                    <p class="text-xs font-medium text-slate-700 pt-1">
                        <strong>Details / Duration:</strong> <?= htmlspecialchars($cert['fit_status_details']) ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Recommendations / Remarks -->
            <?php if (!empty($cert['recommendations'])): ?>
                <div class="space-y-1">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Remarks & Medical Recommendations:</p>
                    <p class="text-xs font-medium text-slate-800 bg-slate-50 p-3 rounded-lg border border-slate-200"><?= htmlspecialchars($cert['recommendations']) ?></p>
                </div>
            <?php endif; ?>

            <p class="text-xs text-slate-500 italic pt-2">
                <?= htmlspecialchars($certDisclaimer) ?>
            </p>
            <?php if (!empty($certFooterNote)): ?>
                <p class="text-[11px] text-slate-400 font-mono italic">
                    Note: <?= htmlspecialchars($certFooterNote) ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Physician Signature Block & Digital Stamp -->
        <div class="pt-8 border-t border-slate-300 flex justify-between items-end">
            <div>
                <?php if (!empty($digitalStamp)): ?>
                    <img src="<?= $digitalStamp ?>" class="h-28 object-contain opacity-90" alt="Official Digital Stamp">
                <?php endif; ?>
            </div>

            <div class="text-center w-72 relative">
                <?php if (!empty($esignature)): ?>
                    <img src="<?= $esignature ?>" class="h-16 object-contain mx-auto -mb-4 relative z-10" alt="E-Signature">
                <?php endif; ?>

                <div class="border-b border-slate-900 pb-1 mb-2 font-serif text-lg font-bold text-slate-900 italic">
                    <?= htmlspecialchars($cert['doctor_name']) ?>
                </div>
                <p class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($cert['doctor_name']) ?>, M.D.</p>
                <p class="text-xs text-slate-600 font-medium">Attending Physician</p>
                <p class="text-xs text-slate-500 font-mono mt-1">PRC No: <?= htmlspecialchars($prcNo) ?></p>
                <p class="text-xs text-slate-500 font-mono">PTR No: <?= htmlspecialchars($ptrNo) ?></p>
            </div>
        </div>

    </div>
</div>

</body>
</html>
