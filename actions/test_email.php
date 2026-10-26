<?php

require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
        exit;
    }
    header('Location: ../admin.php?tab=email');
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $err = 'That request expired. Reload the page and try again.';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }
    setToast('Not sent', $err, 'error');
    header('Location: ../admin.php?tab=email');
    exit;
}

$recipient = trim($_POST['recipient_email'] ?? $_POST['test_email_recipient'] ?? '');
if (empty($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    $err = 'Please enter a valid recipient email address for testing.';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }
    setToast('Invalid Email', $err, 'error');
    header('Location: ../admin.php?tab=email');
    exit;
}

$container = \ClinicFlow\Shared\Container::getInstance();
$emailService = $container->get(\ClinicFlow\Services\EmailService::class);

// Custom SMTP settings if provided in test form, else use tenant saved settings
// Start from what this clinic has saved, then apply anything typed into the
// form, so a test can be run before saving — and so leaving the password box
// empty keeps using the stored one.
$saved = $emailService->getSmtpSettings();
$smtpConfig = $saved;
foreach (['smtp_host', 'smtp_user', 'smtp_from', 'smtp_secure', 'smtp_from_name'] as $k) {
    if (isset($_POST[$k]) && trim((string) $_POST[$k]) !== '') {
        $smtpConfig[$k] = trim((string) $_POST[$k]);
    }
}
if (isset($_POST['smtp_port']) && (int) $_POST['smtp_port'] > 0) {
    $smtpConfig['smtp_port'] = (int) $_POST['smtp_port'];
}
if (isset($_POST['smtp_pass']) && $_POST['smtp_pass'] !== '') {
    $smtpConfig['smtp_pass'] = (string) $_POST['smtp_pass'];
}
if (($smtpConfig['smtp_host'] ?? '') === '') {
    $err = 'Add your outgoing mail server details first, then send a test.';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }
    setToast('Not sent', $err, 'error');
    header('Location: ../admin.php?tab=email');
    exit;
}

$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId() ?: 'default-clinic';
$clinicName = function_exists('nxClinicName') ? nxClinicName() : 'your clinic';
if (($smtpConfig['smtp_from_name'] ?? '') === '') {
    $smtpConfig['smtp_from_name'] = $clinicName; // patients see the clinic, not the mailbox
}
$subject = 'Test message from ' . $clinicName;
$body = "
<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
    <h2 style='color: #2563eb; margin-bottom: 4px;'>Nuvis Healthcare Engine</h2>
    <p style='color: #64748b; font-size: 13px;'>SMTP Email Settings Connection Verification</p>
    <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 16px 0;'>
    <p>Hello,</p>
    <p>This is an automated test email confirming that your tenant (<strong>" . htmlspecialchars($tenantId) . "</strong>) SMTP email settings are properly configured and operational.</p>
    <div style='background-color: #f8fafc; padding: 15px; border-radius: 8px; margin: 15px 0; border: 1px solid #cbd5e1; font-size: 12px; font-family: monospace;'>
        <p style='margin: 2px 0;'><strong>SMTP Server:</strong> " . htmlspecialchars($smtpConfig['smtp_host'] ?: 'Default Host') . ":" . $smtpConfig['smtp_port'] . "</p>
        <p style='margin: 2px 0;'><strong>From Email:</strong> " . htmlspecialchars($smtpConfig['smtp_from'] ?: 'Default Sender') . "</p>
        <p style='margin: 2px 0;'><strong>Security Protocol:</strong> " . strtoupper(htmlspecialchars($smtpConfig['smtp_secure'])) . "</p>
        <p style='margin: 2px 0;'><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</p>
    </div>
    <p style='font-size: 12px; color: #475569;'>If you received this message, your email dispatch system is fully functional.</p>
    <br>
    <p style='font-size: 11px; color: #94a3b8;'>Nuvis Healthcare Platform &bull; Automated System Test</p>
</div>";

try {
    $res = $emailService->sendSmtpEmail($recipient, $subject, $body, $smtpConfig);
} catch (\Throwable $e) {
    $res = [
        'success' => false,
        'error' => 'SMTP Dispatch Exception: ' . $e->getMessage()
    ];
}

// Guarantee log entry in email_logs
try {
    $emailService->logEmailResult($tenantId, $recipient, $subject, 'test_email', null, !empty($res['success']) ? 'Sent' : 'Failed', $res['error'] ?? null);
} catch (\Throwable $e) {
    error_log("Failed to insert email log record: " . $e->getMessage());
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode($res);
    exit;
}

if ($res['success']) {
    setToast('Test Email Sent', "Test email sent successfully to {$recipient}.", 'success');
} else {
    setToast('Test Email Failed', $res['error'] ?? 'Failed to deliver test email.', 'error');
}

$redirect = !empty($_POST['redirect_to']) ? $_POST['redirect_to'] : '../settings.php?tab=email';
header("Location: $redirect");
exit;
