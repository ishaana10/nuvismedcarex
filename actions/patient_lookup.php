<?php
/**
 * Patient picker lookup (appointment booking).
 * GET ?q=term -> JSON { patients: [{id,name,mrn,phone,summary}] }, this clinic only.
 * Identity details only: no clinical information, so the front desk may use it.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}
if (!nxCan('patients', 'view')) {
    http_response_code(403);
    echo json_encode(['error' => 'No access']);
    exit;
}

$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
try {
    $pdo = getDB();
    $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
    $sql = "SELECT id, first_name, last_name, mrn, phone, gender, age, dob FROM patients WHERE tenant_id = :tid AND is_active = 1";
    $params = ['tid' => $tenantId];
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $idClause = \ClinicFlow\Services\PatientIdentifiers::searchClause($pdo, 'patients.id', 'patients.tenant_id', 's6');
        $sql .= " AND (first_name LIKE :s1 OR last_name LIKE :s2 OR mrn LIKE :s3 OR phone LIKE :s4 OR CONCAT(first_name, ' ', last_name) LIKE :s5"
              . ($idClause ? " OR {$idClause}" : '') . ")";
        $params += ['s1' => $like, 's2' => $like, 's3' => $like, 's4' => $like, 's5' => $like];
        if ($idClause) $params['s6'] = $like;
    }
    $stmt = $pdo->prepare($sql . " ORDER BY last_name ASC, first_name ASC LIMIT 15");
    $stmt->execute($params);
    $out = array_map(static fn(array $p): array => [
        'id'      => $p['id'],
        'name'    => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')),
        'mrn'     => $p['mrn'] ?? '',
        'phone'   => $p['phone'] ?? '',
        'summary' => trim(($p['age'] ?? '') . ' yrs · ' . ($p['gender'] ?? ''), ' ·'),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    echo json_encode(['patients' => $out]);
} catch (\Throwable $e) {
    error_log('[patient_lookup] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['patients' => []]);
}
