<?php
declare(strict_types=1);

namespace ClinicFlow\Http\Controllers;

use PDO;
use ClinicFlow\Services\InventoryService;

class InventoryController {
    private InventoryService $service;

    public function __construct(PDO $db) {
        $this->service = new InventoryService($db);
    }

    public function index(): void {
        $category = $_GET['category'] ?? null;
        $items = $this->service->getInventoryItems($category);
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['items' => $items]);
            exit;
        }
    }

    public function store(): void {
        validateCsrfRequest();
        $item = $this->service->addItem($_POST);
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'item' => $item]);
            exit;
        }
        setToast('Success', 'Inventory item added successfully.');
        header('Location: inventory.php');
        exit;
    }

    public function restock(string $id): void {
        validateCsrfRequest();
        $amount = (int)($_POST['change_amount'] ?? $_POST['quantity'] ?? 0);
        $res = $this->service->restockItem($id, $amount, 'restock', $_POST['supplier'] ?? null, (float)($_POST['unit_cost'] ?? 0.0), $_POST['notes'] ?? null);

        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'data' => $res]);
            exit;
        }

        setToast('Success', 'Stock level updated.');
        header('Location: inventory.php');
        exit;
    }
}
