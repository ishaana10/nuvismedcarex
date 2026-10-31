<?php

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;

/**
 * Multi-location support for a clinic tenant. A tenant (clinic account) can run
 * several physical branches; each lives in clinic_locations and operational
 * records may be tagged with a location_id. The "active" location for the
 * session is remembered per tenant.
 */
class LocationService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** All locations for a tenant, primary first then by name. */
    public function getLocations(string $tenantId, bool $activeOnly = false): array
    {
        try {
            $sql = "SELECT * FROM clinic_locations WHERE tenant_id = ?";
            if ($activeOnly) {
                $sql .= " AND is_active = 1";
            }
            $sql .= " ORDER BY is_primary DESC, name ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$tenantId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getLocation(string $id): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM clinic_locations WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function countLocations(string $tenantId): int
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM clinic_locations WHERE tenant_id = ? AND is_active = 1");
            $stmt->execute([$tenantId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Create or update a location. Returns the location id.
     * If the saved location is flagged primary, any sibling primary is cleared.
     */
    public function saveLocation(string $tenantId, array $data): string
    {
        $id = trim((string) ($data['id'] ?? ''));
        $isPrimary = !empty($data['is_primary']) ? 1 : 0;
        $isActive = isset($data['is_active']) ? (int) !empty($data['is_active']) : 1;

        $fields = [
            'tenant_id'  => $tenantId,
            'name'       => trim((string) ($data['name'] ?? '')),
            'code'       => trim((string) ($data['code'] ?? '')),
            'address'    => trim((string) ($data['address'] ?? '')),
            'phone'      => trim((string) ($data['phone'] ?? '')),
            'email'      => trim((string) ($data['email'] ?? '')),
            'timezone'   => trim((string) ($data['timezone'] ?? 'Pacific/Fiji')),
            'is_primary' => $isPrimary,
            'is_active'  => $isActive,
        ];

        if ($id === '') {
            $id = Uuid::uuidv7();
            $cols = array_keys($fields);
            $place = implode(', ', array_fill(0, count($cols), '?'));
            $stmt = $this->db->prepare(
                "INSERT INTO clinic_locations (id, " . implode(', ', $cols) . ") VALUES (?, {$place})"
            );
            $stmt->execute(array_merge([$id], array_values($fields)));
        } else {
            $sets = [];
            foreach ($fields as $k => $v) {
                $sets[] = "`{$k}` = ?";
            }
            $sets[] = "updated_at = CURRENT_TIMESTAMP";
            $stmt = $this->db->prepare(
                "UPDATE clinic_locations SET " . implode(', ', $sets) . " WHERE id = ? AND tenant_id = ?"
            );
            $stmt->execute(array_merge(array_values($fields), [$id, $tenantId]));
        }

        if ($isPrimary) {
            $clear = $this->db->prepare(
                "UPDATE clinic_locations SET is_primary = 0 WHERE tenant_id = ? AND id <> ?"
            );
            $clear->execute([$tenantId, $id]);
        }

        return $id;
    }

    /** Soft-deactivate (never hard-delete; records may reference it). */
    public function deactivateLocation(string $tenantId, string $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE clinic_locations SET is_active = 0, is_primary = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$id, $tenantId]);
    }

    // --- Session "active location" ------------------------------------------

    /** The location the user is currently working in, for this tenant. */
    public function getCurrentLocationId(string $tenantId): ?string
    {
        $stored = $_SESSION['current_location'][$tenantId] ?? null;
        if ($stored && $this->belongsToTenant($stored, $tenantId)) {
            return $stored;
        }
        // Fall back to the primary (or first active) location.
        $locations = $this->getLocations($tenantId, true);
        if (!empty($locations)) {
            return $locations[0]['id'];
        }
        return null;
    }

    public function setCurrentLocationId(string $tenantId, string $locationId): bool
    {
        if ($locationId !== '' && !$this->belongsToTenant($locationId, $tenantId)) {
            return false;
        }
        if (!isset($_SESSION['current_location']) || !is_array($_SESSION['current_location'])) {
            $_SESSION['current_location'] = [];
        }
        $_SESSION['current_location'][$tenantId] = $locationId;
        return true;
    }

    private function belongsToTenant(string $id, string $tenantId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM clinic_locations WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenantId]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
