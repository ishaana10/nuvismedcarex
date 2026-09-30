<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/autoloader.php';

use ClinicFlow\Services\PrescriptionVerificationService;

$token = trim($_GET['token'] ?? '');
$pdo = getDB();

$verificationService = new PrescriptionVerificationService();
$details = !empty($token) ? $verificationService->getVerificationDetails($pdo, $token) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription Verification | Nuvis Medcare X</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-100 min-h-screen text-slate-800 p-4 md:p-8 flex items-center justify-center">

<div class="max-w-2xl w-full bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden my-8">
    <!-- Header branding -->
    <div class="bg-slate-900 text-white p-6 border-b border-slate-800 flex flex-wrap justify-between items-center gap-4">
        <div class="flex items-center gap-3">
            <div class="bg-blue-600 p-2.5 rounded-xl flex items-center justify-center text-white">
                <span class="material-symbols-outlined text-2xl">verified</span>
            </div>
            <div>
                <h1 class="font-bold text-lg text-white tracking-tight">Nuvis Medcare X</h1>
                <p class="text-xs text-slate-400">Official Pharmacy Verification Portal</p>
            </div>
        </div>
        <div class="text-right text-xs text-slate-400 font-mono">
            <?= date('Y-m-d H:i:s') ?> UTC
        </div>
    </div>

    <?php if ($details && $details['valid']): ?>
        <!-- VERIFIED STATUS BANNER -->
        <div class="bg-emerald-50 border-b border-emerald-200 p-6 flex items-center gap-4">
            <div class="bg-emerald-600 text-white p-3 rounded-full flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-3xl">check_circle</span>
            </div>
            <div>
                <span class="inline-block px-2.5 py-0.5 rounded-full text-[11px] font-extrabold uppercase tracking-wider bg-emerald-100 text-emerald-800 mb-1">
                    Verified Authentic
                </span>
                <h2 class="text-lg font-bold text-emerald-950">Official Clinical Prescription</h2>
                <p class="text-xs text-emerald-700 mt-0.5">
                    Token: <span class="font-mono font-semibold"><?= htmlspecialchars($details['token']) ?></span>
                    • Issued: <?= htmlspecialchars(date('M d, Y h:i A', strtotime($details['issued_at']))) ?>
                </p>
            </div>
        </div>

        <div class="p-6 md:p-8 space-y-6">
            <!-- Clinic Info -->
            <div class="border-b border-slate-200 pb-4 flex justify-between items-start flex-wrap gap-4">
                <div>
                    <h3 class="text-base font-bold text-slate-900"><?= htmlspecialchars($details['clinic']['name']) ?></h3>
                    <p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($details['clinic']['address']) ?></p>
                    <p class="text-xs text-slate-500">Phone: <?= htmlspecialchars($details['clinic']['phone']) ?> • Email: <?= htmlspecialchars($details['clinic']['email']) ?></p>
                </div>
                <div class="text-right text-xs text-slate-500">
                    <p>DEA: <strong class="font-mono text-slate-700"><?= htmlspecialchars($details['clinic']['dea']) ?></strong></p>
                    <p>NPI: <strong class="font-mono text-slate-700"><?= htmlspecialchars($details['clinic']['npi']) ?></strong></p>
                </div>
            </div>

            <!-- Patient & Physician Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">person</span> Patient Information
                    </p>
                    <p class="text-sm font-bold text-slate-900"><?= htmlspecialchars($details['patient']['name']) ?></p>
                    <p class="text-slate-600 mt-1">MRN: <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars($details['patient']['mrn']) ?></span></p>
                    <p class="text-slate-600">DOB: <?= htmlspecialchars($details['patient']['dob']) ?></p>
                    <p class="text-slate-600 mt-1">Allergies: <strong class="text-red-600"><?= htmlspecialchars($details['patient']['allergies']) ?></strong></p>
                </div>

                <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">medical_services</span> Attending Practitioner
                    </p>
                    <p class="text-sm font-bold text-slate-900"><?= htmlspecialchars($details['doctor']['name']) ?></p>
                    <p class="text-slate-600 mt-1"><?= htmlspecialchars($details['doctor']['specialty']) ?></p>
                    <p class="text-slate-600 mt-1 font-mono">PRC: <?= htmlspecialchars($details['doctor']['prc_number']) ?></p>
                    <p class="text-slate-600 font-mono">PTR: <?= htmlspecialchars($details['doctor']['ptr_number']) ?></p>
                </div>
            </div>

            <?php if (!empty($details['assessment'])): ?>
                <div class="bg-blue-50 border border-blue-200 p-3 rounded-xl text-xs text-blue-900">
                    <p class="font-semibold text-[10px] uppercase tracking-wider text-blue-600 mb-0.5">ICD-10 Clinical Diagnosis</p>
                    <p class="font-medium"><?= htmlspecialchars($details['assessment']) ?></p>
                </div>
            <?php endif; ?>

            <!-- Medication List -->
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200 pb-2 mb-4 flex items-center gap-1">
                    <span class="material-symbols-outlined text-base">prescriptions</span> Verified Prescribed Medications
                </h3>

                <?php if (!empty($details['prescriptions'])): ?>
                    <div class="space-y-3">
                        <?php foreach ($details['prescriptions'] as $index => $rx): ?>
                            <div class="p-4 rounded-xl bg-slate-50 border border-slate-200">
                                <div class="flex justify-between items-baseline flex-wrap gap-2">
                                    <span class="font-bold text-sm md:text-base text-slate-900">
                                        <?= ($index + 1) ?>. <?= htmlspecialchars($rx['medication_name']) ?>
                                    </span>
                                    <span class="font-mono font-bold text-blue-800 text-xs md:text-sm bg-blue-100 px-2 py-0.5 rounded">
                                        <?= htmlspecialchars($rx['dosage']) ?>
                                    </span>
                                </div>
                                <div class="mt-2 text-xs text-slate-700 flex flex-wrap gap-4">
                                    <span><strong>Frequency:</strong> <?= htmlspecialchars($rx['frequency']) ?></span>
                                    <span><strong>Duration:</strong> <?= htmlspecialchars($rx['duration'] ?? 'N/A') ?></span>
                                </div>
                                <?php if (!empty($rx['instructions'])): ?>
                                    <p class="mt-2 text-xs text-slate-600 bg-white p-2 rounded border border-slate-200 italic">
                                        <strong>Sig / Instructions:</strong> <?= htmlspecialchars($rx['instructions']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-xs text-slate-500 italic p-4 bg-slate-50 rounded-xl border border-slate-200 text-center">
                        No active medication lines associated with this verification token.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Disclaimer -->
            <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-600 space-y-1">
                <p class="font-semibold text-slate-700">Pharmacy Dispensing Notice:</p>
                <p><?= htmlspecialchars($details['clinic']['disclaimer']) ?></p>
            </div>
        </div>

    <?php else: ?>
        <!-- INVALID STATUS BANNER -->
        <div class="bg-red-50 border-b border-red-200 p-8 text-center space-y-3">
            <div class="bg-red-600 text-white p-4 rounded-full inline-flex items-center justify-center">
                <span class="material-symbols-outlined text-4xl">gpp_bad</span>
            </div>
            <h2 class="text-xl font-bold text-red-950">Invalid or Unverified Prescription Token</h2>
            <p class="text-xs text-red-700 max-w-md mx-auto">
                The prescription verification code provided (<span class="font-mono font-bold"><?= htmlspecialchars($token ?: 'None') ?></span>) could not be verified in the Nuvis Medcare X database.
            </p>
            <div class="pt-4 text-xs text-slate-500">
                Please verify that the QR code or URL was scanned correctly, or contact the prescribing clinic directly for authentication.
            </div>
        </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="bg-slate-50 p-4 border-t border-slate-200 text-center text-[11px] text-slate-500">
        Nuvis Medcare X EHR Platform &bull; Secure Digital Prescription Authentication System
    </div>
</div>

</body>
</html>
