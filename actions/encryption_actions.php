<?php
/**
 * Developer-only: encryption key status and re-encryption of clinical data
 * (see EncryptionMigrationService). Mutating actions need POST + CSRF.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/autoloader.php';

use ClinicFlow\Services\EncryptionMigrationService;

header('Content-Type: application/json');

if (empty($_SESSION['authenticated'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}
// Platform tool: the Developer role only (not a clinic Administrator).
if (($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') !== 'Developer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Developer role required']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$action = (string) ($_GET['action'] ?? $body['action'] ?? $_POST['action'] ?? '');
nxRequireCsrfForMutations($action, ['enc_status']);

try {
    $svc = new EncryptionMigrationService(getDB());

    if ($action === 'enc_status') {
        echo json_encode(['success' => true] + $svc->status());
        exit;
    }

    if ($action === 'enc_migrate') {
        // Short slices so a shared host never hits its time limit; the page calls again until done.
        $res = $svc->migrate(15, !empty($body['encrypt_plaintext']), !empty($body['dry_run']));
        if (isset($res['error'])) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => $res['error']]);
            exit;
        }
        try {
            $uid = (string) ($_SESSION['user_id'] ?? '');
            if ($res['migrated'] > 0 && empty($res['dry_run']) && function_exists('getDB')) {
                $tenant = \ClinicFlow\Shared\TenantContext::getTenantId();
                getDB()->prepare("INSERT INTO audit_logs (id, tenant_id, user_id, user_name, user_role, action, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([\ClinicFlow\Utils\Uuid::uuidv7(), $tenant, $uid, (string) ($_SESSION['user_name'] ?? ''), 'Developer',
                        'ENCRYPTION_MIGRATED', 'Re-encrypted ' . $res['migrated'] . ' value(s) under the configured app_key', $_SERVER['REMOTE_ADDR'] ?? '']);
            }
        } catch (\Throwable $e) {
            // auditing must never block the work
        }
        echo json_encode(['success' => true] + $res);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (\Throwable $e) {
    error_log('[encryption_actions] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not complete the request.']);
}
