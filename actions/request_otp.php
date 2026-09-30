<?php
/**
 * Request Password Reset OTP Action Handler
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/autoloader.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../forgot_password.php");
    exit;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (!checkLoginRateLimit($clientIp)) {
    setToast('Too Many Attempts', 'Too many requests. Please wait 5 minutes before trying again.', 'error');
    header("Location: ../forgot_password.php");
    exit;
}

validateCsrfRequest();

$email = trim($_POST['email'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    setToast('Invalid Email', 'Please enter a valid email address.', 'error');
    header("Location: ../forgot_password.php");
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM doctors WHERE LOWER(email) = LOWER(?) LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

// Send OTP if user exists and is active
if ($user && isset($user['is_active']) && (int)$user['is_active'] !== 0) {
    try {
        $otp = sprintf("%06d", random_int(0, 999999));
        $expiryTime = date('Y-m-d H:i:s', time() + 15 * 60); // 15 minutes
        $resetId = \ClinicFlow\Utils\Uuid::uuidv7();

        // Save OTP
        $insertStmt = $pdo->prepare("INSERT INTO password_resets (id, email, otp, expires_at, used) VALUES (?, ?, ?, ?, 0)");
        $insertStmt->execute([$resetId, $email, $otp, $expiryTime]);

        // Dispatch Email
        $emailService = new \ClinicFlow\Services\EmailService($pdo);
        $emailService->sendOtpEmail($email, $otp, 15);

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
                'OTP_REQUESTED',
                'Password reset OTP generated and dispatched via email',
                $clientIp
            ]);
        } catch (Throwable $e) {
            // Ignore audit table issues
        }
    } catch (Throwable $e) {
        error_log("Error generating or sending password reset OTP: " . $e->getMessage());
    }
}

// Always present success toast to prevent user enumeration
setToast('Verification Code Sent', 'If an active account exists with that email address, a 6-digit verification code has been sent.', 'success');
header("Location: ../forgot_password.php?step=verify&email=" . urlencode($email));
exit;
