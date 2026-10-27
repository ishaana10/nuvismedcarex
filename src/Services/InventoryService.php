<?php

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;
use ClinicFlow\Shared\TenantContext;

class InventoryService {
    private PDO $db;
    private AuditService $audit;

    public function __construct(PDO $db, ?AuditService $audit = null) {
        $this->db = $db;
        $this->audit = $audit ?? new AuditService($db);
    }

    /**
     * Inventory set-up for the active clinic: its categories, units, reorder
     * and expiry levels, and any extra fields it records against an item.
     * Falls back to the installation defaults where a clinic has set nothing.
     */
    public function getInventorySettings(?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $settings = (new ClinicSettings($this->db, $tenantId))->all();

        $rawCategories = (string) ($settings['inventory_categories'] ?? '');
        $categories = InventorySetup::parseList($rawCategories, InventorySetup::DEFAULT_CATEGORIES);
        $rawUnits = (string) ($settings['inventory_units'] ?? '');
        $units = InventorySetup::parseList($rawUnits, InventorySetup::DEFAULT_UNITS);

        return [
            'raw_categories'      => $rawCategories !== '' ? $rawCategories : implode(', ', $categories),
            'categories'          => $categories,
            'raw_units'           => $rawUnits !== '' ? $rawUnits : implode(', ', $units),
            'units'               => $units,
            'default_min_threshold' => (int) ($settings['inventory_default_min_threshold'] ?? 10),
            'expiry_warning_days' => (int) ($settings['inventory_expiry_warning_days'] ?? 30),
            'custom_fields_def'   => InventorySetup::fields($settings['inventory_custom_fields_def'] ?? '[]'),
        ];
    }

