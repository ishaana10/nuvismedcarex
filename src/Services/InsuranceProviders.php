<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;

/**
 * The insurers a clinic works with, kept per clinic so a claim can be raised by
 * picking from a list rather than retyping a name — which is what made the same
 * insurer appear three times, spelled three ways, in the reports.
 *
 * Stored as JSON in tenant_settings under 'insurance_providers'.
 */
final class InsuranceProviders
{
    public const SETTING_KEY = 'insurance_providers';
    public const MAX = 40;

    /** Offered on a brand-new clinic so the list is never empty to start with. */
    public const SUGGESTED = [
        'Fiji National Provident Fund',
        'BSP Life Health',
        'Sun Insurance',
        'Tower Insurance',
        'FijiCare Insurance',
        'Dominion Insurance',
    ];

    private PDO $db;
    private string $tenantId;
    private ?array $cache = null;

    public function __construct(PDO $db, string $tenantId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
    }

    public static function blank(): array
    {
        return [
            'name'             => '',
            'code'             => '',
            'policy_label'     => 'Policy / member number',
            'default_coverage' => 0.0,   // percent of the bill the insurer usually covers
            'claim_window_days' => 0,    // days the insurer allows for submission, 0 = not set
            'contact_email'    => '',
            'contact_phone'    => '',
            'notes'            => '',
            'active'           => true,
        ];
    }

    /** Every provider on file, in the clinic's own order. */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows = [];
        $saved = json_decode((string) $this->setting(self::SETTING_KEY), true);
        if (is_array($saved)) {
            foreach ($saved as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $clean = self::sanitise($row);
                if ($clean['name'] !== '') {
                    $rows[] = $clean;
                }
                if (count($rows) >= self::MAX) {
                    break;
                }
            }
        }
        return $this->cache = $rows;
    }

    /** Just the ones still in use, for pickers. */
    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn($p) => !empty($p['active'])));
    }

    /** Provider names only — active first, for a select list. */
    public function names(bool $activeOnly = true): array
    {
        return array_map(
            static fn($p) => $p['name'],
            $activeOnly ? $this->active() : $this->all()
        );
    }

    public function find(?string $name): ?array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        foreach ($this->all() as $p) {
            if (strcasecmp($p['name'], $name) === 0) {
                return $p;
            }
        }
        return null;
    }

    /** True when this name is not on the clinic's list. */
    public function isUnknown(?string $name): bool
    {
        return trim((string) $name) !== '' && $this->find($name) === null;
    }

    public static function sanitise(array $in): array
    {
        $out = self::blank();
        $str = static fn(string $k, int $max): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);

        $out['name'] = InsuranceReportSettings::cleanProviderName($str('name', 80));
        $out['code'] = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $str('code', 12)) ?? '');
        $out['policy_label'] = $str('policy_label', 40) ?: 'Policy / member number';
        $out['contact_email'] = filter_var($str('contact_email', 120), FILTER_VALIDATE_EMAIL) ?: '';
        $out['contact_phone'] = $str('contact_phone', 40);
        $out['notes'] = $str('notes', 400);

        $coverage = (float) ($in['default_coverage'] ?? 0);
        $out['default_coverage'] = round(max(0.0, min(100.0, $coverage)), 2);

        $window = (int) ($in['claim_window_days'] ?? 0);
        $out['claim_window_days'] = max(0, min(365, $window));

        $out['active'] = !empty($in['active']);
        return $out;
    }

    /**
     * Replace the whole list from a submitted form. Rows without a name are
     * dropped, and a duplicate name keeps the first row rather than silently
     * creating two providers that read the same in every report.
     *
     * @return array{saved:int, duplicates:string[]}
     */
    public function saveAll(array $rows): array
    {
        $clean = [];
        $seen = [];
        $duplicates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $p = self::sanitise($row);
            if ($p['name'] === '') {
                continue;
            }
            $key = mb_strtolower($p['name']);
            if (isset($seen[$key])) {
                $duplicates[] = $p['name'];
                continue;
            }
            $seen[$key] = true;
            $clean[] = $p;
            if (count($clean) >= self::MAX) {
                break;
            }
        }
        usort($clean, static fn($a, $b) => [!$a['active'], mb_strtolower($a['name'])] <=> [!$b['active'], mb_strtolower($b['name'])]);
        $this->store($clean);
        return ['saved' => count($clean), 'duplicates' => $duplicates];
    }

    /** Add one provider if it is not already on the list; returns its name. */
    public function addIfMissing(string $name): string
    {
        $name = InsuranceReportSettings::cleanProviderName($name);
        if ($name === '' || $this->find($name) !== null) {
            return $name;
        }
        $rows = $this->all();
        if (count($rows) >= self::MAX) {
            return $name;
        }
        $rows[] = array_merge(self::blank(), ['name' => $name, 'active' => true]);
        $this->store($rows);
        return $name;
    }

    private function store(array $rows): void
    {
        $json = json_encode(array_values($rows), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? "INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (?, ?, ?, ?) ON CONFLICT(tenant_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP"
            : "INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP";
        $this->db->prepare($sql)->execute([
            'ts-' . $this->tenantId . '-' . self::SETTING_KEY,
            $this->tenantId,
            self::SETTING_KEY,
            (string) $json,
        ]);
        $this->cache = null;
    }

    private function setting(string $key): ?string
    {
        try {
            $st = $this->db->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = ? LIMIT 1");
            $st->execute([$this->tenantId, $key]);
            $v = $st->fetchColumn();
            return $v === false ? null : (string) $v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Insurer names already recorded against patients or claims but missing
     * from the clinic's list, so they can be adopted in one click.
     */
    public function unlisted(): array
    {
        $known = array_map('mb_strtolower', $this->names(false));
        $found = [];
        foreach ([
            "SELECT DISTINCT provider_name AS n FROM insurance_claims WHERE tenant_id = ? AND provider_name <> ''",
            "SELECT DISTINCT insurance_provider AS n FROM patients WHERE tenant_id = ? AND insurance_provider IS NOT NULL AND insurance_provider <> ''",
        ] as $sql) {
            try {
                $st = $this->db->prepare($sql);
                $st->execute([$this->tenantId]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
                    $clean = InsuranceReportSettings::cleanProviderName((string) $name);
                    if ($clean !== '' && !in_array(mb_strtolower($clean), $known, true)) {
                        $found[mb_strtolower($clean)] = $clean;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[InsuranceProviders] unlisted: ' . $e->getMessage());
            }
        }
        $found = array_values($found);
        usort($found, 'strnatcasecmp');
        return $found;
    }
}
