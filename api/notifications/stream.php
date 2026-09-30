<?php
/**
 * Server-Sent Events (SSE) Real-Time Notification Stream Endpoint
 * Pushes instant patient registration notifications to doctor dashboards with multi-tenant isolation.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

// Set SSE Headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Disable output buffering
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

// Authentication Check
if (empty($_SESSION['authenticated'])) {
    echo "data: " . json_encode(['error' => 'Unauthorized', 'status' => 'unauthenticated']) . "\n\n";
    flush();
    exit;
}

$pdo = getDB();
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// Helper to check if SSE is enabled for the active tenant
function checkSseEnabled(PDO $pdo, string $tenantId): bool {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = :tid AND setting_key = 'sse_notifications_enabled' LIMIT 1");
        $stmt->execute(['tid' => $tenantId]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) {
            return $val === '1' || $val === 'true';
        }
    } catch (\Throwable $e) {}

    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'sse_notifications_enabled' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) {
            return $val === '1' || $val === 'true';
        }
    } catch (\Throwable $e) {}

    return true;
}

// If SSE notifications are deactivated for this tenant, notify frontend and close
if (!checkSseEnabled($pdo, $tenantId)) {
    echo "data: " . json_encode(['status' => 'disabled', 'enabled' => false, 'tenant_id' => $tenantId]) . "\n\n";
    flush();
    exit;
}

// Send initial connection event
echo "data: " . json_encode([
    'status' => 'connected',
    'enabled' => true,
    'tenant_id' => $tenantId,
    'timestamp' => date('Y-m-d H:i:s')
]) . "\n\n";
flush();

// Track time window for new patient registrations
$lastCheck = date('Y-m-d H:i:s', time() - 3);
$sentIds = [];

// Loop duration limit (25 seconds per HTTP request loop to prevent server process timeouts)
$startTime = time();
$maxDuration = 25;

while ((time() - $startTime) < $maxDuration) {
    if (connection_aborted()) {
        break;
    }

    // Re-verify enablement
    if (!checkSseEnabled($pdo, $tenantId)) {
        echo "data: " . json_encode(['status' => 'disabled', 'enabled' => false, 'tenant_id' => $tenantId]) . "\n\n";
        flush();
        break;
    }

    try {
        // Query new patients registered strictly for this tenant
        $stmt = $pdo->prepare("
            SELECT id, mrn, first_name, last_name, created_at
            FROM patients
            WHERE tenant_id = :tid AND created_at >= :last_check
            ORDER BY created_at ASC
        ");
        $stmt->execute([
            'tid' => $tenantId,
            'last_check' => $lastCheck
        ]);
        $newPatients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($newPatients as $p) {
            if (in_array($p['id'], $sentIds, true)) {
                continue;
            }

            $sentIds[] = $p['id'];
            $fullName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
            $mrn = $p['mrn'] ?? '#00000';

            $payload = [
                'title' => 'New Patient Registered',
                'message' => "Patient {$fullName} ({$mrn}) is waiting.",
                'patient_id' => $p['id'],
                'patient_name' => $fullName,
                'mrn' => $mrn,
                'timestamp' => $p['created_at'] ?? date('Y-m-d H:i:s'),
                'tenant_id' => $tenantId,
                'enabled' => true
            ];

            echo "data: " . json_encode($payload) . "\n\n";
            flush();

            if (!empty($p['created_at'])) {
                $lastCheck = $p['created_at'];
            }
        }
    } catch (\Throwable $e) {
        error_log("SSE Stream Query Error: " . $e->getMessage());
    }

    // Ping keep-alive
    echo ": ping\n\n";
    flush();

    sleep(2);
}
