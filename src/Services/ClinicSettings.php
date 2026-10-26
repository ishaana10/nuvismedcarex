<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;

/**
 * Settings for the active clinic.
 *
 * There are two stores:
 *   clinic_settings  — one shared row per key, for things that belong to the
 *                      installation rather than a clinic (SMTP, git, cloud
 *                      storage, RBAC). These act as defaults.
 *   tenant_settings  — one row per clinic per key. Anything a clinic owns —
 *                      its name, address, document wording, tax numbers,
 *                      inventory set-up — lives here.
 *
 * Reads merge the two (clinic value wins). Writes go to the store that owns
 * the key: PER_CLINIC keys are never written to the shared table, so one
 * clinic can no longer overwrite another's branding.
 */
final class ClinicSettings
{
    /**
     * Keys each clinic owns. Prefix entries ending in '*' match by prefix.
     * Everything not listed here is treated as an installation-wide default.
     */
    private const PER_CLINIC = [
        'clinic_name', 'clinic_subtitle', 'clinic_address', 'clinic_phone', 'clinic_email',
        'clinic_dea', 'clinic_npi', 'clinic_logo', 'clinic_website', 'clinic_accent',
        'doc_prc_no', 'doc_ptr_no',
        'rx_*', 'invoice_*', 'receipt_*', 'cert_*', 'lab_catalog',
        'vms_*', 'inventory_*', 'access_*', 'sse_notifications_enabled',
        // Each clinic sends patient email from its own mailbox
        'smtp_*', 'email_*',
    ];

    /** Keys that are always installation-wide, even if they match above. */
    private const ALWAYS_GLOBAL = [
        'git_*', 'cloud_*', 'rbac_*', 'update_branch', 'dev_environment',
        'default_pagination_limit', 'inventory_api_key',
    ];

    private PDO $db;
    private string $tenantId;
    private ?array $cache = null;

    public function __construct(PDO $db, string $tenantId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
    }

    public static function isPerClinic(string $key): bool
    {
        foreach (self::ALWAYS_GLOBAL as $p) {
            if (self::matches($key, $p)) return false;
        }
        foreach (self::PER_CLINIC as $p) {
            if (self::matches($key, $p)) return true;
        }
        return false;
    }

    private static function matches(string $key, string $pattern): bool
    {
        return str_ends_with($pattern, '*')
            ? str_starts_with($key, substr($pattern, 0, -1))
            : $key === $pattern;
    }

