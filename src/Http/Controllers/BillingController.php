<?php
declare(strict_types=1);

namespace ClinicFlow\Http\Controllers;

use PDO;
use ClinicFlow\Services\BillingService;

class BillingController {
    private BillingService $service;

    public function __construct(PDO $db) {
        $this->service = new BillingService($db);
    }

    public function index(): void {
        $status = $_GET['status'] ?? null;
        $invoices = $this->service->getInvoices($status);
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['invoices' => $invoices]);
            exit;
        }
    }

    public function store(): void {
        validateCsrfRequest();
        $invoice = $this->service->createInvoice($_POST, $_POST['items'] ?? []);
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'invoice' => $invoice]);
            exit;
        }
        setToast('Success', 'Invoice created successfully.');
        header('Location: billing.php');
        exit;
    }

    public function pay(string $id): void {
        validateCsrfRequest();
        $amount = (float)($_POST['amount_paid'] ?? $_POST['amount'] ?? 0.0);
        $method = $_POST['payment_method'] ?? 'Cash';
        $res = $this->service->recordPayment($id, $amount, $method);

        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'invoice' => $res]);
            exit;
        }

        setToast('Success', 'Payment recorded.');
        header('Location: billing.php');
        exit;
    }
}
