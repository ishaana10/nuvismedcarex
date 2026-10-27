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
    $pdo = getDB();
    $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

    $invoiceId = trim($_POST['invoice_id'] ?? '');
    $providerName = trim($_POST['provider_name'] ?? '');
    if ($providerName === '__other__') {
        $providerName = trim($_POST['provider_other'] ?? '');
    }
    $policyNumber = trim($_POST['policy_number'] ?? '');
    $claimAmount = round((float)($_POST['claim_amount'] ?? 0.00), 2);
    $notes = trim($_POST['notes'] ?? '');

    // The patient comes from the invoice, not from the form: a posted id could
    // name someone else, or someone at another clinic entirely.
    $invoice = null;
    if ($invoiceId !== '') {
        $stmt = $pdo->prepare("SELECT id, patient_id, invoice_number, amount FROM invoices WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$invoiceId, $tenantId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$invoice) {
        setToast('Not submitted', 'Choose an invoice from this clinic to claim against.', 'error');
    } elseif ($providerName === '') {
        setToast('Not submitted', 'Choose the insurance provider, or type the name if it is not on the list.', 'error');
    } elseif ($claimAmount <= 0) {
        setToast('Not submitted', 'Enter the amount being claimed.', 'error');
    } elseif ($claimAmount > round((float) $invoice['amount'], 2) + 0.001) {
        setToast(
            'Not submitted',
            'The claim is more than the invoice total of ' . nxCurrencySymbol() . number_format((float) $invoice['amount'], 2) . '.',
            'error'
        );
    } else {
        try {
            $insuranceService->createClaim(
                (string) $invoice['id'],
                (string) $invoice['patient_id'],
                $providerName,
                $policyNumber,
                $claimAmount,
                $notes !== '' ? $notes : null
            );

            // Keep a provider typed in by hand, if asked, so it is on the list next time
            if (!empty($_POST['remember_provider']) && nxCan('settings', 'edit')) {
                try {
                    (new \ClinicFlow\Services\InsuranceProviders($pdo, $tenantId))->addIfMissing($providerName);
                } catch (\Throwable $e) {
                    $logger->error('Could not add insurance provider: ' . $e->getMessage());
                }
            }

            setToast(
                'Claim submitted',
                nxCurrencySymbol() . number_format($claimAmount, 2) . ' claimed from ' . $providerName
                . ' against ' . ($invoice['invoice_number'] ?: 'the invoice') . '.'
            );
        } catch (\Throwable $e) {
            $logger->error("Error creating insurance claim: " . $e->getMessage());
            setToast('Error', 'Could not create insurance claim.', 'error');
        }
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
