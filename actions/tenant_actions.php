<?php
/**
 * Multi-Tenancy Clinic Actions Handler
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();
validateCsrfRequest();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$pdo = getDB();
$tenantService = new \ClinicFlow\Services\TenantService($pdo);

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

    if ($id === '' || $name === '' || $code === '') {
        setToast('Validation Error', 'Tenant ID, Name, and Code are required.', 'error');
        header('Location: ../admin.php?tab=tenants');
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO tenants (id, name, code, status, plan, address, phone, email)
        VALUES (:id, :name, :code, :status, :plan, :address, :phone, :email)
        ON DUPLICATE KEY UPDATE name = VALUES(name), code = VALUES(code), status = VALUES(status), plan = VALUES(plan), address = VALUES(address), phone = VALUES(phone), email = VALUES(email)
    ");

    try {
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'code' => $code,
            'status' => $status,
            'plan' => $plan,
            'address' => $address,
            'phone' => $phone,
            'email' => $email
        ]);
        setToast('Clinic Saved', "Clinic tenant '{$name}' saved successfully.");
    } catch (Throwable $e) {
        setToast('Database Error', 'Failed to save clinic tenant: ' . $e->getMessage(), 'error');
    }

    header('Location: ../admin.php?tab=tenants');
    exit;
}

header('Location: ../admin.php?tab=tenants');
exit;
