<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/autoloader.php';
require_once __DIR__ . '/../config/database.php';

use ClinicFlow\Services\VMSService;

// CSRF check
validateCsrfRequest();

$pdo = getDB();
$vmsService = new VMSService($pdo);

$patientId = trim($_POST['patient_id'] ?? '');
$patientName = trim($_POST['patient_name'] ?? '');
$patientMrn = trim($_POST['patient_mrn'] ?? '');
$dueDate = trim($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));

$invoiceType = trim($_POST['invoice_type'] ?? 'Normal'); // Normal, Advance, Proforma, Copy, Training
$transactionType = trim($_POST['transaction_type'] ?? 'Sale'); // Sale, Refund
$buyerTin = trim($_POST['buyer_tin'] ?? '');
$buyerCostCenter = trim($_POST['buyer_cost_center'] ?? '');
$refNo = trim($_POST['ref_no'] ?? '');
$refTime = trim($_POST['ref_time'] ?? '');

$inventoryIds = $_POST['inventory_id'] ?? [];
$itemNames = $_POST['item_name'] ?? [];
$gtins = $_POST['gtin'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$unitPrices = $_POST['unit_price'] ?? [];
$taxLabels = $_POST['tax_label'] ?? [];

$paymentTypes = $_POST['payment_type'] ?? [];
$paymentAmounts = $_POST['payment_amount'] ?? [];

if (empty($patientName) || empty($itemNames)) {
    $_SESSION['flash_error'] = "Please provide patient information and at least one line item.";
    header("Location: ../billing.php");
    exit;
}

try {
    $pdo->beginTransaction();

    $invoiceId = 'inv-' . uniqid();
    $invoiceNumber = 'INV-' . date('Y') . '-' . rand(10000, 99999);

    // Calculate itemized totals
    $totalAmount = 0.00;
    $totalTax = 0.00;
    $itemsToInsert = [];

    for ($i = 0; $i < count($itemNames); $i++) {
        $name = trim($itemNames[$i]);
        if (empty($name)) continue;

        $qty = (float)($quantities[$i] ?? 1.0);
        $price = (float)($unitPrices[$i] ?? 0.0);
        $label = $taxLabels[$i] ?? 'A';
        $gtin = trim($gtins[$i] ?? '');

        $lineTotal = round($qty * $price, 2);
        $taxCalc = $vmsService->calculateItemTax($lineTotal, $label);

        $totalAmount += $lineTotal;
        $totalTax += round($taxCalc['tax_amount'], 2);

        $invItemId = $inventoryIds[$i] ?? '';

        $itemsToInsert[] = [
            'id' => 'item-' . uniqid(),
            'inventory_id' => $invItemId,
            'name' => $name,
            'gtin' => $gtin,
            'unit_price' => $price,
            'quantity' => $qty,
            'total_price' => $lineTotal,
            'tax_label' => $label,
            'tax_rate' => $taxCalc['tax_rate'],
            'tax_amount' => round($taxCalc['tax_amount'], 2)
        ];

        // If line item is linked to an inventory item, automatically deduct stock & log stock movement
        if (!empty($invItemId)) {
            $stmtInvCheck = $pdo->prepare("SELECT id, current_stock, name FROM inventory WHERE id = ? FOR UPDATE");
            $stmtInvCheck->execute([$invItemId]);
            $invRow = $stmtInvCheck->fetch();

            if ($invRow) {
                $prevStock = (int)$invRow['current_stock'];
                $deductQty = (int)ceil($qty);
                $newStock = max(0, $prevStock - $deductQty);
                $stockStatus = ($newStock <= 0) ? 'Out of Stock' : (($newStock <= 10) ? 'Low Stock' : 'In Stock');

                // Update inventory stock
                $stmtStockUpd = $pdo->prepare("UPDATE inventory SET current_stock = ?, status = ? WHERE id = ?");
                $stmtStockUpd->execute([$newStock, $stockStatus, $invItemId]);

                // Log movement in inventory_logs
                $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
                $logStmt = $pdo->prepare("INSERT INTO inventory_logs (id, tenant_id, inventory_id, change_amount, previous_stock, new_stock, type, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $logStmt->execute([
                    'log-' . uniqid(),
                    $tenantId,
                    $invItemId,
                    -$deductQty,
                    $prevStock,
                    $newStock,
                    'Invoice Sale',
                    'Deducted via VMS Fiscal Invoice: ' . $invoiceNumber,
                    $_SESSION['user_name'] ?? 'Admin'
                ]);
            }
        }
    }

    // Process payment methods JSON
    $paymentMethods = [];
    $totalPaid = 0.00;
    for ($j = 0; $j < count($paymentTypes); $j++) {
        $pType = trim($paymentTypes[$j]);
        $pAmt = (float)($paymentAmounts[$j] ?? 0.0);
        if ($pAmt > 0) {
            $paymentMethods[] = ['type' => $pType, 'amount' => $pAmt];
            $totalPaid += $pAmt;
        }
    }

    if (empty($paymentMethods)) {
        $paymentMethods = [['type' => 'Cash', 'amount' => $totalAmount]];
        $totalPaid = $totalAmount;
    }

    $status = ($totalPaid >= $totalAmount) ? 'Paid' : 'Pending';
    $patientOwed = max(0.00, $totalAmount - $totalPaid);
    $servicesJson = json_encode(array_column($itemsToInsert, 'name'));

    // Get VMS Clinic Settings
    $stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM clinic_settings WHERE setting_key LIKE 'vms_%'");
    $settings = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);

    $sellerTin = $settings['vms_seller_tin'] ?? '502579006';
    $businessLoc = $settings['vms_business_location'] ?? 'Suva Central Clinic, 2 Woodstand Road, Suva';
    $posNum = $settings['vms_pos_number'] ?? 'ASDF238/1.2';
    $cashierName = $_SESSION['user_name'] ?? 'Admin';

    $stmtInv = $pdo->prepare("
        INSERT INTO invoices (
            id, invoice_number, patient_id, patient_name, patient_mrn, service_date, due_date,
            amount, status, insurance_covered, patient_owed, services,
            invoice_type, transaction_type, seller_tin, business_location, cashier,
            buyer_tin, buyer_cost_center, pos_number, pos_time, ref_no, ref_time,
            total_tax, payment_methods
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, 0.00, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?
        )
    ");

    $stmtInv->execute([
        $invoiceId, $invoiceNumber, $patientId, $patientName, $patientMrn, date('Y-m-d'), $dueDate,
        $totalAmount, $status, $patientOwed, $servicesJson,
        $invoiceType, $transactionType, $sellerTin, $businessLoc, $cashierName,
        $buyerTin, $buyerCostCenter, $posNum, date('Y-m-d H:i:s'), $refNo, $refTime,
        $totalTax, json_encode($paymentMethods)
    ]);

    $stmtItem = $pdo->prepare("
        INSERT INTO invoice_items (id, invoice_id, name, gtin, unit_price, quantity, total_price, tax_label, tax_rate, tax_amount)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($itemsToInsert as $item) {
        $stmtItem->execute([
            $item['id'], $invoiceId, $item['name'], $item['gtin'], $item['unit_price'], $item['quantity'],
            $item['total_price'], $item['tax_label'], $item['tax_rate'], $item['tax_amount']
        ]);
    }

    $pdo->commit();

    // Auto-fiscalize invoice with SDC
    $vmsService->fiscalizeInvoice($invoiceId);

    $_SESSION['flash_success'] = "Invoice {$invoiceNumber} successfully created and fiscalized with VMS!";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_error'] = "Failed to create invoice: " . $e->getMessage();
}

header("Location: ../billing.php");
exit;
