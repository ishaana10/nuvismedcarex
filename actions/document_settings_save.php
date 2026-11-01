<?php
/**
 * Save per-clinic prescription / medical-certificate template customisation.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/access.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Shared\TenantContext;
use ClinicFlow\Services\DocumentTemplateSettings;

requireAuth();
validateCsrfRequest();
requireAccess('settings', 'edit');

$type = $_POST['doc_type'] ?? 'prescription';
if (!in_array($type, DocumentTemplateSettings::TYPES, true)) {
    $type = 'prescription';
}
$tenantId = TenantContext::getTenantId();
$svc = new DocumentTemplateSettings(getDB(), $tenantId);
$back = $_SERVER['HTTP_REFERER'] ?? ('../document_settings.php?doc=' . $type);

try {
    $current = $svc->load($type);
    $logoUpload = $_FILES['logo'] ?? null;
    $cfg = DocumentTemplateSettings::sanitise($type, $_POST, $current, $logoUpload);
    $svc->save($type, $cfg);
    setToast('Saved', ($type === 'certificate' ? 'Medical certificate' : 'Prescription') . ' template updated.');
} catch (\InvalidArgumentException $e) {
    setToast('Logo not saved', $e->getMessage(), 'error');
} catch (\Throwable $e) {
    setToast('Error', 'Could not save the template settings.', 'error');
}

header('Location: ' . $back);
exit;
