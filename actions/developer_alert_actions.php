<?php
/**
 * Developer broadcast alert actions.
 *  - create_alert / toggle_alert : developers only
 *  - dismiss_alert : any authenticated user (marks it read for themselves)
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\DeveloperAlertService;
use ClinicFlow\Shared\Logger;

requireAuth();
validateCsrfRequest();

$action = $_POST['action'] ?? '';
$alertService = Container::getInstance()->get(DeveloperAlertService::class);
$logger = Container::getInstance()->get(Logger::class);
$userId = $_SESSION['user_id'] ?? '';

$back = $_SERVER['HTTP_REFERER'] ?? '../index.php';

if ($action === 'dismiss_alert') {
    $id = trim($_POST['alert_id'] ?? '');
    if ($id !== '' && $userId !== '') {
        $alertService->markRead($id, $userId);
    }
    // Dismissals are often fired via fetch(); keep the response quiet.
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    header('Location: ' . $back);
    exit;
}

requireRole(['Developer']);

if ($action === 'create_alert') {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        setToast('Validation error', 'An alert title is required.', 'error');
        header('Location: ' . $back);
        exit;
    }
    try {
        $alertService->createAlert([
            'title'            => $title,
            'body'             => $_POST['body'] ?? '',
            'severity'         => $_POST['severity'] ?? 'info',
            'audience'         => $_POST['audience'] ?? 'all',
            'target_tenant_id' => $_POST['target_tenant_id'] ?? '',
            'target_role'      => $_POST['target_role'] ?? '',
            'link_url'         => $_POST['link_url'] ?? '',
            'expires_at'       => $_POST['expires_at'] ?? '',
            'created_by'       => $userId ?: null,
            'created_by_name'  => $_SESSION['user_name'] ?? null,
        ]);
        setToast('Alert published', 'Your message is now live across the board.');
    } catch (\Throwable $e) {
        $logger->error('Failed to create developer alert: ' . $e->getMessage());
        setToast('Error', 'Could not publish the alert.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'toggle_alert') {
    $id = trim($_POST['alert_id'] ?? '');
    $active = ($_POST['active'] ?? '') === '1';
    if ($id !== '') {
        $alertService->setActive($id, $active);
        setToast('Alert updated', $active ? 'Alert re-activated.' : 'Alert retired.');
    }
    header('Location: ' . $back);
    exit;
}

header('Location: ' . $back);
exit;
