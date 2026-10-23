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

$invoiceHeaderTitle = 'COMMERCIAL INVOICE';
$invoicePaymentTerms = $settingsRows['invoice_payment_terms'] ?? 'Payment due upon receipt or within 30 days.';
$invoiceFooterNote = $settingsRows['invoice_footer_note'] ?? 'Thank you for choosing Nuvis Medico Healthcare. For billing inquiries, please contact our financial department.';

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
    die("Invoice record not found.");
}

// Fetch Itemized lines
$stmtItems = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$stmtItems->execute([$invoice['id']]);
$items = $stmtItems->fetchAll();

// Fallback if no itemized lines exist
if (empty($items)) {
    $services = json_decode($invoice['services'] ?? '[]', true) ?: ['Clinical Consultation & Medical Evaluation'];
    foreach ($services as $svc) {
        $items[] = [
            'name' => $svc,
            'quantity' => 1,
            'unit_price' => $invoice['amount'],
            'total_price' => $invoice['amount'],
            'tax_amount' => $invoice['total_tax'] ?? 0.00
        ];
    }
}

// Fetch Patient Info if available
$patient = null;
if (!empty($invoice['patient_id'])) {
    $pStmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $pStmt->execute([$invoice['patient_id']]);
    $patient = $pStmt->fetch();
}

$subtotal = 0.00;
foreach ($items as $it) {
    $subtotal += (float)($it['total_price'] ?? 0);
}
$taxTotal = (float)($invoice['total_tax'] ?? 0.00);
$grandTotal = (float)($invoice['amount'] ?? $subtotal);
$insuranceCovered = (float)($invoice['insurance_covered'] ?? 0.00);
$patientOwed = (float)($invoice['patient_owed'] ?? max(0, $grandTotal - $insuranceCovered));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commercial Invoice <?= htmlspecialchars($invoice['invoice_number']) ?> - <?= htmlspecialchars($clinicName) ?></title>
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

