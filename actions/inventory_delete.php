<?php
/**
 * Delete Inventory Item Action (Admin / Developer)
 * Soft deletes item by setting is_active = 0
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
    setToast("Access Denied", "Only Administrators and Developers can delete inventory records.", "error");
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

if (!empty($itemId)) {
    try {
        $deleted = $inventoryService->deleteItem($itemId);
        if ($deleted) {
            setToast("Item Deleted", "Inventory record has been removed.", "info");
        } else {
            setToast("Not Found", "Inventory record not found.", "error");
        }
    } catch (\Throwable $e) {
        Container::getInstance()->get(\ClinicFlow\Shared\Logger::class)->error("Error deleting inventory item: " . $e->getMessage());
        setToast("Error", "Could not delete inventory item: " . $e->getMessage(), "error");
    }
}

header("Location: ../inventory.php");
exit;
