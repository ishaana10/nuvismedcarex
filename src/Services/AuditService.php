<?php

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Shared\TenantContext;

class AuditService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function log(string $action, string $details, ?string $userId = null, ?string $userName = null, ?string $userRole = null, ?string $tenantId = null): string {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $userId = $userId ?? ($_SESSION['user_id'] ?? 'SYS-001');
        $userName = $userName ?? ($_SESSION['user_name'] ?? 'System Admin');
        $userRole = $userRole ?? ($_SESSION['user_role'] ?? 'System');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $logId = 'audit-' . uniqid();

        $stmt = $this->db->prepare(
            "INSERT INTO audit_logs (id, tenant_id, user_id, user_name, user_role, action, details, ip_address, created_at) " .
            "VALUES (:id, :tenant_id, :user_id, :user_name, :user_role, :action, :details, :ip, :created_at)"
        );

        $stmt->execute([
            'id' => $logId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'user_name' => $userName,
            'user_role' => $userRole,
            'action' => $action,
            'details' => $details,
            'ip' => $ip,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return $logId;
    }

    public function logInventoryChange(string $inventoryId, string $action, int $changeAmount, array $meta = [], ?string $tenantId = null): string {
        $details = sprintf("Inventory ID %s [%s]: Change %d. Meta: %s", $inventoryId, $action, $changeAmount, json_encode($meta));
        return $this->log("INVENTORY_" . strtoupper($action), $details, null, null, null, $tenantId);
    }
}
