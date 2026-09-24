<?php
/**
 * Email Action Handler for Sending Documents (Prescription, Medical Certificate, Invoice, Receipt)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../src/Services/EmailService.php';

use ClinicFlow\Services\EmailService;

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