    public function searchAndFilterItems(string $searchQuery = '', string $categoryFilter = '', string $statusFilter = '', ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $sql = "SELECT * FROM inventory WHERE tenant_id = :tid AND is_active = 1";
        $params = ['tid' => $tenantId];

        if ($searchQuery !== '') {
            $sql .= " AND (name LIKE :q1 OR sku LIKE :q2 OR batch_number LIKE :q3)";
            $params['q1'] = "%$searchQuery%";
            $params['q2'] = "%$searchQuery%";
            $params['q3'] = "%$searchQuery%";
        }

        if ($categoryFilter !== '' && $categoryFilter !== 'All') {
            $sql .= " AND category = :cat";
            $params['cat'] = $categoryFilter;
        }

        if ($statusFilter !== '') {
            if ($statusFilter === 'Low Stock') {
                $sql .= " AND (status = 'Low Stock' OR current_stock <= min_threshold)";
            } elseif ($statusFilter === 'In Stock') {
                $sql .= " AND (status = 'In Stock' AND current_stock > min_threshold)";
            } elseif ($statusFilter === 'Out of Stock') {
                $sql .= " AND current_stock = 0";
            }
        }

        $sql .= " ORDER BY name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Items that need attention: below their reorder level, out of stock, or
     * close to expiry. One query so the dashboard, the stock list and the
     * alerts view all agree.
     *
     * @return array{reorder: array, expiring: array, expired: array, window_days: int}
     */
    public function getAlerts(?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $settings = $this->getInventorySettings($tenantId);
        $windowDays = max(0, (int) $settings['expiry_warning_days']);
        $today = date('Y-m-d');
        $cutoff = date('Y-m-d', strtotime("+{$windowDays} days"));

        $out = ['reorder' => [], 'expiring' => [], 'expired' => [], 'window_days' => $windowDays];
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM inventory
                  WHERE tenant_id = :tid AND is_active = 1
                    AND (current_stock <= min_threshold
                         OR (expiry_date IS NOT NULL AND expiry_date <> '' AND expiry_date <= :cutoff))
                  ORDER BY current_stock ASC, expiry_date ASC"
            );
            $stmt->execute(['tid' => $tenantId, 'cutoff' => $cutoff]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
                $expiry = trim((string) ($item['expiry_date'] ?? ''));
                if ($expiry !== '' && $expiry < $today) {
                    $out['expired'][] = $item;
                } elseif ($expiry !== '' && $expiry <= $cutoff) {
                    $out['expiring'][] = $item;
                }
                if ((int) $item['current_stock'] <= (int) $item['min_threshold']) {
                    $out['reorder'][] = $item;
                }
            }
        } catch (\Throwable $e) {
            error_log('[InventoryService] alerts: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Figures for the reports view: what stock is worth, where it sits, and
     * what moved over a period.
     */
    public function getReport(string $from, string $to, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $report = [
            'by_category' => [], 'totals' => ['items' => 0, 'units' => 0, 'cost_value' => 0.0, 'retail_value' => 0.0],
            'movements' => ['in' => 0, 'out' => 0, 'spend' => 0.0, 'by_type' => []],
            'top_moving' => [], 'never_moved' => [], 'from' => $from, 'to' => $to,
        ];

        try {
            $stmt = $this->db->prepare("SELECT * FROM inventory WHERE tenant_id = ? AND is_active = 1");
            $stmt->execute([$tenantId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
                $cat = trim((string) ($item['category'] ?? '')) ?: 'Uncategorised';
                $units = (int) $item['current_stock'];
                $cost = $units * (float) ($item['cost_price'] ?? 0);
                $retail = $units * (float) ($item['unit_price'] ?? 0);

                if (!isset($report['by_category'][$cat])) {
                    $report['by_category'][$cat] = ['category' => $cat, 'items' => 0, 'units' => 0, 'cost_value' => 0.0, 'retail_value' => 0.0, 'low' => 0];
                }
                $report['by_category'][$cat]['items']++;
                $report['by_category'][$cat]['units'] += $units;
                $report['by_category'][$cat]['cost_value'] += $cost;
                $report['by_category'][$cat]['retail_value'] += $retail;
                if ($units <= (int) $item['min_threshold']) {
                    $report['by_category'][$cat]['low']++;
                }

                $report['totals']['items']++;
                $report['totals']['units'] += $units;
                $report['totals']['cost_value'] += $cost;
                $report['totals']['retail_value'] += $retail;
            }
            usort($report['by_category'], fn($a, $b) => $b['retail_value'] <=> $a['retail_value']);
        } catch (\Throwable $e) {
            error_log('[InventoryService] report stock: ' . $e->getMessage());
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT l.*, i.name AS item_name, i.unit AS item_unit
                   FROM inventory_logs l
                   LEFT JOIN inventory i ON i.id = l.inventory_id AND i.tenant_id = l.tenant_id
                  WHERE l.tenant_id = :tid AND DATE(l.created_at) BETWEEN :from AND :to
                  ORDER BY l.created_at DESC"
            );
            $stmt->execute(['tid' => $tenantId, 'from' => $from, 'to' => $to]);
            $moved = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $log) {
                $change = (int) ($log['change_amount'] ?? 0);
                $type = trim((string) ($log['type'] ?? 'adjustment')) ?: 'adjustment';
                $report['movements']['by_type'][$type] = ($report['movements']['by_type'][$type] ?? 0) + abs($change);
                if ($change >= 0) {
                    $report['movements']['in'] += $change;
                    $report['movements']['spend'] += $change * (float) ($log['unit_cost'] ?? 0);
                } else {
                    $report['movements']['out'] += abs($change);
                }
                $key = (string) ($log['inventory_id'] ?? '');
                if ($key !== '') {
                    if (!isset($moved[$key])) {
                        $moved[$key] = ['id' => $key, 'name' => $log['item_name'] ?? 'Removed item', 'unit' => $log['item_unit'] ?? '', 'in' => 0, 'out' => 0];
                    }
                    if ($change >= 0) $moved[$key]['in'] += $change; else $moved[$key]['out'] += abs($change);
                }
            }
            usort($moved, fn($a, $b) => ($b['in'] + $b['out']) <=> ($a['in'] + $a['out']));
            $report['top_moving'] = array_slice(array_values($moved), 0, 10);

            // Stock sitting untouched for the whole period is money on a shelf
            $stmt = $this->db->prepare(
                "SELECT * FROM inventory
                  WHERE tenant_id = :tid AND is_active = 1 AND current_stock > 0
                    AND id NOT IN (SELECT inventory_id FROM inventory_logs WHERE tenant_id = :tid2 AND DATE(created_at) BETWEEN :from AND :to)
                  ORDER BY (current_stock * unit_price) DESC LIMIT 10"
            );
            $stmt->execute(['tid' => $tenantId, 'tid2' => $tenantId, 'from' => $from, 'to' => $to]);
            $report['never_moved'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[InventoryService] report movements: ' . $e->getMessage());
        }

        return $report;
    }

    public function getInventoryLogs(int $limit = 100, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $sql = "SELECT l.*, i.name as item_name, i.sku as item_sku
                FROM inventory_logs l
                LEFT JOIN inventory i ON l.inventory_id = i.id
                WHERE l.tenant_id = :tid
                ORDER BY l.created_at DESC LIMIT " . (int)$limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tid' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getInventoryItems(?string $category = null, bool $activeOnly = true, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $sql = "SELECT * FROM inventory WHERE tenant_id = :tid";
        $params = ['tid' => $tenantId];

        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }

        if ($category && $category !== 'All') {
            $sql .= " AND category = :cat";
            $params['cat'] = $category;
        }

        $sql .= " ORDER BY name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getItemById(string $id, ?string $tenantId = null): ?array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM inventory WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function addItem(array $data, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $id = Uuid::uuidv7();
        $sku = $data['sku'] ?? ('SKU-' . rand(10000, 99999));

        $stmt = $this->db->prepare(
            "INSERT INTO inventory (id, tenant_id, name, sku, category, current_stock, min_threshold, unit, status, last_restocked, cost_price, unit_price, batch_number, expiry_date, is_active, vms_tax_code, custom_fields) " .
            "VALUES (:id, :tid, :name, :sku, :category, :stock, :min_threshold, :unit, :status, :last_restocked, :cost_price, :unit_price, :batch_number, :expiry_date, 1, :vms_tax_code, :custom_fields)"
        );

        $stock = (int)($data['current_stock'] ?? 0);
        $minThreshold = (int)($data['min_threshold'] ?? 10);
        $status = $stock > $minThreshold ? 'In Stock' : ($stock > 0 ? 'Low Stock' : 'Out of Stock');

        $stmt->execute([
            'id' => $id,
            'tid' => $tenantId,
            'name' => $data['name'],
            'sku' => $sku,
            'category' => $data['category'] ?? 'General',
            'stock' => $stock,
            'min_threshold' => $minThreshold,
            'unit' => $data['unit'] ?? 'Box',
            'status' => $status,
            'last_restocked' => date('Y-m-d'),
            'cost_price' => (float)($data['cost_price'] ?? 0.0),
            'unit_price' => (float)($data['unit_price'] ?? 0.0),
            'batch_number' => $data['batch_number'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'vms_tax_code' => $data['vms_tax_code'] ?? 'A',
            'custom_fields' => isset($data['custom_fields']) ? json_encode($data['custom_fields']) : null
        ]);

        $this->audit->logInventoryChange($id, 'ADD_ITEM', $stock, ['name' => $data['name'], 'sku' => $sku], $tenantId);

        return array_merge(['id' => $id, 'tenant_id' => $tenantId, 'sku' => $sku, 'status' => $status], $data);
    }

    public function restockItem(string $id, int $changeAmount, string $type = 'restock', ?string $supplier = null, ?float $unitCost = null, ?string $notes = null, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();

        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $autoCommit = true;
        } else {
            $autoCommit = false;
        }

        try {
            $isMysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $sql = "SELECT * FROM inventory WHERE id = :id AND tenant_id = :tid" . ($isMysql ? " FOR UPDATE" : "");
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tid' => $tenantId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                throw new \RuntimeException("Inventory item not found.");
            }

            $prevStock = (int)$item['current_stock'];
            $newStock = $prevStock + $changeAmount;
            $minThreshold = (int)$item['min_threshold'];
            $status = $newStock > $minThreshold ? 'In Stock' : ($newStock > 0 ? 'Low Stock' : 'Out of Stock');

            $upd = $this->db->prepare(
                "UPDATE inventory SET current_stock = :stock, status = :status, last_restocked = :date WHERE id = :id AND tenant_id = :tid"
            );
            $upd->execute([
                'stock' => $newStock,
                'status' => $status,
                'date' => date('Y-m-d'),
                'id' => $id,
                'tid' => $tenantId
            ]);

            if ($autoCommit) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($autoCommit && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $logId = Uuid::uuidv7();
        $logStmt = $this->db->prepare(
            "INSERT INTO inventory_logs (id, tenant_id, inventory_id, change_amount, previous_stock, new_stock, type, supplier, unit_cost, notes, created_by, created_at) " .
            "VALUES (:id, :tid, :inv_id, :change, :prev, :new, :type, :supplier, :cost, :notes, :user, :at)"
        );

        $costToUse = $unitCost !== null ? $unitCost : (float)$item['cost_price'];

        $logStmt->execute([
            'id' => $logId,
            'tid' => $item['tenant_id'],
            'inv_id' => $id,
            'change' => $changeAmount,
            'prev' => $prevStock,
            'new' => $newStock,
            'type' => $type,
            'supplier' => $supplier,
            'cost' => $costToUse,
            'notes' => $notes,
            'user' => $_SESSION['user_name'] ?? 'System',
            'at' => date('Y-m-d H:i:s')
        ]);

        $this->audit->logInventoryChange($id, 'RESTOCK', $changeAmount, ['previous' => $prevStock, 'new' => $newStock], $tenantId);

        return [
            'id' => $id,
            'previous_stock' => $prevStock,
            'new_stock' => $newStock,
            'status' => $status
        ];
    }

    public function updateItem(string $id, array $data, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $item = $this->getItemById($id, $tenantId);
        if (!$item) {
            throw new \RuntimeException("Inventory item not found.");
        }

        $minThreshold = (int)($data['min_threshold'] ?? $item['min_threshold']);
        $currentStock = (int)$item['current_stock'];
        $status = $currentStock > $minThreshold ? 'In Stock' : ($currentStock > 0 ? 'Low Stock' : 'Out of Stock');

        $sql = "UPDATE inventory SET name = :name, sku = :sku, category = :cat, min_threshold = :min_thresh, unit = :unit, status = :status, cost_price = :cost, unit_price = :unit_price, batch_number = :batch, expiry_date = :expiry, vms_tax_code = :vms, custom_fields = :fields WHERE id = :id AND tenant_id = :tid";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'name' => $data['name'] ?? $item['name'],
            'sku' => $data['sku'] ?? $item['sku'],
            'cat' => $data['category'] ?? $item['category'],
            'min_thresh' => $minThreshold,
            'unit' => $data['unit'] ?? $item['unit'],
            'status' => $status,
            'cost' => (float)($data['cost_price'] ?? $item['cost_price']),
            'unit_price' => (float)($data['unit_price'] ?? $item['unit_price']),
            'batch' => $data['batch_number'] ?? $item['batch_number'],
            'expiry' => !empty($data['expiry_date']) ? $data['expiry_date'] : null,
            'vms' => $data['vms_tax_code'] ?? $item['vms_tax_code'],
            'fields' => isset($data['custom_fields']) ? (is_array($data['custom_fields']) ? json_encode($data['custom_fields']) : $data['custom_fields']) : $item['custom_fields'],
            'id' => $id,
            'tid' => $tenantId
        ]);

        $this->audit->logInventoryChange($id, 'UPDATE_ITEM', 0, ['updated_by' => $_SESSION['user_name'] ?? 'System'], $tenantId);

        return $this->getItemById($id, $tenantId);
    }

    public function deleteItem(string $id, ?string $tenantId = null): bool {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $item = $this->getItemById($id, $tenantId);
        if (!$item) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE inventory SET is_active = 0 WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);

        $logId = Uuid::uuidv7();
        $logStmt = $this->db->prepare(
            "INSERT INTO inventory_logs (id, tenant_id, inventory_id, change_amount, previous_stock, new_stock, type, notes, created_by, created_at) " .
            "VALUES (:id, :tid, :inv_id, 0, :prev, :prev, 'deactivation', 'Soft deleted / deactivated item', :user, :at)"
        );
        $logStmt->execute([
            'id' => $logId,
            'tid' => $tenantId,
            'inv_id' => $id,
            'prev' => $item['current_stock'],
            'user' => $_SESSION['user_name'] ?? 'System',
            'at' => date('Y-m-d H:i:s')
        ]);

        $this->audit->logInventoryChange($id, 'DELETE_ITEM', 0, ['deactivated' => true], $tenantId);

        return true;
    }
}
