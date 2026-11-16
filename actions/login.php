<?php
/**
 * ClinicFlow Login Action Handler
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../login.php");
    exit;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

validateCsrfRequest();

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Throttle by IP *and* by account, so a distributed guessing attack on one
// account is slowed down even when every guess comes from a different address.
if (!checkLoginRateLimit($clientIp, $email)) {
    setToast('Too Many Attempts', 'Too many failed login attempts. Please wait 5 minutes before trying again.', 'error');
    header("Location: ../login.php");
    exit;
}

if ($email === '' || $password === '') {
    recordLoginAttempt($clientIp, $email);
    setToast('Login Error', 'Please enter both email and password.', 'error');
    header("Location: ../login.php");
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM doctors WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

// Always run a password hash comparison, even for unknown emails, so response
// time does not reveal which email addresses have accounts.
static $dummyHash = '$2y$10$9KlMwQHSl80pZpMXIWmy.e.AQiURt9/cBxOR.T9I2M3MUwvEJ1.Ym';
$valid = false;
if ($user && !empty($user['password_hash'])) {
    $valid = password_verify($password, $user['password_hash']);
} else {
    password_verify($password, $dummyHash);
}

// Only someone who knows the password learns the account is deactivated.
if ($valid && $user && isset($user['is_active']) && (int)$user['is_active'] === 0) {
    setToast('Account Disabled', 'Your user account has been deactivated. Please contact an administrator.', 'error');
    header("Location: ../login.php");
    exit;
}

// MFA Verification Check if MFA is enabled for user
if ($valid && $user && !empty($user['mfa_enabled']) && (int)$user['mfa_enabled'] === 1) {
    $mfaCode = trim($_POST['mfa_code'] ?? '');
    $mfaService = new \ClinicFlow\Services\MfaService();
    if (empty($mfaCode) || !$mfaService->verifyCode($user['mfa_secret'] ?? '', $mfaCode)) {
        recordLoginAttempt($clientIp, $email);
        setToast('MFA Verification Failed', 'Invalid multi-factor authentication code provided.', 'error');
        header("Location: ../login.php?mfa_required=1&email=" . urlencode($email));
        exit;
    }
}

if ($valid && $user) {
    clearLoginAttempts();
    session_regenerate_id(true);

    // The tenant is part of "being signed in": set it first and never inside a
    // try/catch, so a signed-in session can never exist without one.
    $tenantId = $user['tenant_id'] ?? 'default-clinic';
    $_SESSION['tenant_id'] = $tenantId;
    \ClinicFlow\Shared\TenantContext::setTenantId($tenantId);

    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'] ?? 'Doctor';
    $_SESSION['current_doctor_id'] = $user['id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $_SESSION['user_ip'] = $clientIp;

    // Log login activity to audit_logs
    try {
        $auditId = \ClinicFlow\Utils\Uuid::uuidv7();
        $auditStmt = $pdo->prepare("INSERT INTO audit_logs (id, tenant_id, user_id, user_name, user_role, action, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $auditStmt->execute([
            $auditId,
            $tenantId,
            $user['id'],
            $user['name'],
            $user['role'] ?? 'Doctor',
            'USER_LOGIN',
            'Successful user authentication',
            $clientIp
        ]);
    } catch (Throwable $e) {
        // Suppress if audit table not ready
    }

    setToast('Welcome back', 'Signed in as ' . $user['name'] . '.');

    // Open the page this person chose in Settings → Appearance
    $landing = 'index.php';
    try {
        $own = \ClinicFlow\Services\UserPreferences::forTenant($pdo, (string) $tenantId)->load($user['id'])['landing_page'] ?? 'index.php';
        if (isset(\ClinicFlow\Services\UserPreferences::LANDING[$own])) {
            $landing = $own;
        }
    } catch (\Throwable $e) {
        error_log('[login] landing page: ' . $e->getMessage());
    }
    header('Location: ../' . $landing);
    exit;
} else {
    recordLoginAttempt($clientIp, $email);
    setToast('Authentication Failed', 'Invalid email address or password.', 'error');
    header("Location: ../login.php");
    exit;
}
