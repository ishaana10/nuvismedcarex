<?php
/**
 * Edit Inventory Item Action (Admin / Developer)
 */
session_start();
if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Services\InventoryService;

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['Administrator', 'Developer'])) {
    setToast("Access Denied", "Only Administrators and Developers can edit inventory items.", "error");
    header("Location: ../inventory.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../inventory.php");
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($csrf)) {
    setToast("Security Error", "Invalid CSRF token.", "error");
    header("Location: ../inventory.php");
    exit;
}

$inventoryService = Container::getInstance()->get(InventoryService::class);

$itemId = trim($_POST['item_id'] ?? '');
$name = trim($_POST['name'] ?? '');
$sku = trim($_POST['sku'] ?? '');

if (empty($itemId) || empty($name) || empty($sku)) {
    setToast("Validation Error", "Item ID, Name, and SKU are required.", "error");
    header("Location: ../inventory.php");
    exit;
}

try {
    $updateData = [
        'name' => $name,
        'sku' => $sku,
        'category' => trim($_POST['category'] ?? 'Pharmaceuticals'),
        'min_threshold' => (int)($_POST['min_threshold'] ?? 10),
        'unit' => trim($_POST['unit'] ?? 'Boxes'),
        'cost_price' => (float)($_POST['cost_price'] ?? 0.00),
        'unit_price' => (float)($_POST['unit_price'] ?? 0.00),
        'batch_number' => trim($_POST['batch_number'] ?? ''),
        'expiry_date' => !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
        'vms_tax_code' => trim($_POST['vms_tax_code'] ?? 'A'),
        'custom_fields' => $_POST['custom_fields'] ?? []
    ];

    $item = $inventoryService->updateItem($itemId, $updateData);
    setToast("Inventory Updated", "Item '{$item['name']}' updated successfully.");
} catch (\Throwable $e) {
    Container::getInstance()->get(\ClinicFlow\Shared\Logger::class)->error("Error updating inventory item: " . $e->getMessage());
    setToast("Error", "Could not update inventory item: " . $e->getMessage(), "error");
}

header("Location: ../inventory.php");
exit;
