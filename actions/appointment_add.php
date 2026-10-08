<?php
/**
 * Book Appointment Form POST Handler
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
$patientId = $_POST['patient_id'] ?? '';
$doctorId = $_POST['doctor_id'] ?? 'doc-1';
$appointmentDate = $_POST['appointment_date'] ?? date('Y-m-d');
$time = $_POST['time'] ?? '09:30 AM';
$type = $_POST['type'] ?? 'Consultation';
$notes = trim($_POST['notes'] ?? '');

$pdo = getDB();

// Get patient details
$pStmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND tenant_id = ?");
$pStmt->execute([$patientId, $tenantId]);
$patient = $pStmt->fetch();

if (!$patient) {
    setToast('Error', 'Please select a valid patient.', 'error');
    header("Location: ../appointment.php?action=book");
    exit;
}

// Get doctor details
$dStmt = $pdo->prepare("SELECT * FROM doctors WHERE id = ? AND tenant_id = ?");
$dStmt->execute([$doctorId, $tenantId]);
$doctor = $dStmt->fetch();
$doctorName = $doctor['name'] ?? 'Dr. Jenkins';

$aptId = "apt-" . time() . '-' . bin2hex(random_bytes(3));
$patientName = $patient['first_name'] . ' ' . $patient['last_name'];
$timeSlot = "$time - 10:15 AM";

$stmt = $pdo->prepare("INSERT INTO appointments (id, tenant_id, patient_id, patient_name, patient_mrn, patient_avatar, patient_initials, doctor_id, doctor_name, appointment_date, time, time_slot, type, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$stmt->execute([
    $aptId, $tenantId, $patientId, $patientName, $patient['mrn'], $patient['avatar'], $patient['initials'],
    $doctorId, $doctorName, $appointmentDate, $time, $timeSlot, $type, 'Waiting', $notes
]);

// Also add item to Queue
$qId = "q-" . time() . '-' . bin2hex(random_bytes(3));
$qStmt = $pdo->prepare("INSERT INTO queue (id, tenant_id, patient_id, patient_name, mrn, time, doctor_name, status, check_in_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$qStmt->execute([
    $qId, $tenantId, $patientId, $patientName, $patient['mrn'], $time, $doctorName, 'Waiting', date('h:i A')
]);

// Add Activity log
$actStmt = $pdo->prepare("INSERT INTO activities (id, tenant_id, type, title, detail, timestamp, badge_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
$actStmt->execute([
    "act-" . time(),
    $tenantId,
    "appointment_booked",
    "Appointment Booked: $patientName",
    "For $time with $doctorName",
    date('Y-m-d H:i:s'),
    "blue"
]);

setToast("Appointment Scheduled", "Appointment for $patientName on $appointmentDate at $time booked.");
header("Location: ../appointment.php");
exit;
