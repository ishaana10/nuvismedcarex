<?php
/**
 * Monitoring settings + manual check (developer only).
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\MonitoringService;

requireAuth();
validateCsrfRequest();
requireRole(['Developer']);

$action = $_POST['action'] ?? '';
$mon = Container::getInstance()->get(MonitoringService::class);
$back = $_SERVER['HTTP_REFERER'] ?? '../developer.php';

if ($action === 'save_settings') {
    $mon->saveSettings([
        'monitoring_enabled'   => isset($_POST['monitoring_enabled']),
        'monitor_recipients'   => $_POST['monitor_recipients'] ?? '',
        'th_errors_24h'        => $_POST['th_errors_24h'] ?? 50,
        'th_errors_1h'         => $_POST['th_errors_1h'] ?? 15,
        'th_storage_pct'       => $_POST['th_storage_pct'] ?? 90,
        'th_db_conn'           => $_POST['th_db_conn'] ?? 100,
        'monitor_cooldown_min' => $_POST['monitor_cooldown_min'] ?? 60,
    ]);
    setToast('Saved', 'Monitoring settings updated.');
    header('Location: ' . $back);
    exit;
}

if ($action === 'run_now') {
    [$count, $notified, $message] = $mon->runAndNotify(true);
    if ($count === 0) {
        setToast('All clear', 'No issues detected.');
    } else {
        setToast($notified ? 'Issues found — alert sent' : 'Issues found', "{$count} issue(s). {$message}.", $notified ? 'success' : 'error');
    }
    header('Location: ' . $back);
    exit;
}

header('Location: ' . $back);
exit;
