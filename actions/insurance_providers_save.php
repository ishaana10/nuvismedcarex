<?php
/**
 * Administration → Insurance: save this clinic's list of insurers.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\AuditService;
use ClinicFlow\Services\InsuranceProviders;

$back = '../insurance_providers.php';

if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not saved', 'That request expired. Reload the page and try again.', 'error');
    header("Location: {$back}");
    exit;
}
requireAccess('settings', 'edit');

$pdo = getDB();
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$svc = new InsuranceProviders($pdo, $tenantId);
$before = $svc->names(false);

try {
    if (($_POST['action'] ?? 'save') === 'adopt') {
        $added = [];
        foreach ((array) ($_POST['adopt'] ?? []) as $name) {
            $clean = $svc->addIfMissing((string) $name);
            if ($clean !== '') {
                $added[] = $clean;
            }
        }
        if ($added) {
            audit($pdo, $tenantId, 'INSURANCE_PROVIDERS_SAVED', 'Adopted: ' . implode(', ', array_slice($added, 0, 20)));
            $n = count($added);
            setToast('Providers added', $n . ($n === 1 ? ' provider is' : ' providers are') . ' now on the list.');
        } else {
            setToast('Nothing added', 'Tick at least one name first.', 'info');
        }
        header("Location: {$back}");
        exit;
    }

    $result = $svc->saveAll((array) ($_POST['providers'] ?? []));
} catch (\Throwable $e) {
    error_log('[insurance_providers_save] ' . $e->getMessage());
    setToast('Not saved', 'The provider list could not be saved. Try again, or contact your administrator.', 'error');
    header("Location: {$back}");
    exit;
}

$after = $svc->names(false);
$added = array_values(array_diff($after, $before));
$removed = array_values(array_diff($before, $after));
$detail = sprintf(
    '%d provider(s) on file%s%s',
    $result['saved'],
    $added ? '; added ' . implode(', ', array_slice($added, 0, 10)) : '',
    $removed ? '; removed ' . implode(', ', array_slice($removed, 0, 10)) : ''
);
audit($pdo, $tenantId, 'INSURANCE_PROVIDERS_SAVED', $detail);

if ($result['duplicates']) {
    setToast(
        'Saved, with duplicates merged',
        'These appeared twice and were kept once: ' . implode(', ', array_unique($result['duplicates'])) . '.',
        'info'
    );
} elseif ($result['saved'] === 0) {
    setToast('List cleared', 'No providers are on the list. Claims will fall back to typing the name.', 'info');
} else {
    setToast('Providers saved', $result['saved'] . ($result['saved'] === 1 ? ' provider is' : ' providers are') . ' available when raising a claim.');
}

header("Location: {$back}");
exit;

function audit(PDO $pdo, string $tenantId, string $event, string $detail): void
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
        error_log('[insurance_providers_save] audit: ' . $e->getMessage());
    }
}
