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
$attendingDoc = $docStmt->fetch() ?: ['name' => $order['ordered_by'] ?? 'Dr. Sarah Jenkins', 'specialty' => 'Internal Medicine'];

// Map requested items for checklist matching
$items = !empty($order['items']) ? $order['items'] : [
    ['test_name' => $order['test_name'], 'category' => $order['category'], 'instructions' => '', 'status' => $order['status']]
];

$requestedTestNames = array_map(function($i) {
    return strtolower(trim($i['test_name']));
}, $items);

function isTestRequested(array $testKeywords, array $requestedTestNames): bool {
    foreach ($requestedTestNames as $req) {
        foreach ($testKeywords as $kw) {
            if (stripos($req, strtolower($kw)) !== false) {
                return true;
            }
        }
    }
    return false;
}

// Extract any custom or unmapped tests to display in "Additional Tests"
$knownTests = [
    'renal', 'liver', 'cardiac', 'lipid', 'pih', 'sbr', 'amylase', 'asot', 'rf', 'ua', 'minerals', 'fbs', 'rbs', 'hba1c', 'tft', 'b12', 'folate', 'cortisol', 'ferritin', 'urine acr', 'vit d', 'creatinine clearance',
    'psa', 'bhcg', 'prolactin', 'lh', 'fsh', 'progesterone', 'oestrogen', 'testosterone', '24 hr urine protein', 'rpr', 'tpha', 'hep b', 'hep c', 'hiv', 'dengue', 'leptospirosis', 'ana', 'ds dna',
    'fbc', 'cbc', 'coagulation', 'esr', 'urine', 'microscopy', 'culture', 'swabs', 'stool', 'semen', 'mba'
];

