<?php
declare(strict_types=1);

namespace ClinicFlow\Http;

class SecurityHeadersMiddleware {
    /**
     * Send compliance security headers including HSTS
     */
    public static function applyHeaders(): void {
        if (headers_sent()) {
            return;
        }

        // Enforce HSTS (Strict-Transport-Security)
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // Prevent Clickjacking
        header('X-Frame-Options: DENY');

        // XSS Filter Protection
        header('X-XSS-Protection: 1; mode=block');

        // Referrer Policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // The Content-Security-Policy is sent by includes/security.php (it carries a per-request nonce).
    }
}
