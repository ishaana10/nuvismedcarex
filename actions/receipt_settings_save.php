<?php
/**
 * Administration → Receipts: save this clinic's receipt design.
 * Requires "Clinic settings: Edit" (enforced by the request guard in
 * includes/access.php, and again here so the rule holds if the guard is bypassed).
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\AuditService;
use ClinicFlow\Services\ReceiptSettings;

$back = '../receipt_settings.php';

if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not saved', 'That request expired. Reload the page and try again.', 'error');
    header("Location: {$back}");
    exit;
}
requireAccess('settings', 'edit');

$pdo = getDB();
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$svc = new ReceiptSettings($pdo, $tenantId);
$current = $svc->load();

// Without JavaScript the swatch radio carries the colour instead of the picker.
$input = $_POST;
if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($input['accent'] ?? '')) && !empty($input['accent_choice'])) {
    $input['accent'] = (string) $input['accent_choice'];
}

$upload = $_FILES['logo_file'] ?? null;
$warning = '';

// Report the common upload failures in plain words rather than a PHP error code.
if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && ($upload['error'] ?? 0) !== UPLOAD_ERR_OK) {
    $warning = match ((int) $upload['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The logo was too large to upload, so the previous one was kept.',
        UPLOAD_ERR_PARTIAL => 'The logo upload was interrupted, so the previous one was kept.',
        default => 'The logo could not be uploaded, so the previous one was kept.',
    };
    $upload = null;
}
if ($upload && !empty($upload['tmp_name']) && !is_uploaded_file($upload['tmp_name'])) {
    $upload = null; // only ever read a genuine upload
}

try {
    $cfg = ReceiptSettings::sanitise($input, $current, $upload);
} catch (\InvalidArgumentException $e) {
    // Keep every other change rather than throwing the whole form away
    $warning = $e->getMessage();
    $cfg = ReceiptSettings::sanitise($input, $current, null);
}

try {
    $svc->save($cfg);
} catch (\Throwable $e) {
    error_log('[receipt_settings_save] ' . $e->getMessage());
    setToast('Not saved', 'The receipt design could not be saved. Try again, or contact your administrator.', 'error');
    header("Location: {$back}");
    exit;
}

// Record what actually changed, so the audit log is useful
$changed = [];
foreach ($cfg as $k => $v) {
    $was = $current[$k] ?? null;
    if ($k === 'logo') {
        if ((string) $was !== (string) $v) $changed[] = $v === '' ? 'logo removed' : 'logo replaced';
        continue;
    }
    if ($was === $v) continue;
    $fmt = fn($x) => is_bool($x) ? ($x ? 'on' : 'off') : (string) $x;
    $changed[] = sprintf('%s: %s → %s', $k, $fmt($was), $fmt($v));
}
if ($changed) {
    try {
        (new AuditService($pdo))->log(
            'RECEIPT_DESIGN_UPDATED',
            implode('; ', array_slice($changed, 0, 40)),
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_name'] ?? null,
            nxCurrentRole(),
            $tenantId
        );
    } catch (\Throwable $e) {
        error_log('[receipt_settings_save] audit: ' . $e->getMessage());
    }
}

if ($warning !== '') {
    setToast('Saved with one problem', $warning, 'error');
} elseif (!$changed) {
    setToast('No changes', 'The receipt design was already set that way.', 'info');
} else {
    $n = count($changed);
    setToast('Receipt design saved', $n . ($n === 1 ? ' change' : ' changes') . ' applied. New receipts use this layout.');
}
header("Location: {$back}");
exit;
