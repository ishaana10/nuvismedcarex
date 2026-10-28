<?php
/**
 * Record a consent a patient has signed during an encounter, or withdraw one.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\ConsentForms;
use ClinicFlow\Services\ConsentRecords;

if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}

$patientId = (string) ($_POST['patient_id'] ?? '');
$visitId = (string) ($_POST['visit_id'] ?? '');
$back = '../clinical_visit.php?patient_id=' . urlencode($patientId) . ($visitId !== '' ? '&visit_id=' . urlencode($visitId) : '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not saved', 'That request expired. Reload the page and try again.', 'error');
    header("Location: {$back}");
    exit;
}

$action = (string) ($_POST['action'] ?? 'sign');
requireAccess('encounters', $action === 'withdraw' ? 'full' : 'edit');

$pdo = getDB();
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$records = new ConsentRecords($pdo, $tenantId);

if ($action === 'withdraw') {
    $recordId = (string) ($_POST['record_id'] ?? '');
    if ($records->withdraw($recordId, (string) ($_POST['reason'] ?? ''))) {
        setToast('Consent withdrawn', 'The record is kept and marked as withdrawn.');
    } else {
        setToast('Nothing changed', 'That consent could not be found, or was already withdrawn.', 'info');
    }
    header("Location: {$back}");
    exit;
}

// The patient must belong to this clinic, whatever was posted
$pStmt = $pdo->prepare("SELECT id, first_name, last_name FROM patients WHERE id = ? AND tenant_id = ? LIMIT 1");
$pStmt->execute([$patientId, $tenantId]);
if (!$pStmt->fetch()) {
    setToast('Not saved', 'That patient is not in this clinic.', 'error');
    header("Location: {$back}");
    exit;
}

$form = (new ConsentForms($pdo, $tenantId))->find((string) ($_POST['form_id'] ?? ''));
if (!$form) {
    setToast('Not saved', 'That consent form no longer exists.', 'error');
    header("Location: {$back}");
    exit;
}

try {
    $records->record($form, $patientId, $visitId, $_POST);
    setToast('Consent signed', $form['name'] . ' has been added to this encounter.');
} catch (\InvalidArgumentException | \RuntimeException $e) {
    setToast('Not saved', $e->getMessage(), 'error');
} catch (\Throwable $e) {
    error_log('[consent_record_save] ' . $e->getMessage());
    setToast('Not saved', 'The consent could not be saved. Try again, or contact your administrator.', 'error');
}

header("Location: {$back}");
exit;
