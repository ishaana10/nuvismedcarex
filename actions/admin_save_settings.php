<?php
/**
 * Administrator Settings Save Action
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $redirect = $_SERVER['HTTP_REFERER'] ?? '../admin.php';
    header("Location: " . $redirect);
    exit;
}

$pdo = getDB();
require_once __DIR__ . '/../includes/security.php';
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not saved', 'Your session token expired. Reload the page and try again.', 'error');
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../admin.php'));
    exit;
}

// Only keys present in the submitted form are saved, so saving one section
// (e.g. email) never resets another (e.g. the clinic name) to a default.
$textKeys = [
    'clinic_name', 'clinic_subtitle', 'clinic_address', 'clinic_phone', 'clinic_email', 'clinic_dea', 'clinic_npi',
    'rx_header_title', 'rx_disclaimer', 'rx_footer_note',
    'invoice_header_title', 'invoice_tax_id', 'invoice_payment_terms', 'invoice_footer_note',
    'receipt_header_title', 'receipt_thank_you_msg',
    'cert_header_title', 'cert_disclaimer', 'cert_footer_note',
    'doc_prc_no', 'doc_ptr_no',
    'clinic_website',
];
$settings = [];
foreach ($textKeys as $k) {
    if (array_key_exists($k, $_POST)) {
        $settings[$k] = trim((string) $_POST[$k]);
    }
}
if (isset($settings['clinic_name']) && $settings['clinic_name'] === '') {
    unset($settings['clinic_name']); // a clinic always keeps a name
}
if (array_key_exists('default_pagination_limit', $_POST)) {
    $settings['default_pagination_limit'] = (int) $_POST['default_pagination_limit'];
}

// VMS Fiscal Settings (if submitted)
if (isset($_POST['vms_seller_tin'])) {
    $settings['vms_enabled']           = isset($_POST['vms_enabled']) ? '1' : '0';
    $settings['vms_seller_tin']        = trim($_POST['vms_seller_tin']);
    $settings['vms_pos_number']        = trim($_POST['vms_pos_number']);
    $settings['vms_business_location'] = trim($_POST['vms_business_location']);
    $settings['vms_sdc_url']           = trim($_POST['vms_sdc_url']);
    $settings['vms_tax_rate_a']        = trim($_POST['vms_tax_rate_a'] ?? '15.00');
    $settings['vms_tax_rate_e']        = trim($_POST['vms_tax_rate_e'] ?? '0.00');
    $settings['vms_tax_rate_f']        = trim($_POST['vms_tax_rate_f'] ?? '0.00');
    $settings['vms_tax_rate_p']        = trim($_POST['vms_tax_rate_p'] ?? '0.25');
}

// Email: this clinic's own mailbox
if (isset($_POST['section']) && $_POST['section'] === 'email') {
    $settings['smtp_host']     = trim($_POST['smtp_host'] ?? '');
    $settings['smtp_port']     = (string) max(1, min(65535, (int) ($_POST['smtp_port'] ?? 587)));
    $settings['smtp_user']     = trim($_POST['smtp_user'] ?? '');
    $settings['smtp_from']     = trim($_POST['smtp_from'] ?? '');
    $settings['smtp_secure']   = in_array($_POST['smtp_secure'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_secure'] : 'tls';
    $settings['email_reply_to'] = trim($_POST['email_reply_to'] ?? '');
    $provider = (string) ($_POST['smtp_provider'] ?? 'custom');
    $settings['smtp_provider'] = isset(\ClinicFlow\Services\EmailService::PROVIDERS[$provider]) ? $provider : 'custom';

    // An empty password box means "keep the one already saved", so a password
    // is never wiped just because the form doesn't echo it back.
    if (($_POST['smtp_pass'] ?? '') !== '') {
        $settings['smtp_pass'] = (string) $_POST['smtp_pass'];
    }
}

// Clinic logo (branding tab)
if (!empty($_POST['remove_clinic_logo'])) {
    $settings['clinic_logo'] = '';
}
$logo = $_FILES['clinic_logo_file'] ?? null;
if ($logo && (int) ($logo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $logo['tmp_name'])) {
    try {
        $settings['clinic_logo'] = \ClinicFlow\Services\ClinicSettings::logoToDataUri($logo);
    } catch (\InvalidArgumentException $e) {
        setToast('Logo not updated', $e->getMessage(), 'error');
        unset($settings['clinic_logo']);
    }
} elseif ($logo && (int) ($logo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE) {
    setToast('Logo not updated', 'That image was too large to upload. Use one under 300 KB.', 'error');
}

// Inventory set-up (built by the editor on the Inventory tab)
if (isset($_POST['inventory_categories'])) {
    $settings['inventory_categories'] = \ClinicFlow\Services\InventorySetup::cleanCategoryList((string) $_POST['inventory_categories']);
    $settings['inventory_units'] = \ClinicFlow\Services\InventorySetup::cleanCategoryList((string) ($_POST['inventory_units'] ?? ''));
    $settings['inventory_default_min_threshold'] = max(0, (int) ($_POST['inventory_default_min_threshold'] ?? 10));
    $settings['inventory_expiry_warning_days'] = max(0, (int) ($_POST['inventory_expiry_warning_days'] ?? 30));
    $settings['inventory_custom_fields_def'] = \ClinicFlow\Services\InventorySetup::cleanFieldJson((string) ($_POST['inventory_custom_fields_def'] ?? '[]'));
}

// SSE Real-Time Notifications Toggle (if submitted)
if (isset($_POST['sse_setting_submitted']) || isset($_POST['sse_notifications_enabled'])) {
    $settings['sse_notifications_enabled'] = isset($_POST['sse_notifications_enabled']) ? '1' : '0';
}

// Lab Catalog Customization (if submitted)
if (isset($_POST['lab_catalog_json'])) {
    $settings['lab_catalog'] = trim($_POST['lab_catalog_json']);
}

// Each value is written to the store that owns it: clinic identity, document
// wording, tax numbers and inventory set-up belong to this clinic alone;
// SMTP and other installation-wide keys stay shared. See ClinicSettings.
$svc = nxClinicSettings();
try {
    $counts = $svc->save($settings);
} catch (\Throwable $e) {
    error_log('[admin_save_settings] ' . $e->getMessage());
    setToast('Not saved', 'Those settings could not be saved. Try again, or contact your administrator.', 'error');
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../admin.php'));
    exit;
}

// The tenant record carries the name shown in the clinic switcher
if (!empty($settings['clinic_name'])) {
    try {
        $tStmt = $pdo->prepare("UPDATE tenants SET name = ? WHERE id = ?");
        $tStmt->execute([$settings['clinic_name'], $tenantId]);
    } catch (\Throwable $te) {
        error_log('[admin_save_settings] tenant name: ' . $te->getMessage());
    }
}

require_once __DIR__ . '/../core/Cache.php';
\NuCache::getInstance()->invalidateSystemSettings();

$clinicName = $settings['clinic_name'] ?? nxClinicName();
setToast('Settings saved', $counts['clinic'] > 0
    ? 'Updated for ' . $clinicName . '. Other clinics are unaffected.'
    : 'Updated for every clinic on this installation.');
$redirect = $_SERVER['HTTP_REFERER'] ?? '../admin.php';
header("Location: " . $redirect);
exit;
