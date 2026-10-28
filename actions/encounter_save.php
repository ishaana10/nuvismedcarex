<?php
/**
 * Clinical Encounter Save / Prescriptions / Finalize Handler
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/encounter_invoice.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\EncounterService;
use ClinicFlow\Services\BillingService;
use ClinicFlow\Services\ClinicalAlertService;
use ClinicFlow\Shared\Logger;

requireAuth();
validateCsrfRequest();

/** What to tell the user about an invoice, and the claim it raised. */
function nxInvoiceToastLine(array $result, array $patient): string
{
    $cur = nxCurrencySymbol();
    $who = trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
    $line = $result['invoice_number'] . ' for ' . $who . ', ' . $cur . number_format($result['total'], 2) . '.';
    if ($result['insurance'] > 0) {
        $line .= ' ' . $cur . number_format($result['patient_owed'], 2) . ' paid at the desk, '
            . $cur . number_format($result['insurance'], 2) . ' to ' . ($result['provider'] ?: 'the insurer') . '.';
        $line .= match ($result['claim_action']) {
            'created' => ' A claim was raised automatically.',
            'updated' => ' The existing claim was updated.',
            'failed'  => ' The claim could not be raised — add it under Billing → Insurance claims.',
            'skipped' => ' No claim was raised: choose the insurance provider to raise one.',
            default   => '',
        };
    }
    return $line;
}

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
            // Scoped to this clinic: a posted id must not reach another's records
            $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$patientId, \ClinicFlow\Shared\TenantContext::getTenantId()]);
            $patient = $stmt->fetch();

            if ($patient) {
                $result = nxCreateEncounterInvoice($pdo, $patient, $visitId, $_POST);
                setToast(
                    'Invoice created',
                    nxInvoiceToastLine($result, $patient)
                );
            } else {
                setToast('Not created', 'That patient is not in this clinic.', 'error');
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
                $alertService = Container::getInstance()->get(ClinicalAlertService::class);
                $alerts = $alertService->checkMedicationAllergies($patientId, $medName);

                $rxId = "rx-" . time() . '-' . rand(100, 999);
                $stmt = $pdo->prepare("INSERT INTO prescriptions (id, patient_id, visit_id, medication_name, dosage, frequency, duration, instructions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$rxId, $patientId, $visitId, $medName, $dosage, $frequency, $duration, $instructions]);

                if (!empty($alerts)) {
                    $alertMsg = implode(' ', array_column($alerts, 'message'));
                    setToast("Allergy Alert Warning!", $alertMsg, "error");
                } else {
                    setToast("Medication Added", "$medName $dosage added to prescription.");
                }
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
            'heart_rhythm' => trim($_POST['heart_rhythm'] ?? 'Regular'),
            'temperature' => (float)($_POST['temperature'] ?? 98.6),
            'weight' => (int)($_POST['weight'] ?? 145),
            'height' => (int)($_POST['height'] ?? 66),
            'respiratory_rate' => (int)($_POST['respiratory_rate'] ?? 16),
            'oxygen_sat' => (int)($_POST['oxygen_sat'] ?? 99),
            'sugar' => trim($_POST['sugar'] ?? '95 mg/dL')
        ];

        $icdInput = trim($_POST['icd_code'] ?? '');
        if (empty($icdInput)) {
            $icdInput = 'ENC-' . date('Ymd') . '-' . sprintf('%04d', rand(1, 9999));
        }

        $soapData = [
            'subjective' => trim($_POST['subjective'] ?? ''),
            'objective' => trim($_POST['objective'] ?? ''),
            'icd_code' => $icdInput,
            'plan' => trim($_POST['plan'] ?? '')
        ];

        try {
            $encounterService->saveEncounterData($patientId, $vitalsData, $soapData);

            if ($action === 'finish') {
                $createInvoiceOnFinalize = !empty($_POST['create_invoice_on_finalize']);
                $encounterService->finalizeEncounter($patientId, $visitId, [
                    'create_invoice' => false
                ]);

                if ($createInvoiceOnFinalize) {
                    try {
                        $pStmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND tenant_id = ? LIMIT 1");
                        $pStmt->execute([$patientId, \ClinicFlow\Shared\TenantContext::getTenantId()]);
                        $patientRow = $pStmt->fetch();

                        if ($patientRow) {
                            $result = nxCreateEncounterInvoice($pdo, $patientRow, $visitId, $_POST);
                            $_SESSION['nx_finalize_summary'] = nxInvoiceToastLine($result, $patientRow);
                        }
                    } catch (\Throwable $e) {
                        $logger->error("Error generating invoice on finalize: " . $e->getMessage());
                        $_SESSION['nx_finalize_summary'] = 'The visit was closed, but the invoice could not be raised. '
                            . 'Create it from the patient record, and tell your administrator.';
                    }
                }

                // Automatically send prescription email if prescriptions exist & patient has email
                try {
                    $pStmt = $pdo->prepare("SELECT first_name, last_name, email FROM patients WHERE id = ?");
                    $pStmt->execute([$patientId]);
                    $pRow = $pStmt->fetch();

                    if ($pRow && !empty($pRow['email'])) {
                        $rxStmt = $pdo->prepare("SELECT medication_name, dosage, frequency, duration, instructions FROM prescriptions WHERE patient_id = ? AND visit_id = ?");
                        $rxStmt->execute([$patientId, $visitId]);
                        $prescriptionsData = $rxStmt->fetchAll();

                        if (!empty($prescriptionsData)) {
                            $tenantId = $_SESSION['tenant_id'] ?? 'tenant-default';
                            $rxService = new \ClinicFlow\Services\PrescriptionVerificationService();
                            $token = $rxService->getOrCreateToken($pdo, $tenantId, $patientId, $visitId);
                            $url = $rxService->getVerificationUrl($token);
                            $emailService = Container::getInstance()->get(\ClinicFlow\Services\EmailService::class);
                            $emailService->sendPrescriptionEmail($pRow['email'], $pRow['first_name'] . ' ' . $pRow['last_name'], $prescriptionsData, $url, $token);
                        }
                    }
                } catch (\Throwable $e) {
                    $logger->warning("Automated prescription email error: " . $e->getMessage());
                }

                $finalizeLine = $_SESSION['nx_finalize_summary'] ?? '';
                unset($_SESSION['nx_finalize_summary']);
                setToast('Visit finalised', $finalizeLine !== '' ? $finalizeLine : 'The encounter has been closed and archived.');
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
