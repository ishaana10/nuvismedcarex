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
    header('Location: ../settings.php?tab=email');
    exit;
}

verifyCsrfToken($_POST['csrf_token'] ?? '');

$recipient = trim($_POST['recipient_email'] ?? $_POST['test_email_recipient'] ?? '');
if (empty($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    $err = 'Please enter a valid recipient email address for testing.';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }
    setToast('Invalid Email', $err, 'error');
    header('Location: ../settings.php?tab=email');
    exit;
}

$container = \ClinicFlow\Shared\Container::getInstance();
$emailService = $container->get(\ClinicFlow\Services\EmailService::class);

// Custom SMTP settings if provided in test form, else use tenant saved settings
$smtpConfig = [
    'smtp_host'   => trim($_POST['smtp_host'] ?? ''),
    'smtp_port'   => (int)($_POST['smtp_port'] ?? 587),
    'smtp_user'   => trim($_POST['smtp_user'] ?? ''),
    'smtp_pass'   => trim($_POST['smtp_pass'] ?? ''),
    'smtp_from'   => trim($_POST['smtp_from'] ?? ''),
    'smtp_secure' => trim($_POST['smtp_secure'] ?? 'tls'),
];

// If form fields were empty, resolve from tenant settings
if (empty($smtpConfig['smtp_host'])) {
    $smtpConfig = $emailService->getSmtpSettings();
}

$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$subject = "Nuvis Healthcare - Test Email Dispatch";
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

$res = $emailService->sendSmtpEmail($recipient, $subject, $body, $smtpConfig);

// Log test result
$emailService->logEmailResult($tenantId, $recipient, $subject, 'test_email', null, $res['success'] ? 'Sent' : 'Failed', $res['error'] ?? null);

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
