<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;

/**
 * Per-clinic payment receipt design: paper size, colour, logo, which details
 * are printed, and the wording. Stored as JSON in tenant_settings
 * ('receipt_config'); the older 'receipt_header_title' and
 * 'receipt_thank_you_msg' settings are used as defaults when present.
 */
final class ReceiptSettings
{
    public const PAPER = [
        'a4'        => 'A4 page',
        'letter'    => 'US Letter page',
        'thermal80' => 'Receipt printer, 80 mm roll',
        'thermal58' => 'Receipt printer, 58 mm roll',
    ];
    public const SIZES = ['compact' => 'Compact', 'standard' => 'Standard', 'large' => 'Large'];
    public const DATE_FORMATS = [
        'd M Y'  => '26 Oct 2026',
        'd/m/Y'  => '26/10/2026',
        'M j, Y' => 'Oct 26, 2026',
        'Y-m-d'  => '2026-10-26',
    ];
    public const ACCENTS = [
        '#0E7C63' => 'Lagoon green',
        '#164487' => 'Nuvis blue',
        '#0F2B5C' => 'Navy',
        '#B0640A' => 'Amber',
        '#972541' => 'Rose',
        '#364354' => 'Charcoal',
    ];
    public const MAX_LOGO_BYTES = 300 * 1024;

    /** Toggles shown on the settings screen, grouped. key => [label, default] */
    public const TOGGLES = [
        'Clinic details' => [
            'show_logo'      => ['Logo', true],
            'show_address'   => ['Address', true],
            'show_phone'     => ['Phone number', true],
            'show_email'     => ['Email address', true],
            'show_license'   => ['Medical licence number', false],
            'show_tin'       => ['Tax ID (TIN)', true],
        ],
        'Patient & visit' => [
            'show_mrn'          => ['Medical record number (MRN)', true],
            'show_service_date' => ['Date of service', true],
            'show_encounter'    => ['Encounter reference', false],
            'show_cashier'      => ['Who took the payment', true],
        ],
        'Amounts' => [
            'show_qty'        => ['Quantity column', true],
            'show_unit_price' => ['Unit price column', false],
            'show_tax'        => ['VAT breakdown', true],
            'show_payments'   => ['Payment methods', true],
            'show_balance'    => ['Balance remaining', true],
        ],
        'Sign-off' => [
            'show_signature' => ['Signature', true],
            'show_stamp'     => ['Clinic stamp', true],
            'show_qr'        => ['FRCS verification QR code (fiscalised invoices)', true],
        ],
    ];

    private PDO $db;
    private string $tenantId;

    public function __construct(PDO $db, string $tenantId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
    }

    public static function defaults(): array
    {
        $d = [
            'paper'          => 'a4',
            'accent'         => '#0E7C63',
            'size'           => 'standard',
            'date_format'    => 'd M Y',
            'logo'           => '',
            'logo_align'     => 'left',
            'title'          => 'Payment receipt',
            'number_prefix'  => 'REC-',
            'thank_you'      => 'Thank you for your payment.',
            'footer_note'    => '',
            'currency'       => '',
            'copies'         => 1,
        ];
        foreach (self::TOGGLES as $group) {
            foreach ($group as $k => [, $def]) $d[$k] = $def;
        }
        return $d;
    }

    public function load(): array
    {
        $cfg = self::defaults();
        $legacyTitle = $this->setting('receipt_header_title');
        $legacyThanks = $this->setting('receipt_thank_you_msg');
        if ($legacyTitle) $cfg['title'] = $legacyTitle;
        if ($legacyThanks) $cfg['thank_you'] = $legacyThanks;
        $saved = json_decode((string) $this->setting('receipt_config'), true);
        if (is_array($saved)) {
            $cfg = array_merge($cfg, array_intersect_key($saved, $cfg));
        }
        return $cfg;
    }

    /** Validate submitted form values into a clean config (unknown keys dropped). */
    public static function sanitise(array $in, array $current, ?array $logoUpload = null): array
    {
        $cfg = $current;
        $str = fn(string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);

        if (isset(self::PAPER[$in['paper'] ?? ''])) $cfg['paper'] = $in['paper'];
        if (isset(self::SIZES[$in['size'] ?? ''])) $cfg['size'] = $in['size'];
        if (isset(self::DATE_FORMATS[$in['date_format'] ?? ''])) $cfg['date_format'] = $in['date_format'];
        if (preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($in['accent'] ?? ''))) $cfg['accent'] = strtoupper($in['accent']);
        if (in_array($in['logo_align'] ?? '', ['left', 'center'], true)) $cfg['logo_align'] = $in['logo_align'];
        if (array_key_exists('title', $in)) $cfg['title'] = $str('title', 60) ?: 'Payment receipt';
        if (array_key_exists('number_prefix', $in)) $cfg['number_prefix'] = preg_replace('/[^A-Za-z0-9\-\/#_. ]/', '', $str('number_prefix', 12));
        if (array_key_exists('thank_you', $in)) $cfg['thank_you'] = $str('thank_you', 300);
        if (array_key_exists('footer_note', $in)) $cfg['footer_note'] = $str('footer_note', 500);
        if (array_key_exists('currency', $in)) $cfg['currency'] = $str('currency', 6);
        $copies = (int) ($in['copies'] ?? $current['copies'] ?? 1);
        $cfg['copies'] = in_array($copies, [1, 2], true) ? $copies : 1;

        foreach (self::TOGGLES as $group) {
            foreach ($group as $k => $_) $cfg[$k] = !empty($in[$k]);
        }

        if (!empty($in['remove_logo'])) {
            $cfg['logo'] = '';
        }
        if ($logoUpload && ($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $cfg['logo'] = self::logoToDataUri($logoUpload);
        }
        return $cfg;
    }

    /** Accept PNG, JPEG or WebP up to MAX_LOGO_BYTES; return a data: URI. */
    public static function logoToDataUri(array $file): string
    {
        if (($file['size'] ?? 0) > self::MAX_LOGO_BYTES) {
            throw new \InvalidArgumentException('The logo is larger than 300 KB. Use a smaller image.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $info = $tmp !== '' ? @getimagesize($tmp) : false;
        $allowed = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];
        if (!$info || !isset($allowed[$info[2]])) {
            throw new \InvalidArgumentException('The logo must be a PNG, JPG or WebP image.');
        }
        return 'data:' . $allowed[$info[2]] . ';base64,' . base64_encode((string) file_get_contents($tmp));
    }

    public function save(array $cfg): void
    {
        $this->putSetting('receipt_config', json_encode($cfg));
        // keep the older keys in step for anything else that reads them
        $this->putSetting('receipt_header_title', (string) $cfg['title']);
        $this->putSetting('receipt_thank_you_msg', (string) $cfg['thank_you']);
    }

    /** Currency symbol: the receipt override, else derived from the clinic's currency. */
    public function currencySymbol(array $cfg): string
    {
        if (($cfg['currency'] ?? '') !== '') return (string) $cfg['currency'];
        $code = 'FJD';
        try {
            $s = $this->db->prepare("SELECT currency FROM tenants WHERE id = ?");
            $s->execute([$this->tenantId]);
            $code = (string) ($s->fetchColumn() ?: 'FJD');
        } catch (\Throwable $e) {}
        return ['FJD' => 'FJ$', 'USD' => 'US$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'PHP' => '₱', 'EUR' => '€', 'GBP' => '£', 'WST' => 'WS$', 'TOP' => 'T$', 'VUV' => 'VT', 'SBD' => 'SI$', 'PGK' => 'K'][$code] ?? ($code . ' ');
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
