<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Shared\TenantContext;

class InventoryBatchService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Get inventory items expiring within given number of days
     */
    public function getExpiringItems(int $daysThreshold = 60, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $targetDate = date('Y-m-d', strtotime("+{$daysThreshold} days"));

        $stmt = $this->db->prepare("
            SELECT * FROM inventory
            WHERE tenant_id = :tid AND is_active = 1 AND expiry_date IS NOT NULL AND expiry_date <= :targetDate
            ORDER BY expiry_date ASC
        ");
        $stmt->execute(['tid' => $tenantId, 'targetDate' => $targetDate]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get low stock inventory items below threshold
     */
    public function getLowStockItems(?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("
            SELECT * FROM inventory
            WHERE tenant_id = :tid AND is_active = 1 AND current_stock <= min_threshold
            ORDER BY current_stock ASC
        ");
        $stmt->execute(['tid' => $tenantId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
