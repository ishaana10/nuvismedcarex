<?php
/**
 * Edit Appointment POST Handler (Refactored Thin Orchestration Layer)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();
validateCsrfRequest();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../appointment.php");
    exit;
}

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\AppointmentService;

$id = $_POST['appointment_id'] ?? $_POST['id'] ?? '';

if (!$id) {
    setToast('Error', 'Invalid appointment ID.', 'error');
    header("Location: ../appointment.php");
    exit;
}

try {
    $appointmentService = Container::getInstance()->get(AppointmentService::class);
    $appointmentService->updateAppointment($id, $_POST);

    setToast("Appointment Updated", "Appointment details updated successfully.");
} catch (\Throwable $e) {
    setToast('Error', 'Failed to update appointment: ' . $e->getMessage(), 'error');
}

header("Location: ../appointment.php");
exit;
