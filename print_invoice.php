<?php
session_start();
if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit;
}

$invoiceId = $_GET['id'] ?? '';

require_once __DIR__ . '/config/database.php';
$pdo = getDB();

// Fetch Clinic Settings
$settingsRows = $pdo->query("SELECT * FROM clinic_settings")->fetchAll();
$settings = [];
foreach ($settingsRows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$sellerTin = $settings['vms_seller_tin'] ?? '502579006';
$clinicName = $settings['clinic_name'] ?? 'Nuvis Medico Healthcare';
$businessLocation = $settings['vms_business_location'] ?? ($settings['clinic_address'] ?? '2 Woodstand Road, Suva');

$invoiceHeaderTitle = $settings['invoice_header_title'] ?? 'FISCAL INVOICE';
$invoiceTaxId = $settings['invoice_tax_id'] ?? $sellerTin;
$invoicePaymentTerms = $settings['invoice_payment_terms'] ?? 'Net 30 Days. Please remit payment promptly.';
$invoiceFooterNote = $settings['invoice_footer_note'] ?? 'Thank you for choosing ClinicFlow Medical Center for your care.';

// Fetch Invoice
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? OR invoice_number = ?");
$stmt->execute([$invoiceId, $invoiceId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    $stmt = $pdo->query("SELECT * FROM invoices ORDER BY created_at DESC LIMIT 1");
    $invoice = $stmt->fetch();
}

if (!$invoice) {
    die("Invoice record not found.");
}

// Fetch Itemized lines from invoice_items
$stmtItems = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$stmtItems->execute([$invoice['id']]);
$items = $stmtItems->fetchAll();

// Fallback if no itemized lines exist
if (empty($items)) {
    $services = json_decode($invoice['services'] ?? '[]', true) ?: ['Medical Service / Consultation'];
    foreach ($services as $srv) {
        $items[] = [
            'name' => $srv,
            'gtin' => '10009812',
            'quantity' => 1.0,
            'unit_price' => (float)$invoice['amount'],
            'total_price' => (float)$invoice['amount'],
            'tax_label' => 'A',
            'tax_rate' => 15.00,
            'tax_amount' => round((float)$invoice['amount'] - ((float)$invoice['amount'] / 1.15), 2)
        ];
    }
}

$isNonFiscal = in_array($invoice['invoice_type'], ['Proforma', 'Copy', 'Training']);
$payments = json_decode($invoice['payment_methods'] ?? '[]', true) ?: [['type' => 'Cash', 'amount' => (float)$invoice['amount']]];

// Group taxes by label
$taxSummary = [];
$calculatedTotalTax = 0.00;
foreach ($items as $item) {
    $label = $item['tax_label'] ?? 'A';
    $rate = (float)($item['tax_rate'] ?? 15.00);
    $taxAmt = (float)($item['tax_amount'] ?? 0.00);

    if (!isset($taxSummary[$label])) {
        $taxSummary[$label] = ['rate' => $rate, 'tax_amount' => 0.00];
    }
    $taxSummary[$label]['tax_amount'] += $taxAmt;
    $calculatedTotalTax += $taxAmt;
}

$verificationUrl = $invoice['verification_url'] ?? "https://tap.sandbox.vms.frcs.org.fj/verify?id=" . ($invoice['sdc_invoice_no'] ?? '7AF234D9-E377B30A-150493');
$qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=" . urlencode($verificationUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiscal Invoice <?= htmlspecialchars($invoice['invoice_number']) ?> - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; margin: 0; padding: 0; }
        }
    </style>
</head>
<body class="bg-slate-100 p-6 min-h-screen text-slate-900 font-mono text-xs">

<div class="max-w-md mx-auto bg-white p-6 rounded-2xl shadow-lg border border-slate-200">
    <!-- Action buttons -->
    <div class="no-print mb-6 flex justify-between items-center bg-slate-50 p-3 rounded-xl border border-slate-200 font-sans">
        <a href="billing.php" class="text-xs font-semibold text-slate-600 hover:text-slate-900">&larr; Back to Billing</a>
        <button onclick="window.print()" class="px-4 py-2 bg-blue-600 text-white text-xs font-bold rounded-lg hover:bg-blue-700 transition">Print Fiscal Receipt</button>
    </div>

    <!-- VMS Header Title Line -->
    <div class="text-center font-bold text-sm tracking-tighter border-b border-slate-300 pb-2 mb-3 uppercase">
        ============ <?= htmlspecialchars($invoiceHeaderTitle) ?> ============
    </div>

    <?php if ($isNonFiscal): ?>
        <div class="my-3 p-2 border-2 border-red-500 text-red-600 font-bold text-center text-sm uppercase font-sans">
            ========================================<br>
            THIS IS NOT A FISCAL INVOICE<br>
            ========================================
        </div>
    <?php endif; ?>

    <!-- Header Seller Metadata -->
    <div class="text-left space-y-0.5 mb-3 border-b border-slate-200 pb-3">
        <p class="font-bold text-sm text-slate-900"><?= htmlspecialchars($sellerTin) ?> <?= htmlspecialchars($clinicName) ?></p>
        <?php if (!empty($invoiceTaxId) && $invoiceTaxId !== $sellerTin): ?>
            <p>Tax ID / EIN: <span class="font-bold"><?= htmlspecialchars($invoiceTaxId) ?></span></p>
        <?php endif; ?>
        <p><?= htmlspecialchars($businessLocation) ?></p>
        <p>Cashier: <span class="font-bold"><?= htmlspecialchars($invoice['cashier'] ?? 'Admin') ?></span></p>
        <?php if (!empty($invoice['buyer_tin'])): ?>
            <p>Buyer TIN: <span class="font-bold"><?= htmlspecialchars($invoice['buyer_tin']) ?></span></p>
            <?php if (!empty($invoice['buyer_cost_center'])): ?>
                <p>Buyer Cost Center: <span class="font-bold"><?= htmlspecialchars($invoice['buyer_cost_center']) ?></span></p>
            <?php endif; ?>
        <?php endif; ?>
        <p>POS Number: <?= htmlspecialchars($invoice['pos_number'] ?? 'ASDF238/1.2') ?> | POS Time: <?= htmlspecialchars($invoice['pos_time'] ?? date('Y-m-d H:i:s')) ?></p>
        <?php if (!empty($invoice['ref_no'])): ?>
            <p>Ref No: <span class="font-bold"><?= htmlspecialchars($invoice['ref_no']) ?></span></p>
            <?php if (!empty($invoice['ref_time'])): ?>
                <p>Ref Time: <?= htmlspecialchars($invoice['ref_time']) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Invoice Type Header -->
    <div class="text-center font-bold uppercase text-xs my-2 tracking-wide">
        ------------- <?= htmlspecialchars($invoice['invoice_type']) ?>-<?= htmlspecialchars($invoice['transaction_type']) ?> -------------
    </div>

    <!-- Line Items Table -->
    <div class="my-3 border-b border-slate-300 pb-3">
        <div class="font-bold pb-1 text-[11px]">Items ========================================</div>
        <table class="w-full text-left">
            <thead>
                <tr class="text-[10px] text-slate-500 font-bold border-b border-slate-200">
                    <th class="py-1">Name</th>
                    <th class="py-1 text-right">Price</th>
                    <th class="py-1 text-center">Qty</th>
                    <th class="py-1 text-right">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td class="py-1 font-semibold">
                            <?= htmlspecialchars($item['name']) ?> (<?= htmlspecialchars($item['tax_label'] ?? 'A') ?>)
                            <?php if (!empty($item['gtin'])): ?>
                                <span class="block text-[9px] text-slate-500 font-mono">GTIN: <?= htmlspecialchars($item['gtin']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="py-1 text-right"><?= number_format((float)$item['unit_price'], 2) ?></td>
                        <td class="py-1 text-center"><?= (float)$item['quantity'] ?></td>
                        <td class="py-1 text-right font-bold"><?= number_format((float)$item['total_price'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Totals and Payment Methods -->
    <div class="space-y-1 mb-3 border-b border-slate-300 pb-3">
        <div class="flex justify-between font-bold text-sm">
            <span>Total:</span>
            <span>$<?= number_format((float)$invoice['amount'], 2) ?></span>
        </div>
        <?php foreach ($payments as $p): ?>
            <div class="flex justify-between text-slate-700">
                <span><?= htmlspecialchars($p['type'] ?? 'Cash') ?>:</span>
                <span>$<?= number_format((float)($p['amount'] ?? $invoice['amount']), 2) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Itemized Tax Rates -->
    <div class="my-3 border-b border-slate-300 pb-3">
        <table class="w-full text-left text-[11px]">
            <thead>
                <tr class="text-[10px] text-slate-500 font-bold border-b border-slate-200">
                    <th class="py-0.5">Label</th>
                    <th class="py-0.5">Name</th>
                    <th class="py-0.5 text-center">Rate %</th>
                    <th class="py-0.5 text-right">Tax ($)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($taxSummary as $lbl => $tData): ?>
                    <tr>
                        <td class="py-0.5 font-bold"><?= htmlspecialchars($lbl) ?></td>
                        <td class="py-0.5"><?= $lbl === 'A' ? 'VAT' : ($lbl === 'E' ? 'EXEMPT' : 'OTHER') ?></td>
                        <td class="py-0.5 text-center"><?= number_format($tData['rate'], 2) ?></td>
                        <td class="py-0.5 text-right font-bold"><?= number_format($tData['tax_amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="flex justify-between font-bold text-xs pt-1 border-t border-slate-200 mt-1">
            <span>Total Tax:</span>
            <span>$<?= number_format((float)($invoice['total_tax'] ?? $calculatedTotalTax), 2) ?></span>
        </div>
    </div>

    <!-- SDC Fiscal Metadata -->
    <div class="my-3 space-y-0.5 text-[11px] border-b border-slate-300 pb-3">
        <p>========================================</p>
        <p>SDC Time: <span class="font-bold"><?= htmlspecialchars($invoice['sdc_time'] ?? date('Y-m-d H:i:s')) ?></span></p>
        <p>SDC No: <span class="font-bold"><?= htmlspecialchars($invoice['sdc_invoice_no'] ?? '7AF234D9-E377B30A-150493') ?></span></p>
        <p>Invoice Counter: <span class="font-bold"><?= htmlspecialchars($invoice['invoice_counter'] ?? '143027/150493NS') ?></span></p>
        <p>========================================</p>
    </div>

    <!-- Custom Invoice Payment Terms & Notes -->
    <div class="my-3 space-y-1 text-[10px] text-slate-600 border-b border-slate-300 pb-3 font-sans">
        <?php if (!empty($invoicePaymentTerms)): ?>
            <p><strong>Payment Terms:</strong> <?= htmlspecialchars($invoicePaymentTerms) ?></p>
        <?php endif; ?>
        <?php if (!empty($invoiceFooterNote)): ?>
            <p class="italic"><?= htmlspecialchars($invoiceFooterNote) ?></p>
        <?php endif; ?>
    </div>

    <!-- QR Code & Verification URL -->
    <div class="my-4 text-center">
        <img src="<?= htmlspecialchars($qrCodeUrl) ?>" alt="VMS Verification QR Code" class="w-36 h-36 mx-auto border border-slate-300 p-1 rounded-lg">
        <a href="<?= htmlspecialchars($verificationUrl) ?>" target="_blank" class="block text-[10px] text-blue-600 underline font-sans mt-2 break-all">
            <?= htmlspecialchars($verificationUrl) ?>
        </a>
    </div>

    <!-- Ending Title Line -->
    <div class="text-center font-bold text-sm tracking-tighter pt-2 border-t border-slate-300">
        ======== END OF FISCAL INVOICE ========
    </div>
</div>

</body>
</html>
