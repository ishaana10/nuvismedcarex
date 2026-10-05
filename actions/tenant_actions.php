<?php
/**
 * Multi-Tenancy Clinic Actions Handler
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\TenantService;
use ClinicFlow\Shared\Logger;

requireAuth();
validateCsrfRequest();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$tenantService = Container::getInstance()->get(TenantService::class);
$logger = Container::getInstance()->get(Logger::class);

if ($action === 'switch_tenant') {
    $tenantId = $_POST['tenant_id'] ?? '';
    if ($tenantService->switchTenant($tenantId)) {
        setToast('Clinic Switched', "Switched active clinic context to '{$tenantId}'.");
    } else {
        setToast('Switch Error', 'Invalid or inactive clinic selected.', 'error');
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../admin.php?tab=tenants'));
    exit;
}

if ($action === 'save_tenant') {
    requireRole(['Developer', 'Administrator']);

    $id = trim($_POST['tenant_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $plan = $_POST['plan'] ?? 'standard';
    $status = $_POST['status'] ?? 'active';
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $storageLimitMb = (int)($_POST['storage_limit_mb'] ?? 500);

    if ($id === '' || $name === '' || $code === '') {
        setToast('Validation Error', 'Tenant ID, Name, and Code are required.', 'error');
        header('Location: ../admin.php?tab=tenants');
        exit;
    }

    try {
        $tenantService->saveTenant([
            'id' => $id,
            'name' => $name,
            'code' => $code,
            'status' => $status,
            'plan' => $plan,
            'address' => $address,
            'phone' => $phone,
            'email' => $email,
            'storage_limit_mb' => $storageLimitMb
        ]);
        setToast('Clinic Saved', "Clinic tenant '{$name}' saved successfully with {$storageLimitMb}MB storage allocation.");
    } catch (\Throwable $e) {
        $logger->error("Failed to save clinic tenant: " . $e->getMessage());
        setToast('Error', 'Failed to save clinic tenant.', 'error');
    }

    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../admin.php?tab=tenants'));
    exit;
}

header('Location: ../admin.php?tab=tenants');
exit;
