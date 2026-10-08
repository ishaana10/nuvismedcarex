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
    $stmt = $pdo->prepare("SELECT * FROM activities WHERE tenant_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $tenantId, PDO::PARAM_STR);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
    echo json_encode(['error' => 'Failed to fetch activities: ' . $e->getMessage()]);
}
