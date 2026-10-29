<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;

/**
 * Per-clinic insurance report layouts.
 *
 * Every insurer wants its claim listing a little differently: one asks for the
 * member number and the scheme code, another only wants invoice totals, a third
 * insists on a declaration at the foot of the page. So a clinic keeps one
 * default layout plus, optionally, a named layout per provider. Everything is
 * stored as JSON in tenant_settings under 'insurance_report_config', which
 * ClinicSettings treats as owned by the clinic.
 */
final class InsuranceReportSettings
{
    public const SETTING_KEY = 'insurance_report_config';

    /** Column key => [label, alignment: text|money|date|number] */
    public const COLUMNS = [
        'claim_ref'         => ['Claim reference', 'text'],
        'claim_date'        => ['Date submitted', 'date'],
        'settled_date'      => ['Date settled', 'date'],
        'status'            => ['Status', 'text'],
        'patient_name'      => ['Patient', 'text'],
        'patient_mrn'       => ['MRN', 'text'],
        'patient_dob'       => ['Date of birth', 'date'],
        'policy_number'     => ['Policy / member number', 'text'],
        'group_number'      => ['Scheme / group number', 'text'],
        'provider'          => ['Provider', 'text'],
        'invoice_number'    => ['Invoice number', 'text'],
        'service_date'      => ['Date of service', 'date'],
        'invoice_total'     => ['Invoice total', 'money'],
        'invoice_tax'       => ['VAT on invoice', 'money'],
        'claim_amount'      => ['Amount claimed', 'money'],
        'insurance_covered' => ['Covered on invoice', 'money'],
        'patient_share'     => ["Patient's share", 'money'],
        'age_days'          => ['Days outstanding', 'number'],
        // 'signed' draws the member's actual signature; the name alone is a
        // separate column for reports that only need to record who signed.
        'signed'            => ['Member signature', 'signature'],
        'signed_by_name'    => ['Signed by (name)', 'text'],
        'signed_at'         => ['Date signed', 'date'],
        'notes'             => ['Notes', 'text'],
    ];

    public const GROUPINGS = [
        'none'     => 'One flat list',
        'status'   => 'Group by status',
        'month'    => 'Group by month submitted',
        'patient'  => 'Group by patient',
    ];

    public const STATUSES = ['Submitted', 'Pending', 'Approved', 'Rejected', 'Paid'];

    /** What a printed or downloaded copy contains. */
    public const EXPORT_SCOPES = [
        'full'  => 'The full report — summary, ageing and the claim list',
        'table' => 'Only the claim list, in this layout',
    ];

    public const ORIENTATIONS = ['portrait' => 'Portrait', 'landscape' => 'Landscape'];

    /** Columns a brand-new layout starts with. */
    public const DEFAULT_COLUMNS = [
        'claim_ref', 'claim_date', 'patient_name', 'patient_mrn', 'policy_number',
        'invoice_number', 'service_date', 'claim_amount', 'status',
    ];

    public const MAX_PROVIDERS = 24;

    private PDO $db;
    private string $tenantId;
    private ?array $cache = null;

    public function __construct(PDO $db, string $tenantId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
    }

    /** A layout with nothing customised. */
    public static function defaultLayout(string $label = 'Default layout'): array
    {
        return [
            'label'           => $label,
            'title'           => 'Insurance claim report',
            'columns'         => self::DEFAULT_COLUMNS,
            'reference_label' => 'Policy / member number',
            'claim_prefix'    => '',
            'statuses'        => [],          // empty = every status
            'group_by'        => 'none',
            'show_summary'    => true,
            'show_aging'      => true,
            'show_logo'       => true,
            'show_signature'  => true,
            // 'full' prints the summary, the ageing table and anything billed
            // without a claim; 'table' prints the claim list and nothing else.
            'export_scope'    => 'full',
            // Page shape for the printed/PDF copy. Landscape suits a layout
            // with many columns; portrait is the usual A4 sheet.
            'orientation'     => 'portrait',
            'footer_note'     => '',
        ];
    }

