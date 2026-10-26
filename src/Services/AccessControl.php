<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;

/**
 * Per-clinic, role-based access control.
 *
 * Every role gets one access level per area of the app:
 *   0 = no access, 1 = view, 2 = edit (create + change), 3 = full (edit + delete).
 *
 * Defaults live in this class; each clinic (tenant) can override them, and add
 * its own roles, from Administration → Access & roles. Overrides are stored in
 * tenant_settings under 'access_matrix' and 'access_custom_roles'.
 */
final class AccessControl
{
    public const NONE = 0;
    public const VIEW = 1;
    public const EDIT = 2;
    public const FULL = 3;

    public const LEVEL_LABELS = [
        self::NONE => 'No access',
        self::VIEW => 'View',
        self::EDIT => 'Edit',
        self::FULL => 'Full',
    ];

    /** Areas of the app, in the order they appear on the access screen. */
    public const MODULES = [
        'patients'     => ['label' => 'Patients',             'help' => 'Patient records, registration and charts'],
        'appointments' => ['label' => 'Appointments & queue', 'help' => 'Booking, check-in and the waiting list'],
        'encounters'   => ['label' => 'Clinical encounters',  'help' => 'Visit notes, vitals, prescriptions, lab orders, certificates'],
        'billing'      => ['label' => 'Billing',              'help' => 'Invoices, payments, receipts, insurance claims, VMS'],
        'inventory'    => ['label' => 'Inventory',            'help' => 'Stock, restocking and batches'],
        'documents'    => ['label' => 'Documents',            'help' => 'Uploaded files, scans and lab reports'],
        'settings'     => ['label' => 'Clinic settings',      'help' => 'Branding, receipts, email and integrations'],
        'users'        => ['label' => 'Staff & access',       'help' => 'Staff accounts, roles and this screen'],
        'developer'    => ['label' => 'Developer tools',      'help' => 'Git updates, cache, migrations and API keys'],
    ];

    /** Built-in roles and what they can do out of the box. */
    public const DEFAULT_MATRIX = [
        'Administrator' => ['patients' => 3, 'appointments' => 3, 'encounters' => 3, 'billing' => 3, 'inventory' => 3, 'documents' => 3, 'settings' => 3, 'users' => 3, 'developer' => 0],
        'Doctor'        => ['patients' => 2, 'appointments' => 2, 'encounters' => 3, 'billing' => 2, 'inventory' => 1, 'documents' => 2, 'settings' => 1, 'users' => 0, 'developer' => 0],
        'Nurse'         => ['patients' => 2, 'appointments' => 2, 'encounters' => 2, 'billing' => 0, 'inventory' => 1, 'documents' => 2, 'settings' => 0, 'users' => 0, 'developer' => 0],
        'Receptionist'  => ['patients' => 2, 'appointments' => 3, 'encounters' => 0, 'billing' => 2, 'inventory' => 0, 'documents' => 1, 'settings' => 0, 'users' => 0, 'developer' => 0],
        'Staff'         => ['patients' => 1, 'appointments' => 1, 'encounters' => 0, 'billing' => 0, 'inventory' => 0, 'documents' => 0, 'settings' => 0, 'users' => 0, 'developer' => 0],
        'Developer'     => ['patients' => 3, 'appointments' => 3, 'encounters' => 3, 'billing' => 3, 'inventory' => 3, 'documents' => 3, 'settings' => 3, 'users' => 3, 'developer' => 3],
    ];

    public const ROLE_DESCRIPTIONS = [
        'Administrator' => 'Runs the clinic: staff, settings and every module',
        'Doctor'        => 'Sees patients, writes encounters and bills visits',
        'Nurse'         => 'Records vitals and supports encounters',
        'Receptionist'  => 'Front desk: registrations, bookings and payments',
        'Staff'         => 'Read-only access to patients and the schedule',
        'Developer'     => 'System maintenance across all clinics',
    ];

    /** Older role names found in existing data, mapped onto the built-in roles. */
    public const ROLE_ALIASES = [
        'Admin'            => 'Administrator',
        'System Admin'     => 'Administrator',
        'Clinic Admin'     => 'Administrator',
        'Doctor/Physician' => 'Doctor',
        'Physician'        => 'Doctor',
    ];

    /** Roles that can never lose full control of staff & access (prevents lock-out). */
    public const LOCKED_ROLES = ['Administrator', 'Developer'];

    /** Fallback for roles nobody has configured yet. */
    private const UNKNOWN_ROLE_DEFAULT = ['patients' => 1, 'appointments' => 1];

    private PDO $db;
    private string $tenantId;
    private ?array $overrides = null;
    private ?array $customRoles = null;

    public function __construct(PDO $db, string $tenantId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
    }

    public static function normaliseRole(string $role): string
    {
        $role = trim($role);
        return self::ROLE_ALIASES[$role] ?? $role;
    }

    /** Built-in roles shown on the access screen (Developer only to developers). */
    public function roles(bool $includeDeveloper = false): array
    {
        $roles = array_keys(self::DEFAULT_MATRIX);
        if (!$includeDeveloper) {
            $roles = array_values(array_diff($roles, ['Developer']));
        }
        foreach ($this->customRoles() as $custom) {
            if (!in_array($custom['name'], $roles, true)) {
                $roles[] = $custom['name'];
            }
        }
        return $roles;
    }

    /** @return array<int, array{name:string, description:string}> */
    public function customRoles(): array
    {
        if ($this->customRoles === null) {
            $decoded = json_decode((string) $this->setting('access_custom_roles'), true);
            $this->customRoles = [];
            foreach (is_array($decoded) ? $decoded : [] as $r) {
                $name = is_array($r) ? (string) ($r['name'] ?? '') : (string) $r;
                if ($name !== '') {
                    $this->customRoles[] = ['name' => $name, 'description' => is_array($r) ? (string) ($r['description'] ?? '') : ''];
                }
            }
        }
        return $this->customRoles;
    }

