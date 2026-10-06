<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

validateCsrfRequest();

$pdo = getDB();

$settings = [
    'vms_enabled' => isset($_POST['vms_enabled']) ? '1' : '0',
    'vms_seller_tin' => trim($_POST['vms_seller_tin'] ?? '502579006'),
    'vms_business_location' => trim($_POST['vms_business_location'] ?? 'Suva Central Clinic, 2 Woodstand Road, Suva'),
    'vms_pos_number' => trim($_POST['vms_pos_number'] ?? 'ASDF238/1.2'),
    'vms_sdc_url' => trim($_POST['vms_sdc_url'] ?? 'https://tap.sandbox.vms.frcs.org.fj'),
    'vms_tax_rate_a' => (float)($_POST['vms_tax_rate_a'] ?? 15.00),
    'vms_tax_rate_e' => (float)($_POST['vms_tax_rate_e'] ?? 0.00),
    'vms_tax_rate_f' => (float)($_POST['vms_tax_rate_f'] ?? 0.00),
    'vms_tax_rate_p' => (float)($_POST['vms_tax_rate_p'] ?? 0.25)
];

try {
    $query = "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    $stmt = $pdo->prepare($query);

    foreach ($settings as $key => $val) {
        $stmt->execute([$key, (string)$val]);
    }

    $_SESSION['flash_success'] = "VMS / EFD Configuration updated successfully!";
} catch (Exception $e) {
    $_SESSION['flash_error'] = "Failed to update VMS settings: " . $e->getMessage();
}

header("Location: ../billing.php?tab=settings");
exit;
