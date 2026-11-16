<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

/**
 * How one clinic wants Nuvis Medcare X to look and behave for its staff.
 *
 * Stored per clinic in tenant_settings under ui_* keys (see ClinicSettings).
 * Three kinds of choice:
 *   - brand colour: one hex value that recolours buttons, links and highlights
 *   - defaults for staff who have not set their own preferences (theme, density,
 *     date and time format, first page, sidebar)
 *   - which optional modules appear in the menu, and a dashboard notice
 *
 * Hiding a module is cosmetic. Who may open it is still decided by the role
 * permissions (AccessControl), never by these settings.
 */
final class AppearanceSettings
{
    public const ACCENTS = [
        '#164487' => 'Nuvis blue',
        '#0E7C63' => 'Lagoon green',
        '#0F766E' => 'Teal',
        '#4F46E5' => 'Indigo',
        '#7C3AED' => 'Violet',
        '#BE185D' => 'Rose',
        '#B45309' => 'Amber',
        '#334155' => 'Slate',
    ];
    public const DEFAULT_ACCENT = '#164487';

    /** Menu items a clinic may switch off. Core clinical areas are always shown. */
    public const OPTIONAL_NAV = [
        'inventory' => 'Inventory',
        'uploads'   => 'Documents',
        'wishboard' => 'Wish board',
    ];

    public const SIDEBARS = ['expanded' => 'Expanded', 'collapsed' => 'Collapsed (icons only)'];
    public const ANNOUNCEMENT_MAX = 240;

    /** Shades of the brand colour, in the order custom.css names them. */
    private const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
    /** The built-in Nuvis blue ramps (r g b) in light and dark mode, from custom.css. */
    private const LIGHT = [[238,244,251],[217,230,246],[181,205,238],[132,173,226],[78,134,210],[42,104,191],[27,84,166],[22,68,135],[20,57,110],[15,43,92],[10,29,63]];
    private const DARK  = [[18,34,56],[22,42,69],[29,58,96],[42,82,136],[68,121,194],[91,144,216],[74,132,211],[63,120,199],[141,181,234],[185,210,243],[220,232,249]];

    /** @return array<string,string> the saved ui_* values, each checked */
    public static function load(array $settings): array
    {
        $accent = self::cleanAccent((string) ($settings['ui_accent'] ?? ''));
        $hidden = array_values(array_intersect(
            array_keys(self::OPTIONAL_NAV),
            array_filter(explode(',', (string) ($settings['ui_hidden_nav'] ?? '')))
        ));
        return [
            'accent'       => $accent ?? self::DEFAULT_ACCENT,
            'theme'        => self::pick((string) ($settings['ui_default_theme'] ?? ''), UserPreferences::THEMES) ?? 'system',
            'density'      => self::pick((string) ($settings['ui_default_density'] ?? ''), UserPreferences::DENSITIES) ?? 'comfortable',
            'date_format'  => self::pick((string) ($settings['ui_default_date_format'] ?? ''), UserPreferences::DATE_FORMATS) ?? 'j M Y',
            'time_format'  => self::pick((string) ($settings['ui_default_time_format'] ?? ''), UserPreferences::TIME_FORMATS) ?? 'g:i A',
            'landing_page' => self::pick((string) ($settings['ui_default_landing'] ?? ''), UserPreferences::LANDING) ?? 'index.php',
            'sidebar'      => self::pick((string) ($settings['ui_default_sidebar'] ?? ''), self::SIDEBARS) ?? 'expanded',
            'hidden_nav'   => $hidden,
            'announcement' => self::cleanAnnouncement((string) ($settings['ui_announcement'] ?? '')),
        ];
    }

    /** The clinic's defaults, shaped like UserPreferences keys; only those the clinic actually set. */
    public static function userDefaults(array $settings): array
    {
        $out = [];
        $map = [
            'ui_default_theme' => 'theme', 'ui_default_density' => 'density',
            'ui_default_date_format' => 'date_format', 'ui_default_time_format' => 'time_format',
            'ui_default_landing' => 'landing_page',
        ];
        $v = self::load($settings);
        foreach ($map as $key => $pref) {
            if (!empty($settings[$key])) $out[$pref] = $v[$pref === 'landing_page' ? 'landing_page' : $pref];
        }
        if (!empty($settings['ui_default_sidebar'])) $out['sidebar_collapsed'] = $v['sidebar'] === 'collapsed';
        return $out;
    }

    /**
     * Check a submitted form and turn it into the ui_* values to store.
     * @return array{values:array<string,string>, adjusted:?string} adjusted = accent actually used, if it had to be darkened
     */
    public static function fromForm(array $in): array
    {
        $accent = self::cleanAccent((string) ($in['accent'] ?? ''))
            ?? self::cleanAccent((string) ($in['accent_choice'] ?? ''))
            ?? self::DEFAULT_ACCENT;
        $usable = self::readable($accent);
        $hidden = array_values(array_intersect(array_keys(self::OPTIONAL_NAV), (array) ($in['hidden_nav'] ?? [])));

        $values = [
            'ui_accent'              => $usable === self::DEFAULT_ACCENT ? '' : $usable,
            'ui_default_theme'       => self::pick((string) ($in['theme'] ?? ''), UserPreferences::THEMES) ?? '',
            'ui_default_density'     => self::pick((string) ($in['density'] ?? ''), UserPreferences::DENSITIES) ?? '',
            'ui_default_date_format' => self::pick((string) ($in['date_format'] ?? ''), UserPreferences::DATE_FORMATS) ?? '',
            'ui_default_time_format' => self::pick((string) ($in['time_format'] ?? ''), UserPreferences::TIME_FORMATS) ?? '',
            'ui_default_landing'     => self::pick((string) ($in['landing_page'] ?? ''), UserPreferences::LANDING) ?? '',
            'ui_default_sidebar'     => self::pick((string) ($in['sidebar'] ?? ''), self::SIDEBARS) ?? '',
            'ui_hidden_nav'          => implode(',', $hidden),
            'ui_announcement'        => self::cleanAnnouncement((string) ($in['announcement'] ?? '')),
        ];
        return ['values' => $values, 'adjusted' => strcasecmp($usable, $accent) === 0 ? null : $usable];
    }

