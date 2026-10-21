<?php
/**
 * Action: Integration & Webhook Management Handlers
 */

require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (empty($_SESSION['authenticated'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit();
}

$userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Doctor';
if (!in_array($userRole, ['Administrator', 'Developer'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied: Administrator or Developer role required']);
    exit();
}

$pdo = getDB();
$container = \ClinicFlow\Shared\Container::getInstance();
$webhookService = $container->get(\ClinicFlow\Services\WebhookService::class);
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

$jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? ($jsonInput['action'] ?? ($_POST['action'] ?? ''));

try {
    if ($action === 'save_webhook') {
        $appName = trim((string)($jsonInput['app_name'] ?? $_POST['app_name'] ?? ''));
        $eventType = trim((string)($jsonInput['event_type'] ?? $_POST['event_type'] ?? ''));
        $targetUrl = trim((string)($jsonInput['target_url'] ?? $_POST['target_url'] ?? ''));
        $secretToken = trim((string)($jsonInput['secret_token'] ?? $_POST['secret_token'] ?? ''));
        $id = trim((string)($jsonInput['id'] ?? $_POST['id'] ?? ''));

        if (!$appName || !$eventType || !$targetUrl) {
            echo json_encode(['success' => false, 'error' => 'Application name, event type, and target URL are required.']);
            exit();
        }

        $subId = $webhookService->saveSubscription($appName, $eventType, $targetUrl, $secretToken, $id, $tenantId);
        echo json_encode(['success' => true, 'id' => $subId, 'message' => 'Webhook subscription saved successfully.']);
        exit();
    }

    if ($action === 'delete_webhook') {
        $id = trim((string)($jsonInput['id'] ?? $_POST['id'] ?? $_GET['id'] ?? ''));
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'Webhook subscription ID required.']);
            exit();
        }

        $deleted = $webhookService->deleteSubscription($id, $tenantId);
        echo json_encode(['success' => $deleted, 'message' => 'Webhook subscription deleted successfully.']);
        exit();
    }

    if ($action === 'test_webhook') {
        $targetUrl = trim((string)($jsonInput['target_url'] ?? $_POST['target_url'] ?? ''));
        $eventType = trim((string)($jsonInput['event_type'] ?? $_POST['event_type'] ?? 'PATIENT_REGISTERED'));

        if (!$targetUrl) {
            echo json_encode(['success' => false, 'error' => 'Target URL is required for test dispatch.']);
            exit();
        }

        $samplePayload = [
            'test' => true,
            'event' => $eventType,
            'timestamp' => date('c'),
            'patient_id' => 'pat-sample-001',
            'patient_name' => 'John Doe',
            'mrn' => 'MRN-982301'
        ];

        $res = $webhookService->dispatch($eventType, $samplePayload, $targetUrl, $tenantId);
        echo json_encode([
            'success' => true,
            'message' => "Test payload dispatched for event '{$eventType}'",
            'response' => $res
        ]);
        exit();
    }

    if ($action === 'generate_api_key') {
        $appName = trim((string)($jsonInput['app_name'] ?? $_POST['app_name'] ?? 'External Client App'));
        $id = 'key-' . uniqid();
        $apiKey = 'cf_live_' . bin2hex(random_bytes(20));

        $stmt = $pdo->prepare("INSERT INTO app_integrations (id, tenant_id, app_name, api_key, permissions, status) VALUES (?, ?, ?, ?, 'read,write', 'active')");
        $stmt->execute([$id, $tenantId, $appName, $apiKey]);

        echo json_encode([
            'success' => true,
            'id' => $id,
            'api_key' => $apiKey,
            'message' => 'API Key generated successfully. Save this secret key in a secure location.'
        ]);
        exit();
    }

    if ($action === 'revoke_api_key') {
        $id = trim((string)($jsonInput['id'] ?? $_POST['id'] ?? $_GET['id'] ?? ''));
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'API Key ID required.']);
            exit();
        }

        $stmt = $pdo->prepare("DELETE FROM app_integrations WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenantId]);

        echo json_encode(['success' => true, 'message' => 'API Access Key revoked successfully.']);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Invalid integration action specified.']);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
