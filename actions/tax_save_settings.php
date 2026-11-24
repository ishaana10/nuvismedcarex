<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

validateCsrfRequest();

$pdo = getDB();

$rate = static function (string $key, float $default): float {
    $v = (float) ($_POST[$key] ?? $default);
    return max(0.0, min(100.0, $v));
};
$settings = [
    'tax_rate_a' => $rate('tax_rate_a', 15.00),
    'tax_rate_e' => $rate('tax_rate_e', 0.00),
    'tax_rate_f' => $rate('tax_rate_f', 0.00),
    'tax_rate_p' => $rate('tax_rate_p', 0.25),
];

try {
    $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $query = ($driver === 'sqlite')
        ? "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value"
        : "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    $stmt = $pdo->prepare($query);

    $tsQuery = ($driver === 'sqlite')
        ? "INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (?, ?, ?, ?) ON CONFLICT(tenant_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP"
        : "INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP";
    $tsStmt = $pdo->prepare($tsQuery);

    foreach ($settings as $key => $val) {
        if ($tenantId === 'default-clinic') { $stmt->execute([$key, (string)$val]); } // the shared row is the original clinic's default
        try {
            $tsStmt->execute(['ts-' . $tenantId . '-' . $key, $tenantId, $key, (string)$val]);
        } catch (\Throwable $te) {}
    }

    require_once __DIR__ . '/../core/Cache.php';
    \NuCache::getInstance()->invalidateSystemSettings();

    $_SESSION['flash_success'] = "Tax rates saved.";
} catch (Exception $e) {
    $_SESSION['flash_error'] = "Failed to save tax rates: " . $e->getMessage();
}

header("Location: ../billing.php?tab=settings");
exit;
