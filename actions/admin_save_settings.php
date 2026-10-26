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

// Email Settings (if submitted)
if (isset($_POST['smtp_host']) || (isset($_POST['section']) && $_POST['section'] === 'email')) {
    $settings['smtp_host']   = trim($_POST['smtp_host'] ?? '');
    $settings['smtp_port']   = trim($_POST['smtp_port'] ?? '587');
    $settings['smtp_user']   = trim($_POST['smtp_user'] ?? '');
    $settings['smtp_pass']   = trim($_POST['smtp_pass'] ?? '');
    $settings['smtp_from']   = trim($_POST['smtp_from'] ?? '');
    $settings['smtp_secure'] = trim($_POST['smtp_secure'] ?? 'tls');
}

// Inventory Developer Settings (if submitted)
if (isset($_POST['inventory_categories'])) {
    $settings['inventory_categories']            = trim($_POST['inventory_categories']);
    $settings['inventory_default_min_threshold'] = (int)($_POST['inventory_default_min_threshold'] ?? 10);
    $settings['inventory_custom_fields_def']     = trim($_POST['inventory_custom_fields_def'] ?? '[]');
}

// SSE Real-Time Notifications Toggle (if submitted)
if (isset($_POST['sse_setting_submitted']) || isset($_POST['sse_notifications_enabled'])) {
    $settings['sse_notifications_enabled'] = isset($_POST['sse_notifications_enabled']) ? '1' : '0';
}

// Lab Catalog Customization (if submitted)
if (isset($_POST['lab_catalog_json'])) {
    $settings['lab_catalog'] = trim($_POST['lab_catalog_json']);
}

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$query = ($driver === 'sqlite')
    ? "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value"
    : "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
$stmt = $pdo->prepare($query);

$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$tsQuery = ($driver === 'sqlite')
    ? "INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (?, ?, ?, ?) ON CONFLICT(tenant_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP"
    : "INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP";
$tsStmt = $pdo->prepare($tsQuery);

foreach ($settings as $key => $val) {
    $stmt->execute([$key, $val]);
    try {
        $tsStmt->execute(['ts-' . $tenantId . '-' . $key, $tenantId, $key, $val]);
    } catch (\Throwable $te) {
        // Fallback
    }
}

// Also update tenants table name if clinic_name was saved
if (!empty($settings['clinic_name'])) {
    try {
        $tStmt = $pdo->prepare("UPDATE tenants SET name = ? WHERE id = ?");
        $tStmt->execute([$settings['clinic_name'], $tenantId]);
    } catch (\Throwable $te) {
        // Fallback
    }
}

require_once __DIR__ . '/../core/Cache.php';
\NuCache::getInstance()->invalidateSystemSettings();

setToast("Settings Saved", "Clinic branding and prescription settings updated successfully.");
$redirect = $_SERVER['HTTP_REFERER'] ?? '../admin.php';
header("Location: " . $redirect);
exit;
