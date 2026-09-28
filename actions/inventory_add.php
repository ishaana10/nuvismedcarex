<?php
/**
 * Add New Inventory Item Action (Admin / Developer)
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
    setToast("Access Denied", "Only Administrators and Developers can add new inventory items.", "error");
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

$name = trim($_POST['name'] ?? '');
$sku = trim($_POST['sku'] ?? '');

if (empty($name) || empty($sku)) {
    setToast("Validation Error", "Item Name and SKU are required fields.", "error");
    header("Location: ../inventory.php");
    exit;
}

try {
    $itemData = [
        'name' => $name,
        'sku' => $sku,
        'category' => trim($_POST['category'] ?? 'Pharmaceuticals'),
        'current_stock' => (int)($_POST['current_stock'] ?? 0),
        'min_threshold' => (int)($_POST['min_threshold'] ?? 10),
        'unit' => trim($_POST['unit'] ?? 'Boxes'),
        'cost_price' => (float)($_POST['cost_price'] ?? 0.00),
        'unit_price' => (float)($_POST['unit_price'] ?? 0.00),
        'batch_number' => trim($_POST['batch_number'] ?? ''),
        'expiry_date' => !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
        'vms_tax_code' => trim($_POST['vms_tax_code'] ?? 'A'),
        'custom_fields' => $_POST['custom_fields'] ?? []
    ];

    $item = $inventoryService->addItem($itemData);
    setToast("Inventory Added", "Item '{$item['name']}' ({$item['sku']}) created successfully.");
} catch (\Throwable $e) {
    \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Shared\Logger::class)->error("Error adding inventory item: " . $e->getMessage());
    setToast("Error", "Could not add inventory item: " . $e->getMessage(), "error");
}

header("Location: ../inventory.php");
exit;