    /** ['default' => layout, 'providers' => [name => layout]] */
    public function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $config = ['default' => self::defaultLayout(), 'providers' => []];
        $saved = json_decode((string) $this->setting(self::SETTING_KEY), true);
        if (is_array($saved)) {
            if (isset($saved['default']) && is_array($saved['default'])) {
                $config['default'] = self::sanitiseLayout($saved['default'], self::defaultLayout());
            }
            foreach ((array) ($saved['providers'] ?? []) as $name => $layout) {
                $name = self::cleanProviderName((string) $name);
                if ($name === '' || !is_array($layout)) {
                    continue;
                }
                $config['providers'][$name] = self::sanitiseLayout($layout, self::defaultLayout($name));
                if (count($config['providers']) >= self::MAX_PROVIDERS) {
                    break;
                }
            }
        }
        return $this->cache = $config;
    }

    /**
     * The layout to print for a provider: its own if it has one, else the
     * clinic default. The second element says which was used.
     *
     * @return array{0: array, 1: string}
     */
    public function layoutFor(?string $provider): array
    {
        $config = $this->load();
        $name = self::cleanProviderName((string) $provider);
        if ($name !== '' && isset($config['providers'][$name])) {
            return [$config['providers'][$name], $name];
        }
        // Match case-insensitively so "BSP health" finds "BSP Health"
        foreach ($config['providers'] as $key => $layout) {
            if (strcasecmp($key, $name) === 0) {
                return [$layout, $key];
            }
        }
        return [$config['default'], ''];
    }

    /** Provider names that have a layout of their own. */
    public function customisedProviders(): array
    {
        return array_keys($this->load()['providers']);
    }

    /** Validate one submitted layout; unknown keys and columns are dropped. */
    public static function sanitiseLayout(array $in, array $base): array
    {
        $out = $base;
        $str = static fn(string $k, int $max, string $fallback = ''): string
            => mb_substr(trim((string) ($in[$k] ?? $fallback)), 0, $max);

        if (array_key_exists('label', $in)) {
            $out['label'] = self::cleanProviderName($str('label', 80)) ?: $base['label'];
        }
        if (array_key_exists('title', $in)) {
            $out['title'] = $str('title', 90) ?: 'Insurance claim report';
        }
        if (array_key_exists('reference_label', $in)) {
            $out['reference_label'] = $str('reference_label', 40) ?: 'Policy / member number';
        }
        if (array_key_exists('claim_prefix', $in)) {
            $out['claim_prefix'] = preg_replace('/[^A-Za-z0-9\-\/#_.]/', '', $str('claim_prefix', 12)) ?? '';
        }
        if (array_key_exists('footer_note', $in)) {
            $out['footer_note'] = $str('footer_note', 600);
        }
        if (isset(self::GROUPINGS[$in['group_by'] ?? ''])) {
            $out['group_by'] = (string) $in['group_by'];
        }
        if (in_array($in['export_scope'] ?? '', ['full', 'table'], true)) {
            $out['export_scope'] = (string) $in['export_scope'];
        }
        if (in_array($in['orientation'] ?? '', ['portrait', 'landscape'], true)) {
            $out['orientation'] = (string) $in['orientation'];
        }
        foreach (['show_summary', 'show_aging', 'show_logo', 'show_signature'] as $flag) {
            if (array_key_exists('_toggles_present', $in) || array_key_exists($flag, $in)) {
                $out[$flag] = !empty($in[$flag]);
            }
        }

        if (array_key_exists('columns', $in)) {
            $columns = [];
            foreach (self::asList($in['columns']) as $key) {
                if (isset(self::COLUMNS[$key]) && !in_array($key, $columns, true)) {
                    $columns[] = $key;
                }
            }
            $out['columns'] = $columns ?: self::DEFAULT_COLUMNS;
        }

        if (array_key_exists('statuses', $in)) {
            $statuses = [];
            foreach (self::asList($in['statuses']) as $status) {
                if (in_array($status, self::STATUSES, true) && !in_array($status, $statuses, true)) {
                    $statuses[] = $status;
                }
            }
            // Choosing every status means the same as choosing none
            $out['statuses'] = count($statuses) === count(self::STATUSES) ? [] : $statuses;
        }

        return $out;
    }

    /** Save the clinic default. */
    public function saveDefault(array $layout): void
    {
        $config = $this->load();
        $config['default'] = self::sanitiseLayout($layout, $config['default']);
        $this->store($config);
    }

    /** Add or replace one provider's layout. */
    public function saveProvider(string $provider, array $layout): string
    {
        $name = self::cleanProviderName($provider);
        if ($name === '') {
            throw new \InvalidArgumentException('Give the insurance provider a name.');
        }
        $config = $this->load();
        if (!isset($config['providers'][$name]) && count($config['providers']) >= self::MAX_PROVIDERS) {
            throw new \InvalidArgumentException('You already have ' . self::MAX_PROVIDERS . ' provider layouts. Remove one first.');
        }
        $base = $config['providers'][$name] ?? array_merge($config['default'], ['label' => $name]);
        $layout['label'] = $name;
        $config['providers'][$name] = self::sanitiseLayout($layout, $base);
        ksort($config['providers'], SORT_NATURAL | SORT_FLAG_CASE);
        $this->store($config);
        return $name;
    }

    /** Drop a provider layout so the clinic default applies to it again. */
    public function removeProvider(string $provider): bool
    {
        $config = $this->load();
        $name = self::cleanProviderName($provider);
        foreach (array_keys($config['providers']) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                unset($config['providers'][$key]);
                $this->store($config);
                return true;
            }
        }
        return false;
    }

    public static function cleanProviderName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        return mb_substr($name, 0, 80);
    }

    /** Accept either an array of values or a comma separated string. */
    private static function asList($value): array
    {
        if (is_array($value)) {
            return array_map(static fn($v) => trim((string) $v), $value);
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($v) => $v !== ''));
    }

    private function store(array $config): void
    {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
}
