<?php
/**
 * Change Password Action Handler
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();
validateCsrfRequest();

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    setToast('Error', 'Invalid session state.', 'error');
    header("Location: ../index.php");
    exit;
}

$currentPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
$redirectUrl = $_POST['redirect_url'] ?? '../index.php';

// Ensure redirect stays internal
if (strpos($redirectUrl, '..') !== 0 && strpos($redirectUrl, '/') !== 0) {
    $redirectUrl = '../index.php';
}

if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
    setToast('Validation Error', 'Please complete all password fields.', 'error');
    header("Location: " . $redirectUrl);
    exit;
}

if ($newPassword !== $confirmPassword) {
    setToast('Password Mismatch', 'New password and confirmation password do not match.', 'error');
    header("Location: " . $redirectUrl);
    exit;
}

if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
    setToast('Weak Password', 'New password must be at least 8 characters long and contain at least one uppercase letter and one number.', 'error');
    header("Location: " . $redirectUrl);
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM doctors WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || empty($user['password_hash'])) {
    setToast('User Error', 'User account not found.', 'error');
    header("Location: " . $redirectUrl);
    exit;
}

if (!password_verify($currentPassword, $user['password_hash'])) {
    setToast('Authentication Error', 'Current password entered is incorrect.', 'error');
    header("Location: " . $redirectUrl);
    exit;
}

// Update password hash
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$updateStmt = $pdo->prepare("UPDATE doctors SET password_hash = ? WHERE id = ?");
$updateStmt->execute([$newHash, $userId]);

setToast('Password Updated', 'Your account password was updated successfully.');
header("Location: " . $redirectUrl);
exit;
