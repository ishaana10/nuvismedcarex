<?php
/**
 * Action: Log JavaScript Runtime Errors & Promise Rejections
 */

require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// This endpoint can be called before sign-in (login page errors), so it is
// throttled and size-capped to stop it being used to flood the error log.
$json = file_get_contents('php://input', false, null, 0, 16384);
$data = json_decode($json ?: '', true) ?? [];

$now = time();
$_SESSION['js_err_window'] = array_values(array_filter($_SESSION['js_err_window'] ?? [], fn($t) => $now - $t < 60));
if (count($_SESSION['js_err_window']) >= 10) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many reports']);
    exit();
}
$_SESSION['js_err_window'][] = $now;

$message = mb_substr(trim((string)($data['message'] ?? '')), 0, 1000);
if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Message is required']);
    exit();
}

$url = mb_substr(trim((string)($data['url'] ?? '')), 0, 500);
$line = isset($data['line']) ? (int)$data['line'] : 0;
$column = isset($data['column']) ? (int)$data['column'] : 0;
$stack = mb_substr(trim((string)($data['stack'] ?? '')), 0, 4000);
$userAgent = mb_substr(trim((string)($data['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''))), 0, 300);

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
    error_log('[log_js_error] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not record the error']);
}
