<?php
/**
 * Download the insurance report as a CSV, laid out the way the selected
 * provider's template says, so it can be sent on or opened in a spreadsheet.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\InsuranceReportService;
use ClinicFlow\Services\InsuranceReportSettings;

requireAuth();
requireAccess('billing', 'view');

$pdo = getDB();
require_once __DIR__ . '/../includes/insurance_report_query.php';

$clinic = nxClinic();
$cur = nxCurrencySymbol();
$table = InsuranceReportService::tabulate($insReport, $insLayout);
$totals = $insReport['totals'];

$slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower(
    ($insProvider !== '' ? $insProvider : $clinic['name']) . '-insurance'
)) ?: 'insurance';
$filename = trim((string) $slug, '-') . '-' . $insFilters['from'] . '-to-' . $insFilters['to'] . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // so Excel reads accents correctly
$row = static fn(array $cells) => fputcsv($out, $cells);

$money = static fn($v) => number_format((float) $v, 2, '.', '');
$claims = static fn(int $n) => $n . ($n === 1 ? ' claim' : ' claims');

$row([$clinic['name'] . ' — ' . $insLayout['title']]);
if (($clinic['tin'] ?? '') !== '') {
    $row(['TIN', $clinic['tin']]);
}
$row(['Provider', $insProvider !== '' ? $insProvider : 'All providers']);
$row(['Period', $insFilters['from'], 'to', $insFilters['to']]);
$row(['Dates mean', $insFilters['date_basis'] === 'service' ? 'Date of service' : 'Date claim submitted']);
if ($insFilters['statuses'] !== []) {
    $row(['Statuses', implode(', ', $insFilters['statuses'])]);
}
if ($insFilters['search'] !== '') {
    $row(['Search', $insFilters['search']]);
}
$row(['Currency', $cur]);
$row(['Generated', date('Y-m-d H:i')]);
$row([]);

if (!empty($insLayout['show_summary'])) {
    $row(['Summary']);
    $row(['Claims in the period', $totals['count']]);
    $row(['Total claimed', $money($totals['claimed'])]);
    $row(['Approved', $money($totals['approved']), $claims($totals['approved_count'])]);
    $row(['Rejected', $money($totals['rejected']), $claims($totals['rejected_count'])]);
    $row(['Still with the insurer', $money($totals['open']), $claims($totals['open_count'])]);
    $row(['Approval rate (of decided claims)', $totals['approval_rate'] . '%']);
    $row(['Average days to a decision', $totals['average_settle_days']]);
    $row(['Oldest open claim (days)', $totals['oldest_open_days']]);
    $row([]);
}

if (count($insReport['by_provider']) > 1) {
    $row(['By provider']);
    $row(['Provider', 'Claims', 'Claimed', 'Approved', 'Rejected', 'Outstanding', 'Open claims', 'Oldest open (days)']);
    foreach ($insReport['by_provider'] as $p) {
        $row([
            $p['provider'], $p['count'], $money($p['claimed']), $money($p['approved']),
            $money($p['rejected']), $money($p['open']), $p['open_count'], $p['oldest_open_days'],
        ]);
    }
    $row([]);
}

if (!empty($insLayout['show_aging']) && $totals['open_count'] > 0) {
    $row(['How long open claims have been waiting']);
    $row(['Age', 'Claims', 'Amount']);
    foreach ($insReport['aging'] as $bucket) {
        $row([$bucket['label'], $bucket['count'], $money($bucket['claimed'])]);
    }
    $row([]);
}

$row([$insLayout['title']]);
$row($table['headers']);
if ($table['groups'] !== []) {
    foreach ($table['groups'] as $group) {
        $row([$group['label'], $claims(count($group['rows'])), $money($group['claimed'])]);
        foreach ($group['rows'] as $cells) {
            $row(formatCells($cells, $table['types']));
        }
        $row([]);
    }
} else {
    foreach ($table['rows'] as $cells) {
        $row(formatCells($cells, $table['types']));
    }
}

if (!empty($insReport['unclaimed'])) {
    $row([]);
    $row(['Insurance billed on invoices with no claim submitted']);
    $row(['Invoice', 'Patient', 'MRN', 'Provider on file', 'Date of service', 'Invoice total', 'Insurance portion']);
    foreach ($insReport['unclaimed'] as $u) {
        $row([
            $u['invoice_number'], $u['patient_name'], $u['patient_mrn'], $u['provider'],
            $u['service_date'], $money($u['amount']), $money($u['insurance_covered']),
        ]);
    }
}

if (!empty($insLayout['footer_note'])) {
    $row([]);
    $row([$insLayout['footer_note']]);
}

fclose($out);
exit;

/** Money columns go out as plain numbers so spreadsheets can total them. */
function formatCells(array $cells, array $types): array
{
    foreach ($cells as $i => $cell) {
        if (($types[$i] ?? 'text') === 'money') {
            $cells[$i] = number_format((float) $cell, 2, '.', '');
        }
    }
    return $cells;
}
