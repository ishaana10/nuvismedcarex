<?php
/**
 * Clinic settings helpers.
 *
 *   nxSettings()            all settings for the active clinic (installation
 *                           defaults overlaid with this clinic's own values)
 *   nxSetting('key', $def)  one value
 *   nxClinic()              the clinic's identity: name, address, phone, logo…
 *   nxClinicName()          just the name, for headers and page titles
 *
 * Use these instead of querying clinic_settings directly: that table is shared
 * by every clinic, so reading it alone shows the wrong clinic's details.
 */

use ClinicFlow\Services\ClinicSettings;

if (!function_exists('nxMediaUrl')) {
    /**
     * Address of a stored picture. Signatures, stamps and profile photos live in
     * uploads/, which the web server refuses to serve directly (so nobody can
     * download a doctor's signature by guessing a file name). They are fetched
     * through media.php instead, which checks the person is signed in and that
     * the picture belongs to their clinic. Other values (assets/..., data: URLs,
     * http(s) links) are returned unchanged.
     */
    function nxMediaUrl(?string $v): string
    {
        $v = (string) $v;
        if (preg_match('#^uploads/(signatures|stamps|avatars)/[A-Za-z0-9_.-]+$#', $v)) {
            return 'media.php?p=' . rawurlencode($v);
        }
        return $v;
    }
}

if (!function_exists('nxClinicSettings')) {

    function nxClinicSettings(): ClinicSettings
    {
        static $svc = null, $forTenant = null;
        $tenant = \ClinicFlow\Shared\TenantContext::getTenantId();
        if ($svc === null || $forTenant !== $tenant) {
            $svc = new ClinicSettings(getDB(), $tenant);
            $forTenant = $tenant;
        }
        return $svc;
    }

    function nxSettings(): array
    {
        return nxClinicSettings()->all();
    }

    function nxSetting(string $key, $default = null)
    {
        return nxClinicSettings()->get($key, $default);
    }

    function nxClinic(): array
    {
        static $profile = null, $forTenant = null;
        $tenant = \ClinicFlow\Shared\TenantContext::getTenantId();
        if ($profile === null || $forTenant !== $tenant) {
            $profile = nxClinicSettings()->profile();
            $forTenant = $tenant;
        }
        return $profile;
    }

    function nxClinicName(): string
    {
        return nxClinic()['name'];
    }

    /** Preferences service for the active clinic: its defaults sit under each person's own choices. */
    function nxUserPreferences(): \ClinicFlow\Services\UserPreferences
    {
        return new \ClinicFlow\Services\UserPreferences(getDB(), \ClinicFlow\Services\AppearanceSettings::userDefaults(nxSettings()));
    }

    /** Preferences of the signed-in person (see UserPreferences). */
    function nxPrefs(): array
    {
        static $prefs = null, $forUser = null;
        $uid = $_SESSION['user_id'] ?? null;
        if ($prefs === null || $forUser !== $uid) {
            $prefs = nxUserPreferences()->load($uid);
            $forUser = $uid;
        }
        return $prefs;
    }

    /** Format a date the way this person chose. */
    function nxDate($when, bool $withTime = false): string
    {
        $ts = is_numeric($when) ? (int) $when : strtotime((string) $when);
        if (!$ts) return '';
        $p = nxPrefs();
        return date($p['date_format'] . ($withTime ? ' ' . $p['time_format'] : ''), $ts);
    }

    /** Currency symbol for the active clinic. */
    function nxCurrencySymbol(?string $override = null): string
    {
        if ($override !== null && $override !== '') return $override;
        $code = nxClinic()['currency'] ?: 'FJD';
        return [
            'FJD' => 'FJ$', 'USD' => 'US$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'PHP' => '₱',
            'EUR' => '€', 'GBP' => '£', 'WST' => 'WS$', 'TOP' => 'T$', 'VUV' => 'VT',
            'SBD' => 'SI$', 'PGK' => 'K', 'INR' => '₹',
        ][$code] ?? ($code . ' ');
    }
}
