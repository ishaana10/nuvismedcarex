<?php
/**
 * Quick search for the command palette (Ctrl+K).
 * GET ?q=term → JSON { patients: [...], appointments: [...] }, scoped to the active tenant.
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

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['patients' => [], 'appointments' => []]);
    exit;
}
$q = mb_substr($q, 0, 80);

try {
    $pdo = getDB();
    $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    $stmt = $pdo->prepare(
        "SELECT id, first_name, last_name, mrn, phone, gender, age, known_allergies, avatar, initials
           FROM patients
          WHERE tenant_id = :tid
            AND (first_name LIKE :s1 OR last_name LIKE :s2 OR mrn LIKE :s3 OR phone LIKE :s4
                 OR CONCAT(first_name, ' ', last_name) LIKE :s5)
          ORDER BY last_name ASC, first_name ASC
          LIMIT 8"
    );
    $stmt->execute(['tid' => $tenantId, 's1' => $like, 's2' => $like, 's3' => $like, 's4' => $like, 's5' => $like]);

    $patients = array_map(static function (array $p): array {
        $allergies = trim((string) ($p['known_allergies'] ?? ''));
        return [
            'id'        => $p['id'],
            'name'      => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')),
            'mrn'       => $p['mrn'] ?? '',
            'phone'     => $p['phone'] ?? '',
            'summary'   => trim(($p['age'] ?? '') . ' yrs · ' . ($p['gender'] ?? ''), ' ·'),
            'allergies' => ($allergies !== '' && strcasecmp($allergies, 'NKDA') !== 0 && strcasecmp($allergies, 'None') !== 0) ? $allergies : '',
            'initials'  => $p['initials'] ?: mb_strtoupper(mb_substr($p['first_name'] ?? '', 0, 1) . mb_substr($p['last_name'] ?? '', 0, 1)),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

    $apptStmt = $pdo->prepare(
        "SELECT id, patient_id, patient_name, doctor_name, appointment_date, time, status
           FROM appointments
          WHERE tenant_id = :tid AND appointment_date >= :today
            AND (patient_name LIKE :s1 OR patient_mrn LIKE :s2)
          ORDER BY appointment_date ASC, time ASC
          LIMIT 4"
    );
    $apptStmt->execute(['tid' => $tenantId, 'today' => date('Y-m-d'), 's1' => $like, 's2' => $like]);
    $appointments = $apptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode(['patients' => $patients, 'appointments' => $appointments]);
} catch (\Throwable $e) {
    error_log('[quick_search] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Search is unavailable right now.']);
}