$additionalTests = [];
foreach ($items as $item) {
    $tName = trim($item['test_name']);
    $matched = false;
    foreach ($knownTests as $kt) {
        if (stripos($tName, $kt) !== false) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        $additionalTests[] = $tName;
    }
}
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
            body { background: white; padding: 0; margin: 0; }
            .form-container { border: 2px solid #000 !important; box-shadow: none !important; }
        }
        td, th { border: 1px solid #000; }
        .box-check { width: 14px; height: 14px; border: 1px solid #000; display: inline-flex; align-items: center; justify-content: center; font-weight: bold; font-size: 11px; margin-right: 4px; }
    </style>
</head>
<body class="bg-slate-100 p-4 md:p-8 min-h-screen text-slate-900 font-sans text-xs">

<div class="max-w-4xl mx-auto">
    <!-- Action buttons -->
    <div class="no-print mb-4 flex flex-wrap justify-between items-center gap-2 bg-slate-50 p-4 rounded-xl border border-slate-200">
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

    <!-- Official Lab Request Form -->
    <div class="form-container bg-white border-2 border-black p-3 rounded-none shadow-md">

        <!-- Top Section: Patient Details & Requester Details -->
        <table class="w-full border-collapse mb-1">
            <tbody>
                <tr>
                    <td class="font-bold bg-slate-100 px-2 py-1 w-1/2">Patient Details:</td>
                    <td class="font-bold bg-slate-100 px-2 py-1 w-1/2">Requester Details:</td>
                </tr>
                <tr>
                    <td class="p-1">
                        <span class="font-semibold">Name:</span> <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?>
                    </td>
                    <td class="p-1">
                        <span class="font-semibold">Name:</span> Dr. <?= htmlspecialchars($attendingDoc['name']) ?>
                    </td>
                </tr>
                <tr>
                    <td class="p-1">
                        <span class="font-semibold">Address:</span> <?= htmlspecialchars($patient['address'] ?? 'N/A') ?>
                    </td>
                    <td class="p-1" rowspan="2">
                        <span class="font-semibold">Health Facility:</span> <?= htmlspecialchars($clinicName) ?><br>
                        <span class="text-[11px] text-slate-600"><?= htmlspecialchars($clinicAddress) ?></span>
                    </td>
                </tr>
                <tr>
                    <td class="p-1">
                        <span class="font-semibold">Tel Number:</span> <?= htmlspecialchars($patient['phone'] ?? 'N/A') ?>
                    </td>
                </tr>
                <tr>
                    <td class="p-1">
                        <span class="font-semibold">Date of Birth:</span> <?= htmlspecialchars($patient['dob'] ?? '--/--/----') ?>
                    </td>
                    <td class="p-1">
                        <span class="font-semibold">Tel Number:</span> <?= htmlspecialchars($clinicPhone) ?>
                    </td>
                </tr>
                <tr>
                    <td class="p-1" colspan="2">
                        <span class="font-semibold mr-4">Gender:</span>
                        <span class="box-check"><?= strtolower($patient['gender'] ?? '') === 'male' ? 'X' : '' ?></span> Male
                        <span class="box-check ml-6"><?= strtolower($patient['gender'] ?? '') === 'female' ? 'X' : '' ?></span> Female
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Sample Details & Specimen Info -->
        <table class="w-full border-collapse mb-1">
            <tbody>
                <tr>
                    <td class="p-1 w-1/3">
                        <span class="font-semibold">Sample Details: Urgency:</span><br>
                        <span class="inline-flex items-center mt-1">
                            <span class="box-check"><?= (isset($order['urgency']) && strtolower($order['urgency']) === 'urgent') ? '' : 'X' ?></span> Normal
                        </span>
                        <span class="inline-flex items-center mt-1 ml-4">
                            <span class="box-check"><?= (isset($order['urgency']) && strtolower($order['urgency']) === 'urgent') ? 'X' : '' ?></span> URGENT
                        </span>
                    </td>
                    <td class="p-1 w-2/3" colspan="2">
                        <span class="font-semibold">Sample taken from patient:</span><br>
                        <span class="mr-6">Date: <?= date('Y-m-d', strtotime($order['created_at'])) ?></span>
                        <span>Time: <?= date('H:i', strtotime($order['created_at'])) ?></span>
                    </td>
                </tr>
                <tr>
                    <td class="p-1">
                        <span class="font-semibold">Sample Type:</span> <?= htmlspecialchars($order['sample_type'] ?? 'Blood / Urine / Swab') ?>
                    </td>
                    <td class="p-1" colspan="2">
                        <span class="box-check"></span> Fasting
                        <span class="box-check ml-6"></span> Non - Fasting
                    </td>
                </tr>
                <tr>
                    <td class="p-1" colspan="3">
                        <span class="inline-flex items-center mr-6"><span class="box-check">X</span> Blood</span>
                        <span class="inline-flex items-center mr-6"><span class="box-check"></span> Urine</span>
                        <span class="inline-flex items-center mr-6"><span class="box-check"></span> Swab</span>
                        <span class="inline-flex items-center mr-6"><span class="box-check"></span> Faeces</span>
                        <span class="inline-flex items-center mr-6"><span class="box-check"></span> Sputum</span>
                        <span class="inline-flex items-center mr-6"><span class="box-check"></span> Fluids</span>
                        <span class="inline-flex items-center"><span class="box-check"></span> Other, namely: ________</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Clinical Information -->
        <table class="w-full border-collapse mb-1">
            <tbody>
                <tr>
                    <td class="p-1 bg-slate-50 font-semibold w-36">Relevant clinical information:</td>
                    <td class="p-1 italic text-slate-800"><?= htmlspecialchars($order['clinical_notes'] ?: 'None specified') ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Examination Requested Grid -->
        <div class="font-bold border border-b-0 border-black p-1 bg-slate-100">Examination Requested:</div>

        <table class="w-full border-collapse text-[11px] mb-2">
            <thead>
                <tr class="bg-slate-50">
                    <th class="p-1 text-left w-1/3">Biochemistry</th>
                    <th class="p-1 text-left w-1/3">Serology</th>
                    <th class="p-1 text-left w-1/6">Hematology</th>
                    <th class="p-1 text-left w-1/6">Additional Tests:</th>
                </tr>
            </thead>
            <tbody class="align-top">
                <tr>
                    <!-- Biochemistry Column -->
                    <td class="p-1 leading-normal">
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['renal'], $requestedTestNames) ? 'X' : '' ?></span> Renal Function Test</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['liver'], $requestedTestNames) ? 'X' : '' ?></span> Liver Function Tests</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['cardiac'], $requestedTestNames) ? 'X' : '' ?></span> Cardiac Enzymes</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['lipid'], $requestedTestNames) ? 'X' : '' ?></span> Lipid Profile</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['pih'], $requestedTestNames) ? 'X' : '' ?></span> PIH Profile</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['sbr'], $requestedTestNames) ? 'X' : '' ?></span> SBR</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['amylase'], $requestedTestNames) ? 'X' : '' ?></span> Amylase</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['asot'], $requestedTestNames) ? 'X' : '' ?></span> ASOT</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['rf'], $requestedTestNames) ? 'X' : '' ?></span> RF</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['ua'], $requestedTestNames) ? 'X' : '' ?></span> UA</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['minerals'], $requestedTestNames) ? 'X' : '' ?></span> Minerals</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['fbs', 'rbs', 'blood sugar', 'glucose'], $requestedTestNames) ? 'X' : '' ?></span> FBS , RBS</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['hba1c'], $requestedTestNames) ? 'X' : '' ?></span> hba1c</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['tft', 'thyroid'], $requestedTestNames) ? 'X' : '' ?></span> TFT</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['b12', 'folate'], $requestedTestNames) ? 'X' : '' ?></span> B12 / Folate</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['cortisol'], $requestedTestNames) ? 'X' : '' ?></span> Cortisol</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['ferritin'], $requestedTestNames) ? 'X' : '' ?></span> Ferritin</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['urine acr'], $requestedTestNames) ? 'X' : '' ?></span> Urine ACR</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['vit d'], $requestedTestNames) ? 'X' : '' ?></span> Vit D</div>
                        <div class="flex items-center"><span class="box-check"><?= isTestRequested(['creatinine clearance'], $requestedTestNames) ? 'X' : '' ?></span> 24HR Urine Creatinine Clearance</div>
                    </td>

                    <!-- Serology & Endocrine Column -->
                    <td class="p-1 leading-normal">
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['psa'], $requestedTestNames) ? 'X' : '' ?></span> PSA</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['bhcg'], $requestedTestNames) ? 'X' : '' ?></span> BHCG</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['prolactin'], $requestedTestNames) ? 'X' : '' ?></span> Prolactin</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['lh'], $requestedTestNames) ? 'X' : '' ?></span> LH</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['fsh'], $requestedTestNames) ? 'X' : '' ?></span> FSH</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['progesterone'], $requestedTestNames) ? 'X' : '' ?></span> Progesterone</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['oestrogen', 'estrogen'], $requestedTestNames) ? 'X' : '' ?></span> Oestrogen</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['testosterone'], $requestedTestNames) ? 'X' : '' ?></span> Testosterone</div>
                        <div class="flex items-center mb-2"><span class="box-check"><?= isTestRequested(['24 hr urine protein'], $requestedTestNames) ? 'X' : '' ?></span> 24 hr Urine Protein</div>

                        <div class="font-bold border-t border-black pt-1 mb-1">Serology</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['rpr'], $requestedTestNames) ? 'X' : '' ?></span> RPR</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['tpha'], $requestedTestNames) ? 'X' : '' ?></span> TPHA</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['hep b'], $requestedTestNames) ? 'X' : '' ?></span> Hep B</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['hep c'], $requestedTestNames) ? 'X' : '' ?></span> Hep C</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['hiv'], $requestedTestNames) ? 'X' : '' ?></span> HIV</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['dengue'], $requestedTestNames) ? 'X' : '' ?></span> Dengue</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['leptospirosis'], $requestedTestNames) ? 'X' : '' ?></span> Leptospirosis</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['ana'], $requestedTestNames) ? 'X' : '' ?></span> ANA</div>
                        <div class="flex items-center"><span class="box-check"><?= isTestRequested(['ds dna'], $requestedTestNames) ? 'X' : '' ?></span> Ds DNA</div>
                    </td>

                    <!-- Hematology & Microbiology Column -->
                    <td class="p-1 leading-normal">
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['fbc', 'cbc', 'blood count'], $requestedTestNames) ? 'X' : '' ?></span> FBC</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['coagulation'], $requestedTestNames) ? 'X' : '' ?></span> Coagulation Profile</div>
                        <div class="flex items-center mb-2"><span class="box-check"><?= isTestRequested(['esr'], $requestedTestNames) ? 'X' : '' ?></span> ESR</div>

                        <div class="font-bold border-t border-black pt-1 mb-1">Microbiology</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['urine'], $requestedTestNames) ? 'X' : '' ?></span> Urine</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['microscopy'], $requestedTestNames) ? 'X' : '' ?></span> Microscopy</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['urine analysis', 'urinalysis'], $requestedTestNames) ? 'X' : '' ?></span> Urine Analysis</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['culture', 'sensitivity'], $requestedTestNames) ? 'X' : '' ?></span> Culture / Sensitivity</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['swabs'], $requestedTestNames) ? 'X' : '' ?></span> Swabs</div>
                        <div class="text-[10px] italic mb-1 text-slate-600">Throat / Ear / Eye / Wound / HVS</div>
                        <div class="flex items-center mb-0.5"><span class="box-check"><?= isTestRequested(['stool'], $requestedTestNames) ? 'X' : '' ?></span> Stool Test</div>
                        <div class="flex items-center mb-2"><span class="box-check"><?= isTestRequested(['semen'], $requestedTestNames) ? 'X' : '' ?></span> Semen Analysis</div>
                        <div class="flex items-center"><span class="box-check"><?= isTestRequested(['mba'], $requestedTestNames) ? 'X' : '' ?></span> MBA</div>
                    </td>

                    <!-- Additional Tests Column -->
                    <td class="p-1 align-top leading-relaxed">
                        <?php if (!empty($additionalTests)): ?>
                            <ul class="list-disc list-inside font-semibold text-slate-800">
                                <?php foreach ($additionalTests as $addTest): ?>
                                    <li><?= htmlspecialchars($addTest) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="text-slate-400 italic">None specified</div>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Results section if available -->
        <?php if (!empty($order['results'])): ?>
        <div class="mt-3 p-2 bg-slate-50 border border-black text-xs">
            <h3 class="font-bold text-slate-900 mb-1 flex items-center justify-between">
                <span>Laboratory Results & Findings:</span>
                <span class="px-2 py-0.5 text-[10px] font-bold border <?= !empty($order['is_abnormal']) ? 'bg-red-100 text-red-800 border-red-500' : 'bg-emerald-100 text-emerald-800 border-emerald-500' ?>">
                    <?= !empty($order['is_abnormal']) ? 'ABNORMAL FINDINGS' : 'NORMAL' ?>
                </span>
            </h3>
            <p class="text-slate-800 whitespace-pre-wrap font-mono bg-white p-2 border border-slate-300"><?= htmlspecialchars($order['results']) ?></p>
        </div>
        <?php endif; ?>

        <!-- Signature / Authorization Footer -->
        <div class="mt-4 pt-2 border-t border-black flex justify-between items-end">
            <div>
                <p class="text-[10px] uppercase font-bold text-slate-600">Requesting Facility Stamp:</p>
                <div class="h-10 w-32 border border-dashed border-slate-400 rounded flex items-center justify-center text-[10px] text-slate-400 mt-1">
                    <?= htmlspecialchars($clinicName) ?>
                </div>
            </div>
            <div class="text-right w-64">
                <div class="border-b border-black mb-1 pb-1 font-serif text-sm font-bold italic">Dr. <?= htmlspecialchars($attendingDoc['name']) ?></div>
                <p class="font-bold text-slate-900">Authorized Physician Signature</p>
            </div>
        </div>

    </div>
</div>

</body>
</html>
