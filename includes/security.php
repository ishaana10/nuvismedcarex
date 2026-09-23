<?php
/**
 * Security & Helper functions (CSRF, RBAC, Rate Limiting, Sanitization, Secure Session)
 */

if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session cookie attributes
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Global Security & Anti-Caching HTTP Headers
if (!headers_sent()) {
    header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-XSS-Protection: 1; mode=block");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self' https:;");
    header("Referrer-Policy: strict-origin-when-cross-origin");
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
 * Require User Authentication
 */
function requireAuth(): void {
    if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        if (isAjaxRequest()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthenticated']);
            exit;
        }
        header("Location: login.php");
        exit;
    }

    // Inactivity timeout check (2 hours = 7200s)
    $maxInactivity = 7200;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $maxInactivity)) {
        session_unset();
        session_destroy();
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

function isAjaxRequest(): bool {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
           (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}
