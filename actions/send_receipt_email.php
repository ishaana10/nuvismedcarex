<?php
require_once __DIR__ . '/../core/SessionBootstrap.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\EmailService;
use ClinicFlow\Shared\Logger;

if (empty($_SESSION['authenticated'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized session.']);
        exit;
    }
    header('Location: ../login.php');
    exit;
}

$pdo = getDB();
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

$invoiceId = $_POST['invoice_id'] ?? $_GET['invoice_id'] ?? $_GET['id'] ?? '';
$recipientEmail = trim($_POST['email'] ?? $_GET['email'] ?? '');

if (empty($invoiceId)) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invoice ID is required.']);
        exit;
    }
    setToast('Error', 'Invoice ID is required.', 'error');
    header('Location: ../billing.php');
    exit;
}

try {
    // Fetch Invoice
    $stmt = $pdo->prepare("SELECT i.*, p.email as patient_email FROM invoices i LEFT JOIN patients p ON i.patient_id = p.id WHERE (i.id = ? OR i.invoice_number = ?) AND i.tenant_id = ?");
    $stmt->execute([$invoiceId, $invoiceId, $currentTenantId]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        throw new \Exception("Invoice record not found for active clinic.");
    }

    if (empty($recipientEmail)) {
        $recipientEmail = $invoice['patient_email'] ?? $invoice['email'] ?? '';
    }

    if (empty($recipientEmail)) {
        throw new \Exception("No email address provided or found for this patient.");
    }

    $amountPaid = (float)($invoice['amount'] ?? 0.00);
    $paymentMethod = $invoice['payment_type'] ?? 'Cash';

    $emailService = Container::getInstance()->get(EmailService::class);
    $sent = $emailService->sendReceiptEmail($recipientEmail, $invoice, $amountPaid, $paymentMethod);

    if ($sent) {
        $msg = "Official payment receipt sent successfully to " . htmlspecialchars($recipientEmail) . ".";
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $msg]);
            exit;
        }
        setToast('Email Sent', $msg);
    } else {
        throw new \Exception("Failed to dispatch email. Please check your SMTP settings in Settings.");
    }

} catch (\Throwable $e) {
    Container::getInstance()->get(Logger::class)->error("Send receipt email error: " . $e->getMessage());
    $errorMsg = $e->getMessage();
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $errorMsg]);
        exit;
    }
    setToast('Error', $errorMsg, 'error');
}

$redirectTo = $_SERVER['HTTP_REFERER'] ?? '../billing.php';
header("Location: $redirectTo");
exit;
