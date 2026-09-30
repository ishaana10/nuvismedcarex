<?php
/**
 * Verify OTP & Reset Password Action Handler
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../forgot_password.php");
    exit;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (!checkLoginRateLimit($clientIp)) {
    setToast('Too Many Attempts', 'Too many failed attempts. Please wait 5 minutes before trying again.', 'error');
    header("Location: ../forgot_password.php");
    exit;
}

validateCsrfRequest();

$email = trim($_POST['email'] ?? '');
$otp = trim($_POST['otp'] ?? '');
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if (empty($email) || empty($otp) || empty($newPassword) || empty($confirmPassword)) {
    setToast('Validation Error', 'Please complete all required fields.', 'error');
    header("Location: ../forgot_password.php?step=verify&email=" . urlencode($email));
    exit;
}

if ($newPassword !== $confirmPassword) {
    recordLoginAttempt($clientIp, $email);
    setToast('Password Mismatch', 'New password and confirmation password do not match.', 'error');
    header("Location: ../forgot_password.php?step=verify&email=" . urlencode($email));
    exit;
}

if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
    recordLoginAttempt($clientIp, $email);
    setToast('Weak Password', 'New password must be at least 8 characters long and contain at least one uppercase letter and one number.', 'error');
    header("Location: ../forgot_password.php?step=verify&email=" . urlencode($email));
    exit;
}

$pdo = getDB();
$now = date('Y-m-d H:i:s');

// Verify OTP
$otpStmt = $pdo->prepare("SELECT * FROM password_resets WHERE LOWER(email) = LOWER(?) AND otp = ? AND used = 0 AND expires_at > ? ORDER BY created_at DESC LIMIT 1");
$otpStmt->execute([$email, $otp, $now]);
$resetRecord = $otpStmt->fetch();

if (!$resetRecord) {
    recordLoginAttempt($clientIp, $email);
    setToast('Invalid Code', 'The verification code entered is invalid or has expired.', 'error');
    header("Location: ../forgot_password.php?step=verify&email=" . urlencode($email));
    exit;
}

// Find user account
$userStmt = $pdo->prepare("SELECT * FROM doctors WHERE LOWER(email) = LOWER(?) LIMIT 1");
$userStmt->execute([$email]);
$user = $userStmt->fetch();

if (!$user || (isset($user['is_active']) && (int)$user['is_active'] === 0)) {
    setToast('Account Error', 'Unable to update password for this account.', 'error');
    header("Location: ../login.php");
    exit;
}

// Update password
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$updateStmt = $pdo->prepare("UPDATE doctors SET password_hash = ? WHERE id = ?");
$updateStmt->execute([$newHash, $user['id']]);

// Mark OTP as used
$markStmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
$markStmt->execute([$resetRecord['id']]);

// Clear rate limits
clearLoginAttempts($clientIp, $email, $pdo);

// Audit log
try {
    $auditId = \ClinicFlow\Utils\Uuid::uuidv7();
    $tenantId = $user['tenant_id'] ?? 'default-clinic';
    $auditStmt = $pdo->prepare("INSERT INTO audit_logs (id, tenant_id, user_id, user_name, user_role, action, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $auditStmt->execute([
        $auditId,
        $tenantId,
        $user['id'],
        $user['name'],
        $user['role'] ?? 'Doctor',
        'PASSWORD_RESET_COMPLETED',
        'User password successfully reset via email OTP verification',
        $clientIp
    ]);
} catch (Throwable $e) {
    // Suppress if audit table unavailable
}

setToast('Password Reset Successful', 'Your password has been successfully reset. Please log in with your new credentials.', 'success');
header("Location: ../login.php");
exit;
