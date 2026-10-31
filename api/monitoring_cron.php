<?php
declare(strict_types=1);

/**
 * Automatic monitoring check, intended to be called by a system cron.
 * Runs the health checks and emails developers when issues are found
 * (respecting the enabled flag, thresholds and notification cooldown).
 *
 * Authenticated by a shared token (not a session), e.g. every 5 minutes:
 *   /5 * * * * curl -s "https://your-domain/api/monitoring_cron.php?token=XXXX" >/dev/null
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $mon = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\MonitoringService::class);
    $settings = $mon->settings();

    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    if ($settings['monitor_cron_token'] === '' || !hash_equals($settings['monitor_cron_token'], (string) $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid token']);
        exit;
    }

    [$count, $notified, $message] = $mon->runAndNotify(false);
    echo json_encode([
        'ok' => true,
        'issues' => $count,
        'notified' => $notified,
        'message' => $message,
        'checked_at' => date('c'),
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Monitoring check failed']);
}
