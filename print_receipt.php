<?php
require_once __DIR__ . '/core/SessionBootstrap.php';
if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit;
}

$invoiceId = $_GET['id'] ?? '';

require_once __DIR__ . '/config/database.php';
$pdo = getDB();

$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// Fetch Clinic & Tenant Settings
$settingsRows = $pdo->query("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = " . $pdo->quote($currentTenantId))->fetchAll(PDO::FETCH_KEY_PAIR);
if (empty($settingsRows)) {
    $settingsRows = $pdo->query("SELECT setting_key, setting_value FROM clinic_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

$clinicName = $settingsRows['clinic_name'] ?? 'Nuvis Medico Healthcare';
$clinicSubtitle = $settingsRows['clinic_subtitle'] ?? 'Medical & Clinical Practice';
$clinicAddress = $settingsRows['clinic_address'] ?? '2 Woodstand Road, Suva, Fiji';
$clinicPhone = $settingsRows['clinic_phone'] ?? '(+679) 330 1234';
$clinicEmail = $settingsRows['clinic_email'] ?? 'billing@nuvismedcare.com';
$docPrcNo = $settingsRows['doc_prc_no'] ?? 'LIC-984120';

$receiptHeaderTitle = 'OFFICIAL PAYMENT RECEIPT';
$receiptThankYouMsg = $settingsRows['receipt_thank_you_msg'] ?? 'Thank you for your payment. Your medical account balance for this transaction is cleared.';

// Fetch Invoice
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE (id = ? OR invoice_number = ?) AND tenant_id = ?");
$stmt->execute([$invoiceId, $invoiceId, $currentTenantId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    // Fallback to any recent invoice for tenant
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$currentTenantId]);
    $invoice = $stmt->fetch();
}

if (!$invoice) {
    die("Receipt record not found.");
}

// Fetch Itemized lines
$stmtItems = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$stmtItems->execute([$invoice['id']]);
$items = $stmtItems->fetchAll();

if (empty($items)) {
    $services = json_decode($invoice['services'] ?? '[]', true) ?: ['Medical Service / Consultation'];
    foreach ($services as $svc) {
        $items[] = [
            'name' => $svc,
            'quantity' => 1,
            'unit_price' => $invoice['amount'],
            'total_price' => $invoice['amount']
        ];
    }
}

$payments = json_decode($invoice['payment_methods'] ?? '[]', true) ?: [['type' => ($invoice['payment_type'] ?? 'Cash'), 'amount' => (float)$invoice['amount']]];
$totalAmountPaid = array_reduce($payments, fn($acc, $p) => $acc + (float)($p['amount'] ?? 0), 0.00);
if ($totalAmountPaid <= 0) {
    $totalAmountPaid = (float)$invoice['amount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt REC-<?= htmlspecialchars($invoice['invoice_number']) ?> - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            body { background: white; padding: 0; }
            .print-card { border: none !important; shadow: none !important; padding: 0 !important; }
        }
    </style>
</head>
<body class="bg-slate-100 p-4 sm:p-8 min-h-screen text-slate-800 text-xs">

<div class="max-w-2xl mx-auto bg-white p-8 sm:p-10 rounded-2xl shadow-xl border border-slate-200 print-card space-y-8">

    <!-- Action Controls -->
    <div class="no-print flex justify-between items-center bg-slate-50 p-4 rounded-2xl border border-slate-200">
        <a href="billing.php" class="text-xs font-bold text-slate-600 hover:text-slate-900 transition flex items-center gap-1">
            &larr; Back to Billing
        </a>
        <button onclick="window.print()" class="px-5 py-2.5 bg-emerald-700 text-white text-xs font-bold rounded-xl hover:bg-emerald-800 transition shadow-xs flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
            Print Official Receipt
        </button>
    </div>

    <!-- Receipt Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start border-b-2 border-emerald-900 pb-6 gap-6">
        <div>
            <h1 class="text-2xl font-extrabold text-emerald-900 uppercase tracking-tight"><?= htmlspecialchars($clinicName) ?></h1>
            <p class="text-xs font-semibold text-emerald-700 mt-0.5"><?= htmlspecialchars($clinicSubtitle) ?></p>
            <div class="text-slate-500 space-y-0.5 mt-2 leading-relaxed">
                <p><?= htmlspecialchars($clinicAddress) ?></p>
                <p>Phone: <span class="font-medium text-slate-700"><?= htmlspecialchars($clinicPhone) ?></span> &nbsp;|&nbsp; Email: <span class="font-medium text-slate-700"><?= htmlspecialchars($clinicEmail) ?></span></p>
                <p>Medical License: <span class="font-mono font-semibold text-slate-700"><?= htmlspecialchars($docPrcNo) ?></span></p>
            </div>
        </div>

        <div class="text-left sm:text-right space-y-1">
            <span class="inline-block px-3 py-1 bg-emerald-50 text-emerald-800 font-extrabold text-sm uppercase tracking-wider rounded-lg border border-emerald-200">
                <?= htmlspecialchars($receiptHeaderTitle) ?>
            </span>
            <p class="text-lg font-mono font-bold text-slate-900 mt-2">REC-<?= htmlspecialchars($invoice['invoice_number']) ?></p>
            <p class="text-slate-500 font-medium">Receipt Date: <span class="font-mono font-bold text-slate-800"><?= date('M d, Y') ?></span></p>
            <p class="text-slate-500 font-medium">Invoice Ref: <span class="font-mono font-bold text-slate-800"><?= htmlspecialchars($invoice['invoice_number']) ?></span></p>
        </div>
    </div>

    <!-- Settlement Confirmation Box -->
    <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-5 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-800 flex items-center justify-center font-bold text-2xl shadow-xs shrink-0">
                ✓
            </div>
            <div>
                <h3 class="font-extrabold text-emerald-900 text-sm">Payment Confirmed & Settled</h3>
                <p class="text-emerald-700 text-xs mt-0.5 leading-relaxed"><?= htmlspecialchars($receiptThankYouMsg) ?></p>
            </div>
        </div>
        <div class="text-right font-mono bg-white px-4 py-2.5 rounded-xl border border-emerald-200 shrink-0">
            <span class="text-[10px] uppercase font-bold text-slate-400 block">Total Amount Paid</span>
            <span class="text-xl font-extrabold text-emerald-800">$<?= number_format($totalAmountPaid, 2) ?></span>
        </div>
    </div>

    <!-- Patient & Payment Method Details Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 bg-slate-50 p-5 rounded-2xl border border-slate-200">
        <div>
            <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Received From</h3>
            <p class="text-sm font-bold text-slate-900"><?= htmlspecialchars($invoice['patient_name'] ?? 'Patient') ?></p>
            <p class="text-slate-600 mt-0.5">Medical Record Number (MRN): <span class="font-mono font-bold text-slate-800"><?= htmlspecialchars($invoice['patient_mrn'] ?? 'N/A') ?></span></p>
            <p class="text-slate-500 mt-0.5">Service Date: <?= date('M d, Y', strtotime($invoice['service_date'] ?? $invoice['created_at'])) ?></p>
        </div>

        <div class="space-y-1 text-slate-600 sm:text-right">
            <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Payment Method Breakdown</h3>
            <?php foreach ($payments as $pm): ?>
                <p><?= htmlspecialchars($pm['type'] ?? 'Cash') ?> Payment: <span class="font-mono font-bold text-slate-900">$<?= number_format((float)($pm['amount'] ?? $totalAmountPaid), 2) ?></span></p>
            <?php endforeach; ?>
            <p>Attending Cashier: <span class="font-semibold text-slate-800"><?= htmlspecialchars($invoice['cashier'] ?? 'Clinical Finance') ?></span></p>
            <?php if (!empty($invoice['ref_no'])): ?>
                <p>Encounter Ref: <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars($invoice['ref_no']) ?></span></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Paid Services Table -->
    <div class="border border-slate-200 rounded-2xl overflow-hidden">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-900 text-white text-[10px] font-bold uppercase tracking-wider">
                    <th class="py-3 px-4">Services / Items Rendered</th>
                    <th class="py-3 px-3 text-center w-16">Qty</th>
                    <th class="py-3 px-4 text-right w-28">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 bg-white">
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td class="py-3 px-4 font-bold text-slate-900 text-xs"><?= htmlspecialchars($item['name']) ?></td>
                        <td class="py-3 px-3 text-center font-mono font-semibold text-slate-700"><?= (float)($item['quantity'] ?? 1) ?></td>
                        <td class="py-3 px-4 text-right font-mono font-bold text-slate-900">$<?= number_format((float)($item['total_price'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Remaining Balance & Notes -->
    <div class="flex flex-col sm:flex-row justify-between items-center gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-200 text-xs">
        <div>
            <span class="font-bold text-slate-900 block">Payment Status: <span class="text-emerald-700">PAID IN FULL</span></span>
            <span class="text-slate-500 text-[11px]">All medical services listed on this receipt have been settled.</span>
        </div>
        <div class="font-mono text-right">
            <span class="text-slate-500 text-[10px] uppercase font-bold block">Patient Balance Remaining</span>
            <span class="text-lg font-extrabold text-slate-900">$0.00</span>
        </div>
    </div>

    <!-- Official Signoff -->
    <div class="pt-6 border-t border-slate-200 flex justify-between items-end">
        <div>
            <p class="text-[10px] uppercase font-bold text-slate-400">Issued By</p>
            <p class="font-bold text-slate-800 mt-1"><?= htmlspecialchars($clinicName) ?></p>
            <p class="text-slate-500 text-[10px]"><?= htmlspecialchars($clinicPhone) ?></p>
        </div>
        <div class="text-right">
            <div class="border-b border-slate-400 mb-1 pb-2 font-serif text-sm font-bold text-emerald-900 italic">Nuvis MedcareX Finance</div>
            <p class="font-bold text-slate-700">Authorized Cashier Sign-off</p>
        </div>
    </div>

</div>

</body>
</html>
