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
    /** Certificate layouts a clinic can choose between. */
    public const LAYOUTS = [
        'classic' => ['Classic letter', 'A formal letter: centred letterhead, a statement that the patient was examined, diagnosis and fitness boxes.'],
        'form'    => ['Clinic form', 'Like a printed clinic pad: doctor credentials line, "consultation in" line, problem tick-boxes, illness / resume / review dates.'],
    ];
    public const DEFAULT_CATEGORIES = [
        'Genitourinary Problems', 'Cardiac Problems', 'Skin Problems',
        'Musculoskeletal Problems', 'OBS/ Gynae Problems', 'GIT Problems',
        'Respiratory Tract Problems', 'Medical Problems', 'EENT Problems', 'Dental',
    ];
    /** Certificate numbering: what the date part looks like, and when the count restarts. */
    public const NUMBER_DATES = ['Ymd' => 'Full date (20261117)', 'ymd' => 'Short date (261117)', 'Ym' => 'Year and month (202611)', 'Y' => 'Year (2026)', 'none' => 'No date'];
    public const NUMBER_SEPARATORS = ['-' => 'Dash ( - )', '/' => 'Slash ( / )', '.' => 'Dot ( . )', '' => 'None'];
    public const NUMBER_RESETS = ['daily' => 'Every day', 'monthly' => 'Every month', 'yearly' => 'Every year', 'never' => 'Never (keeps counting)'];
    public const CADUCEUS = 'assets/images/caduceus_logo.png';
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
                'show_license'  => ['Medical licence number', true],
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
                'show_caduceus' => ['Golden caduceus beside the clinic name', true],
            ],
            'Clinic form layout' => [
                'show_categories' => ['Problem tick-boxes', true],
                'show_dates'      => ['Illness-from and resume-duties dates', true],
                'show_review'     => ['Review line', true],
                'show_consult_date' => ['Date of consultation by the signature', true],
            ],
            'Certificate body' => [
                'show_diagnosis'     => ['Diagnosis / findings', true],
                'show_rest_period'   => ['Recommended rest period', true],
                'show_fitness'       => ['Fitness-to-work / study statement', true],
                'show_patient_id'    => ['Patient ID / MRN', true],
            ],
            'Credentials & sign-off' => [
                'show_license'   => ['Medical licence number', true],
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
                'layout'          => 'classic',
                'number_prefix'   => 'MC',
                'number_date'     => 'Ymd',
                'number_separator'=> '-',
                'number_digits'   => 4,
                'number_reset'    => 'daily',
                'doctor_line'     => '',
                'consultation_in' => '',
                'problem_categories' => implode("\n", self::DEFAULT_CATEGORIES),
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
        if ($type === 'certificate') {
            if (isset(self::LAYOUTS[$in['layout'] ?? ''])) $cfg['layout'] = $in['layout'];
            if (array_key_exists('number_prefix', $in)) {
                $cfg['number_prefix'] = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $in['number_prefix'])), 0, 8);
            }
            if (isset(self::NUMBER_DATES[$in['number_date'] ?? ''])) $cfg['number_date'] = $in['number_date'];
            if (isset($in['number_separator']) && array_key_exists($in['number_separator'], self::NUMBER_SEPARATORS)) $cfg['number_separator'] = $in['number_separator'];
            if (isset($in['number_digits'])) $cfg['number_digits'] = max(3, min(8, (int) $in['number_digits']));
            if (isset(self::NUMBER_RESETS[$in['number_reset'] ?? ''])) $cfg['number_reset'] = $in['number_reset'];
            $cfg['number_reset'] = self::coherentReset($cfg['number_date'], $cfg['number_reset']);
            if (array_key_exists('doctor_line', $in)) $cfg['doctor_line'] = $str('doctor_line', 160);
            if (array_key_exists('consultation_in', $in)) $cfg['consultation_in'] = $str('consultation_in', 300);
            if (array_key_exists('problem_categories', $in)) {
                $lines = [];
                foreach (preg_split('/\R/', (string) $in['problem_categories']) ?: [] as $l) {
                    $l = mb_substr(trim($l), 0, 40);
                    if ($l !== '' && !in_array($l, $lines, true)) $lines[] = $l;
                    if (count($lines) >= 15) break;
                }
                $cfg['problem_categories'] = implode("\n", $lines ?: self::DEFAULT_CATEGORIES);
            }
        }
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

    /**
     * The count may only restart as often as the date in the number changes,
     * otherwise two certificates could get the same number. A number with no
     * date therefore never restarts.
     */
    public static function coherentReset(string $date, string $reset): string
    {
        $rank = ['daily' => 3, 'monthly' => 2, 'yearly' => 1, 'never' => 0];
        $shows = ['Ymd' => 3, 'ymd' => 3, 'Ym' => 2, 'Y' => 1, 'none' => 0][$date] ?? 0;
        return ($rank[$reset] ?? 0) <= $shows ? $reset : (['3' => 'daily', '2' => 'monthly', '1' => 'yearly', '0' => 'never'][(string) $shows]);
    }

    /** Period key that the count restarts on ('' when it never restarts). */
    public static function numberPeriod(array $cfg, \DateTimeInterface $d): string
    {
        $fmt = ['daily' => 'Ymd', 'monthly' => 'Ym', 'yearly' => 'Y'][$cfg['number_reset'] ?? 'never'] ?? null;
        return $fmt ? $d->format($fmt) : '';
    }

    /** The text before the running number, e.g. "MC-20261117-". */
    public static function numberHead(array $cfg, \DateTimeInterface $d): string
    {
        $sep = (string) ($cfg['number_separator'] ?? '-');
        $parts = [];
        if (($cfg['number_prefix'] ?? '') !== '') $parts[] = $cfg['number_prefix'];
        if (($cfg['number_date'] ?? 'Ymd') !== 'none') $parts[] = $d->format($cfg['number_date']);
        return $parts ? implode($sep, $parts) . $sep : '';
    }

    public static function formatNumber(array $cfg, int $n, \DateTimeInterface $d): string
    {
        return self::numberHead($cfg, $d) . str_pad((string) $n, (int) ($cfg['number_digits'] ?? 4), '0', STR_PAD_LEFT);
    }

    /** Last number handed out in the current period (0 when none). */
    public function certificateCounter(string $period): int
    {
        $saved = json_decode((string) $this->setting('certificate_counter'), true);
        return (is_array($saved) && ($saved['period'] ?? null) === $period) ? (int) ($saved['n'] ?? 0) : 0;
    }

    public function setCertificateCounter(string $period, int $n): void
    {
        $this->putSetting('certificate_counter', json_encode(['period' => $period, 'n' => max(0, $n)]));
    }

    /**
     * Give out the next certificate number and pass it to $insert, which must
     * throw a PDOException when the number is already used. Numbers already in
     * the table (old random ones included) are never reused: counting carries
     * on after the highest one with the same prefix and date.
     *
     * @param callable(string):void $insert
     */
    public function issueCertificateNumber(\DateTimeInterface $date, callable $insert): string
    {
        $cfg = $this->load('certificate');
        $period = self::numberPeriod($cfg, $date);
        $head = self::numberHead($cfg, $date);
        $n = $this->certificateCounter($period);

        $n = max($n, $this->highestIssued($head));

        for ($try = 0; $try < 25; $try++) {
            $n++;
            $number = self::formatNumber($cfg, $n, $date);
            try {
                $insert($number);
            } catch (\PDOException $e) {
                if (($e->errorInfo[0] ?? $e->getCode()) !== '23000') throw $e;
                continue; // taken meanwhile; try the next one
            }
            $this->setCertificateCounter($period, $n);
            return $number;
        }
        throw new \RuntimeException('Could not find a free certificate number.');
    }

    /** Highest running number already used under a head such as "MC-20261117-" (0 if none). */
    private function highestIssued(string $head): int
    {
        $max = 0;
        try {
            $q = $this->db->prepare("SELECT certificate_number FROM medical_certificates WHERE tenant_id = ? AND certificate_number LIKE ?");
            $q->execute([$this->tenantId, $head . '%']); // head is letters, digits and - / . only
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $existing) {
                $tail = substr((string) $existing, strlen($head));
                if ($tail !== '' && ctype_digit($tail)) $max = max($max, (int) $tail);
            }
        } catch (\Throwable $e) {
            // the unique key still stops repeats
        }
        return $max;
    }

    /** What the next number will look like, for the settings page. */
    public function previewNextNumber(\DateTimeInterface $date): string
    {
        $cfg = $this->load('certificate');
        $head = self::numberHead($cfg, $date);
        $n = max($this->certificateCounter(self::numberPeriod($cfg, $date)), $this->highestIssued($head));
        return self::formatNumber($cfg, $n + 1, $date);
    }

    /** The tick-box labels for a certificate config, in order. */
    public static function categories(array $cfg): array
    {
        $out = [];
        foreach (preg_split('/\R/', (string) ($cfg['problem_categories'] ?? '')) ?: [] as $l) {
            $l = trim($l);
            if ($l !== '') $out[] = $l;
        }
        return $out ?: self::DEFAULT_CATEGORIES;
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
