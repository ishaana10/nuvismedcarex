<?php
declare(strict_types=1);

namespace ClinicFlow\Shared;

class TenantContext {
    private static ?string $currentTenantId = null;

    public static function setTenantId(string $tenantId): void {
        self::$currentTenantId = trim($tenantId);
    }

    public static function getTenantId(): string {
        if (self::$currentTenantId !== null && self::$currentTenantId !== '') {
            return self::$currentTenantId;
        }

        // Try resolving from HTTP Header / Session / Environment
        $tenantId = self::resolveTenantId();
        self::$currentTenantId = $tenantId;
        return $tenantId;
    }

    public static function resolveTenantId(): string {
        // 1. HTTP Header
        if (isset($_SERVER['HTTP_X_TENANT_ID']) && !empty($_SERVER['HTTP_X_TENANT_ID'])) {
            return trim($_SERVER['HTTP_X_TENANT_ID']);
        }

        // 2. HTTP Host / Subdomain (e.g. clinic1.yourdomain.com -> clinic1)
        if (isset($_SERVER['HTTP_HOST'])) {
            $host = explode(':', $_SERVER['HTTP_HOST'])[0];
            $parts = explode('.', $host);
            if (count($parts) >= 3 && $parts[0] !== 'www' && $parts[0] !== 'localhost') {
                return $parts[0];
            }
        }

        // 3. Authenticated Session
        if (isset($_SESSION['tenant_id']) && !empty($_SESSION['tenant_id'])) {
            return $_SESSION['tenant_id'];
        }

        if (isset($_SESSION['clinic_id']) && !empty($_SESSION['clinic_id'])) {
            return $_SESSION['clinic_id'];
        }

        // 4. Fallback Default Tenant
        return 'default-clinic';
    }

    public static function clear(): void {
        self::$currentTenantId = null;
    }
}
