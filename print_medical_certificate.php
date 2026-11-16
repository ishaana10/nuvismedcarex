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
$certLayout = ($certCfg['layout'] ?? 'classic') === 'form' ? 'form' : 'classic';
$certCaduceus = !empty($certCfg['show_caduceus']) ? \ClinicFlow\Services\DocumentTemplateSettings::CADUCEUS : '';
$certDateFmt = $certCfg['date_format'] ?: 'd M Y';
$fmtDate = static fn($d) => ($d && strtotime((string) $d)) ? date($certDateFmt, strtotime((string) $d)) : '';

// Fetch Certificate details
$cert = null;
if ($certId) {
    $stmt = $pdo->prepare("SELECT * FROM medical_certificates WHERE (id = ? OR certificate_number = ?) AND tenant_id = ?");
    $stmt->execute([$certId, $certId, $currentTenantId]);
    $cert = $stmt->fetch();
}
// No "latest certificate" fallback: a mistyped or stale link must never print
// some other patient's certificate.

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
// Only clinicians of THIS clinic. (This used to search every clinic by name and
// then take "any Doctor", printing another clinic's signature on the certificate.)
$doctor = nxFindDoctorByName($pdo, (string) ($cert['doctor_name'] ?? ''));

// Never invent a registration number on a legal document.
$prcNo = $cert['prc_number'] ?: ($doctor['prc_number'] ?? $settings['doc_prc_no'] ?? '');
$ptrNo = $cert['ptr_number'] ?: ($doctor['ptr_number'] ?? $settings['doc_ptr_no'] ?? '');
$esignature = $doctor['esignature'] ?? '';
$digitalStamp = $doctor['digital_stamp'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Certificate - <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></title>
    <link rel="stylesheet" href="assets/css/fonts.css"/>
    <link rel="stylesheet" href="assets/css/tailwind-public.css"/>
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

<?php if ($certLayout === 'form'):
    $fAcc = htmlspecialchars($certAccent);
    $fTitle = ($certHeaderTitle === 'OFFICIAL MEDICAL CERTIFICATE') ? 'MEDICAL CERTIFICATE' : $certHeaderTitle;
    $fDocLine = $certCfg['doctor_line'] !== '' ? $certCfg['doctor_line'] : trim(($cert['doctor_name'] ?? '') . ($prcNo ? '   Reg. No. ' . $prcNo : ''));
    $fTicked = array_map('trim', explode(',', (string) ($cert['problem_category'] ?? '')));
    $fCats = \ClinicFlow\Services\DocumentTemplateSettings::categories($certCfg);
    $fComments = trim(implode(' ', array_filter([
        !empty($certCfg['show_fitness']) ? (string) ($cert['fitness_status'] ?? '') . '.' : '',
        !empty($certCfg['show_rest_period']) ? (string) ($cert['fit_status_details'] ?? '') : '',
        (string) ($cert['recommendations'] ?? ''),
    ])));
?>
    <style>
        .cf { background:#fff; padding:28px 34px 26px; border:1px solid #cbd5e1; border-radius:6px; color:#1e293b; font-family: <?= $certFontStack ?>; }
        .cf-head { display:flex; align-items:center; gap:18px; }
        .cf-head .cf-mid { flex:1; text-align:center; min-width:0; }
        .cf-name { font-size:30px; line-height:1.1; letter-spacing:.02em; font-variant: small-caps; color:<?= $fAcc ?>; font-weight:500; }
        .cf-docline { white-space:pre-wrap; font-size:14px; margin-top:6px; letter-spacing:.02em; }
        .cf-consult { font-weight:700; font-size:18px; text-decoration:underline; text-underline-offset:3px; margin-top:8px; }
        .cf-consult-in { font-size:11px; margin-top:2px; }
        .cf-contact { display:flex; justify-content:space-between; gap:20px; font-size:12px; margin-top:6px; line-height:1.35; text-align:left; }
        .cf-contact .r { text-align:right; }
        .cf-title { display:flex; align-items:baseline; gap:18px; margin-top:16px; }
        .cf-title h2 { font-size:20px; font-weight:700; text-decoration:underline; text-underline-offset:4px; letter-spacing:.02em; margin:0; }
        .cf-title .no { color:#d6322e; font-size:17px; letter-spacing:.04em; font-weight:600; }
        .cf-line { display:flex; align-items:flex-end; gap:8px; margin-top:12px; font-size:15px; }
        .cf-fill { flex:1; border-bottom:1px solid #1e293b; min-height:26px; padding:0 8px 1px; font-family: "Segoe Script","Bradley Hand","Brush Script MT",cursive; font-size:19px; color:#1d3a8a; line-height:1.2; }
        .cf-cats { display:grid; grid-template-columns:repeat(3, 1fr); gap:8px 18px; margin-top:14px; font-size:15px; }
        .cf-cat { display:flex; align-items:center; gap:8px; }
        .cf-box { width:17px; height:17px; border:1.5px solid #1e293b; border-radius:2px; display:inline-flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; line-height:1; color:#1d3a8a; flex:none; }
        .cf-two { display:grid; grid-template-columns: 1.3fr 1fr; gap:18px; align-items:end; }
        .cf-small { font-size:13px; }
        .cf-sign { display:flex; justify-content:space-between; align-items:flex-end; gap:20px; margin-top:22px; }
        .cf-foot { font-size:11px; color:#64748b; margin-top:16px; }
        .cf-wm { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:90px; font-weight:800; color:rgba(100,116,139,.08); transform:rotate(-24deg); pointer-events:none; }
        @media print { .cf { border:none; padding:0; } }
    </style>
    <div class="cf" style="position:relative">
        <?php if (!empty($certCfg['watermark'])): ?><div class="cf-wm"><?= htmlspecialchars($certCfg['watermark']) ?></div><?php endif; ?>
        <div class="cf-head">
            <?php if ($certCaduceus): ?><img src="<?= htmlspecialchars($certCaduceus) ?>" alt="" style="width:78px;height:auto;flex:none"><?php endif; ?>
            <div class="cf-mid">
                <div class="cf-name"><?= htmlspecialchars($clinicName) ?></div>
                <?php if ($fDocLine !== ''): ?><div class="cf-docline"><?= htmlspecialchars($fDocLine) ?></div><?php endif; ?>
                <?php if ($certCfg['consultation_in'] !== '' || $certSubtitle !== ''): ?>
                    <?php if ($certCfg['consultation_in'] !== ''): ?><div class="cf-consult">Consultation in :</div><?php endif; ?>
                    <div class="cf-consult-in"><?= htmlspecialchars($certCfg['consultation_in'] !== '' ? $certCfg['consultation_in'] : $certSubtitle) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($certLogo): ?><img src="<?= htmlspecialchars($certLogo) ?>" alt="Clinic logo" style="width:<?= $certLogoW ?>px;height:auto;flex:none;object-fit:contain"><?php endif; ?>
        </div>
        <?php
            $fLeft = []; $fRight = [];
            if (!empty($certCfg['show_address']) && $clinicAddress) foreach (preg_split('/\s*,\s*|\R/', (string) $clinicAddress) as $l) if (trim($l) !== '') $fLeft[] = trim($l);
            if (!empty($certCfg['show_phone']) && $clinicPhone) $fRight[] = 'Phone: ' . $clinicPhone;
            if (!empty($certCfg['show_email']) && $clinicEmail) $fRight[] = 'Email: ' . $clinicEmail;
            if (!empty($certCfg['show_website']) && $clinicWebsite) $fRight[] = $clinicWebsite;
        ?>
        <?php if ($fLeft || $fRight): ?>
        <div class="cf-contact">
            <div><?= implode('<br>', array_map('htmlspecialchars', $fLeft)) ?></div>
            <div class="r"><?= implode('<br>', array_map('htmlspecialchars', $fRight)) ?></div>
        </div>
        <?php endif; ?>

        <div class="cf-title"><h2><?= htmlspecialchars($fTitle) ?></h2><span class="no"><?= htmlspecialchars((string) $cert['certificate_number']) ?></span></div>

        <div class="cf-line"><span>This is to certify that</span><span class="cf-fill"><?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></span></div>
        <?php if (!empty($certCfg['show_diagnosis'])): ?>
        <div class="cf-line"><span>i) is suffering from:</span><span class="cf-fill"><?= htmlspecialchars((string) $cert['diagnosis']) ?></span></div>
        <?php endif; ?>

        <?php if (!empty($certCfg['show_categories'])): ?>
        <div class="cf-cats">
            <?php foreach ($fCats as $c): ?>
                <div class="cf-cat"><span class="cf-box"><?= in_array($c, $fTicked, true) ? '&#10003;' : '' ?></span><span><?= htmlspecialchars($c) ?></span></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($certCfg['show_dates'])): ?>
        <div class="cf-line cf-small"><span>ii) Gives history of illness from:</span><span class="cf-fill" style="max-width:230px"><?= htmlspecialchars($fmtDate($cert['illness_from'] ?? '')) ?></span></div>
        <?php endif; ?>
        <div class="cf-two">
            <?php if (!empty($certCfg['show_dates'])): ?>
            <div class="cf-line cf-small"><span>iii) Should resume duties on:</span><span class="cf-fill"><?= htmlspecialchars($fmtDate($cert['resume_on'] ?? '')) ?></span></div>
            <?php else: ?><div></div><?php endif; ?>
            <?php if (!empty($certCfg['show_review'])): ?>
            <div class="cf-line cf-small"><span>iv) Review</span><span class="cf-fill"><?= htmlspecialchars($fmtDate($cert['review_on'] ?? '')) ?></span></div>
            <?php endif; ?>
        </div>
        <div class="cf-line cf-small"><span>Comments:</span><span class="cf-fill" style="font-size:16px"><?= htmlspecialchars($fComments) ?></span></div>

        <div class="cf-sign">
            <div>
                <?php if (!empty($certCfg['show_stamp']) && !empty($digitalStamp)): ?>
                    <img src="<?= htmlspecialchars($digitalStamp) ?>" style="max-height:120px;max-width:200px;object-fit:contain" alt="Official Digital Stamp">
                <?php endif; ?>
            </div>
            <div style="width:300px;font-size:13px">
                <?php if (!empty($certCfg['show_signature']) && !empty($esignature)): ?>
                    <img src="<?= htmlspecialchars($esignature) ?>" style="max-height:70px;max-width:220px;object-fit:contain;display:block;margin-bottom:-14px;position:relative" alt="E-Signature">
                <?php endif; ?>
                <div style="border-bottom:1px solid #1e293b;padding-bottom:2px">Signature:</div>
                <div style="margin-top:3px;font-weight:600"><?= htmlspecialchars((string) $cert['doctor_name']) ?></div>
                <?php if (!empty($certCfg['show_license']) && $prcNo): ?><div style="font-size:11px;color:#64748b">Licence No: <?= htmlspecialchars($prcNo) ?></div><?php endif; ?>
                <?php if (!empty($certCfg['show_ptr']) && $ptrNo): ?><div style="font-size:11px;color:#64748b">PTR No: <?= htmlspecialchars($ptrNo) ?></div><?php endif; ?>
                <?php if (!empty($certCfg['show_consult_date'])): ?>
                <div style="border-bottom:1px solid #1e293b;padding-bottom:2px;margin-top:10px">Date of Consult: <span style="font-weight:600"><?= htmlspecialchars($fmtDate($cert['issue_date'] ?? '')) ?></span></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($certDisclaimer !== '' || $certFooterNote !== ''): ?>
        <div class="cf-foot"><?= htmlspecialchars($certDisclaimer) ?> <?= htmlspecialchars($certFooterNote) ?></div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <!-- Official Certificate Body -->
    <div class="cert-box bg-white p-10 rounded-2xl shadow-xl border-2 border-slate-900 relative">

        <!-- Clinic Branding Header -->
        <div class="pb-6 mb-8 flex items-center gap-4" style="border-bottom: 2px solid <?= htmlspecialchars($certAccent) ?>; font-family: <?= $certFontStack ?>;">
            <?php if ($certCaduceus): ?>
                <img src="<?= htmlspecialchars($certCaduceus) ?>" alt="" style="width: 64px; height: auto" class="object-contain shrink-0">
            <?php endif; ?>
            <div class="text-center flex-1 min-w-0">
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
            <?php if ($certCaduceus): ?><div style="width: 64px" class="shrink-0" aria-hidden="true"></div><?php endif; ?>
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
<?php endif; ?>
</div>

</body>
</html>
