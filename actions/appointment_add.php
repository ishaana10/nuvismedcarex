<?php
/**
 * Book Appointment Form POST Handler (Refactored Thin Orchestration Layer)
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

try {
    $appointmentService = Container::getInstance()->get(AppointmentService::class);
    $result = $appointmentService->scheduleAppointment($_POST);

    setToast("Appointment Scheduled", "Appointment for {$result['patient_name']} on {$result['appointment_date']} at {$result['time']} booked.");
    header("Location: ../appointment.php");
    exit;
} catch (\InvalidArgumentException $e) {
    setToast('Error', $e->getMessage(), 'error');
    header("Location: ../appointment.php?action=book");
    exit;
} catch (\Throwable $e) {
    setToast('Error', 'Failed to schedule appointment: ' . $e->getMessage(), 'error');
    header("Location: ../appointment.php?action=book");
    exit;
}
