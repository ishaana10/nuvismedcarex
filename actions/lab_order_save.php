<?php
/**
 * Action Handler for Lab Orders
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\LabOrderService;
use ClinicFlow\Shared\Logger;

requireAuth();
validateCsrfRequest();

$labOrderService = Container::getInstance()->get(LabOrderService::class);
$logger = Container::getInstance()->get(Logger::class);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$patientId = $_POST['patient_id'] ?? $_GET['patient_id'] ?? '';
$visitId = $_POST['visit_id'] ?? $_GET['visit_id'] ?? '';

if ($action === 'create_lab_order') {
    $testName = trim($_POST['test_name'] ?? '');
    $orderType = trim($_POST['order_type'] ?? 'Lab');
    $category = trim($_POST['category'] ?? 'General');

    if (!empty($patientId) && !empty($testName)) {
        try {
            $labOrderService->createOrder($patientId, $testName, $orderType, $category);
            setToast('Lab Order Created', "Ordered '{$testName}' for patient.");
        } catch (\Throwable $e) {
            $logger->error("Error creating lab order: " . $e->getMessage());
            setToast('Error', 'Could not create lab order.', 'error');
        }
    } else {
        setToast('Validation Error', 'Patient ID and Test Name are required.', 'error');
    }
}

if ($action === 'attach_results') {
    $orderId = trim($_POST['order_id'] ?? '');
    $results = trim($_POST['results'] ?? '');
    $isAbnormal = !empty($_POST['is_abnormal']);

    if (!empty($orderId) && !empty($results)) {
        try {
            $labOrderService->attachResults($orderId, $results, $isAbnormal);
            setToast('Results Updated', 'Lab order results attached successfully.');
        } catch (\Throwable $e) {
            $logger->error("Error attaching lab results: " . $e->getMessage());
            setToast('Error', 'Could not attach lab results.', 'error');
        }
    }
}

$redirect = !empty($visitId)
    ? "../clinical_visit.php?patient_id={$patientId}&visit_id={$visitId}"
    : (!empty($patientId) ? "../patient_detail.php?id={$patientId}" : "../patients.php");

header("Location: " . $redirect);
exit;
