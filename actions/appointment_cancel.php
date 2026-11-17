<?php
/**
 * Cancel an appointment (keeps the record; status becomes Cancelled).
 * Removing an appointment altogether is a separate, more restricted action.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();
validateCsrfRequest();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../appointment.php');
    exit;
}

$id = (string) ($_POST['appointment_id'] ?? '');
if ($id === '') {
    setToast('Error', 'Invalid appointment ID.', 'error');
    header('Location: ../appointment.php');
    exit;
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE appointments SET status = 'Cancelled', updated_at = CURRENT_TIMESTAMP
                            WHERE id = ? AND tenant_id = ? AND status NOT IN ('Completed', 'Cancelled')");
    $stmt->execute([$id, \ClinicFlow\Shared\TenantContext::getTenantId()]);
    if ($stmt->rowCount() > 0) {
        setToast('Appointment Cancelled', 'The appointment was cancelled and is kept in the record.');
    } else {
        setToast('Nothing to cancel', 'That appointment is already completed or cancelled.', 'error');
    }
} catch (\Throwable $e) {
    error_log('[appointment_cancel] ' . $e->getMessage());
    setToast('Error', 'Could not cancel the appointment.', 'error');
}

header('Location: ../appointment.php');
exit;
