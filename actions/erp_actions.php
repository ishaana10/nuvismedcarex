<?php
/**
 * NuvisERPX management actions (developer only):
 *  - save_config      : connection settings
 *  - sync_now         : pull invoices from NuvisERPX
 *  - record_invoice   : manually add/update an invoice (onboarding / testing)
 *  - set_invoice_status : manually move an invoice's status (incl. mark paid)
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\NuvisErpService;
use ClinicFlow\Shared\Logger;

requireAuth();
validateCsrfRequest();
requireRole(['Developer']);

$action = $_POST['action'] ?? '';
$erp = Container::getInstance()->get(NuvisErpService::class);
$logger = Container::getInstance()->get(Logger::class);
$back = $_SERVER['HTTP_REFERER'] ?? '../developer.php';

if ($action === 'save_config') {
    try {
        $erp->saveConfig([
            'enabled'        => isset($_POST['enabled']),
            'base_url'       => $_POST['base_url'] ?? '',
            'api_key'        => $_POST['api_key'] ?? '',
            'webhook_secret' => $_POST['webhook_secret'] ?? '',
        ]);
        setToast('Saved', 'NuvisERPX connection settings saved.');
    } catch (\Throwable $e) {
        $logger->error('ERP save_config: ' . $e->getMessage());
        setToast('Error', 'Could not save ERP settings.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'sync_now') {
    $tenantId = trim($_POST['tenant_id'] ?? '') ?: null;
    [$count, $error] = $erp->syncFromErp($tenantId);
    if ($error && $count === 0) {
        setToast('Sync incomplete', $error, 'error');
    } else {
        setToast('Sync complete', "Pulled {$count} invoice(s) from NuvisERPX." . ($error ? " Some errors: {$error}" : ''));
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'record_invoice') {
    $tenantId = trim($_POST['tenant_id'] ?? '');
    if ($tenantId === '') {
        setToast('Validation error', 'Select a tenant for the invoice.', 'error');
        header('Location: ' . $back);
        exit;
    }
    try {
        $erp->upsertInvoice($tenantId, [
            'erp_invoice_id' => $_POST['erp_invoice_id'] ?? '',
            'invoice_number' => $_POST['invoice_number'] ?? '',
            'amount'         => $_POST['amount'] ?? 0,
            'currency'       => $_POST['currency'] ?? 'FJD',
            'status'         => $_POST['status'] ?? 'issued',
            'description'    => $_POST['description'] ?? '',
            'period_start'   => $_POST['period_start'] ?? '',
            'period_end'     => $_POST['period_end'] ?? '',
            'issue_date'     => $_POST['issue_date'] ?? '',
            'due_date'       => $_POST['due_date'] ?? '',
            'pdf_url'        => $_POST['pdf_url'] ?? '',
        ]);
        setToast('Invoice saved', 'The invoice was recorded for the tenant.');
    } catch (\Throwable $e) {
        $logger->error('ERP record_invoice: ' . $e->getMessage());
        setToast('Error', 'Could not save the invoice.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'set_invoice_status') {
    $ref = trim($_POST['invoice_id'] ?? '');
    $status = $_POST['status'] ?? '';
    if ($ref === '') {
        header('Location: ' . $back);
        exit;
    }
    if ($status === 'paid') {
        $erp->applyReconciliation($ref, ['status' => 'paid', 'method' => 'Manual (developer)']);
        setToast('Invoice reconciled', 'Invoice marked paid; payment recorded and subscription reactivated.');
    } else {
        try {
            getDB()->prepare("UPDATE tenant_invoices SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? OR erp_invoice_id = ?")
                ->execute([$status, $ref, $ref]);
            setToast('Invoice updated', 'Invoice status updated.');
        } catch (\Throwable $e) {
            setToast('Error', 'Could not update the invoice.', 'error');
        }
    }
    header('Location: ' . $back);
    exit;
}

header('Location: ' . $back);
exit;
