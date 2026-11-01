<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;

/**
 * Per-clinic customisation of printed documents: the prescription and the
 * medical certificate. Each clinic controls the letterhead (logo, colours,
 * titles), which details print, the wording, and the sign-off. Stored as JSON
 * in tenant_settings ('prescription_config' / 'certificate_config').
 */
final class DocumentTemplateSettings
{
    public const TYPES = ['prescription', 'certificate'];

    public const PAPER = ['a4' => 'A4', 'letter' => 'US Letter', 'a5' => 'A5'];
    public const FONTS = [
        'sans'  => 'Sans-serif (modern)',
        'serif' => 'Serif (classic)',
        'mono'  => 'Monospace',
    ];
    public const LOGO_SIZES = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'];
    public const ALIGNS = ['left' => 'Left', 'center' => 'Centre', 'right' => 'Right'];
    public const DATE_FORMATS = [
        'd M Y'  => '26 Nov 2026',
        'd/m/Y'  => '26/11/2026',
        'M j, Y' => 'Nov 26, 2026',
        'Y-m-d'  => '2026-11-26',
    ];
    public const ACCENTS = [
        '#164487' => 'Nuvis blue',
        '#0E7C63' => 'Lagoon green',
        '#0F2B5C' => 'Navy',
        '#7A1F3D' => 'Maroon',
        '#B0640A' => 'Amber',
        '#364354' => 'Charcoal',
    ];
    public const MAX_LOGO_BYTES = 300 * 1024;

    /** Toggles per document type: group => [key => [label, default]]. */
    public const TOGGLES = [
        'prescription' => [
            'Letterhead' => [
                'show_logo'     => ['Clinic logo', true],
                'show_address'  => ['Clinic address', true],
                'show_phone'    => ['Phone number', true],
                'show_email'    => ['Email address', true],
                'show_website'  => ['Website', false],
            ],
            'Credentials' => [
                'show_dea'      => ['DEA number', true],
                'show_npi'      => ['NPI number', true],
                'show_license'  => ['Prescriber licence (PRC)', true],
                'show_ptr'      => ['PTR number', false],
            ],
            'Prescription body' => [
                'show_rx_symbol'   => ['℞ superscription symbol', true],
                'show_patient_age' => ['Patient age / sex', true],
                'show_allergies'   => ['Patient allergies', true],
                'show_diagnosis'   => ['Diagnosis line', false],
                'show_generic'     => ['Generic-name column', true],
            ],
            'Sign-off' => [
                'show_signature'   => ['Prescriber signature', true],
                'show_stamp'       => ['Clinic stamp', true],
                'show_verification_qr' => ['Verification QR code', true],
            ],
        ],
        'certificate' => [
            'Letterhead' => [
                'show_logo'     => ['Clinic logo', true],
                'show_address'  => ['Clinic address', true],
                'show_phone'    => ['Phone number', true],
                'show_email'    => ['Email address', true],
                'show_website'  => ['Website', false],
            ],
            'Certificate body' => [
                'show_diagnosis'     => ['Diagnosis / findings', true],
                'show_rest_period'   => ['Recommended rest period', true],
                'show_fitness'       => ['Fitness-to-work / study statement', true],
                'show_patient_id'    => ['Patient ID / MRN', true],
            ],
            'Credentials & sign-off' => [
                'show_license'   => ['Physician licence (PRC)', true],
                'show_ptr'       => ['PTR number', true],
                'show_signature' => ['Physician signature', true],
                'show_stamp'     => ['Clinic stamp', true],
            ],
        ],
    ];

    private PDO $db;
    private string $tenantId;

    public function __construct(PDO $db, string $tenantId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
    }

    public static function key(string $type): string
    {
        return ($type === 'certificate' ? 'certificate' : 'prescription') . '_config';
    }

