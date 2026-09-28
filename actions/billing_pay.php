<?php
/**
 * Mark Invoice Paid Handler with Custom Payments & CSRF
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\BillingService;
use ClinicFlow\Shared\Logger;

requireAuth();
validateCsrfRequest();

$invoiceId = $_POST['invoice_id'] ?? '';
$paymentAmount = (float)($_POST['payment_amount'] ?? 0);
$paymentMethod = $_POST['payment_method'] ?? 'Cash';

if ($invoiceId !== '') {
    try {
        $billingService = Container::getInstance()->get(BillingService::class);
        $res = $billingService->recordPayment($invoiceId, $paymentAmount, $paymentMethod);
        if ($res) {
            // Automatically send payment receipt email if patient email is available
            try {
                $pdo = getDB();
                $invStmt = $pdo->prepare("SELECT i.*, p.email FROM invoices i LEFT JOIN patients p ON i.patient_id = p.id WHERE i.id = ?");
                $invStmt->execute([$invoiceId]);
                $invRow = $invStmt->fetch();
                if ($invRow && !empty($invRow['email'])) {
                    $emailService = Container::getInstance()->get(\ClinicFlow\Services\EmailService::class);
                    $emailService->sendReceiptEmail($invRow['email'], $invRow, $paymentAmount, $paymentMethod);
                }
            } catch (\Throwable $e) {
                Container::getInstance()->get(Logger::class)->warning("Automated receipt email error: " . $e->getMessage());
            }

            setToast("Payment Recorded", "Payment recorded successfully via " . htmlspecialchars($paymentMethod) . ".");
        } else {
            setToast("Error", "Invoice not found or could not record payment.", "error");
        }
    } catch (\Throwable $e) {
        Container::getInstance()->get(Logger::class)->error("Error processing payment: " . $e->getMessage());
        setToast("Error", "Failed to record payment.", "error");
    }
}

header("Location: ../billing.php");
exit;
