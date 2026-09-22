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

    public function createOrder(string $patientId, string $testName, string $orderType = 'Lab', string $category = 'General', ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $id = Uuid::uuidv7();
        $doctorName = $_SESSION['user_name'] ?? 'Doctor';

        $stmt = $this->db->prepare("
            INSERT INTO lab_orders (id, tenant_id, patient_id, order_type, test_name, category, status, ordered_by)
            VALUES (:id, :tid, :pid, :type, :test, :cat, 'Ordered', :doc)
        ");

        $stmt->execute([
            'id' => $id,
            'tid' => $tenantId,
            'pid' => $patientId,
            'type' => $orderType,
            'test' => $testName,
            'cat' => $category,
            'doc' => $doctorName
        ]);

        $this->audit->log('CREATE_LAB_ORDER', "Created {$orderType} order '{$testName}' for patient {$patientId}", null, null, null, $tenantId);

        return $this->getOrderById($id, $tenantId);
    }

    public function getOrderById(string $id, ?string $tenantId = null): ?array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM lab_orders WHERE id = :id AND tenant_id = :tid LIMIT 1");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getOrdersByPatient(string $patientId, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM lab_orders WHERE patient_id = :pid AND tenant_id = :tid ORDER BY created_at DESC");
        $stmt->execute(['pid' => $patientId, 'tid' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        $this->audit->log('ATTACH_LAB_RESULTS', "Attached lab results for order {$id} (Abnormal: " . ($isAbnormal ? 'YES' : 'NO') . ")", null, null, null, $tenantId);

        return $this->getOrderById($id, $tenantId);
    }
}
