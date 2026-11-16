<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

use ClinicFlow\Services\AuditService;

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!empty($_SESSION['authenticated'])) {
        echo json_encode([
            'authenticated' => true,
            'user' => [
                'id' => $_SESSION['user_id'] ?? null,
                'name' => $_SESSION['user_name'] ?? null,
                'email' => $_SESSION['user_email'] ?? null,
                'role' => $_SESSION['user_role'] ?? null,
                'tenant_id' => $_SESSION['tenant_id'] ?? 'default-clinic'
            ],
            'csrf_token' => getCsrfToken()
        ]);
    } else {
        echo json_encode([
            'authenticated' => false,
            'csrf_token' => getCsrfToken()
        ]);
    }
    exit;
}

if ($method === 'POST') {
    validateCsrfRequest();
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (!checkLoginRateLimit($ip, $email, $pdo)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many login attempts. Please try again in 5 minutes.']);
        exit;
    }

    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password are required.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM doctors WHERE email = :email AND is_active = 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Same timing whether or not the account exists.
    static $dummyHash = '$2y$10$9KlMwQHSl80pZpMXIWmy.e.AQiURt9/cBxOR.T9I2M3MUwvEJ1.Ym';
    $passwordOk = $user && !empty($user['password_hash'])
        ? password_verify($password, $user['password_hash'])
        : (password_verify($password, $dummyHash) && false);

    if ($passwordOk) {
        // Multi-factor: this endpoint used to skip it, so MFA could be bypassed
        // by signing in here instead of through the login page.
        if (!empty($user['mfa_enabled']) && (int) $user['mfa_enabled'] === 1) {
            $mfaCode = trim((string) ($input['mfa_code'] ?? ''));
            $mfa = new \ClinicFlow\Services\MfaService();
            if ($mfaCode === '' || !$mfa->verifyCode((string) ($user['mfa_secret'] ?? ''), $mfaCode)) {
                recordLoginAttempt($ip, $email, $pdo);
                http_response_code(401);
                echo json_encode(['error' => 'Invalid email, password or verification code.', 'mfa_required' => true]);
                exit;
            }
        }

        clearLoginAttempts($ip, $email, $pdo);
        session_regenerate_id(true);

        // Tenant first, so a signed-in session never exists without one.
        $_SESSION['tenant_id'] = $user['tenant_id'] ?? 'default-clinic';
        \ClinicFlow\Shared\TenantContext::setTenantId($_SESSION['tenant_id']);

        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'] ?? 'Doctor';
        $_SESSION['current_doctor_id'] = $user['id'];
        $_SESSION['last_activity'] = time();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['user_ip'] = $ip;
        // NOTE: the full user row (password hash, MFA secret, signature images)
        // is intentionally NOT stored in the session any more.

        $audit = new AuditService($pdo);
        $audit->log("LOGIN_SUCCESS", "User {$user['email']} logged in successfully");

        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);
        exit;
    }

    recordLoginAttempt($ip, $email, $pdo);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password.']);
    exit;
}

if ($method === 'DELETE' || ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'logout')) {
    validateCsrfRequest();
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
