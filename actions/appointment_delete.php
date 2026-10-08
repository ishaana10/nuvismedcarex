<?php
/**
 * Delete Appointment POST Handler
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

$pdo = getDB();

// Verify appointment exists and belongs to active tenant
$stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ? AND tenant_id = ?");
$stmt->execute([$appointmentId, $tenantId]);
$appt = $stmt->fetch();

if (!$appt) {
    setToast('Error', 'Appointment not found or access denied.', 'error');
    header("Location: ../appointment.php");
    exit;
}

$delStmt = $pdo->prepare("DELETE FROM appointments WHERE id = ? AND tenant_id = ?");
$delStmt->execute([$appointmentId, $tenantId]);

// Log activity
$actStmt = $pdo->prepare("INSERT INTO activities (id, tenant_id, type, title, detail, timestamp, badge_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
$actStmt->execute([
    "act-" . time(),
    $tenantId,
    "appointment_cancel",
    "Appointment Removed: {$appt['patient_name']}",
    "Appointment on {$appt['appointment_date']} at {$appt['time']} removed.",
    date('Y-m-d H:i:s'),
    "red"
]);

setToast("Appointment Removed", "Appointment for {$appt['patient_name']} has been removed.");
header("Location: ../appointment.php");
exit;