    public function isCustomRole(string $role): bool
    {
        foreach ($this->customRoles() as $r) {
            if ($r['name'] === $role) return true;
        }
        return false;
    }

    public function describe(string $role): string
    {
        $role = self::normaliseRole($role);
        if (isset(self::ROLE_DESCRIPTIONS[$role])) return self::ROLE_DESCRIPTIONS[$role];
        foreach ($this->customRoles() as $r) {
            if ($r['name'] === $role) return $r['description'];
        }
        return '';
    }

    /** Access level (0–3) a role has for a module in this clinic. */
    public function level(string $role, string $module): int
    {
        $role = self::normaliseRole($role);
        if (!isset(self::MODULES[$module])) {
            return self::NONE;
        }
        if ($role === 'Developer') {
            return self::FULL;
        }
        if ($module === 'developer') {
            return self::NONE; // developer tools are never delegated to clinic roles
        }
        if (in_array($role, self::LOCKED_ROLES, true) && $module === 'users') {
            return self::FULL;
        }
        $overrides = $this->overrides();
        if (isset($overrides[$role][$module])) {
            return max(self::NONE, min(self::FULL, (int) $overrides[$role][$module]));
        }
        if (isset(self::DEFAULT_MATRIX[$role][$module])) {
            return self::DEFAULT_MATRIX[$role][$module];
        }
        return self::UNKNOWN_ROLE_DEFAULT[$module] ?? self::NONE;
    }

    public function can(string $role, string $module, int $needed = self::VIEW): bool
    {
        return $this->level($role, $module) >= $needed;
    }

    /** Full matrix for display: role => module => level. */
    public function matrix(array $roles): array
    {
        $out = [];
        foreach ($roles as $role) {
            foreach (array_keys(self::MODULES) as $m) {
                $out[$role][$m] = $this->level($role, $m);
            }
        }
        return $out;
    }

    /**
     * Save the matrix for this clinic. Unknown roles/modules are ignored and
     * locked cells are forced back to their fixed values.
     *
     * @return array<string, array<string, array{0:int,1:int}>> changes as role => module => [old, new]
     */
    public function saveMatrix(array $submitted): array
    {
        $roles = $this->roles(false);
        $clean = $this->overrides();
        $changes = [];
        foreach ($roles as $role) {
            foreach (array_keys(self::MODULES) as $m) {
                if ($m === 'developer' || !isset($submitted[$role][$m])) continue;
                if (in_array($role, self::LOCKED_ROLES, true) && $m === 'users') continue;
                $new = max(self::NONE, min(self::FULL, (int) $submitted[$role][$m]));
                $old = $this->level($role, $m);
                if ($new !== $old) $changes[$role][$m] = [$old, $new];
                $clean[$role][$m] = $new;
            }
        }
        $this->putSetting('access_matrix', json_encode($clean));
        $this->overrides = $clean;
        return $changes;
    }

    public function addRole(string $name, string $description, string $copyFrom): void
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '' || mb_strlen($name) > 40) {
            throw new \InvalidArgumentException('Role names need 1–40 characters.');
        }
        foreach (array_merge(array_keys(self::DEFAULT_MATRIX), array_keys(self::ROLE_ALIASES)) as $taken) {
            if (strcasecmp($taken, $name) === 0) throw new \InvalidArgumentException("“{$name}” is already a built-in role.");
        }
        foreach ($this->customRoles() as $r) {
            if (strcasecmp($r['name'], $name) === 0) throw new \InvalidArgumentException("A role called “{$name}” already exists.");
        }
        $roles = $this->customRoles();
        $roles[] = ['name' => $name, 'description' => mb_substr(trim($description), 0, 120)];
        $this->putSetting('access_custom_roles', json_encode($roles));
        $this->customRoles = $roles;

        $overrides = $this->overrides();
        foreach (array_keys(self::MODULES) as $m) {
            if ($m === 'developer') continue;
            $overrides[$name][$m] = $copyFrom !== '' ? $this->level($copyFrom, $m) : (self::UNKNOWN_ROLE_DEFAULT[$m] ?? self::NONE);
        }
        $this->putSetting('access_matrix', json_encode($overrides));
        $this->overrides = $overrides;
    }

    public function deleteRole(string $name): void
    {
        $roles = array_values(array_filter($this->customRoles(), fn($r) => $r['name'] !== $name));
        $this->putSetting('access_custom_roles', json_encode($roles));
        $this->customRoles = $roles;
        $overrides = $this->overrides();
        unset($overrides[$name]);
        $this->putSetting('access_matrix', json_encode($overrides));
        $this->overrides = $overrides;
    }

    public function resetRole(string $role): void
    {
        $overrides = $this->overrides();
        unset($overrides[$role]);
        $this->putSetting('access_matrix', json_encode($overrides));
        $this->overrides = $overrides;
    }

    // ------------------------------------------------------------------ storage

    private function overrides(): array
    {
        if ($this->overrides === null) {
            $decoded = json_decode((string) $this->setting('access_matrix'), true);
            $this->overrides = is_array($decoded) ? $decoded : [];
        }
        return $this->overrides;
    }

    private function setting(string $key): ?string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = ? LIMIT 1");
            $s->execute([$this->tenantId, $key]);
            $v = $s->fetchColumn();
            return $v === false ? null : (string) $v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function putSetting(string $key, string $value): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? "INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (?, ?, ?, ?) ON CONFLICT(tenant_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value"
            : "INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP";
        $this->db->prepare($sql)->execute(['ts-' . $this->tenantId . '-' . $key, $this->tenantId, $key, $value]);
    }
}
