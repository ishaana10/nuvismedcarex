<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;
use ClinicFlow\Shared\TenantContext;

class LabOrderService {
    private PDO $db;
    private AuditService $audit;

    public function __construct(PDO $db, ?AuditService $audit = null) {
        $this->db = $db;
        $this->audit = $audit ?? new AuditService($db);
    }

    /**
     * Create a multi-item lab order.
     */
    public function createMultiItemOrder(string $patientId, array $items, string $orderType = 'Lab', ?string $notes = null, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $orderId = Uuid::uuidv7();
        $doctorName = $_SESSION['user_name'] ?? 'Doctor';

        if (empty($items)) {
            throw new \InvalidArgumentException("At least one lab test item is required.");
        }

        $primaryTestName = $items[0]['test_name'] ?? 'Lab Request';
        $primaryCategory = $items[0]['category'] ?? 'General';
        if (count($items) > 1) {
            $primaryTestName .= ' (+' . (count($items) - 1) . ' more)';
        }

        $stmt = $this->db->prepare("
            INSERT INTO lab_orders (id, tenant_id, patient_id, order_type, test_name, category, status, ordered_by, clinical_notes)
            VALUES (:id, :tid, :pid, :type, :test, :cat, 'Ordered', :doc, :notes)
        ");

        $stmt->execute([
            'id' => $orderId,
            'tid' => $tenantId,
            'pid' => $patientId,
            'type' => $orderType,
            'test' => $primaryTestName,
            'cat' => $primaryCategory,
            'doc' => $doctorName,
            'notes' => $notes
        ]);

        $itemStmt = $this->db->prepare("
            INSERT INTO lab_order_items (id, tenant_id, lab_order_id, test_name, category, instructions, status)
            VALUES (:id, :tid, :order_id, :test, :cat, :inst, 'Ordered')
        ");

        foreach ($items as $item) {
            $testName = trim($item['test_name'] ?? '');
            if (empty($testName)) continue;

            $itemStmt->execute([
                'id' => Uuid::uuidv7(),
                'tid' => $tenantId,
                'order_id' => $orderId,
                'test' => $testName,
                'cat' => $item['category'] ?? 'General',
                'inst' => $item['instructions'] ?? ''
            ]);
        }

        $this->audit->log('CREATE_LAB_ORDER', "Created {$orderType} order with " . count($items) . " test item(s) for patient {$patientId}", null, null, null, $tenantId);

        return $this->getOrderWithItems($orderId, $tenantId);
    }

    /**
     * Legacy/Single-item order creation fallback.
     */
    public function createOrder(string $patientId, string $testName, string $orderType = 'Lab', string $category = 'General', ?string $tenantId = null): array {
        return $this->createMultiItemOrder($patientId, [
            ['test_name' => $testName, 'category' => $category, 'instructions' => '']
        ], $orderType, null, $tenantId);
    }

    /**
     * Edit/Update an existing lab order and its test items.
     */
    public function updateOrder(string $orderId, array $items, ?string $notes = null, ?string $status = null, ?string $results = null, ?bool $isAbnormal = null, ?string $tenantId = null): ?array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $order = $this->getOrderById($orderId, $tenantId);

        if (!$order) {
            return null;
        }

        if (empty($items)) {
            throw new \InvalidArgumentException("At least one lab test item is required.");
        }

        $primaryTestName = $items[0]['test_name'] ?? 'Lab Request';
        $primaryCategory = $items[0]['category'] ?? 'General';
        if (count($items) > 1) {
            $primaryTestName .= ' (+' . (count($items) - 1) . ' more)';
        }

        $statusVal = $status ?? $order['status'] ?? 'Ordered';
        $resultsVal = $results ?? $order['results'] ?? null;
        $isAbnormalVal = $isAbnormal !== null ? ($isAbnormal ? 1 : 0) : ($order['is_abnormal'] ?? 0);

        $stmt = $this->db->prepare("
            UPDATE lab_orders
            SET test_name = :test, category = :cat, clinical_notes = :notes, status = :status, results = :results, is_abnormal = :abnormal, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute([
            'test' => $primaryTestName,
            'cat' => $primaryCategory,
            'notes' => $notes,
            'status' => $statusVal,
            'results' => $resultsVal,
            'abnormal' => $isAbnormalVal,
            'id' => $orderId,
            'tid' => $tenantId
        ]);

        // Delete existing items and recreate
        $delStmt = $this->db->prepare("DELETE FROM lab_order_items WHERE lab_order_id = :order_id AND tenant_id = :tid");
        $delStmt->execute(['order_id' => $orderId, 'tid' => $tenantId]);

        $itemStmt = $this->db->prepare("
            INSERT INTO lab_order_items (id, tenant_id, lab_order_id, test_name, category, instructions, status, results, is_abnormal)
            VALUES (:id, :tid, :order_id, :test, :cat, :inst, :status, :results, :is_abnormal)
        ");

        foreach ($items as $item) {
            $testName = trim($item['test_name'] ?? '');
            if (empty($testName)) continue;

            $itemStmt->execute([
                'id' => Uuid::uuidv7(),
                'tid' => $tenantId,
                'order_id' => $orderId,
                'test' => $testName,
                'cat' => $item['category'] ?? 'General',
                'inst' => $item['instructions'] ?? '',
                'status' => $item['status'] ?? $statusVal ?? 'Ordered',
                'results' => $item['results'] ?? $resultsVal ?? null,
                'is_abnormal' => !empty($item['is_abnormal']) ? 1 : ($isAbnormalVal ? 1 : 0)
            ]);
        }

        $this->audit->log('UPDATE_LAB_ORDER', "Updated lab order {$orderId} with " . count($items) . " test item(s)", null, null, null, $tenantId);

        return $this->getOrderWithItems($orderId, $tenantId);
    }

    public function getOrderById(string $id, ?string $tenantId = null): ?array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM lab_orders WHERE id = :id AND tenant_id = :tid LIMIT 1");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getOrderItems(string $orderId, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM lab_order_items WHERE lab_order_id = :order_id AND tenant_id = :tid ORDER BY created_at ASC");
        $stmt->execute(['order_id' => $orderId, 'tid' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOrderWithItems(string $id, ?string $tenantId = null): ?array {
        $order = $this->getOrderById($id, $tenantId);
        if (!$order) return null;

        $order['items'] = $this->getOrderItems($id, $tenantId);
        return $order;
    }

    public function getOrdersByPatient(string $patientId, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM lab_orders WHERE patient_id = :pid AND tenant_id = :tid ORDER BY created_at DESC");
        $stmt->execute(['pid' => $patientId, 'tid' => $tenantId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orders as &$order) {
            $order['items'] = $this->getOrderItems($order['id'], $tenantId);
        }

        return $orders;
    }

    public function attachResults(string $id, string $results, bool $isAbnormal = false, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("
            UPDATE lab_orders SET results = :res, is_abnormal = :abnormal, status = 'Completed', updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute([
            'res' => $results,
            'abnormal' => $isAbnormal ? 1 : 0,
            'id' => $id,
            'tid' => $tenantId
        ]);

        // Also update all order items status to completed
        $itemStmt = $this->db->prepare("
            UPDATE lab_order_items SET status = 'Completed', results = :res, is_abnormal = :abnormal, updated_at = CURRENT_TIMESTAMP
            WHERE lab_order_id = :id AND tenant_id = :tid
        ");
        $itemStmt->execute([
            'res' => $results,
            'abnormal' => $isAbnormal ? 1 : 0,
            'id' => $id,
            'tid' => $tenantId
        ]);

        $this->audit->log('ATTACH_LAB_RESULTS', "Attached lab results for order {$id} (Abnormal: " . ($isAbnormal ? 'YES' : 'NO') . ")", null, null, null, $tenantId);

        return $this->getOrderWithItems($id, $tenantId);
    }

    /**
     * Get customizable Lab Test Catalog (developer/clinic setting).
     */
    public function getLabCatalog(?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();

        // Try tenant_settings first, then clinic_settings
        $stmt = $this->db->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = :tid AND setting_key = 'lab_catalog' LIMIT 1");
        $stmt->execute(['tid' => $tenantId]);
        $val = $stmt->fetchColumn();

        if (!$val) {
            $stmt = $this->db->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'lab_catalog' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
        }

        if ($val) {
            $catalog = json_decode($val, true);
            if (is_array($catalog)) {
                return $catalog;
            }
        }

        // Default fallback catalog
        return [
            [
                'category' => 'Hematology',
                'tests' => ['Complete Blood Count (CBC)', 'Erythrocyte Sedimentation Rate (ESR)', 'Blood Grouping & Rh', 'Hemoglobin (Hb)']
            ],
            [
                'category' => 'Biochemistry',
                'tests' => ['Lipid Profile', 'Fasting Blood Sugar (FBS)', 'HbA1c', 'Liver Function Test (LFT)', 'Renal Function Test (RFT)', 'Serum Electrolytes']
            ],
            [
                'category' => 'Urinalysis & Clinical Pathology',
                'tests' => ['Routine Urinalysis', 'Urine Culture & Sensitivity', 'Stool Microscopy']
            ],
            [
                'category' => 'Microbiology & Serology',
                'tests' => ['Dengue NS1 / IgM / IgG', 'Typhoid Widal Test', 'HIV 1 & 2 Rapid Test', 'HBsAg Rapid Test']
            ],
            [
                'category' => 'Imaging & Diagnostics',
                'tests' => ['Chest X-Ray (PA View)', 'Abdominal Ultrasound', 'Electrocardiogram (ECG)']
            ]
        ];
    }

    /**
     * Save customizable Lab Test Catalog.
     */
    public function saveLabCatalog(array $catalog, ?string $tenantId = null): bool {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $json = json_encode($catalog, JSON_PRETTY_PRINT);

        // Save in clinic_settings
        $isSqlite = ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
        $querySettings = $isSqlite
            ? "INSERT INTO clinic_settings (setting_key, setting_value) VALUES ('lab_catalog', :val) ON CONFLICT(setting_key) DO UPDATE SET setting_value = :val"
            : "INSERT INTO clinic_settings (setting_key, setting_value) VALUES ('lab_catalog', :val) ON DUPLICATE KEY UPDATE setting_value = :val";
        $stmt = $this->db->prepare($querySettings);
        $stmt->execute(['val' => $json]);

        // Also save in tenant_settings if tenant_id present
        if ($tenantId) {
            $id = Uuid::uuidv7();
            $isSqlite = ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
            $query = $isSqlite
                ? "INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (:id, :tid, 'lab_catalog', :val) ON CONFLICT(tenant_id, setting_key) DO UPDATE SET setting_value = :val"
                : "INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (:id, :tid, 'lab_catalog', :val) ON DUPLICATE KEY UPDATE setting_value = :val, updated_at = CURRENT_TIMESTAMP";
            $stmt = $this->db->prepare($query);
            $stmt->execute(['id' => $id, 'tid' => $tenantId, 'val' => $json]);
        }

        $this->audit->log('UPDATE_LAB_CATALOG', "Updated customizable lab test catalog", null, null, null, $tenantId);
        return true;
    }
}
