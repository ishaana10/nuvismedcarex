<?php
/**
 * AJAX Endpoint for Loading More Tenant-Scoped Activities
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Shared\TenantContext;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

$tenantId = TenantContext::getTenantId();
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 5;

try {
    $pdo = getDB();

    // Safely interpolate integer limit and offset to avoid MySQL PDO string quoting issue (LIMIT '5')
    $intLimit = (int)$limit;
    $intOffset = (int)$offset;

    $stmt = $pdo->prepare("SELECT * FROM activities WHERE tenant_id = ? ORDER BY created_at DESC, id DESC LIMIT {$intLimit} OFFSET {$intOffset}");
    $stmt->execute([$tenantId]);
    $rawActivities = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $activities = [];
    foreach ($rawActivities as $act) {
        $timestamp = !empty($act['created_at']) ? strtotime($act['created_at']) : (is_numeric($act['timestamp']) ? (int)$act['timestamp'] : strtotime($act['timestamp']));
        if ($timestamp) {
            $diff = time() - $timestamp;
            if ($diff < 60) $formattedTime = 'Just now';
            elseif ($diff < 3600) $formattedTime = floor($diff / 60) . 'm ago';
            elseif ($diff < 86400) $formattedTime = floor($diff / 3600) . 'h ago';
            elseif ($diff < 604800) $formattedTime = floor($diff / 86400) . 'd ago';
            else $formattedTime = date('M j, Y', $timestamp);
        } else {
            $formattedTime = $act['timestamp'] ?? 'Recently';
        }
        $act['formatted_time'] = $formattedTime;
        $activities[] = $act;
    }

    // Check if there are more records after this batch
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activities WHERE tenant_id = ?");
    $countStmt->execute([$tenantId]);
    $totalCount = (int)$countStmt->fetchColumn();

    $hasMore = ($offset + count($activities)) < $totalCount;

    echo json_encode([
        'success' => true,
        'activities' => $activities,
        'has_more' => $hasMore,
        'total' => $totalCount,
        'next_offset' => $offset + count($activities)
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch activities: ' . $e->getMessage()
    ]);
}
