<?php
/**
 * Action: Medical Certificate Save / Issue
 */

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();
validateCsrfRequest();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../patients.php');
    exit();
}

$patientId = trim($_POST['patient_id'] ?? '');
$visitId = trim($_POST['visit_id'] ?? '');
$issueDate = trim($_POST['issue_date'] ?? date('Y-m-d'));
$diagnosis = trim($_POST['diagnosis'] ?? '');
$fitnessStatus = trim($_POST['fitness_status'] ?? 'Fit for Work / School');
$fitStatusDetails = trim($_POST['fit_status_details'] ?? '');
$recommendations = trim($_POST['recommendations'] ?? '');
$doctorName = trim($_POST['doctor_name'] ?? $_SESSION['user_name'] ?? 'Attending Physician');
$prcNumber = trim($_POST['prc_number'] ?? '');
$ptrNumber = trim($_POST['ptr_number'] ?? '');

if (!$patientId || !$diagnosis) {
    setToast('Error', 'Patient ID and Diagnosis are required.', 'error');
    header('Location: ' . (nxSafeReferer('../patients.php')));
    exit();
}

$pdo = getDB();
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// The patient must belong to this clinic (a posted id is not proof of that).
$own = $pdo->prepare("SELECT id FROM patients WHERE id = ? AND tenant_id = ? LIMIT 1");
$own->execute([$patientId, $tenantId]);
if (!$own->fetchColumn()) {
    setToast('Not found', 'That patient is not in this clinic.', 'error');
    header('Location: ../patients.php');
    exit();
}

// Generate Certificate Number (MC-YYYYMMDD-XXXX)
$certSeq = sprintf("%04d", rand(1, 9999));
$certNum = 'MC-' . date('Ymd') . '-' . $certSeq;
$certId = 'mc-' . uniqid();

// Optional "clinic form" fields. Only written when the column exists, so issuing
// still works on a database where the migration has not run yet.
$cols = ['id', 'tenant_id', 'certificate_number', 'patient_id', 'visit_id', 'issue_date', 'diagnosis', 'fitness_status', 'fit_status_details', 'recommendations', 'doctor_name', 'prc_number', 'ptr_number'];
$vals = [$certId, $tenantId, $certNum, $patientId, $visitId ?: null, $issueDate, $diagnosis, $fitnessStatus, $fitStatusDetails, $recommendations, $doctorName, $prcNumber, $ptrNumber];

$toDate = static function (string $v): ?string {
    $v = trim($v);
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
};
$allowed = \ClinicFlow\Services\DocumentTemplateSettings::categories(
    (new \ClinicFlow\Services\DocumentTemplateSettings($pdo, $tenantId))->load('certificate')
);
$picked = array_values(array_intersect($allowed, array_map('strval', (array) ($_POST['problem_category'] ?? []))));
$extras = [
    'problem_category' => $picked ? implode(', ', $picked) : null,
    'illness_from'     => $toDate((string) ($_POST['illness_from'] ?? '')),
    'resume_on'        => $toDate((string) ($_POST['resume_on'] ?? '')),
    'review_on'        => $toDate((string) ($_POST['review_on'] ?? '')),
];
try {
    $have = $pdo->query("SHOW COLUMNS FROM medical_certificates")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $have = [];
}
foreach ($extras as $col => $val) {
    if (in_array($col, $have, true)) { $cols[] = $col; $vals[] = $val; }
}

$stmt = $pdo->prepare('INSERT INTO medical_certificates (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
$stmt->execute($vals);

setToast('Success', 'Medical Certificate issued successfully.', 'success');

// Redirect to print page immediately or back to reference page
if (isset($_POST['print_immediately']) && $_POST['print_immediately'] === '1') {
    header('Location: ../print_medical_certificate.php?id=' . urlencode($certId));
} else {
    header('Location: ' . (nxSafeReferer('../patient_detail.php?id=' . urlencode($patientId))));
}
exit();
