<?php
/**
 * Clinical Encounter Save / Prescriptions / Finalize Handler
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\EncounterService;
use ClinicFlow\Services\BillingService;
use ClinicFlow\Shared\Logger;

requireAuth();
validateCsrfRequest();

$pdo = getDB();
$encounterService = Container::getInstance()->get(EncounterService::class);
$billingService = Container::getInstance()->get(BillingService::class);
$logger = Container::getInstance()->get(Logger::class);

$action = $_REQUEST['action'] ?? 'save';
$patientId = $_REQUEST['patient_id'] ?? '';
$visitId = $_REQUEST['visit_id'] ?? '';

if ($patientId === '') {
    header("Location: ../patients.php");
    exit;
}

if ($action === 'delete_rx') {
    $rxId = $_GET['rx_id'] ?? '';
    if ($rxId !== '') {
        try {
            $stmt = $pdo->prepare("DELETE FROM prescriptions WHERE id = ? AND patient_id = ?");
            $stmt->execute([$rxId, $patientId]);
            setToast("Medication Removed", "Prescription line removed.", "info");
        } catch (\Throwable $e) {
            $logger->error("Error deleting rx: " . $e->getMessage());
            setToast("Error", "Could not delete prescription line.", "error");
        }
    }
    header("Location: ../clinical_visit.php?patient_id=$patientId&visit_id=$visitId");
    exit;
}

if ($action === 'copy_rx') {
    $rxId = $_GET['rx_id'] ?? '';
    if ($rxId !== '') {
        try {
            $stmt = $pdo->prepare("SELECT * FROM prescriptions WHERE id = ? AND patient_id = ?");
            $stmt->execute([$rxId, $patientId]);
            $oldRx = $stmt->fetch();

            if ($oldRx) {
                $newRxId = "rx-" . time() . '-' . rand(100, 999);
                $copyStmt = $pdo->prepare("INSERT INTO prescriptions (id, patient_id, visit_id, medication_name, dosage, frequency, duration, instructions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $copyStmt->execute([
                    $newRxId,
                    $patientId,
                    $visitId,
                    $oldRx['medication_name'],
                    $oldRx['dosage'],
                    $oldRx['frequency'],
                    $oldRx['duration'],
                    $oldRx['instructions']
                ]);
                setToast("Medication Copied", "Copied " . $oldRx['medication_name'] . " to current encounter.");
            }
        } catch (\Throwable $e) {
            $logger->error("Error copying rx: " . $e->getMessage());
            setToast("Error", "Could not copy prescription.", "error");
        }
    }
    header("Location: ../clinical_visit.php?patient_id=$patientId&visit_id=$visitId");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create_invoice') {
        try {
            $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
            $stmt->execute([$patientId]);
            $patient = $stmt->fetch();

            if ($patient) {
                $serviceDesc = trim($_POST['service_description'] ?? 'Clinical Consultation & Examination');
                $amount = (float)($_POST['amount'] ?? 150.00);
                $insuranceCovered = (float)($_POST['insurance_covered'] ?? 0.00);
                $serviceDate = trim($_POST['service_date'] ?? date('Y-m-d'));
                $dueDate = trim($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));

                $invoiceData = [
                    'patient_id' => $patientId,
                    'patient_name' => $patient['first_name'] . ' ' . $patient['last_name'],
                    'patient_mrn' => $patient['mrn'],
                    'service_date' => $serviceDate,
                    'due_date' => $dueDate,
                    'amount' => $amount,
                    'insurance_covered' => $insuranceCovered,
                    'services' => [$serviceDesc]
                ];

                $inv = $billingService->createInvoice($invoiceData);
                setToast("Invoice Created", "Invoice {$inv['invoice_number']} generated for " . $patient['first_name'] . ' ' . $patient['last_name'] . " ($" . number_format($inv['patient_owed'], 2) . " owed).");
            }
        } catch (\Throwable $e) {
            $logger->error("Error creating invoice in encounter_save: " . $e->getMessage());
            setToast("Error", "Could not create invoice.", "error");
        }

        $redirect = !empty($_POST['redirect_to']) ? $_POST['redirect_to'] : "../clinical_visit.php?patient_id=$patientId&visit_id=$visitId";
        if (!empty($_GET['redirect_to'])) {
            $redirect = $_GET['redirect_to'];
        }
        if (stristr($redirect, 'patient_detail.php') !== false) {
            header("Location: ../patient_detail.php?id=$patientId");
        } else {
            header("Location: ../clinical_visit.php?patient_id=$patientId&visit_id=$visitId");
        }
        exit;
    }

    if ($action === 'edit_rx') {
        $rxId = trim($_POST['rx_id'] ?? '');
        $medName = trim($_POST['medication_name'] ?? '');
        $dosage = trim($_POST['dosage'] ?? '');
        $frequency = trim($_POST['frequency'] ?? '');
        $duration = trim($_POST['duration'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');

        if ($rxId !== '' && $medName !== '') {
            try {
                $updateStmt = $pdo->prepare("UPDATE prescriptions SET medication_name = ?, dosage = ?, frequency = ?, duration = ?, instructions = ? WHERE id = ? AND patient_id = ?");
                $updateStmt->execute([$medName, $dosage, $frequency, $duration, $instructions, $rxId, $patientId]);
                setToast("Medication Updated", "Prescription line updated successfully.");
            } catch (\Throwable $e) {
                $logger->error("Error updating rx: " . $e->getMessage());
                setToast("Error", "Could not update prescription.", "error");
            }
        }
        header("Location: ../clinical_visit.php?patient_id=$patientId&visit_id=$visitId");
        exit;
    }

    if (isset($_POST['add_rx'])) {
        $medName = trim($_POST['medication_name'] ?? '');
        $dosage = trim($_POST['dosage'] ?? '');
        $frequency = trim($_POST['frequency'] ?? '');
        $duration = trim($_POST['duration'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');

        if ($medName !== '') {
            try {
                $rxId = "rx-" . time() . '-' . rand(100, 999);
                $stmt = $pdo->prepare("INSERT INTO prescriptions (id, patient_id, visit_id, medication_name, dosage, frequency, duration, instructions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$rxId, $patientId, $visitId, $medName, $dosage, $frequency, $duration, $instructions]);
                setToast("Medication Added", "$medName $dosage added to prescription.");
            } catch (\Throwable $e) {
                $logger->error("Error adding rx: " . $e->getMessage());
                setToast("Error", "Could not add prescription line.", "error");
            }
        }
        header("Location: ../clinical_visit.php?patient_id=$patientId&visit_id=$visitId");
        exit;
    }

    if ($action === 'save' || $action === 'finish') {
        $vitalsData = [
            'blood_pressure' => trim($_POST['blood_pressure'] ?? '120/80'),
            'heart_rate' => (int)($_POST['heart_rate'] ?? 72),
            'temperature' => (float)($_POST['temperature'] ?? 98.6),
            'oxygen_sat' => (int)($_POST['oxygen_sat'] ?? 99)
        ];

        $soapData = [
            'subjective' => trim($_POST['subjective'] ?? ''),
            'objective' => trim($_POST['objective'] ?? ''),
            'icd_code' => trim($_POST['icd_code'] ?? 'J01.90'),
            'plan' => trim($_POST['plan'] ?? '')
        ];

        try {
            $encounterService->saveEncounterData($patientId, $vitalsData, $soapData);

            if ($action === 'finish') {
                $encounterService->finalizeEncounter($patientId, $visitId, [
                    'create_invoice' => !empty($_POST['create_invoice_on_finalize']),
                    'service_description' => trim($_POST['service_description'] ?? 'Clinical Consultation & Examination'),
                    'amount' => (float)($_POST['amount'] ?? 150.00),
                    'insurance_covered' => (float)($_POST['insurance_covered'] ?? 0.00)
                ]);

                setToast("Visit Finalized!", "Encounter for patient has been finalized.");
                header("Location: ../patient_detail.php?id=$patientId");
                exit;
            } else {
                setToast("Changes Saved", "Vitals and SOAP notes updated successfully.");
                header("Location: ../clinical_visit.php?patient_id=$patientId&visit_id=$visitId");
                exit;
            }
        } catch (\Throwable $e) {
            $logger->error("Error saving encounter: " . $e->getMessage());
            setToast("Error", "Could not save encounter data.", "error");
            header("Location: ../clinical_visit.php?patient_id=$patientId&visit_id=$visitId");
            exit;
        }
    }
}
