<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;

/**
 * Preferences belonging to one staff account: how the app looks for them,
 * which alerts they want, and where they land after signing in.
 *
 * Clinic-wide set-up lives in ClinicSettings; this is only ever about the
 * person signed in, so two people sharing a clinic can work differently.
 */
final class UserPreferences
{
    /** key => [label, choices or null for a toggle, default] */
    public const THEMES = ['system' => 'Follow my device', 'light' => 'Light', 'dark' => 'Dark'];
    public const DENSITIES = ['comfortable' => 'Comfortable', 'compact' => 'Compact'];
    public const PAGE_SIZES = [5, 10, 25, 50, 100];
    public const DATE_FORMATS = [
        'j M Y'  => '27 Oct 2026',
        'd/m/Y'  => '27/10/2026',
        'm/d/Y'  => '10/27/2026',
        'Y-m-d'  => '2026-10-27',
    ];
    public const TIME_FORMATS = ['g:i A' => '2:30 PM', 'H:i' => '14:30'];

    /** Pages someone may want to open first, by nav id. */
    public const LANDING = [
        'index.php'          => 'Dashboard',
        'patients.php'       => 'Patients',
        'appointment.php'    => 'Appointments',
        'clinical_visit.php' => 'Clinical encounters',
        'billing.php'        => 'Billing',
        'inventory.php'      => 'Inventory',
    ];

    /** Languages the browser's speech recogniser is asked to listen for. */
    public const VOICE_LANGS = [
        'en-US' => 'English (United States)',
        'en-GB' => 'English (United Kingdom)',
        'en-AU' => 'English (Australia)',
        'en-IN' => 'English (India)',
        'en-PH' => 'English (Philippines)',
        'en-NZ' => 'English (New Zealand)',
    ];

    public const ALERTS = [
        'alert_patient_registered' => ['A patient is registered', true],
        'alert_appointment_booked' => ['An appointment is booked', true],
        'alert_sound'              => ['Play a sound with alerts', false],
    ];

    public static function defaults(): array
    {
        $d = [
            'theme'            => 'system',
            'density'          => 'comfortable',
            'sidebar_collapsed' => false,
            'page_size'        => 0,            // 0 = follow the clinic default
            'date_format'      => 'j M Y',
            'time_format'      => 'g:i A',
            'landing_page'     => 'index.php',
            'show_help_hints'  => true,
            'voice_input'      => false,        // dictation is opt-in, typing always works
            'voice_lang'       => 'en-US',
            'voice_words'      => '',           // personal word list, one entry per line
        ];
        foreach (self::ALERTS as $k => [, $def]) {
            $d[$k] = $def;
        }
        return $d;
    }

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function load(?string $userId): array
    {
        $prefs = self::defaults();
        if (!$userId) {
            return $prefs;
        }
        try {
            $stmt = $this->db->prepare("SELECT preferences FROM doctors WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $raw = $stmt->fetchColumn();
            $saved = $raw ? json_decode((string) $raw, true) : null;
            if (is_array($saved)) {
                $prefs = array_merge($prefs, array_intersect_key($saved, $prefs));
            }
        } catch (\Throwable $e) {
            error_log('[UserPreferences] load: ' . $e->getMessage());
        }
        return $prefs;
    }

    /** Keep only values we recognise, so a stored preference can't break a page. */
    public static function sanitise(array $in, array $current): array
    {
        $out = $current;
        if (isset(self::THEMES[$in['theme'] ?? ''])) $out['theme'] = $in['theme'];
        if (isset(self::DENSITIES[$in['density'] ?? ''])) $out['density'] = $in['density'];
        if (isset(self::DATE_FORMATS[$in['date_format'] ?? ''])) $out['date_format'] = $in['date_format'];
        if (isset(self::TIME_FORMATS[$in['time_format'] ?? ''])) $out['time_format'] = $in['time_format'];
        if (isset(self::LANDING[$in['landing_page'] ?? ''])) $out['landing_page'] = $in['landing_page'];

        $size = (int) ($in['page_size'] ?? 0);
        $out['page_size'] = ($size === 0 || in_array($size, self::PAGE_SIZES, true)) ? $size : 0;

        $out['sidebar_collapsed'] = !empty($in['sidebar_collapsed']);
        $out['show_help_hints'] = !empty($in['show_help_hints']);
        $out['voice_input'] = !empty($in['voice_input']);
        if (isset(self::VOICE_LANGS[$in['voice_lang'] ?? ''])) $out['voice_lang'] = $in['voice_lang'];
        if (array_key_exists('voice_words', $in)) $out['voice_words'] = self::cleanVoiceWords((string) $in['voice_words']);
        foreach (array_keys(self::ALERTS) as $k) {
            $out[$k] = !empty($in[$k]);
        }
        return $out;
    }

    /**
     * A personal word list for dictation. Each line is either a term the doctor uses
     * ("Metoclopramide") or a fix ("sevilla = Seville"). Capped so it can't bloat the row.
     */
    public static function cleanVoiceWords(string $raw): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $line) ?? '');
            if ($line === '' || $line === '=') continue;
            $lines[] = mb_substr($line, 0, 80);
            if (count($lines) >= 150) break;
        }
        return implode("\n", $lines);
    }

    public function save(string $userId, array $prefs): void
    {
        $stmt = $this->db->prepare("UPDATE doctors SET preferences = ? WHERE id = ?");
        $stmt->execute([json_encode($prefs), $userId]);
    }

    public function reset(string $userId): void
    {
        $this->db->prepare("UPDATE doctors SET preferences = NULL WHERE id = ?")->execute([$userId]);
    }
}
