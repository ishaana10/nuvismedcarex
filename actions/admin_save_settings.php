<?php
/**
 * Administrator Settings Save Action
 */
session_start();
if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin.php");
    exit;
}

$pdo = getDB();

$settings = [
    'clinic_name'      => trim($_POST['clinic_name'] ?? 'ClinicFlow Medical Center'),
    'clinic_subtitle'  => trim($_POST['clinic_subtitle'] ?? ''),
    'clinic_address'   => trim($_POST['clinic_address'] ?? ''),
    'clinic_phone'     => trim($_POST['clinic_phone'] ?? ''),
    'clinic_email'     => trim($_POST['clinic_email'] ?? ''),
    'clinic_dea'       => trim($_POST['clinic_dea'] ?? ''),
    'clinic_npi'       => trim($_POST['clinic_npi'] ?? ''),
    'rx_header_title'       => trim($_POST['rx_header_title'] ?? 'OFFICIAL MEDICAL PRESCRIPTION'),
    'rx_disclaimer'         => trim($_POST['rx_disclaimer'] ?? ''),
    'rx_footer_note'        => trim($_POST['rx_footer_note'] ?? ''),
    'invoice_header_title'  => trim($_POST['invoice_header_title'] ?? 'MEDICAL SERVICES INVOICE'),
    'invoice_tax_id'        => trim($_POST['invoice_tax_id'] ?? ''),
    'invoice_payment_terms' => trim($_POST['invoice_payment_terms'] ?? ''),
    'invoice_footer_note'   => trim($_POST['invoice_footer_note'] ?? ''),
    'receipt_header_title'  => trim($_POST['receipt_header_title'] ?? 'OFFICIAL PAYMENT RECEIPT'),
    'receipt_thank_you_msg' => trim($_POST['receipt_thank_you_msg'] ?? ''),
    'cert_header_title'     => trim($_POST['cert_header_title'] ?? 'OFFICIAL MEDICAL CERTIFICATE'),
    'cert_disclaimer'       => trim($_POST['cert_disclaimer'] ?? ''),
    'cert_footer_note'      => trim($_POST['cert_footer_note'] ?? ''),
    'doc_prc_no'            => trim($_POST['doc_prc_no'] ?? ''),
    'doc_ptr_no'            => trim($_POST['doc_ptr_no'] ?? '')
];

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

// Dynamic Custom Permission Addition / Deletion
$action = $_POST['action'] ?? '';
if ($action === 'add_custom_permission') {
    $key = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['permission_key'] ?? '')));
    $label = trim($_POST['permission_label'] ?? '');
    if ($key !== '' && $label !== '') {
        $stmtSel = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'rbac_custom_modules'");
        $stmtSel->execute();
        $currJson = $stmtSel->fetchColumn();
        $currArr = json_decode($currJson ?: '{}', true) ?: [];
        $currArr[$key] = $label;
        $settings['rbac_custom_modules'] = json_encode($currArr);
    }
}

if ($action === 'delete_custom_permission') {
    $key = trim($_POST['permission_key'] ?? '');
    if ($key !== '') {
        $stmtSel = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'rbac_custom_modules'");
        $stmtSel->execute();
        $currJson = $stmtSel->fetchColumn();
        $currArr = json_decode($currJson ?: '{}', true) ?: [];
        unset($currArr[$key]);
        $settings['rbac_custom_modules'] = json_encode($currArr);
    }
}

// RBAC Group Permissions Settings (if submitted)
if (isset($_POST['rbac'])) {
    $settings['rbac_group_permissions'] = json_encode($_POST['rbac']);
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

$isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
$query = $isSqlite
    ? "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value"
    : "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
$stmt = $pdo->prepare($query);

foreach ($settings as $key => $val) {
    $stmt->execute([$key, $val]);
}

setToast("Settings Saved", "Clinic branding and prescription settings updated successfully.");
header("Location: ../admin.php");
exit;
