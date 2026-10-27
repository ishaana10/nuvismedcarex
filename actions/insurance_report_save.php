<?php
/**
 * Save (or remove) an insurance report layout for this clinic — either the
 * clinic default or one provider's own columns and wording.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\AuditService;
use ClinicFlow\Services\InsuranceReportSettings;

if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}

// Only ever return to our own page, carrying the filters the user had
parse_str(str_replace(["\r", "\n"], '', (string) ($_POST['return'] ?? '')), $returnParams);
$returnParams = array_intersect_key(
    is_array($returnParams) ? $returnParams : [],
    ['from' => 1, 'to' => 1, 'provider' => 1, 'basis' => 1, 'q' => 1, 'status' => 1]
);
$returnTo = static function (array $params): string {
    $query = http_build_query($params);
    return '../insurance_report.php' . ($query !== '' ? '?' . $query : '');
};
$return = $returnTo($returnParams);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not saved', 'That request expired. Reload the page and try again.', 'error');
    header("Location: {$return}");
    exit;
}
requireAccess('billing', 'edit');

$pdo = getDB();
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$settings = new InsuranceReportSettings($pdo, $tenantId);

$target = (string) ($_POST['target'] ?? '');
if ($target === '__new__') {
    $target = (string) ($_POST['new_provider'] ?? '');
}
$target = InsuranceReportSettings::cleanProviderName($target);
$action = ($_POST['action'] ?? 'save') === 'remove' ? 'remove' : 'save';

if ($action === 'remove') {
    if ($target === '') {
        setToast('Nothing removed', 'The clinic default layout cannot be removed, only changed.', 'info');
    } elseif ($settings->removeProvider($target)) {
        logLayoutChange($pdo, $tenantId, 'INSURANCE_REPORT_LAYOUT_REMOVED', $target . ' now uses the clinic default layout');
        setToast('Layout removed', $target . ' now uses the clinic default layout.');
    } else {
        setToast('Nothing removed', $target . ' did not have a layout of its own.', 'info');
    }
    header("Location: {$return}");
    exit;
}

if (($_POST['target'] ?? '') === '__new__' && $target === '') {
    setToast('Not saved', 'Type the name of the insurance provider before saving.', 'error');
    header("Location: {$return}");
    exit;
}

$layout = [
    'title'           => $_POST['title'] ?? '',
    'reference_label' => $_POST['reference_label'] ?? '',
    'claim_prefix'    => $_POST['claim_prefix'] ?? '',
    'footer_note'     => $_POST['footer_note'] ?? '',
    'group_by'        => $_POST['group_by'] ?? 'none',
    'columns'         => $_POST['columns'] ?? [],
    'statuses'        => $_POST['statuses'] ?? [],
    // Unticked checkboxes are simply absent, so flag that the form carried them
    '_toggles_present' => 1,
    'show_summary'    => $_POST['show_summary'] ?? null,
    'show_aging'      => $_POST['show_aging'] ?? null,
    'show_logo'       => $_POST['show_logo'] ?? null,
    'show_signature'  => $_POST['show_signature'] ?? null,
];

try {
    if ($target === '') {
        $settings->saveDefault($layout);
        $who = 'clinic default';
    } else {
        $who = $settings->saveProvider($target, $layout);
    }
} catch (\InvalidArgumentException $e) {
    setToast('Not saved', $e->getMessage(), 'error');
    header("Location: {$return}");
    exit;
} catch (\Throwable $e) {
    error_log('[insurance_report_save] ' . $e->getMessage());
    setToast('Not saved', 'The report layout could not be saved. Try again, or contact your administrator.', 'error');
    header("Location: {$return}");
    exit;
}

logLayoutChange(
    $pdo,
    $tenantId,
    'INSURANCE_REPORT_LAYOUT_SAVED',
    sprintf('%s: %d columns, grouped by %s', $who, count((array) $layout['columns']), (string) $layout['group_by'])
);
setToast('Layout saved', $target === ''
    ? 'The clinic default report layout was updated.'
    : $who . "'s report layout was updated. CSV and printed output follow it too.");

// Land on the provider whose layout was just saved, so the change is visible
if ($target !== '') {
    $returnParams['provider'] = $target;
}
header('Location: ' . $returnTo($returnParams));
exit;

function logLayoutChange(PDO $pdo, string $tenantId, string $event, string $detail): void
{
    try {
        (new AuditService($pdo))->log(
            $event,
            $detail,
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_name'] ?? null,
            nxCurrentRole(),
            $tenantId
        );
    } catch (\Throwable $e) {
        error_log('[insurance_report_save] audit: ' . $e->getMessage());
    }
}
