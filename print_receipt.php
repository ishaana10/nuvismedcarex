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
$clinicAddress = $settings['clinic_address'] ?? '100 Healthcare Way, Suite 400, Springfield, OR 97477';
$clinicPhone = $settings['clinic_phone'] ?? '(555) 019-2831';

$receiptHeaderTitle = $settings['receipt_header_title'] ?? 'OFFICIAL PAYMENT RECEIPT';
$receiptThankYouMsg = $settings['receipt_thank_you_msg'] ?? 'Thank you for your payment. Your account balance for this invoice is cleared.';

// Fetch Invoice
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? OR invoice_number = ?");
$stmt->execute([$invoiceId, $invoiceId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    $stmt = $pdo->query("SELECT * FROM invoices ORDER BY created_at DESC LIMIT 1");
    $invoice = $stmt->fetch();
}

if (!$invoice) {
    die("Receipt file not found.");
}

$services = json_decode($invoice['services'] ?? '[]', true) ?: ['Medical Services'];
$payments = json_decode($invoice['payment_methods'] ?? '[]', true) ?: [['type' => 'Cash', 'amount' => (float)$invoice['amount']]];
$verificationUrl = $invoice['verification_url'] ?? "https://tap.sandbox.vms.frcs.org.fj/verify?id=" . ($invoice['sdc_invoice_no'] ?? '7AF234D9-E377B30A-150493');
$qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=" . urlencode($verificationUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?= htmlspecialchars($invoice['invoice_number']) ?> - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
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
    <div class="no-print mb-6 flex justify-between items-center bg-slate-50 p-4 rounded-xl border border-slate-200">
        <a href="billing.php" class="text-xs font-semibold text-slate-600 hover:text-slate-900">&larr; Back to Billing</a>
        <div class="flex gap-2">
            <button onclick="window.print()" class="px-4 py-2 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700 transition">Print Official Receipt</button>
        </div>
    </div>

    <!-- Clinic Header -->
    <div class="border-b-2 border-emerald-800 pb-4 mb-6 flex justify-between items-start">
        <div>
            <h1 class="text-2xl font-bold text-emerald-900 uppercase tracking-tight"><?= htmlspecialchars($clinicName) ?></h1>
            <p class="text-xs text-emerald-700 font-medium">TIN: <?= htmlspecialchars($sellerTin) ?></p>
            <p class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($clinicAddress) ?> • Phone: <?= htmlspecialchars($clinicPhone) ?></p>
        </div>
        <div class="text-right">
            <span class="text-xs font-bold uppercase tracking-wider text-emerald-900 block"><?= htmlspecialchars($receiptHeaderTitle) ?></span>
            <p class="text-sm font-mono font-bold text-slate-900 mt-1">REC-<?= htmlspecialchars($invoice['invoice_number']) ?></p>
            <p class="text-xs text-slate-500 font-mono">Date: <?= date('M d, Y') ?></p>
        </div>
    </div>

    <!-- Payment Confirmation Badge -->
    <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-6 flex items-center justify-between text-xs">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-xl">✓</div>
            <div>
                <p class="font-bold text-emerald-900 text-sm">Payment Confirmed & Settled</p>
                <p class="text-emerald-700 text-[11px]"><?= htmlspecialchars($receiptThankYouMsg) ?></p>
            </div>
        </div>
        <div class="text-right font-mono">
            <span class="text-xs text-slate-500 block">Amount Paid</span>
            <span class="text-lg font-bold text-emerald-800">$<?= number_format($invoice['amount'], 2) ?></span>
        </div>
    </div>

    <!-- Receipt Details -->
    <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 mb-6 text-xs grid grid-cols-2 gap-4">
        <div>
            <p class="text-slate-500 font-semibold uppercase text-[10px]">Received From</p>
            <p class="font-bold text-sm text-slate-900"><?= htmlspecialchars($invoice['patient_name']) ?></p>
            <p class="text-slate-500 mt-1">MRN: <span class="font-mono font-bold text-slate-800"><?= htmlspecialchars($invoice['patient_mrn']) ?></span></p>
        </div>
        <div class="text-right">
            <p class="text-slate-500 font-semibold uppercase text-[10px]">VMS Fiscal Reference</p>
            <p class="text-slate-700 mt-1"><strong>SDC No:</strong> <?= htmlspecialchars($invoice['sdc_invoice_no'] ?? 'N/A') ?></p>
            <p class="text-slate-700"><strong>SDC Time:</strong> <?= htmlspecialchars($invoice['sdc_time'] ?? date('Y-m-d H:i:s')) ?></p>
            <p class="text-slate-700"><strong>Invoice Ref:</strong> <?= htmlspecialchars($invoice['invoice_number']) ?></p>
        </div>
    </div>

    <!-- Paid Services Breakdown -->
    <div class="mb-6 overflow-hidden border border-slate-200 rounded-xl">
        <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase text-[10px]">
                <tr>
                    <th class="py-3 px-4">Service Rendered</th>
                    <th class="py-3 px-4 text-right">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($services as $service): ?>
                    <tr>
                        <td class="py-3 px-4 font-semibold text-slate-800"><?= htmlspecialchars($service) ?></td>
                        <td class="py-3 px-4 text-right font-semibold text-emerald-700">PAID</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Verification QR Code -->
    <div class="mb-6 flex items-center justify-between p-4 bg-slate-50 border border-slate-200 rounded-xl text-xs">
        <div>
            <p class="font-bold text-slate-900">FRCS VMS Verification</p>
            <p class="text-slate-500 text-[11px] mt-0.5">Scan to verify fiscal receipt authenticity on FRCS Portal</p>
            <p class="font-mono text-[10px] text-blue-600 mt-1 break-all"><?= htmlspecialchars($verificationUrl) ?></p>
        </div>
        <img src="<?= htmlspecialchars($qrCodeUrl) ?>" alt="QR Code" class="w-24 h-24 border border-slate-300 p-1 rounded-lg bg-white">
    </div>

    <!-- Authorized Signoff -->
    <div class="pt-6 border-t border-slate-300 flex justify-between items-end text-xs">
        <div>
            <p class="text-slate-500 text-[10px] uppercase font-semibold">Clinic Stamp / Authorized By</p>
            <p class="font-bold text-slate-800 mt-1"><?= htmlspecialchars($clinicName) ?></p>
            <p class="text-slate-500 text-[10px]"><?= htmlspecialchars($clinicPhone) ?></p>
        </div>
        <div class="text-right">
            <div class="border-b border-slate-400 mb-1 pb-2 font-serif text-base font-bold text-emerald-900 italic">Nuvis Medico Billing</div>
            <p class="font-bold text-slate-800">Authorized Cashier: <?= htmlspecialchars($invoice['cashier'] ?? 'Admin') ?></p>
        </div>
    </div>
</div>

</body>
</html>
