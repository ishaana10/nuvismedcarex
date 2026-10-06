<?php
session_start();
if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/autoloader.php';
require_once __DIR__ . '/config/database.php';

use ClinicFlow\Services\VMSService;
use ClinicFlow\Shared\TenantContext;

$pdo = getDB();
$vmsService = new VMSService($pdo);
$currentTenantId = TenantContext::getTenantId();

// Fetch Clinic / Tenant Settings
$stmtSet = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
$stmtSet->execute([$currentTenantId]);
$tenantSettings = $stmtSet->fetchAll(PDO::FETCH_KEY_PAIR);

$stmtGlobal = $pdo->query("SELECT setting_key, setting_value FROM clinic_settings");
$globalSettings = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR);

$settings = array_merge($globalSettings, $tenantSettings);

$sellerTin = $settings['vms_seller_tin'] ?? '502579006';
$clinicName = $settings['clinic_name'] ?? 'Nuvis Medico Healthcare';
$businessLocation = $settings['vms_business_location'] ?? ($settings['clinic_address'] ?? '2 Woodstand Road, Suva');

// Report date parameters
$selectedDate = $_GET['report_date'] ?? date('Y-m-d');
$fromDate = $_GET['from_date'] ?? null;
$toDate = $_GET['to_date'] ?? null;
$preset = $_GET['preset'] ?? null;

if ($preset === 'quarterly') {
    $month = (int)date('n');
    $quarter = ceil($month / 3);
    $startMonth = ($quarter - 1) * 3 + 1;
    $endMonth = $quarter * 3;
    $fromDate = date("Y-") . sprintf("%02d", $startMonth) . "-01";
    $toDate = date("Y-m-t", strtotime(date("Y-") . sprintf("%02d", $endMonth) . "-01"));
} elseif ($preset === 'half_yearly') {
    $month = (int)date('n');
    if ($month <= 6) {
        $fromDate = date("Y-01-01");
        $toDate = date("Y-06-30");
    } else {
        $fromDate = date("Y-07-01");
        $toDate = date("Y-12-31");
    }
} elseif ($preset === 'yearly') {
    $fromDate = date("Y-01-01");
    $toDate = date("Y-12-31");
}

if (!empty($_GET['custom_range']) && !empty($_GET['from_date']) && !empty($_GET['to_date'])) {
    $fromDate = $_GET['from_date'];
    $toDate = $_GET['to_date'];
}

if ($fromDate && $toDate) {
    $zReportData = $vmsService->getDailyFiscalReport($selectedDate, $fromDate, $toDate, $currentTenantId);
    $reportPeriodTitle = "FISCAL REPORT SUMMARY (" . date('M d, Y', strtotime($fromDate)) . " - " . date('M d, Y', strtotime($toDate)) . ")";
} else {
    $zReportData = $vmsService->getDailyFiscalReport($selectedDate, null, null, $currentTenantId);
    $reportPeriodTitle = "DAILY FISCAL REPORT (Z-REPORT) - " . date('M d, Y', strtotime($selectedDate));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Z-Report PDF - <?= htmlspecialchars($clinicName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; margin: 0; padding: 0; }
        }
    </style>
</head>
<body class="bg-slate-100 p-6 min-h-screen text-slate-900 font-mono text-xs">

<div class="max-w-2xl mx-auto bg-white p-8 rounded-2xl shadow-lg border border-slate-200">
    <div class="no-print mb-6 flex justify-between items-center bg-slate-50 p-3 rounded-xl border border-slate-200 font-sans">
        <a href="billing.php?tab=zreport" class="text-xs font-semibold text-slate-600 hover:text-slate-900">&larr; Back to Billing</a>
        <button onclick="window.print()" class="px-4 py-2 bg-blue-600 text-white text-xs font-bold rounded-lg hover:bg-blue-700 transition">Print PDF Report</button>
    </div>

    <div class="text-center pb-4 border-b border-dashed border-slate-300 space-y-1">
        <h1 class="text-lg font-bold text-slate-900 uppercase"><?= htmlspecialchars($clinicName) ?></h1>
        <h2 class="text-xs font-bold text-slate-700 uppercase tracking-wide"><?= htmlspecialchars($reportPeriodTitle) ?></h2>
        <p class="text-[11px] text-slate-500">TIN: <?= htmlspecialchars($sellerTin) ?> | Clinic Location: <?= htmlspecialchars($businessLocation) ?></p>
        <p class="text-[11px] text-slate-500">Generated on: <?= date('Y-m-d H:i:s') ?></p>
    </div>

    <div class="py-4 border-b border-dashed border-slate-300 space-y-2">
        <h3 class="font-bold text-slate-800 uppercase">SUMMARY BY INVOICE / TRANSACTION TYPE</h3>
        <table class="w-full text-left">
            <thead>
                <tr class="text-[10px] text-slate-500 uppercase border-b border-slate-200">
                    <th class="py-1.5">Transaction Type</th>
                    <th class="py-1.5 text-center">Count</th>
                    <th class="py-1.5 text-right">Amount ($)</th>
                    <th class="py-1.5 text-right">VAT Tax ($)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($zReportData['by_type'] as $type => $data): ?>
                    <tr>
                        <td class="py-1.5 font-bold"><?= htmlspecialchars($type) ?></td>
                        <td class="py-1.5 text-center"><?= $data['count'] ?></td>
                        <td class="py-1.5 text-right">$<?= number_format($data['amount'], 2) ?></td>
                        <td class="py-1.5 text-right">$<?= number_format($data['tax'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="py-4 border-b border-dashed border-slate-300 space-y-2">
        <h3 class="font-bold text-slate-800 uppercase">SUMMARY BY PAYMENT TYPE</h3>
        <div class="grid grid-cols-2 gap-2 text-[11px]">
            <?php foreach ($zReportData['by_payment'] as $pType => $pAmt): ?>
                <div class="flex justify-between py-1 border-b border-slate-100">
                    <span><?= htmlspecialchars($pType) ?>:</span>
                    <span class="font-bold">$<?= number_format($pAmt, 2) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="pt-4 space-y-1 text-right font-bold text-sm text-slate-900">
        <div>Total Turnover Sales: $<?= number_format($zReportData['total_sales'], 2) ?></div>
        <div>Total Refunds / Credits: $<?= number_format($zReportData['total_refunds'], 2) ?></div>
        <div class="text-blue-600">Total VAT Tax Component: $<?= number_format($zReportData['total_vat'], 2) ?></div>
    </div>

    <div class="mt-8 text-center text-[10px] text-slate-400 border-t border-slate-200 pt-3">
        Official FRCS VMS Fiscal Summary Report &bull; NuvisMedCareX EHR
    </div>
</div>

</body>
</html>
