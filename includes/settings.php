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