    /** Installation defaults overlaid with this clinic's own values. */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $merged = [];
        try {
            $rows = $this->db->query("SELECT setting_key, setting_value FROM clinic_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            foreach ($rows as $k => $v) {
                // A shared value for a key a clinic owns is only a starting point
                $merged[$k] = $v;
            }
        } catch (\Throwable $e) {
            error_log('[ClinicSettings] global: ' . $e->getMessage());
        }
        try {
            $st = $this->db->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
            $st->execute([$this->tenantId]);
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [] as $k => $v) {
                $merged[$k] = $v;
            }
        } catch (\Throwable $e) {
            error_log('[ClinicSettings] tenant: ' . $e->getMessage());
        }
        return $this->cache = $merged;
    }

    public function get(string $key, $default = null)
    {
        $all = $this->all();
        $v = $all[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    /** Values this clinic has set itself (no installation defaults). */
    public function own(): array
    {
        try {
            $st = $this->db->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
            $st->execute([$this->tenantId]);
            return $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Save settings, each to the store that owns it.
     * @return array{clinic:int, global:int} how many values went to each store
     */
    public function save(array $values): array
    {
        $counts = ['clinic' => 0, 'global' => 0];
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        $tenantSql = $driver === 'sqlite'
            ? "INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (?, ?, ?, ?) ON CONFLICT(tenant_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP"
            : "INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP";
        $globalSql = $driver === 'sqlite'
            ? "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value"
            : "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $tenantStmt = $this->db->prepare($tenantSql);
        $globalStmt = $this->db->prepare($globalSql);

        foreach ($values as $key => $val) {
            $val = (string) $val;
            if (self::isPerClinic($key)) {
                $tenantStmt->execute(['ts-' . $this->tenantId . '-' . $key, $this->tenantId, $key, $val]);
                $counts['clinic']++;
            } else {
                $globalStmt->execute([$key, $val]);
                $counts['global']++;
            }
        }
        $this->cache = null;
        return $counts;
    }

    /** Drop this clinic's own value so the installation default applies again. */
    public function clear(string ...$keys): void
    {
        $st = $this->db->prepare("DELETE FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?");
        foreach ($keys as $k) {
            $st->execute([$this->tenantId, $k]);
        }
        $this->cache = null;
    }

    /**
     * The clinic's identity for headers, printed documents and emails.
     * The name comes from this clinic's own setting, then the tenant record,
     * then the installation default — never another clinic's value.
     */
    public function profile(): array
    {
        $s = $this->all();
        $own = $this->own();
        $tenant = [];
        try {
            $st = $this->db->prepare("SELECT name, address, phone, email, currency FROM tenants WHERE id = ? LIMIT 1");
            $st->execute([$this->tenantId]);
            $tenant = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {}

        $pick = static function (string $key, array $own, array $tenant, array $merged, string $tenantCol, string $fallback = '') {
            foreach ([$own[$key] ?? null, $tenant[$tenantCol] ?? null, $merged[$key] ?? null] as $v) {
                if ($v !== null && trim((string) $v) !== '') return (string) $v;
            }
            return $fallback;
        };

        return [
            'id'       => $this->tenantId,
            'name'     => $pick('clinic_name', $own, $tenant, $s, 'name', 'Nuvis Medcare X Clinic'),
            'subtitle' => (string) ($own['clinic_subtitle'] ?? $s['clinic_subtitle'] ?? ''),
            'address'  => $pick('clinic_address', $own, $tenant, $s, 'address'),
            'phone'    => $pick('clinic_phone', $own, $tenant, $s, 'phone'),
            'email'    => $pick('clinic_email', $own, $tenant, $s, 'email'),
            'website'  => (string) ($own['clinic_website'] ?? $s['clinic_website'] ?? ''),
            'logo'     => (string) ($own['clinic_logo'] ?? $s['clinic_logo'] ?? ''),
            'license'  => (string) ($own['doc_prc_no'] ?? $s['doc_prc_no'] ?? ''),
            'dea'      => (string) ($own['clinic_dea'] ?? $s['clinic_dea'] ?? ''),
            'npi'      => (string) ($own['clinic_npi'] ?? $s['clinic_npi'] ?? ''),
            'tin'      => (string) ($own['vms_seller_tin'] ?? $own['invoice_tax_id'] ?? $s['vms_seller_tin'] ?? $s['invoice_tax_id'] ?? ''),
            'currency' => (string) ($tenant['currency'] ?? 'FJD'),
        ];
    }

    public const MAX_LOGO_BYTES = 300 * 1024;

    /** Accept PNG, JPEG, WebP or SVG up to MAX_LOGO_BYTES; return a data: URI. */
    public static function logoToDataUri(array $file): string
    {
        if (($file['size'] ?? 0) > self::MAX_LOGO_BYTES) {
            throw new \InvalidArgumentException('The logo is larger than 300 KB. Use a smaller image.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) {
            throw new \InvalidArgumentException('The logo could not be read. Try uploading it again.');
        }
        $info = @getimagesize($tmp);
        $allowed = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];
        if ($info && isset($allowed[$info[2]])) {
            return 'data:' . $allowed[$info[2]] . ';base64,' . base64_encode((string) file_get_contents($tmp));
        }
        throw new \InvalidArgumentException('The logo must be a PNG, JPG or WebP image.');
    }
}
