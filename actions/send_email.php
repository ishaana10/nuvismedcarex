<?php
/**
 * Email Action Handler for Sending Documents (Prescription, Medical Certificate, Invoice, Receipt)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/autoloader.php';

use ClinicFlow\Services\EmailService;
use ClinicFlow\Services\PrescriptionVerificationService;

requireAuth();
validateCsrfRequest();

$pdo = getDB();
$emailService = new EmailService($pdo);

$docType = trim($_POST['document_type'] ?? '');
$docId = trim($_POST['document_id'] ?? '');
$recipient = trim($_POST['email'] ?? '');
$customNotes = trim($_POST['notes'] ?? '');

if (empty($recipient) || empty($docType) || empty($docId)) {
    setToast('Email Error', 'Recipient email and document information are required.', 'error');
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '../index.php'));
    exit;
}

$qrSection = "";
if ($docType === 'prescription') {
    $patientId = trim($_POST['patient_id'] ?? '');
    $visitId = trim($_POST['visit_id'] ?? '');

    if (empty($patientId) && strpos($docId, 'RX-') === 0) {
        $mrn = str_replace('RX-', '', $docId);
        $pStmt = $pdo->prepare("SELECT id FROM patients WHERE mrn = ?");
        $pStmt->execute([$mrn]);
        $patientId = $pStmt->fetchColumn() ?: '';
    }

    if (!empty($patientId)) {
        $tenantId = $_SESSION['tenant_id'] ?? 'tenant-default';
        $rxService = new PrescriptionVerificationService();
        $token = $rxService->getOrCreateToken($pdo, $tenantId, $patientId, $visitId);
        $url = $rxService->getVerificationUrl($token);

        $qrSection = "
        <div style='background-color: #f8fafc; padding: 15px; border-radius: 8px; margin: 15px 0; border: 1px solid #e2e8f0; text-align: center;'>
            <p style='margin: 0 0 10px 0; font-size: 13px; font-weight: bold; color: #1e293b;'>Pharmacy Verification QR Code</p>
            <img src='https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=" . urlencode($url) . "' alt='QR Verification' style='width: 140px; height: 140px; border-radius: 6px; border: 1px solid #cbd5e1;'>
            <p style='margin: 8px 0 0 0; font-size: 11px; font-family: monospace; color: #475569;'>Token: " . htmlspecialchars($token) . "</p>
            <p style='margin: 4px 0 0 0; font-size: 12px;'><a href='" . htmlspecialchars($url) . "' style='color: #2563eb; text-decoration: underline;'>Verify Prescription Online</a></p>
        </div>";
    }
}

$subject = "Nuvis Medico Healthcare - Document (" . ucfirst($docType) . ")";
$body = "
<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
    <h2 style='color: #1e3a8a;'>Nuvis Medico Healthcare</h2>
    <p>Dear Patient,</p>
    <p>Please find attached your clinical document details below:</p>
    <div style='background-color: #f8fafc; padding: 15px; border-radius: 8px; margin: 15px 0;'>
        <p><strong>Document Type:</strong> " . htmlspecialchars(ucwords(str_replace('_', ' ', $docType))) . "</p>
        <p><strong>Reference ID:</strong> " . htmlspecialchars($docId) . "</p>
        " . ($customNotes ? "<p><strong>Notes:</strong> " . nl2br(htmlspecialchars($customNotes)) . "</p>" : "") . "
    </div>
    " . $qrSection . "
    <p>If you have any questions, please feel free to contact our clinic.</p>
    <br>
    <p style='font-size: 12px; color: #64748b;'>Nuvis Medico Healthcare Team</p>
</div>";

$success = $emailService->sendDocumentEmail($recipient, $subject, $body, $docType, $docId);

if ($success) {
    setToast('Email Sent', 'The ' . str_replace('_', ' ', $docType) . ' has been sent to ' . htmlspecialchars($recipient) . '.');
} else {
    setToast('Email Failed', 'Could not dispatch email. Please check the email address.', 'error');
}

header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '../index.php'));
exit;
