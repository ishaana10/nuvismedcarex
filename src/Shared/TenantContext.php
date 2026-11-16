<?php
declare(strict_types=1);

namespace ClinicFlow\Shared;

/**
 * Resolves which clinic (tenant) the current request is working in.
 *
 * SECURITY: the tenant must come from something the caller cannot choose.
 * Resolution order:
 *   1. An explicit setTenantId() call (login, developer clinic switch, tests, CLI jobs).
 *   2. The authenticated session ($_SESSION['tenant_id'], written at login).
 *   3. Before sign-in only (login / forgot-password / installer): the host
 *      sub-domain, then the default clinic.
 *
 * The X-Tenant-ID request header is deliberately NOT honoured. It used to be
 * read first, which let any signed-in user act as any other clinic by adding a
 * header to their requests. If a server-to-server API with API keys is added
 * later, derive the tenant from the key's owner, never from a client header.
 */
class TenantContext {
    private static ?string $currentTenantId = null;

    public function __construct(?string $tenantId = null) {
        if ($tenantId !== null && $tenantId !== '') {
            self::$currentTenantId = self::sanitize($tenantId);
        }
    }

    public static function setTenantId(string $tenantId): void {
        self::$currentTenantId = self::sanitize($tenantId);
    }

    public static function getTenantId(): string {
        // A signed-in session always wins over a value cached earlier in the
        // request (e.g. one resolved from the host before login finished).
        $sessionTenant = self::sessionTenant();
        if ($sessionTenant !== null) {
            if (self::$currentTenantId === null || self::$currentTenantId !== $sessionTenant) {
                // Only trust an explicit override that matches the session, or
                // that was made by trusted code after login (switchTenant()
                // updates the session too, so they agree).
                self::$currentTenantId = $sessionTenant;
            }
            return self::$currentTenantId;
        }

        if (self::isAuthenticatedSession()) {
            // Signed in but no tenant recorded: refuse rather than guess.
            self::failClosed();
        }

        if (self::$currentTenantId !== null && self::$currentTenantId !== '') {
            return self::$currentTenantId;
        }

        self::$currentTenantId = self::resolvePreAuthTenantId();
        return self::$currentTenantId;
    }

    /** Tenant for requests that have no signed-in user yet. */
    public static function resolvePreAuthTenantId(): string {
        // Host sub-domain (e.g. clinic1.yourdomain.com -> clinic1)
        if (isset($_SERVER['HTTP_HOST'])) {
            $host = explode(':', (string) $_SERVER['HTTP_HOST'])[0];
            if (!filter_var($host, FILTER_VALIDATE_IP)) {
                $parts = explode('.', $host);
                if (count($parts) >= 3 && $parts[0] !== 'www' && $parts[0] !== 'localhost') {
                    $candidate = self::sanitize($parts[0]);
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }
        }

        return 'default-clinic';
    }

    /** @deprecated kept for callers; identical to getTenantId() resolution. */
    public static function resolveTenantId(): string {
        return self::getTenantId();
    }

    public static function clear(): void {
        self::$currentTenantId = null;
    }

    private static function sessionTenant(): ?string {
        if (empty($_SESSION['authenticated']) || empty($_SESSION['tenant_id'])) {
            return null;
        }
        $t = self::sanitize((string) $_SESSION['tenant_id']);
        return $t !== '' ? $t : null;
    }

    private static function isAuthenticatedSession(): bool {
        return !empty($_SESSION['authenticated']);
    }

    /** Tenant ids are short slugs; strip anything else (defence in depth). */
    private static function sanitize(string $tenantId): string {
        return substr(preg_replace('/[^A-Za-z0-9_\-]/', '', trim($tenantId)) ?? '', 0, 50);
    }

    private static function failClosed(): never {
        if (PHP_SAPI === 'cli') {
            throw new \RuntimeException('No tenant is associated with this session.');
        }
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Your session has no clinic assigned. Please sign out and sign in again.';
        exit;
    }
}
