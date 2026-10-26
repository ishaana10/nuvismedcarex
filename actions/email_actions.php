<?php

require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$container = \ClinicFlow\Shared\Container::getInstance();
$emailService = $container->get(\ClinicFlow\Services\EmailService::class);

if ($action === 'resend_email') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'That request expired. Reload the page and try again.']);
        exit;
    }
    $logId = trim($_POST['log_id'] ?? '');

    if (empty($logId)) {
        echo json_encode(['success' => false, 'error' => 'Log ID is required.']);
        exit;
    }

    $res = $emailService->resendEmailLog($logId);
    echo json_encode($res);
    exit;
}

if ($action === 'get_email_log') {
    $logId = trim($_GET['log_id'] ?? '');
    $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM email_logs WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$logId, $tenantId]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($log) {
        echo json_encode(['success' => true, 'log' => $log]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Email log entry not found.']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action.']);
exit;