<div class="max-w-3xl mx-auto bg-white p-8 sm:p-10 rounded-2xl shadow-xl border border-slate-200 print-card space-y-8">

    <!-- Action Controls -->
    <div class="no-print flex justify-between items-center bg-slate-50 p-4 rounded-2xl border border-slate-200">
        <a href="billing.php" class="text-xs font-bold text-slate-600 hover:text-slate-900 transition flex items-center gap-1">
            &larr; Back to Billing
        </a>
        <button onclick="window.print()" class="px-5 py-2.5 bg-blue-700 text-white text-xs font-bold rounded-xl hover:bg-blue-800 transition shadow-xs flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
            Print Commercial Invoice
        </button>
    </div>

    <!-- Commercial Invoice Top Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start border-b-2 border-slate-900 pb-6 gap-6">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900 uppercase tracking-tight"><?= htmlspecialchars($clinicName) ?></h1>
            <p class="text-xs font-semibold text-blue-700 mt-0.5"><?= htmlspecialchars($clinicSubtitle) ?></p>
            <div class="text-slate-500 space-y-0.5 mt-2 leading-relaxed">
                <p><?= htmlspecialchars($clinicAddress) ?></p>
                <p>Phone: <span class="font-medium text-slate-700"><?= htmlspecialchars($clinicPhone) ?></span> &nbsp;|&nbsp; Email: <span class="font-medium text-slate-700"><?= htmlspecialchars($clinicEmail) ?></span></p>
                <p>Medical License: <span class="font-mono font-semibold text-slate-700"><?= htmlspecialchars($docPrcNo) ?></span></p>
            </div>
        </div>

        <div class="text-left sm:text-right space-y-1">
            <span class="inline-block px-3 py-1 bg-blue-50 text-blue-800 font-extrabold text-sm uppercase tracking-wider rounded-lg border border-blue-200">
                <?= htmlspecialchars($invoiceHeaderTitle) ?>
            </span>
            <p class="text-lg font-mono font-bold text-slate-900 mt-2"><?= htmlspecialchars($invoice['invoice_number']) ?></p>
            <p class="text-slate-500 font-medium">Issue Date: <span class="font-mono font-bold text-slate-800"><?= date('M d, Y', strtotime($invoice['service_date'] ?? $invoice['created_at'])) ?></span></p>
            <p class="text-slate-500 font-medium">Due Date: <span class="font-mono font-bold text-slate-800"><?= date('M d, Y', strtotime($invoice['due_date'] ?? '+30 days')) ?></span></p>
            <p class="text-slate-500 font-medium">Status:
                <span class="font-bold uppercase px-2 py-0.5 rounded text-[10px] <?= $invoice['status'] === 'Paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' ?>">
                    <?= htmlspecialchars($invoice['status'] ?? 'Pending') ?>
                </span>
            </p>
        </div>
    </div>

    <!-- Bill To & Transaction Info Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 bg-slate-50 p-5 rounded-2xl border border-slate-200">
        <div>
            <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Billed Patient / Client</h3>
            <p class="text-sm font-bold text-slate-900"><?= htmlspecialchars($invoice['patient_name'] ?? 'Patient') ?></p>
            <p class="text-slate-600 mt-0.5">Medical Record Number (MRN): <span class="font-mono font-bold text-slate-800"><?= htmlspecialchars($invoice['patient_mrn'] ?? 'N/A') ?></span></p>
            <?php if ($patient): ?>
                <p class="text-slate-500 mt-0.5">DOB: <?= htmlspecialchars($patient['dob'] ?? 'N/A') ?> (<?= htmlspecialchars($patient['gender'] ?? '') ?>)</p>
                <?php if (!empty($patient['phone'])): ?>
                    <p class="text-slate-500">Contact: <?= htmlspecialchars($patient['phone']) ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="space-y-1 text-slate-600 sm:text-right">
            <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Billing Reference</h3>
            <p>Invoice Type: <span class="font-semibold text-slate-800"><?= htmlspecialchars($invoice['invoice_type'] ?? 'Normal') ?> Invoice</span></p>
            <p>Transaction: <span class="font-semibold text-slate-800"><?= htmlspecialchars($invoice['transaction_type'] ?? 'Sale') ?></span></p>
            <p>Attending Cashier: <span class="font-semibold text-slate-800"><?= htmlspecialchars($invoice['cashier'] ?? 'Clinical Finance') ?></span></p>
            <?php if (!empty($invoice['ref_no'])): ?>
                <p>Encounter / Visit Ref: <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars($invoice['ref_no']) ?></span></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Line Items Table -->
    <div class="border border-slate-200 rounded-2xl overflow-hidden">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-900 text-white text-[10px] font-bold uppercase tracking-wider">
                    <th class="py-3 px-4">Item / Service Description</th>
                    <th class="py-3 px-3 text-center w-16">Qty</th>
                    <th class="py-3 px-3 text-right w-24">Unit Price</th>
                    <th class="py-3 px-4 text-right w-28">Total Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 bg-white">
                <?php foreach ($items as $item): ?>
                    <tr class="hover:bg-slate-50 transition">
                        <td class="py-3 px-4">
                            <span class="font-bold text-slate-900 text-xs block"><?= htmlspecialchars($item['name']) ?></span>
                        </td>
                        <td class="py-3 px-3 text-center font-mono font-semibold text-slate-700"><?= (float)($item['quantity'] ?? 1) ?></td>
                        <td class="py-3 px-3 text-right font-mono font-semibold text-slate-700">$<?= number_format((float)($item['unit_price'] ?? 0), 2) ?></td>
                        <td class="py-3 px-4 text-right font-mono font-bold text-slate-900">$<?= number_format((float)($item['total_price'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Summary & Financial Totals Breakdown -->
    <div class="flex flex-col sm:flex-row justify-between items-start gap-6 pt-2">
        <div class="space-y-3 sm:max-w-xs text-slate-600">
            <div>
                <h4 class="font-bold text-slate-900 uppercase text-[10px] tracking-wider mb-1">Payment Terms</h4>
                <p class="text-[11px] leading-relaxed"><?= htmlspecialchars($invoicePaymentTerms) ?></p>
            </div>
            <div>
                <h4 class="font-bold text-slate-900 uppercase text-[10px] tracking-wider mb-1">Remarks & Notes</h4>
                <p class="text-[11px] leading-relaxed italic text-slate-500"><?= htmlspecialchars($invoiceFooterNote) ?></p>
            </div>
        </div>

        <div class="w-full sm:w-72 bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-2 font-mono text-xs">
            <div class="flex justify-between text-slate-600">
                <span>Gross Subtotal:</span>
                <span class="font-bold text-slate-800">$<?= number_format($subtotal, 2) ?></span>
            </div>
            <?php if ($taxTotal > 0): ?>
                <div class="flex justify-between text-slate-600">
                    <span>Tax / VAT Component:</span>
                    <span class="font-bold text-slate-800">$<?= number_format($taxTotal, 2) ?></span>
                </div>
            <?php endif; ?>
            <div class="flex justify-between text-slate-900 font-bold border-t border-slate-200 pt-2 text-sm">
                <span>Invoice Total:</span>
                <span class="text-blue-700">$<?= number_format($grandTotal, 2) ?></span>
            </div>
            <?php if ($insuranceCovered > 0): ?>
                <div class="flex justify-between text-emerald-700 font-semibold pt-1 border-t border-slate-200">
                    <span>Insurance Coverage:</span>
                    <span>-$<?= number_format($insuranceCovered, 2) ?></span>
                </div>
            <?php endif; ?>
            <div class="flex justify-between text-slate-900 font-extrabold text-base border-t-2 border-slate-900 pt-2">
                <span>Patient Balance Due:</span>
                <span class="text-slate-900">$<?= number_format($patientOwed, 2) ?></span>
            </div>
        </div>
    </div>

    <!-- Official Signature Line -->
    <div class="pt-8 border-t border-slate-200 flex justify-between items-end">
        <div>
            <p class="text-[10px] uppercase font-bold text-slate-400">Issued By</p>
            <p class="font-bold text-slate-800 mt-1"><?= htmlspecialchars($clinicName) ?></p>
            <p class="text-slate-500 text-[10px]"><?= htmlspecialchars($clinicAddress) ?></p>
        </div>
        <div class="text-right">
            <div class="border-b border-slate-400 mb-1 pb-2 font-serif text-sm font-bold text-slate-800 italic">Nuvis MedcareX Finance</div>
            <p class="font-bold text-slate-700">Authorized Financial Signatory</p>
        </div>
    </div>

</div>

</body>
</html>
