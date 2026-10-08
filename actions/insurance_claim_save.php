<?php
/**
 * Action Handler for Insurance Claims
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\InsuranceService;
use ClinicFlow\Shared\Logger;

requireAuth();
validateCsrfRequest();

$insuranceService = Container::getInstance()->get(InsuranceService::class);
$logger = Container::getInstance()->get(Logger::class);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'create_claim') {
    $invoiceId = trim($_POST['invoice_id'] ?? '');
    $patientId = trim($_POST['patient_id'] ?? '');
    $providerName = trim($_POST['provider_name'] ?? 'National Health Insurance');
    $policyNumber = trim($_POST['policy_number'] ?? '');
    $claimAmount = (float)($_POST['claim_amount'] ?? 0.00);
    $notes = trim($_POST['notes'] ?? '');

    if (!empty($invoiceId) && !empty($patientId) && $claimAmount > 0) {
        try {
            $insuranceService->createClaim($invoiceId, $patientId, $providerName, $policyNumber, $claimAmount, $notes);
            setToast('Claim Submitted', "Insurance claim of $" . number_format($claimAmount, 2) . " submitted.");
        } catch (\Throwable $e) {
            $logger->error("Error creating insurance claim: " . $e->getMessage());
            setToast('Error', 'Could not create insurance claim.', 'error');
        }
    } else {
        setToast('Validation Error', 'Invoice ID, Patient ID, and positive Claim Amount are required.', 'error');
    }
}

if ($action === 'update_status') {
    $claimId = trim($_POST['claim_id'] ?? '');
    $status = trim($_POST['status'] ?? 'Submitted');
    $notes = trim($_POST['notes'] ?? '');

    if (!empty($claimId)) {
        try {
            $insuranceService->updateClaimStatus($claimId, $status, $notes);
            setToast('Claim Updated', "Insurance claim status updated to {$status}.");
        } catch (\Throwable $e) {
            $logger->error("Error updating insurance claim: " . $e->getMessage());
            setToast('Error', 'Could not update insurance claim status.', 'error');
        }
    }
}

if ($action === 'edit_claim') {
    $claimId = trim($_POST['claim_id'] ?? '');
    $providerName = trim($_POST['provider_name'] ?? '');
    $policyNumber = trim($_POST['policy_number'] ?? '');
    $claimAmount = (float)($_POST['claim_amount'] ?? 0.00);
    $status = trim($_POST['status'] ?? 'Submitted');
    $notes = trim($_POST['notes'] ?? '');

    if (!empty($claimId) && $claimAmount > 0) {
        try {
            $insuranceService->editClaim($claimId, $providerName, $policyNumber, $claimAmount, $status, $notes);
            setToast('Claim Updated', "Insurance claim updated successfully.");
        } catch (\Throwable $e) {
            $logger->error("Error editing insurance claim: " . $e->getMessage());
            setToast('Error', 'Could not edit insurance claim.', 'error');
        }
    } else {
        setToast('Validation Error', 'Claim ID and a positive Claim Amount are required.', 'error');
    }
}

if ($action === 'delete_claim') {
    $claimId = trim($_POST['claim_id'] ?? '');

    if (!empty($claimId)) {
        try {
            $insuranceService->deleteClaim($claimId);
            setToast('Claim Deleted', "Insurance claim was deleted successfully.");
        } catch (\Throwable $e) {
            $logger->error("Error deleting insurance claim: " . $e->getMessage());
            setToast('Error', 'Could not delete insurance claim.', 'error');
        }
    }
}

header("Location: ../billing.php?tab=insurance");
exit;
