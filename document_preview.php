<?php
/**
 * Live preview for Administration → Documents.
 *
 * Receives the settings form exactly as it stands (saved or not), applies it to
 * a copy and draws the real prescription / medical certificate page with a
 * sample patient. Nothing is saved. The settings page puts the result in a
 * frame and refreshes it as the clinic changes options.
 */
require_once __DIR__ . '/core/SessionBootstrap.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/access.php';

use ClinicFlow\Services\DocumentTemplateSettings;

requireAuth();
requireAccess('settings', 'view');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
validateCsrfRequest();

$type = $_POST['doc_type'] ?? 'prescription';
if (!in_array($type, DocumentTemplateSettings::TYPES, true)) {
    $type = 'prescription';
}

$pdo = getDB();
$svc = new DocumentTemplateSettings($pdo, \ClinicFlow\Shared\TenantContext::getTenantId());
$current = $svc->load($type);
try {
    $cfg = DocumentTemplateSettings::sanitise($type, $_POST, $current, $_FILES['logo'] ?? null);
} catch (\InvalidArgumentException $e) {
    // an unusable logo file: preview without it rather than failing
    $cfg = DocumentTemplateSettings::sanitise($type, $_POST, $current, null);
}

define('NX_DOC_PREVIEW', true);
$GLOBALS['nxPreviewCfg'] = $cfg;
$_GET['patient_id'] = 'preview';
header('Cache-Control: no-store');

require __DIR__ . ($type === 'certificate' ? '/print_medical_certificate.php' : '/print_prescription.php');
