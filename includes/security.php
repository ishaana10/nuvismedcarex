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
    // Scripts run only from this site or from a block carrying this request's nonce; inline event
    // handlers are refused outright (pages use data-on-click, see assets/js/actions.js).
    // Everything the app needs is served from this site; the browser never contacts another host.
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . nxCspNonce() . "'; script-src-attr 'none'; style-src 'self' 'nonce-" . nxCspNonce() . "'; style-src-attr 'none'; font-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self';");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    // Once the site is served over https, tell browsers never to fall back to plain http.
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    }
    header("Cross-Origin-Opener-Policy: same-origin");
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
/** Why a password is not acceptable, or null when it is fine. One rule for every place a password is set. */
function nxPasswordProblem(string $pw): ?string {
    if (strlen($pw) < 10 || !preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw) || !preg_match('/[0-9]/', $pw)) {
        return 'Use at least 10 characters with a capital letter, a small letter and a number.';
    }
    if (strlen($pw) > 200) {
        return 'That password is too long (200 characters at most).';
    }
    $plain = strtolower(preg_replace('/[^a-z]/i', '', $pw));
    if (in_array($plain, ['password', 'passw', 'qwerty', 'welcome', 'letmein', 'admin', 'administrator', 'clinic', 'nuvis'], true)) {
        return 'That password is too easy to guess. Choose something less common.';
    }
    return null;
}

/**
 * Allow at most $max events per $seconds for a named bucket (for example one email
 * address), counting this call. Stored in the database so clearing cookies does not reset it.
 * Fails open if the table is unavailable, so a database hiccup never locks everyone out.
 */
function nxThrottle(string $bucket, int $max, int $seconds): bool {
    try {
        $pdo = getDB();
        $cut = date('Y-m-d H:i:s', time() - $seconds);
        $st = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = 'throttle' AND email = ? AND attempted_at > ?");
        $st->execute([$bucket, $cut]);
        if ((int) $st->fetchColumn() >= $max) {
            return false;
        }
        $pdo->prepare("INSERT INTO login_attempts (id, ip_address, email, attempted_at) VALUES (?, 'throttle', ?, ?)")
            ->execute([\ClinicFlow\Utils\Uuid::uuidv7(), $bucket, date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        error_log('[throttle] ' . $e->getMessage());
    }
    return true;
}

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

    // Signed out after this long without a request. 30 minutes by default (clinic PCs get left
    // unattended); a clinic can change it with 'sessionTimeout' in config/config.php.
    $maxInactivity = max(300, (int) (getAppConfig()['sessionTimeout'] ?? 1800));
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
