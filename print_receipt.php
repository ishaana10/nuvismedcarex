<?php
/**
 * Payment receipt (print / PDF). Layout comes from the clinic's receipt
 * design in Administration → Receipts.
 *
 *   print_receipt.php?id=INVOICE_ID          the receipt for one invoice
 *   print_receipt.php?preview=1  (POST)      live preview for the receipt designer
 */
require_once __DIR__ . '/includes/autoloader.php';
require_once __DIR__ . '/core/SessionBootstrap.php';
if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/receipt_template.php';

use ClinicFlow\Services\ReceiptSettings;

$pdo = getDB();
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$isPreview = isset($_GET['preview']);

// Identity of the clinic this receipt belongs to
$settingsRows = nxSettings();
$clinic = nxClinic();

$rs = new ReceiptSettings($pdo, $currentTenantId);
$cfg = $rs->load();
// A receipt with no logo of its own uses the clinic's logo
if (($cfg['logo'] ?? '') === '' && !empty($clinic['logo'])) {
    $cfg['logo'] = $clinic['logo'];
}

if ($isPreview) {
    // Designer preview: render the submitted (unsaved) settings with sample data
    if (!nxCan('settings', 'view')) { http_response_code(403); exit('No access'); }
    $previewError = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            $cfg = ReceiptSettings::sanitise($_POST, $cfg, $_FILES['logo_file'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $previewError = $e->getMessage();
            $cfg = ReceiptSettings::sanitise($_POST, $cfg, null);
        }
    }
    $signer = ['name' => $_SESSION['user_name'] ?? 'Dr. Sarah Jenkins', 'title' => 'Attending physician', 'signature' => '', 'stamp' => ''];
    try {
        $d = $pdo->prepare("SELECT name, specialty, esignature, digital_stamp FROM doctors WHERE id = ?");
        $d->execute([$_SESSION['user_id'] ?? '']);
        if ($row = $d->fetch(PDO::FETCH_ASSOC)) {
            $signer = ['name' => $row['name'], 'title' => (string) $row['specialty'], 'signature' => (string) ($row['esignature'] ?? ''), 'stamp' => (string) ($row['digital_stamp'] ?? '')];
        }
    } catch (\Throwable $e) {}
    $data = nxSampleReceiptData($clinic, $rs->currencySymbol($cfg), $signer);
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt preview</title>
<style><?= nxReceiptCss($cfg) ?>
body{margin:0;padding:20px 12px;background:#e9edf2;font-family:system-ui,sans-serif}
.rc-screen-frame{box-shadow:0 8px 30px rgb(15 43 92 / .14);border-radius:6px;overflow:hidden;margin:0 auto;width:max-content;max-width:100%}
.pv-err{max-width:520px;margin:0 auto 12px;padding:10px 12px;border-radius:8px;background:#fdf2f1;color:#962b1f;font-size:13px;border:1px solid #f7c4be}
</style></head><body>
<?php if ($previewError): ?><div class="pv-err"><?= htmlspecialchars($previewError) ?></div><?php endif; ?>
<div class="rc-screen-frame"><?= nxRenderReceipt($cfg, $data) ?></div>
</body></html><?php
    exit;
}

// ----------------------------------------------------------------- real receipt
$invoiceId = (string) ($_GET['id'] ?? '');
$stmt = $pdo->prepare("SELECT i.*, p.email AS patient_email, p.insurance_provider AS patient_insurer
                         FROM invoices i
                         LEFT JOIN patients p ON p.id = i.patient_id AND p.tenant_id = i.tenant_id
                        WHERE (i.id = ? OR i.invoice_number = ?) AND i.tenant_id = ?");
$stmt->execute([$invoiceId, $invoiceId, $currentTenantId]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    // Never fall back to another patient's invoice
    http_response_code(404);
    setToast('Receipt not found', 'That invoice doesn\'t exist at this clinic. Open it from Billing.', 'error');
    header('Location: billing.php');
    exit;
}

$stmtItems = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$stmtItems->execute([$invoice['id']]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (!$items) {
    $services = json_decode($invoice['services'] ?? '[]', true) ?: ['Medical service'];
    $each = count($services) ? (float) $invoice['amount'] / count($services) : (float) $invoice['amount'];
    foreach ($services as $svc) {
        $items[] = ['name' => is_array($svc) ? ($svc['name'] ?? 'Service') : $svc, 'quantity' => 1, 'unit_price' => $each, 'total_price' => $each, 'tax_amount' => 0];
    }
}

// What an insurer covers is not money the patient handed over, so the receipt
// separates the two: the insurer's share is shown as a deduction and "paid"
// means what the patient actually paid.
$insuranceCovered = max(0.0, (float) ($invoice['insurance_covered'] ?? 0));
$patientShare = $insuranceCovered > 0
    ? max(0.0, (float) $invoice['amount'] - $insuranceCovered)
    : (float) $invoice['amount'];

$payments = json_decode($invoice['payment_methods'] ?? '[]', true);
if (!is_array($payments) || !$payments) {
    // No itemised payments recorded: fall back to the patient's share, never
    // the full invoice total, which would overstate what they paid.
    $payments = [['type' => $invoice['payment_type'] ?? 'Cash', 'amount' => $patientShare]];
}
$payments = array_map(fn($p) => ['type' => (string) ($p['type'] ?? $p['method'] ?? 'Cash'), 'amount' => (float) ($p['amount'] ?? 0)], $payments);
$totalPaid = array_sum(array_column($payments, 'amount')) ?: $patientShare;
$taxTotal = array_sum(array_map(fn($i) => (float) ($i['tax_amount'] ?? 0), $items)) ?: (float) ($invoice['total_tax'] ?? 0);
$balance = ($invoice['status'] ?? '') === 'Paid' ? 0.0 : max(0.0, (float) ($invoice['patient_owed'] ?? 0));

// Who signs: the cashier on the invoice, else the clinician printing it
$signerRow = null;
if (!empty($invoice['cashier'])) {
    $docStmt = $pdo->prepare("SELECT * FROM doctors WHERE (name = ? OR email = ?) AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1");
    $docStmt->execute([$invoice['cashier'], $invoice['cashier'], $currentTenantId]);
    $signerRow = $docStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$signerRow && !empty($_SESSION['current_doctor_id'])) {
    $docStmt = $pdo->prepare("SELECT * FROM doctors WHERE id = ?");
    $docStmt->execute([$_SESSION['current_doctor_id']]);
    $signerRow = $docStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$data = [
    'currency' => $rs->currencySymbol($cfg),
    'clinic'   => $clinic,
    'receipt'  => [
        'number'           => $invoice['invoice_number'],
        'invoice_number'   => $invoice['invoice_number'],
        'paid_at'          => (!empty($invoice['pos_time']) && strtotime((string) $invoice['pos_time'])) ? $invoice['pos_time'] : time(),
        'service_date'     => $invoice['service_date'] ?? '',
        'ref'              => (string) ($invoice['ref_no'] ?? ''),
        'cashier'          => (string) ($invoice['cashier'] ?? ''),
        'tin'              => (string) ($invoice['seller_tin'] ?: $clinic['tin']),
        'verification_url' => !empty($invoice['is_fiscalized']) ? (string) ($invoice['verification_url'] ?? '') : '',
    ],
    'patient'  => ['name' => (string) $invoice['patient_name'], 'mrn' => (string) ($invoice['patient_mrn'] ?? '')],
    'items'    => $items,
    'payments' => $payments,
    'total'    => (float) $invoice['amount'],
    'tax_total' => $taxTotal,
    'insurance_covered' => $insuranceCovered,
    'insurer'  => trim((string) ($invoice['patient_insurer'] ?? '')),
    'patient_share' => $patientShare,
    'total_paid' => $totalPaid,
    'balance'  => $balance,
    'signer'   => [
        'name'      => (string) ($signerRow['name'] ?? ''),
        'title'     => (string) ($signerRow['specialty'] ?? ''),
        'signature' => (string) ($signerRow['esignature'] ?? ''),
        'stamp'     => (string) ($signerRow['digital_stamp'] ?? ''),
    ],
];
$receiptNo = $cfg['number_prefix'] . $invoice['invoice_number'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($receiptNo . ' · ' . $clinic['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        <?= nxReceiptCss($cfg) ?>
        body { margin: 0; background: #e9edf2; font-family: "IBM Plex Sans", system-ui, sans-serif; color: #1b2430; }
        .bar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; padding: 10px 16px; background: #fff; border-bottom: 1px solid #d6dde6; }
        .bar a, .bar button { font: 600 13px/1 inherit; font-family: inherit; border-radius: 9px; padding: 9px 14px; border: 1px solid #d6dde6; background: #fff; color: #1b2430; cursor: pointer; text-decoration: none; }
        .bar .primary { background: #164487; border-color: #164487; color: #fff; }
        .bar .meta { font-size: 12px; color: #5b6878; }
        .wrap { padding: 24px 12px 48px; }
        .rc-screen-frame { box-shadow: 0 8px 30px rgb(15 43 92 / .14); border-radius: 6px; overflow: hidden; margin: 0 auto; width: max-content; max-width: 100%; background: #fff; }
        dialog { border: 0; border-radius: 14px; padding: 0; width: min(420px, calc(100vw - 32px)); box-shadow: 0 30px 80px -20px rgb(10 29 63 / .45); }
        dialog::backdrop { background: rgb(10 17 25 / .45); }
        dialog form { padding: 20px; display: grid; gap: 12px; font-size: 14px; }
        dialog h2 { margin: 0; font-size: 16px; }
        dialog input { font: inherit; padding: 9px 11px; border: 1px solid #c9d2dd; border-radius: 9px; }
        dialog .row { display: flex; justify-content: flex-end; gap: 8px; }
        dialog p { margin: 0; color: #5b6878; font-size: 13px; }
        @media print { .wrap { padding: 0; } }
    </style>
</head>
<body>
<div class="bar no-print">
    <a href="billing.php">&larr; Billing</a>
    <span class="meta"><?= htmlspecialchars(\ClinicFlow\Services\ReceiptSettings::PAPER[$cfg['paper']] ?? '') ?><?php if (nxCan('settings', 'view')): ?> · <a href="receipt_settings.php" style="border:0;padding:0;color:#164487">Change receipt design</a><?php endif; ?></span>
    <span style="display:flex;gap:8px">
        <button type="button" onclick="document.getElementById('email-dlg').showModal()">Email to patient</button>
        <button type="button" class="primary" onclick="window.print()">Print</button>
    </span>
</div>

<dialog id="email-dlg">
    <form action="actions/send_receipt_email.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
        <input type="hidden" name="invoice_id" value="<?= htmlspecialchars($invoice['id']) ?>">
        <h2>Email this receipt</h2>
        <label for="rc-email">Patient's email</label>
        <input id="rc-email" type="email" name="email" required value="<?= htmlspecialchars($invoice['patient_email'] ?? '') ?>" placeholder="name@example.com">
        <p>Receipt <?= htmlspecialchars($receiptNo) ?> will be sent straight away.</p>
        <div class="row">
            <button type="button" onclick="this.closest('dialog').close()" style="font:inherit;padding:9px 14px;border-radius:9px;border:1px solid #d6dde6;background:#fff">Cancel</button>
            <button type="submit" style="font:inherit;font-weight:600;padding:9px 14px;border-radius:9px;border:0;background:#164487;color:#fff">Send receipt</button>
        </div>
    </form>
</dialog>

<div class="wrap"><div class="rc-screen-frame"><?= nxRenderReceipt($cfg, $data) ?></div></div>
</body>
</html>
