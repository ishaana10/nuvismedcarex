<?php
/**
 * Administration → Appearance: save how this clinic wants the app to look and
 * behave for its staff (brand colour, defaults for people who have not set their
 * own preferences, optional menu items, dashboard notice).
 * Requires "Clinic settings: Edit" (checked by the request guard in
 * includes/access.php and again below).
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\AppearanceSettings;
use ClinicFlow\Services\AuditService;
use ClinicFlow\Services\ClinicSettings;

$back = '../appearance_settings.php';

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
$svc = new ClinicSettings($pdo, $tenantId);

try {
    if (!empty($_POST['reset'])) {
        $svc->clear('ui_accent', 'ui_default_theme', 'ui_default_density', 'ui_default_date_format',
                    'ui_default_time_format', 'ui_default_landing', 'ui_default_sidebar', 'ui_hidden_nav', 'ui_announcement');
        $detail = 'Appearance reset to the standard look';
        $toast = ['Appearance reset', 'This clinic is back to the standard look and defaults.'];
    } else {
        $result = AppearanceSettings::fromForm($_POST);
        $svc->save($result['values']);
        $detail = 'Appearance settings updated';
        $toast = $result['adjusted'] !== null
            ? ['Appearance saved', 'That colour was a little light for white text, so it was darkened to ' . $result['adjusted'] . '.']
            : ['Appearance saved', 'Staff see the new look the next time a page loads.'];
    }
    try {
        (new AuditService($pdo))->log(
            'APPEARANCE_UPDATED',
            $detail,
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_name'] ?? null,
            nxCurrentRole(),
            $tenantId
        );
    } catch (\Throwable $e) {
        error_log('[appearance_save] audit: ' . $e->getMessage());
    }
    setToast($toast[0], $toast[1]);
} catch (\Throwable $e) {
    error_log('[appearance_save] ' . $e->getMessage());
    setToast('Not saved', 'The settings could not be saved. Try again.', 'error');
}
header("Location: {$back}");
exit;
