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
        try {
            $stmt = $this->db->prepare("SELECT id FROM tenants WHERE id = 'default-clinic' LIMIT 1");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $ins = $this->db->prepare("INSERT INTO tenants (id, name, code, status, plan, address, phone, email, storage_limit_mb, is_active) VALUES ('default-clinic', 'Main Suva Central Clinic', 'default-clinic', 'active', 'enterprise', '2 Woodstand Road, Suva', '+679 330 1234', 'suva@clinicflow.org', 500, 1)");
                $ins->execute();
            }
        } catch (\Throwable $e) {
            // Ignore if tenants table not ready
        }

        $stmt = $this->db->query("SELECT id, name, code, status, plan, address, phone, email, storage_limit_mb, is_active FROM tenants ORDER BY CASE WHEN id = 'default-clinic' THEN 0 ELSE 1 END, name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTenantById(string $id): ?array {
        $stmt = $this->db->prepare("SELECT id, name, code, status, plan, address, phone, email, storage_limit_mb, is_active FROM tenants WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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
        if ($tenantId === 'default-clinic') {
            // Always allow switching to default-clinic
            $stmt = $this->db->prepare("SELECT id FROM tenants WHERE id = 'default-clinic' LIMIT 1");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $ins = $this->db->prepare("INSERT INTO tenants (id, name, code, status, plan, storage_limit_mb, is_active) VALUES ('default-clinic', 'Nuvis Medico Healthcare', 'default-clinic', 'active', 'enterprise', 500, 1)");
                $ins->execute();
            } else {
                $upd = $this->db->prepare("UPDATE tenants SET is_active = 1, status = 'active' WHERE id = 'default-clinic'");
                $upd->execute();
            }
            $_SESSION['tenant_id'] = 'default-clinic';
            TenantContext::setTenantId('default-clinic');
            return true;
        }

        $stmt = $this->db->prepare("SELECT id FROM tenants WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $tenantId]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            return false;
        }

        if (isset($tenant['is_active']) && (int)$tenant['is_active'] === 0) {
            $upd = $this->db->prepare("UPDATE tenants SET is_active = 1, status = 'active' WHERE id = :id");
            $upd->execute(['id' => $tenantId]);
        }

        $_SESSION['tenant_id'] = $tenantId;
        TenantContext::setTenantId($tenantId);
        return true;
    }

    public function saveTenant(array $data): void {
        $id = $data['id'];
        $name = $data['name'];
        $code = $data['code'];
        $status = $data['status'] ?? 'active';
        $plan = $data['plan'] ?? 'standard';
        $address = $data['address'] ?? '';
        $phone = $data['phone'] ?? '';
        $email = $data['email'] ?? '';
        $storageLimitMb = (int)($data['storage_limit_mb'] ?? 500);

        $stmt = $this->db->prepare("SELECT id FROM tenants WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        if ($stmt->fetch()) {
            $upd = $this->db->prepare("
                UPDATE tenants
                SET name = :name, code = :code, status = :status, plan = :plan, address = :address, phone = :phone, email = :email, storage_limit_mb = :storage_limit_mb
                WHERE id = :id
            ");
            $upd->execute([
                'id' => $id,
                'name' => $name,
                'code' => $code,
                'status' => $status,
                'plan' => $plan,
                'address' => $address,
                'phone' => $phone,
                'email' => $email,
                'storage_limit_mb' => $storageLimitMb
            ]);
        } else {
            $ins = $this->db->prepare("
                INSERT INTO tenants (id, name, code, status, plan, address, phone, email, storage_limit_mb)
                VALUES (:id, :name, :code, :status, :plan, :address, :phone, :email, :storage_limit_mb)
            ");
            $ins->execute([
                'id' => $id,
                'name' => $name,
                'code' => $code,
                'status' => $status,
                'plan' => $plan,
                'address' => $address,
                'phone' => $phone,
                'email' => $email,
                'storage_limit_mb' => $storageLimitMb
            ]);
        }
    }
}
