<?php
/**
 * Subscription payment actions. Recording a SaaS payment against a tenant is a
 * vendor/developer action, so it is restricted to developers.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\SubscriptionService;
use ClinicFlow\Shared\Logger;

requireAuth();
validateCsrfRequest();
requireRole(['Developer']);

$action = $_POST['action'] ?? '';
$subscriptionService = Container::getInstance()->get(SubscriptionService::class);
$logger = Container::getInstance()->get(Logger::class);

$back = $_SERVER['HTTP_REFERER'] ?? '../developer.php';

if ($action === 'record_payment') {
    $tenantId = trim($_POST['tenant_id'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    if ($tenantId === '') {
        setToast('Validation error', 'A clinic/tenant must be selected.', 'error');
        header('Location: ' . $back);
        exit;
    }
    try {
        $subscriptionService->recordPayment($tenantId, [
            'amount'         => $amount,
            'currency'       => $_POST['currency'] ?? 'FJD',
            'plan'           => $_POST['plan'] ?? '',
            'billing_cycle'  => $_POST['billing_cycle'] ?? 'monthly',
            'period_start'   => $_POST['period_start'] ?? '',
            'period_end'     => $_POST['period_end'] ?? '',
            'status'         => $_POST['status'] ?? 'paid',
            'method'         => $_POST['method'] ?? '',
            'reference'      => $_POST['reference'] ?? '',
            'invoice_no'     => $_POST['invoice_no'] ?? '',
            'paid_at'        => $_POST['paid_at'] ?? '',
            'notes'          => $_POST['notes'] ?? '',
            'recorded_by'    => $_SESSION['user_id'] ?? null,
            'recorded_by_name' => $_SESSION['user_name'] ?? null,
        ]);
        setToast('Payment recorded', 'Subscription payment saved to the tenant ledger.');
    } catch (\Throwable $e) {
        $logger->error('Failed to record subscription payment: ' . $e->getMessage());
        setToast('Error', 'Could not record the payment.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

header('Location: ' . $back);
exit;
