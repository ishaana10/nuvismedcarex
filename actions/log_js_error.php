<?php
/**
 * Action: Log JavaScript Runtime Errors & Promise Rejections
 */

require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json, true) ?? [];

$message = trim((string)($data['message'] ?? ''));
if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Message is required']);
    exit();
}

$url = trim((string)($data['url'] ?? ''));
$line = isset($data['line']) ? (int)$data['line'] : 0;
$column = isset($data['column']) ? (int)$data['column'] : 0;
$stack = trim((string)($data['stack'] ?? ''));
$userAgent = trim((string)($data['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));

$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$userId = $_SESSION['user_id'] ?? null;
$logId = 'err-js-' . uniqid();

try {
    $pdo = getDB();
    $fileLine = $url . ($line > 0 ? " [Line {$line}, Col {$column}]" : "");

    $stmt = $pdo->prepare("INSERT INTO system_error_logs (id, tenant_id, type, level, message, file_line, stack_trace, user_id, created_at) VALUES (?, ?, 'JS', 'ERROR', ?, ?, ?, ?, ?)");
    $stmt->execute([
        $logId,
        $tenantId,
        $message,
        $fileLine,
        $stack ?: "User-Agent: {$userAgent}",
        $userId,
        date('Y-m-d H:i:s')
    ]);

    echo json_encode(['success' => true, 'id' => $logId]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
