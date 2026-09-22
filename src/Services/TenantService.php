<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Shared\TenantContext;

class TenantService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getAllTenants(): array {
        $stmt = $this->db->query("SELECT id, name, code, status, plan, address, phone, email, is_active FROM tenants ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUserTenants(string $userId): array {
        $stmt = $this->db->prepare("
            SELECT t.id, t.name, t.code, ut.role, ut.is_default
            FROM tenants t
            JOIN user_tenants ut ON t.id = ut.tenant_id
            WHERE ut.user_id = :uid AND t.is_active = 1
        ");
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function switchTenant(string $tenantId): bool {
        $stmt = $this->db->prepare("SELECT id FROM tenants WHERE id = :id AND is_active = 1 LIMIT 1");
        $stmt->execute(['id' => $tenantId]);
        if (!$stmt->fetch()) {
            return false;
        }

        $_SESSION['tenant_id'] = $tenantId;
        TenantContext::setTenantId($tenantId);
        return true;
    }
}
