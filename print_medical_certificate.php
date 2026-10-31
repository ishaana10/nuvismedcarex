<?php
require_once __DIR__ . '/core/SessionBootstrap.php';
if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit;
}

$certId = $_GET['id'] ?? '';

require_once __DIR__ . '/config/database.php';
$pdo = getDB();
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// Settings for the active clinic (its own values over the shared defaults)
$settings = nxSettings();

$clinicProfile = nxClinic();
$clinicName = $clinicProfile['name'];
$clinicSubtitle = $clinicProfile['subtitle'] ?? '';
$clinicAddress = $clinicProfile['address'];
$clinicPhone = $settings['clinic_phone'] ?? '+679 331 2233';
$clinicEmail = $settings['clinic_email'] ?? 'medico@nuvistechnologies.com.fj';
$clinicWebsite = $settings['clinic_website'] ?? '';

// Per-clinic certificate template customisation
$tenantIdForCert = \ClinicFlow\Shared\TenantContext::getTenantId();
$certCfg = (new \ClinicFlow\Services\DocumentTemplateSettings($pdo, $tenantIdForCert))->load('certificate');
$certAccent = $certCfg['accent'];
$certFontStack = \ClinicFlow\Services\DocumentTemplateSettings::fontStack($certCfg['font']);
$certLogo = !empty($certCfg['show_logo']) ? ($certCfg['logo'] ?: ($clinicProfile['logo'] ?? '')) : '';
$certLogoW = \ClinicFlow\Services\DocumentTemplateSettings::logoWidth($certCfg['logo_size']);
$certHeaderTitle = $certCfg['header_title'] ?: 'OFFICIAL MEDICAL CERTIFICATE';
$certSubtitle = $certCfg['header_subtitle'] ?: $clinicSubtitle;
$certDisclaimer = $certCfg['disclaimer'];
$certFooterNote = $certCfg['footer_note'];

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
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND tenant_id = ?");
$stmt->execute([$cert['patient_id'], $currentTenantId]);
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
        <div class="text-center pb-6 mb-8" style="border-bottom: 2px solid <?= htmlspecialchars($certAccent) ?>; font-family: <?= $certFontStack ?>;">
            <?php if ($certLogo): ?>
                <img src="<?= htmlspecialchars($certLogo) ?>" alt="Clinic logo" style="width: <?= $certLogoW + 20 ?>px" class="object-contain mx-auto mb-2">
            <?php endif; ?>
            <h1 class="text-3xl font-extrabold uppercase tracking-tight" style="color: <?= htmlspecialchars($certAccent) ?>"><?= htmlspecialchars($clinicName) ?></h1>
            <?php if (!empty($certSubtitle)): ?><p class="text-sm font-semibold text-slate-700 mt-1"><?= htmlspecialchars($certSubtitle) ?></p><?php endif; ?>
            <?php
            $certContact = [];
            if (!empty($certCfg['show_address']) && $clinicAddress) $certContact[] = htmlspecialchars($clinicAddress);
            if (!empty($certCfg['show_phone']) && $clinicPhone) $certContact[] = 'Tel: ' . htmlspecialchars($clinicPhone);
            if (!empty($certCfg['show_email']) && $clinicEmail) $certContact[] = htmlspecialchars($clinicEmail);
            if (!empty($certCfg['show_website']) && $clinicWebsite) $certContact[] = htmlspecialchars($clinicWebsite);
            if ($certContact): ?><p class="text-xs text-slate-500 mt-1"><?= implode(' • ', $certContact) ?></p><?php endif; ?>
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
            <?php if (!empty($certCfg['show_diagnosis'])): ?>
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-300">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Clinical Impression / Diagnosis:</p>
                <p class="text-base font-bold text-slate-900"><?= htmlspecialchars($cert['diagnosis']) ?></p>
            </div>
            <?php endif; ?>

            <!-- Fitness Status & Assessment -->
            <?php if (!empty($certCfg['show_fitness'])): ?>
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-300 space-y-2">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Fitness Assessment & Classification:</p>
                <div class="flex items-center gap-2">
                    <span class="inline-block px-3 py-1 rounded-lg bg-emerald-100 text-emerald-900 font-bold text-sm border border-emerald-300">
                        <?= htmlspecialchars($cert['fitness_status']) ?>
                    </span>
                </div>
                <?php if (!empty($certCfg['show_rest_period']) && !empty($cert['fit_status_details'])): ?>
                    <p class="text-xs font-medium text-slate-700 pt-1">
                        <strong>Details / Duration:</strong> <?= htmlspecialchars($cert['fit_status_details']) ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

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
                <?php if (!empty($certCfg['show_stamp']) && !empty($digitalStamp)): ?>
                    <img src="<?= htmlspecialchars($digitalStamp) ?>" class="max-h-36 max-w-[200px] object-contain opacity-95" alt="Official Digital Stamp">
                <?php endif; ?>
            </div>

            <div class="text-center w-72 relative">
                <?php if (!empty($certCfg['show_signature']) && !empty($esignature)): ?>
                    <img src="<?= htmlspecialchars($esignature) ?>" class="max-h-24 max-w-[220px] object-contain mx-auto -mb-4 relative z-10" alt="E-Signature">
                <?php endif; ?>

                <div class="border-b border-slate-900 pb-1 mb-2 font-serif text-lg font-bold italic" style="color: <?= htmlspecialchars($certAccent) ?>">
                    <?= htmlspecialchars($cert['doctor_name']) ?>
                </div>
                <p class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($cert['doctor_name']) ?>, M.D.</p>
                <p class="text-xs text-slate-600 font-medium">Attending Physician</p>
                <?php if (!empty($certCfg['show_license'])): ?><p class="text-xs text-slate-500 font-mono mt-1">Medical License No: <?= htmlspecialchars($prcNo) ?></p><?php endif; ?>
                <?php if (!empty($certCfg['show_ptr'])): ?><p class="text-xs text-slate-500 font-mono">PTR No: <?= htmlspecialchars($ptrNo) ?></p><?php endif; ?>
            </div>
        </div>

    </div>
</div>

</body>
</html>
