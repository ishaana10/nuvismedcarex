<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Shared\TenantContext;

class RbacService {
    private PDO $db;

    // Granular permissions matrix by role
    private array $rolePermissions = [
        'Developer' => [
            'view_patients', 'create_patient', 'edit_patient', 'delete_patient',
            'view_billing', 'create_invoice', 'process_payment', 'vms_fiscalize',
            'view_inventory', 'manage_inventory', 'restock_inventory',
            'manage_settings', 'view_audit_logs', 'manage_users', 'switch_tenant'
        ],
        'Administrator' => [
            'view_patients', 'create_patient', 'edit_patient',
            'view_billing', 'create_invoice', 'process_payment', 'vms_fiscalize',
            'view_inventory', 'manage_inventory', 'restock_inventory',
            'manage_settings', 'view_audit_logs', 'manage_users', 'switch_tenant'
        ],
        'Doctor' => [
            'view_patients', 'create_patient', 'edit_patient', 'create_encounter',
            'order_labs', 'prescribe_medication', 'view_billing', 'create_invoice',
            'view_inventory', 'restock_inventory'
        ],
        'Nurse' => [
            'view_patients', 'create_patient', 'record_vitals', 'view_inventory'
        ],
        'Receptionist' => [
            'view_patients', 'create_patient', 'manage_appointments', 'manage_queue',
            'view_billing', 'process_payment'
        ]
    ];

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Check if user role has a specific permission
     */
    public function hasPermission(string $role, string $permission): bool {
        $perms = $this->rolePermissions[$role] ?? [];
        return in_array($permission, $perms, true);
    }

    /**
     * Get feature flags for tenant
     */
    public function getFeatureFlags(?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT feature_flags FROM tenants WHERE id = :tid LIMIT 1");
        $stmt->execute(['tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['feature_flags'])) {
            $flags = json_decode($row['feature_flags'], true);
            if (is_array($flags)) {
                return $flags;
            }
        }

        return [
            'vms_fiscalization' => true,
            'inventory_module' => true,
            'telehealth' => false,
            'lab_orders' => true,
            'insurance_claims' => true
        ];
    }

    /**
     * Check if a specific feature flag is enabled for tenant
     */
    public function isFeatureEnabled(string $flag, ?string $tenantId = null): bool {
        $flags = $this->getFeatureFlags($tenantId);
        return !empty($flags[$flag]);
    }
}
