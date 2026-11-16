<?php
/**
 * Security & Helper functions (CSRF, RBAC, Rate Limiting, Sanitization, Secure Session)
 */

require_once __DIR__ . '/../core/SessionBootstrap.php';

// Global Security & Anti-Caching HTTP Headers
if (!headers_sent()) {
    header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-XSS-Protection: 1; mode=block");
    // Everything the app needs is served from this site. The only outside hosts left are Google's,
    // for the Google Drive picker on the Documents page (sign-in script, picker script and frames).
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://accounts.google.com https://apis.google.com; style-src 'self' 'unsafe-inline' https://accounts.google.com; font-src 'self'; img-src 'self' data: blob: https://*.googleusercontent.com; connect-src 'self' https://*.googleapis.com https://accounts.google.com https://apis.google.com; frame-src 'self' https://accounts.google.com https://docs.google.com https://drive.google.com https://content.googleapis.com; object-src 'none'; base-uri 'self';");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    // Dictation needs the microphone; nothing embedded here ever should.
    header("Permissions-Policy: microphone=(self)");
}

/**
 * Generate CSRF token
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function getCsrfToken(): string {
    return generateCsrfToken();
}

// Auto-initialize CSRF token in session
generateCsrfToken();

/**
 * Verify CSRF token
 */
function verifyCsrfToken(?string $token): bool {
    if (!$token || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function validateCsrfToken(?string $token): bool {
    return verifyCsrfToken($token);
}

/**
 * Check CSRF token on POST/PUT/DELETE requests or terminate
 */
function validateCsrfRequest(): void {
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'DELETE'])) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!verifyCsrfToken($token)) {
            http_response_code(403);
            die("Invalid or missing CSRF token.");
        }
    }
}

/**
 * Keyed digest of a password-reset OTP, so the DB never holds usable codes.
 * 10 hex chars fits the existing password_resets.otp VARCHAR(10) column.
 */
function nxOtpDigest(string $otp): string {
    return substr(hash_hmac('sha256', 'pwreset|' . $otp, \ClinicFlow\Utils\Encryption::getKey()), 0, 10);
}

/**
 * Guard for JSON/AJAX control endpoints (git, redis, webhooks...).
 * Read-only actions may use GET; every other action must be a POST that carries
 * a valid CSRF token (X-CSRF-Token header, csrf_token field, or JSON body field).
 */
function nxRequireCsrfForMutations(string $action, array $readOnlyActions): void {
    if (in_array($action, $readOnlyActions, true)) {
        return;
    }
    $fail = static function (int $code, string $msg): never {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    };
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $fail(405, 'This action must be sent as POST.');
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? (is_array($body) ? ($body['csrf_token'] ?? null) : null);
    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        $fail(403, 'Invalid or missing CSRF token. Reload the page and try again.');
    }
}

/**
 * Require User Authentication
 */
function requireAuth(): void {
    $scriptName = basename($_SERVER['PHP_SELF'] ?? '');
    if (in_array($scriptName, ['login.php', 'install.php', 'forgot_password.php', 'verify_prescription.php'])) {
        return;
    }

    if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        if (isAjaxRequest()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthenticated']);
            exit;
        }
        setToast("Session Expired", "Your session has expired. Please log in again.", "error");
        header("Location: login.php?expired=1");
        exit;
    }

    // Inactivity timeout check (2 hours = 7200s)
    $maxInactivity = 7200;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $maxInactivity)) {
        session_unset();
        session_destroy();
        require_once __DIR__ . '/../core/SessionBootstrap.php';
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        setToast("Session Expired", "Your session has expired due to inactivity. Please log in again.", "error");
        if (isAjaxRequest()) {
            http_response_code(401);
            echo json_encode(['error' => 'Session expired due to inactivity.']);
            exit;
        }
        header("Location: login.php?expired=1");
        exit;
    }
    $_SESSION['last_activity'] = time();

    // Session fingerprint check (User-Agent)
    $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!empty($_SESSION['user_agent']) && !empty($currentUserAgent) && $_SESSION['user_agent'] !== $currentUserAgent) {
        session_unset();
        session_destroy();
        require_once __DIR__ . '/../core/SessionBootstrap.php';
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        setToast("Session Violation", "Session security check failed. Please log in again.", "error");
        if (isAjaxRequest()) {
            http_response_code(401);
            echo json_encode(['error' => 'Session security violation.']);
            exit;
        }
        header("Location: login.php");
        exit;
    }
}

