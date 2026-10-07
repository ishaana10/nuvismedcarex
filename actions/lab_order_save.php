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
    $orderType = trim($_POST['order_type'] ?? 'Lab');
    $notes = trim($_POST['clinical_notes'] ?? '');

    $items = [];

    // 1. Parse structured checklist tests array
    $checklist = $_POST['checklist_tests'] ?? [];
    if (is_array($checklist)) {
        foreach ($checklist as $chk) {
            $parts = explode('|', $chk, 2);
            if (count($parts) === 2) {
                $cat = trim($parts[0]);
                $tName = trim($parts[1]);
                if (!empty($tName)) {
                    $items[] = [
                        'test_name' => $tName,
                        'category' => $cat,
                        'instructions' => ''
                    ];
                }
            }
        }
    }

    // 2. Parse custom multi-item array if submitted
    $rawItems = $_POST['items'] ?? [];
    if (is_array($rawItems)) {
        foreach ($rawItems as $raw) {
            $testName = trim($raw['test_name'] ?? '');
            if (!empty($testName)) {
                $items[] = [
                    'test_name' => $testName,
                    'category' => trim($raw['category'] ?? 'General'),
                    'instructions' => trim($raw['instructions'] ?? '')
                ];
            }
        }
    }

    // 3. Fallback single test_name if items array empty
    if (empty($items)) {
        $singleTest = trim($_POST['test_name'] ?? '');
        if (!empty($singleTest)) {
            $items[] = [
                'test_name' => $singleTest,
                'category' => trim($_POST['category'] ?? 'General'),
                'instructions' => trim($_POST['instructions'] ?? '')
            ];
        }
    }

    if (!empty($patientId) && !empty($items)) {
        try {
            $order = $labOrderService->createMultiItemOrder($patientId, $items, $orderType, $notes);
            setToast('Lab Order Created', "Created lab order with " . count($items) . " test item(s).");
        } catch (\Throwable $e) {
            $logger->error("Error creating lab order: " . $e->getMessage());
            setToast('Error', 'Could not create lab order: ' . $e->getMessage(), 'error');
        }
    } else {
        setToast('Validation Error', 'Patient ID and at least one Test Name are required.', 'error');
    }
}

if ($action === 'edit_lab_order' || $action === 'update_lab_order') {
    $orderId = trim($_POST['order_id'] ?? '');
    $notes = trim($_POST['clinical_notes'] ?? '');
    $status = trim($_POST['status'] ?? 'Ordered');
    $results = trim($_POST['results'] ?? '');
    $isAbnormal = !empty($_POST['is_abnormal']);

    $items = [];

    // 1. Parse structured checklist tests array
    $checklist = $_POST['checklist_tests'] ?? [];
    if (is_array($checklist)) {
        foreach ($checklist as $chk) {
            $parts = explode('|', $chk, 2);
            if (count($parts) === 2) {
                $cat = trim($parts[0]);
                $tName = trim($parts[1]);
                if (!empty($tName)) {
                    $items[] = [
                        'test_name' => $tName,
                        'category' => $cat,
                        'instructions' => '',
                        'status' => $status,
                        'results' => $results,
                        'is_abnormal' => $isAbnormal
                    ];
                }
            }
        }
    }

    // 2. Parse custom multi-item array if submitted
    $rawItems = $_POST['items'] ?? [];
    if (is_array($rawItems)) {
        foreach ($rawItems as $raw) {
            $testName = trim($raw['test_name'] ?? '');
            if (!empty($testName)) {
                $items[] = [
                    'test_name' => $testName,
                    'category' => trim($raw['category'] ?? 'General'),
                    'instructions' => trim($raw['instructions'] ?? ''),
                    'status' => trim($raw['status'] ?? $status ?? 'Ordered'),
                    'results' => trim($raw['results'] ?? $results ?? ''),
                    'is_abnormal' => !empty($raw['is_abnormal']) || $isAbnormal
                ];
            }
        }
    }

    // 3. Fallback if no array items passed, create single item from submitted order test name or default
    if (empty($items)) {
        $singleTest = trim($_POST['test_name'] ?? 'Lab Request');
        $items[] = [
            'test_name' => $singleTest,
            'category' => trim($_POST['category'] ?? 'General'),
            'instructions' => trim($_POST['instructions'] ?? ''),
            'status' => $status,
            'results' => $results,
            'is_abnormal' => $isAbnormal
        ];
    }

    if (!empty($orderId) && !empty($items)) {
        try {
            $labOrderService->updateOrder($orderId, $items, $notes, $status, $results, $isAbnormal);
            setToast('Lab Order Updated', 'Lab order and test items updated successfully.');
        } catch (\Throwable $e) {
            $logger->error("Error updating lab order: " . $e->getMessage());
            setToast('Error', 'Could not update lab order: ' . $e->getMessage(), 'error');
        }
    } else {
        setToast('Validation Error', 'Order ID and at least one test item are required.', 'error');
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
