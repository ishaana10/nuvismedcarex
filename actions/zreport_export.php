<?php
/**
 * Download the Z-Report as a CSV, for the clinic's own books or the accountant.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();
requireAccess('billing', 'view');

$pdo = getDB();
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
require_once __DIR__ . '/../includes/zreport_query.php';

$clinic = nxClinic();
$cur = nxCurrencySymbol();
$z = $zReportData;

$slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($clinic['name'])) ?: 'clinic';
$period = $z['is_range'] ? $z['from'] . '-to-' . $z['to'] : $zSelectedDate;
$filename = trim((string) $slug, '-') . '-zreport-' . $period . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
$row = static fn(array $cells) => fputcsv($out, $cells);
$money = static fn($v) => number_format((float) $v, 2, '.', '');

$row([$clinic['name'] . ' — ' . ($z['is_range'] ? 'Fiscal summary' : 'Daily Z-Report')]);
if ($z['is_range']) {
    $row(['Period', $z['from'], 'to', $z['to']]);
} else {
    $row(['Report date', $zSelectedDate]);
}
if (($clinic['tin'] ?? '') !== '') {
    $row(['Seller TIN', $clinic['tin']]);
}
if (($clinic['address'] ?? '') !== '') {
    $row(['Business location', nxSetting('vms_business_location', $clinic['address'])]);
}
$row(['Currency', $cur]);
$row(['Generated', date('Y-m-d H:i')]);
$row([]);

$row(['Totals']);
$row(['Invoices', $z['total_invoices']]);
$row(['Sales', $z['sales_count'], $money($z['total_sales'])]);
$row(['Refunds and credits', $z['refund_count'], $money($z['total_refunds'])]);
$row(['Turnover (sales less refunds)', '', $money($z['total_sales'] - $z['total_refunds'])]);
$row(['VAT component', '', $money($z['total_vat'])]);
$row(['Net of VAT', '', $money($z['net_sales'])]);
$row(['Billed to insurers', '', $money($z['insurance_covered'])]);
$row(['Paid by patients', '', $money($z['patient_share'])]);
$row(['Fiscalised invoices', $z['fiscalised_count']]);
$row(['Not yet fiscalised', $z['unfiscalised_count']]);
if ($z['first_invoice'] !== '') {
    $row(['Invoice range', $z['first_invoice'], $z['last_invoice']]);
}
$row([]);

$row(['By invoice and transaction type']);
$row(['Type', 'Count', 'Amount', 'VAT']);
foreach ($z['by_type'] as $type => $data) {
    if ($data['count'] === 0) {
        continue;
    }
    $row([$type, $data['count'], $money($data['amount']), $money($data['tax'])]);
}
$row([]);

$row(['How it was paid']);
$row(['Method', 'Amount']);
foreach ($z['by_payment'] as $type => $amount) {
    if (abs((float) $amount) < 0.01) {
        continue;
    }
    $row([$type, $money($amount)]);
}
$row(['Recorded in total', $money($z['payments_total'])]);
$row(['Difference against turnover', $money($z['reconciliation'])]);
$row([]);

if (!empty($z['by_tax'])) {
    $row(['VAT by tax label']);
    $row(['Label', 'Rate %', 'Lines', 'Net', 'VAT', 'Gross']);
    foreach ($z['by_tax'] as $t) {
        $row([
            $t['label'],
            $t['rate'] === null ? '' : number_format((float) $t['rate'], 2, '.', ''),
            $t['lines'], $money($t['net']), $money($t['tax']), $money($t['gross']),
        ]);
    }
}

fclose($out);
exit;