    public static function cleanAccent(string $hex): ?string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', trim($hex)) ? strtoupper(trim($hex)) : null;
    }

    public static function cleanAnnouncement(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return mb_substr($text, 0, self::ANNOUNCEMENT_MAX);
    }

    private static function pick(string $value, array $choices): ?string
    {
        return $value !== '' && isset($choices[$value]) ? $value : null;
    }

    /* ------------------------------------------------------------------ colour */

    /** CSS that re-points the brand colour variables at this accent, light and dark mode. */
    public static function css(string $accent): string
    {
        $accent = self::readable(self::cleanAccent($accent) ?? self::DEFAULT_ACCENT);
        if ($accent === self::DEFAULT_ACCENT) return '';
        [$h, $s] = self::rgbToHsl(...self::hexToRgb($accent));
        $light = self::ramp(self::LIGHT, $h, $s, $accent);
        $dark = self::ramp(self::DARK, $h, $s, null, 1.0);   // dark surfaces stay muted
        $decl = fn(array $ramp) => implode(';', array_map(
            fn($shade, $rgb) => "--c-blue-{$shade}:" . implode(' ', $rgb), self::SHADES, $ramp
        ));
        return ':root{' . $decl($light) . '}html.dark{' . $decl($dark) . '}';
    }

    /** Darken a colour until white text on it is easy to read (WCAG contrast 4.5:1). */
    public static function readable(string $hex): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        [$h, $s, $l] = self::rgbToHsl($r, $g, $b);
        for ($i = 0; $i < 60 && self::contrastWithWhite(self::hslToRgb($h, $s, $l)) < 4.5; $i++) {
            $l = max(0.0, $l - 0.01);
        }
        return self::rgbToHex(...self::hslToRgb($h, $s, $l));
    }

    public static function contrastWithWhite(array $rgb): float
    {
        $lin = fn(int $c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $lum = 0.2126 * $lin($rgb[0]) + 0.7152 * $lin($rgb[1]) + 0.0722 * $lin($rgb[2]);
        return 1.05 / ($lum + 0.05);
    }

    /**
     * Same lightness curve as the built-in blue, with the accent's hue and a
     * matching saturation. In light mode the 700 shade is the accent itself and
     * the other shades are stretched either side of it.
     */
    private static function ramp(array $base, float $hue, float $sat, ?string $anchorHex, float $maxK = 1.6): array
    {
        $ref = array_map(fn($c) => self::rgbToHsl(...$c), $base);       // [h,s,l] per shade
        $refSat700 = max(0.05, $ref[7][1]);
        $k = max(0.3, min($maxK, $sat / $refSat700));
        $lightness = array_column($ref, 2);
        if ($anchorHex !== null) {
            $target = self::rgbToHsl(...self::hexToRgb($anchorHex))[2];
            $l50 = $lightness[0]; $l700 = $lightness[7];
            foreach ($lightness as $i => $l) {
                $lightness[$i] = match (true) {
                    $i === 7 => $target,
                    // lighter shades: keep the palest where it was, stretch the rest toward the accent
                    $i < 7   => $l50 + ($target - $l50) * (($l - $l50) / ($l700 - $l50)),
                    // darker shades: scale down in proportion
                    default  => $target * ($l / $l700),
                };
            }
        }
        $out = [];
        foreach ($ref as $i => [, $s]) {
            $out[] = self::hslToRgb($hue, max(0.0, min(1.0, $s * $k)), max(0.0, min(1.0, $lightness[$i])));
        }
        return $out;
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    private static function rgbToHsl(int $r, int $g, int $b): array
    {
        $r /= 255; $g /= 255; $b /= 255;
        $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2;
        if ($max === $min) return [0.0, 0.0, $l];
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match (true) {
            $max === $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $max === $g => ($b - $r) / $d + 2,
            default     => ($r - $g) / $d + 4,
        } / 6;
        return [$h, $s, $l];
    }

    private static function hslToRgb(float $h, float $s, float $l): array
    {
        if ($s <= 0) { $v = (int) round($l * 255); return [$v, $v, $v]; }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $hue = function (float $t) use ($p, $q) {
            if ($t < 0) $t += 1; if ($t > 1) $t -= 1;
            if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
            if ($t < 1 / 2) return $q;
            if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
            return $p;
        };
        return [(int) round($hue($h + 1 / 3) * 255), (int) round($hue($h) * 255), (int) round($hue($h - 1 / 3) * 255)];
    }
}
