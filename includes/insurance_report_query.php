<?php
/**
 * Reads the insurance report filters out of the query string and runs the
 * report. Shared by the screen, the CSV export and the printable version so
 * all three always show the same figures for the same link.
 *
 * Defines: $insFilters, $insReport, $insProviders, $insLayout, $insLayoutOwner,
 *          $insSettings, $insQueryString
 */

use ClinicFlow\Services\InsuranceReportService;
use ClinicFlow\Services\InsuranceReportSettings;

$insTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$insService  = new InsuranceReportService($pdo, $insTenantId);
$insSettings = new InsuranceReportSettings($pdo, $insTenantId);

$insPresets = InsuranceReportService::presets();
$insPreset  = (string) ($_GET['preset'] ?? '');

if (isset($insPresets[$insPreset])) {
    [, $insFrom, $insTo] = $insPresets[$insPreset];
} else {
    $insPreset = '';
    $insFrom = InsuranceReportService::cleanDate($_GET['from'] ?? null) ?? date('Y-m-01');
    $insTo   = InsuranceReportService::cleanDate($_GET['to'] ?? null) ?? date('Y-m-d');
}

// One provider drives the layout; 'all' keeps the clinic default layout.
$insProvider = InsuranceReportSettings::cleanProviderName((string) ($_GET['provider'] ?? ''));
$insStatuses = [];
foreach ((array) ($_GET['status'] ?? []) as $s) {
    if (in_array($s, InsuranceReportSettings::STATUSES, true)) {
        $insStatuses[] = $s;
    }
}

$insFilters = [
    'from'       => $insFrom,
    'to'         => $insTo,
    'providers'  => $insProvider !== '' ? [$insProvider] : [],
    'statuses'   => $insStatuses,
    'date_basis' => ($_GET['basis'] ?? 'submitted') === 'service' ? 'service' : 'submitted',
    'search'     => trim((string) ($_GET['q'] ?? '')),
];

$insReport    = $insService->report($insFilters);
$insProviders = $insService->providers();
[$insLayout, $insLayoutOwner] = $insSettings->layoutFor($insProvider);

/** The current filters as a query string, for the export and print links. */
$insQueryString = http_build_query(array_filter([
    'from'     => $insFilters['from'],
    'to'       => $insFilters['to'],
    'provider' => $insProvider,
    'basis'    => $insFilters['date_basis'],
    'q'        => $insFilters['search'],
], static fn($v) => $v !== '' && $v !== null));
foreach ($insStatuses as $s) {
    $insQueryString .= '&status%5B%5D=' . urlencode($s);
}
