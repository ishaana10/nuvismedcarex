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

    // Tag the new appointment with the active clinic location (best-effort; a
    // missing column or no configured location must never block a booking).
    try {
        if (!empty($result['id'])) {
            $tid = \ClinicFlow\Shared\TenantContext::getTenantId();
            $locSvc = Container::getInstance()->get(\ClinicFlow\Services\LocationService::class);
            $locId = $_POST['location_id'] ?? $locSvc->getCurrentLocationId($tid);
            if (!empty($locId)) {
                $upd = getDB()->prepare("UPDATE appointments SET location_id = ? WHERE id = ? AND tenant_id = ?");
                $upd->execute([$locId, $result['id'], $tid]);
            }
        }
    } catch (\Throwable $ignore) {
        // location tagging is optional
    }

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
