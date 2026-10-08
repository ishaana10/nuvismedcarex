<?php
/**
 * Edit Appointment POST Handler
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();
validateCsrfRequest();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../appointment.php");
    exit;
}

use ClinicFlow\Shared\TenantContext;

$tenantId = TenantContext::getTenantId();
$appointmentId = $_POST['appointment_id'] ?? '';
$doctorId = $_POST['doctor_id'] ?? '';
$appointmentDate = $_POST['appointment_date'] ?? date('Y-m-d');
$time = $_POST['time'] ?? '09:30 AM';
$type = $_POST['type'] ?? 'Consultation';
$status = $_POST['status'] ?? 'Scheduled';
$notes = trim($_POST['notes'] ?? '');

$pdo = getDB();

// Verify existing appointment exists for active tenant
$stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ? AND tenant_id = ?");
$stmt->execute([$appointmentId, $tenantId]);
$appt = $stmt->fetch();

if (!$appt) {
    setToast('Error', 'Appointment not found or access denied.', 'error');
    header("Location: ../appointment.php");
    exit;
}

// Get doctor details
$doctorName = $appt['doctor_name'];
if (!empty($doctorId)) {
    $dStmt = $pdo->prepare("SELECT * FROM doctors WHERE id = ? AND tenant_id = ?");
    $dStmt->execute([$doctorId, $tenantId]);
    $doctor = $dStmt->fetch();
    if ($doctor) {
        $doctorName = $doctor['name'];
    }
}

$timeSlot = "$time - 10:15 AM";

$updateStmt = $pdo->prepare("UPDATE appointments SET doctor_id = ?, doctor_name = ?, appointment_date = ?, time = ?, time_slot = ?, type = ?, status = ?, notes = ? WHERE id = ? AND tenant_id = ?");
$updateStmt->execute([
    $doctorId ?: $appt['doctor_id'],
    $doctorName,
    $appointmentDate,
    $time,
    $timeSlot,
    $type,
    $status,
    $notes,
    $appointmentId,
    $tenantId
]);

// Also update corresponding queue item status or time if found
try {
    $qUpdateStmt = $pdo->prepare("UPDATE queue SET time = ?, doctor_name = ? WHERE patient_id = ? AND tenant_id = ? AND (status != 'Completed' OR status IS NULL)");
    $qUpdateStmt->execute([$time, $doctorName, $appt['patient_id'], $tenantId]);
} catch (\Throwable $e) {
    error_log("Failed to sync queue on appointment edit: " . $e->getMessage());
}

setToast("Appointment Updated", "Appointment for {$appt['patient_name']} was updated successfully.");
header("Location: ../appointment.php");
exit;
