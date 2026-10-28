<?php
/**
 * Developer → Database: apply any schema changes that have not run yet.
 *
 * They normally apply themselves on the first database connection after a
 * deploy; this is for when one failed and has to be retried after the cause
 * is fixed.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\AuditService;
use ClinicFlow\Services\MigrationRunner;

$back = '../developer.php?dev_tab=database';

if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not applied', 'That request expired. Reload the page and try again.', 'error');
    header("Location: {$back}");
    exit;
}
requireAccess('developer', 'full');

$pdo = getDB();

try {
    $runner = new MigrationRunner($pdo);
    $applied = $runner->run();
    $failures = $runner->failures();
} catch (\Throwable $e) {
    error_log('[run_migrations] ' . $e->getMessage());
    setToast('Not applied', 'The schema changes could not be read: ' . $e->getMessage(), 'error');
    header("Location: {$back}");
    exit;
}

try {
    (new AuditService($pdo))->log(
        'DATABASE_MIGRATIONS_RUN',
        sprintf('%d applied, %d failed', count($applied), count($failures)),
        $_SESSION['user_id'] ?? null,
        $_SESSION['user_name'] ?? null,
        nxCurrentRole(),
        \ClinicFlow\Shared\TenantContext::getTenantId()
    );
} catch (\Throwable $e) {
    error_log('[run_migrations] audit: ' . $e->getMessage());
}

if ($failures) {
    $first = array_key_first($failures);
    setToast(
        count($applied) ? 'Applied, with problems' : 'Not applied',
        $first . ': ' . $failures[$first],
        'error'
    );
} elseif (!$applied) {
    setToast('Nothing to do', 'Every schema change had already been applied.', 'info');
} else {
    $n = count($applied);
    setToast('Database updated', $n . ($n === 1 ? ' change was' : ' changes were') . ' applied.');
}

header("Location: {$back}");
exit;
