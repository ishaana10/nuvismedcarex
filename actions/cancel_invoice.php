<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/autoloader.php';
require_once __DIR__ . '/../config/database.php';

use ClinicFlow\Services\TaxService;

validateCsrfRequest();

$invoiceId = trim($_POST['invoice_id'] ?? '');
if (empty($invoiceId)) {
    $_SESSION['flash_error'] = "Missing invoice ID for cancellation.";
    header("Location: ../billing.php");
    exit;
}

try {
    $pdo = getDB();
    $taxService = new TaxService($pdo);

    $cashierName = $_SESSION['user_name'] ?? 'Admin';
    $cancelInvoiceId = $taxService->cancelInvoice($invoiceId, $cashierName);

    $_SESSION['flash_success'] = "Invoice cancelled. A matching cancellation invoice was created.";
} catch (Exception $e) {
    $_SESSION['flash_error'] = "Cancellation failed: " . $e->getMessage();
}

header("Location: ../billing.php");
exit;
