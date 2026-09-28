<?php
/**
 * Inventory Restock Handler (Quick & Detailed Restock)
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

$itemId = $_POST['item_id'] ?? '';
$amount = (int)($_POST['amount'] ?? 0);
$restockType = $_POST['restock_type'] ?? 'quick';
$supplier = trim($_POST['supplier'] ?? 'Standard Supplier');
$unitCost = isset($_POST['unit_cost']) && $_POST['unit_cost'] !== '' ? (float)$_POST['unit_cost'] : null;
$notes = trim($_POST['notes'] ?? '');
$batchNumber = trim($_POST['batch_number'] ?? '');
$expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

if ($itemId !== '' && $amount > 0) {
    try {
        if (!empty($batchNumber) || !empty($expiryDate)) {
            $inventoryService->updateItem($itemId, [
                'batch_number' => $batchNumber,
                'expiry_date' => $expiryDate
            ]);
        }

        $res = $inventoryService->restockItem($itemId, $amount, $restockType === 'detailed' ? 'detailed_restock' : 'quick_restock', $supplier, $unitCost, $notes);
        setToast("Inventory Restocked", "Added $amount items. New Stock: {$res['new_stock']}.");
    } catch (\Throwable $e) {
        Container::getInstance()->get(\ClinicFlow\Shared\Logger::class)->error("Restock failed: " . $e->getMessage());
        setToast("Error", "Restock failed: " . $e->getMessage(), "error");
    }
} else {
    setToast("Validation Error", "Invalid restock quantity or item.", "error");
}

header("Location: ../inventory.php");
exit;