    public static function defaults(string $type): array
    {
        $common = [
            'paper'          => 'a4',
            'font'           => 'sans',
            'accent'         => '#164487',
            'date_format'    => 'd M Y',
            'logo'           => '',
            'logo_align'     => 'left',
            'logo_size'      => 'medium',
            'watermark'      => '',
            'footer_note'    => '',
        ];
        if ($type === 'certificate') {
            return array_merge($common, [
                'header_title'    => 'OFFICIAL MEDICAL CERTIFICATE',
                'header_subtitle' => '',
                'disclaimer'      => 'This medical certificate is issued upon request of the patient for whatever legal or administrative purpose it may serve.',
                'footer_note'     => 'Valid with official clinic digital stamp and physician e-signature.',
            ] + self::toggleDefaults('certificate'));
        }
        return array_merge($common, [
            'header_title'    => 'OFFICIAL MEDICAL PRESCRIPTION',
            'header_subtitle' => '',
            'disclaimer'      => 'Notice: This prescription is valid for 30 days from date of issue unless specified otherwise.',
            'footer_note'     => 'Substitution permitted unless DAW (Dispense As Written) is indicated.',
        ] + self::toggleDefaults('prescription'));
    }

    private static function toggleDefaults(string $type): array
    {
        $d = [];
        foreach (self::TOGGLES[$type] ?? [] as $group) {
            foreach ($group as $k => [, $def]) {
                $d[$k] = $def;
            }
        }
        return $d;
    }

    public function load(string $type): array
    {
        $type = in_array($type, self::TYPES, true) ? $type : 'prescription';
        $cfg = self::defaults($type);
        $saved = json_decode((string) $this->setting(self::key($type)), true);
        if (is_array($saved)) {
            $cfg = array_merge($cfg, array_intersect_key($saved, $cfg));
        }
        return $cfg;
    }

    public static function sanitise(string $type, array $in, array $current, ?array $logoUpload = null): array
    {
        $type = in_array($type, self::TYPES, true) ? $type : 'prescription';
        $cfg = $current;
        $str = fn(string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);

        if (isset(self::PAPER[$in['paper'] ?? ''])) $cfg['paper'] = $in['paper'];
        if (isset(self::FONTS[$in['font'] ?? ''])) $cfg['font'] = $in['font'];
        if (isset(self::DATE_FORMATS[$in['date_format'] ?? ''])) $cfg['date_format'] = $in['date_format'];
        if (isset(self::LOGO_SIZES[$in['logo_size'] ?? ''])) $cfg['logo_size'] = $in['logo_size'];
        if (isset(self::ALIGNS[$in['logo_align'] ?? ''])) $cfg['logo_align'] = $in['logo_align'];
        if (preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($in['accent'] ?? ''))) $cfg['accent'] = strtoupper($in['accent']);

        if (array_key_exists('header_title', $in)) $cfg['header_title'] = $str('header_title', 80);
        if (array_key_exists('header_subtitle', $in)) $cfg['header_subtitle'] = $str('header_subtitle', 120);
        if (array_key_exists('disclaimer', $in)) $cfg['disclaimer'] = $str('disclaimer', 500);
        if (array_key_exists('footer_note', $in)) $cfg['footer_note'] = $str('footer_note', 500);
        if (array_key_exists('watermark', $in)) $cfg['watermark'] = $str('watermark', 40);

        foreach (self::TOGGLES[$type] as $group) {
            foreach ($group as $k => $_) $cfg[$k] = !empty($in[$k]);
        }

        if (!empty($in['remove_logo'])) $cfg['logo'] = '';
        if ($logoUpload && ($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $cfg['logo'] = self::logoToDataUri($logoUpload);
        }
        return $cfg;
    }

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

    public function save(string $type, array $cfg): void
    {
        $this->putSetting(self::key($type), json_encode($cfg));
    }

    /** CSS font stack for a chosen font key. */
    public static function fontStack(string $font): string
    {
        return [
            'serif' => 'Georgia, "Times New Roman", serif',
            'mono'  => '"IBM Plex Mono", ui-monospace, monospace',
        ][$font] ?? '"IBM Plex Sans", Inter, system-ui, -apple-system, sans-serif';
    }

    /** Logo width in px for a size key. */
    public static function logoWidth(string $size): int
    {
        return ['small' => 48, 'large' => 96][$size] ?? 68;
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
