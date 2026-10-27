<?php
/**
 * Works out which period a Z-Report covers from the query string and runs it.
 * Shared by the Z-Report tab, the printable version and the CSV export so the
 * same link always gives the same figures.
 *
 * Expects $pdo and $currentTenantId. Defines: $zPreset, $zSelectedDate,
 * $zFrom, $zTo, $zReportData, $zPeriodLabel, $zQueryString, $zPresets.
 */

use ClinicFlow\Services\VMSService;

$zService = new VMSService($pdo);

/** Period presets: key => [label, from, to] */
$zPresets = [
    'today'       => ['Today', date('Y-m-d'), date('Y-m-d')],
    'yesterday'   => ['Yesterday', date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'this_month'  => ['This month', date('Y-m-01'), date('Y-m-t')],
    'quarterly'   => ['This quarter', date(sprintf('Y-%02d-01', ((int) ceil((int) date('n') / 3) - 1) * 3 + 1)), date('Y-m-t', strtotime(date(sprintf('Y-%02d-01', (int) ceil((int) date('n') / 3) * 3))))],
    'half_yearly' => ['Half year', (int) date('n') <= 6 ? date('Y-01-01') : date('Y-07-01'), (int) date('n') <= 6 ? date('Y-06-30') : date('Y-12-31')],
    'yearly'      => ['This year', date('Y-01-01'), date('Y-12-31')],
];

$nxCleanDate = static function ($value): ?string {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    return $ts === false ? null : date('Y-m-d', $ts);
};

$zPreset = (string) ($_GET['preset'] ?? '');
$zSelectedDate = $nxCleanDate($_GET['report_date'] ?? null) ?? date('Y-m-d');
$zFrom = $nxCleanDate($_GET['from_date'] ?? null);
$zTo   = $nxCleanDate($_GET['to_date'] ?? null);

if (isset($zPresets[$zPreset])) {
    [, $zFrom, $zTo] = $zPresets[$zPreset];
} else {
    $zPreset = '';
}

if ($zFrom !== null && $zTo !== null && $zFrom > $zTo) {
    [$zFrom, $zTo] = [$zTo, $zFrom];
}

// A single day is reported as the daily Z-Report rather than a range
if ($zFrom !== null && $zTo !== null && $zFrom !== $zTo) {
    $zReportData = $zService->getDailyFiscalReport($zSelectedDate, $zFrom, $zTo, $currentTenantId);
    $zPeriodLabel = nxDate($zFrom) . ' – ' . nxDate($zTo);
} else {
    if ($zFrom !== null && $zTo !== null) {
        $zSelectedDate = $zFrom;
    }
    $zFrom = $zTo = null;
    $zReportData = $zService->getDailyFiscalReport($zSelectedDate, null, null, $currentTenantId);
    $zPeriodLabel = nxDate($zSelectedDate);
}

$zQueryString = http_build_query(array_filter([
    'report_date' => $zSelectedDate,
    'from_date'   => $zFrom,
    'to_date'     => $zTo,
    'preset'      => $zPreset,
], static fn($v) => $v !== null && $v !== ''));