/**
 * Require Role-based Access
 */
function requireRole(array $allowedRoles): void {
    requireAuth();
    $userRole = $_SESSION['user_role'] ?? 'Doctor';
    if (!in_array($userRole, $allowedRoles, true)) {
        if (isAjaxRequest()) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized role access']);
            exit;
        }
        http_response_code(403);
        die("403 Forbidden: You do not have permission to access this resource.");
    }
}

/**
 * Rate Limiter for Login Attempts (DB-backed with Session Fallback)
 */
function checkLoginRateLimit(string $ip, string $email = '', ?PDO $pdo = null): bool {
    $maxAttempts = 5;
    $lockoutSeconds = 300; // 5 minutes

    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo !== null) {
        try {
            $cutoff = date('Y-m-d H:i:s', time() - $lockoutSeconds);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE (ip_address = :ip OR (email != '' AND email = :email)) AND attempted_at > :cutoff");
            $stmt->execute(['ip' => $ip, 'email' => $email, 'cutoff' => $cutoff]);
            $count = (int)$stmt->fetchColumn();
            return $count < $maxAttempts;
        } catch (Throwable $e) {
            // Fallback to session rate limit
        }
    }

    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }

    $now = time();
    $_SESSION['login_attempts'] = array_filter($_SESSION['login_attempts'], function($timestamp) use ($now, $lockoutSeconds) {
        return ($now - $timestamp) < $lockoutSeconds;
    });

    return count($_SESSION['login_attempts']) < $maxAttempts;
}

function recordLoginAttempt(string $ip = '', string $email = '', ?PDO $pdo = null): void {
    if ($ip === '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo !== null) {
        try {
            $id = \ClinicFlow\Utils\Uuid::uuidv7();
            $stmt = $pdo->prepare("INSERT INTO login_attempts (id, ip_address, email, attempted_at) VALUES (:id, :ip, :email, :at)");
            $stmt->execute([
                'id' => $id,
                'ip' => $ip,
                'email' => $email,
                'at' => date('Y-m-d H:i:s')
            ]);
            return;
        } catch (Throwable $e) {
            // Fallback
        }
    }

    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    $_SESSION['login_attempts'][] = time();
}

function clearLoginAttempts(string $ip = '', string $email = '', ?PDO $pdo = null): void {
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo !== null && ($ip !== '' || $email !== '')) {
        try {
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = :ip OR (email != '' AND email = :email)");
            $stmt->execute(['ip' => $ip, 'email' => $email]);
        } catch (Throwable $e) {
            // Fallback
        }
    }

    unset($_SESSION['login_attempts']);
}

/**
 * Input Sanitization & Escaping
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Where to send the user back to after an action: the page they came from, but
 * only if it is on THIS site. Redirecting to the raw Referer header let another
 * website bounce a signed-in user onward (open redirect).
 */
function nxSafeReferer(string $fallback = '../index.php'): string {
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref === '') {
        return $fallback;
    }
    $parts = parse_url($ref);
    if ($parts === false) {
        return $fallback;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $refHost = strtolower(($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    if ($refHost !== '' && $refHost !== $host) {
        return $fallback;
    }
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    // Same-site absolute path only; no protocol-relative (//host) or control characters.
    if (!preg_match('#^/(?!/)[^\r\n]*$#', $path)) {
        return $fallback;
    }
    return $path;
}

function isAjaxRequest(): bool {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
           (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}
