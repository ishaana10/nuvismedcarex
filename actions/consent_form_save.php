<?php
/**
 * Administration → Consent forms: create, edit, duplicate, retire or delete a
 * consent form template.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\AuditService;
use ClinicFlow\Services\ConsentForms;

$back = '../consent_forms.php';

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
$svc = new ConsentForms($pdo, $tenantId);

$action = (string) ($_POST['action'] ?? 'save');
$formId = (string) ($_POST['form_id'] ?? '');

try {
    switch ($action) {
        case 'install_sample':
            $key = (string) ($_POST['sample'] ?? '');
            $newId = $svc->installSample($key);
            $form = $svc->find($newId);
            audit($pdo, $tenantId, 'CONSENT_FORM_CREATED', 'Installed template: ' . ($form['name'] ?? $key));
            setToast('Template added', ($form['name'] ?? 'The form') . ' is ready. Change the wording to suit the clinic.');
            header("Location: {$back}?form_id=" . urlencode($newId));
            exit;

        case 'duplicate':
            $copyId = $svc->duplicate($formId);
            if ($copyId === null) {
                setToast('Not copied', 'That form no longer exists.', 'error');
                break;
            }
            audit($pdo, $tenantId, 'CONSENT_FORM_CREATED', 'Duplicated form ' . $formId);
            setToast('Copied', 'The copy is retired until you put it in use.');
            header("Location: {$back}?form_id=" . urlencode($copyId));
            exit;

        case 'retire':
            $svc->retire($formId);
            audit($pdo, $tenantId, 'CONSENT_FORM_RETIRED', 'Retired form ' . $formId);
            setToast('Retired', 'It will not be offered on new encounters. Consents already signed are kept.');
            break;

        case 'restore':
            $svc->restore($formId);
            audit($pdo, $tenantId, 'CONSENT_FORM_RESTORED', 'Restored form ' . $formId);
            setToast('Back in use', 'The form is offered on encounters again.');
            break;

        case 'delete':
            if ($svc->delete($formId)) {
                audit($pdo, $tenantId, 'CONSENT_FORM_DELETED', 'Deleted form ' . $formId);
                setToast('Deleted', 'The form is gone.');
            } else {
                setToast('Not deleted', 'Consents have been signed against this form, so it was retired instead.', 'error');
                $svc->retire($formId);
            }
            break;

        case 'save':
        default:
            $clean = ConsentForms::sanitise($_POST);
            $savedId = $svc->save($clean, $formId !== '' ? $formId : null);
            audit(
                $pdo,
                $tenantId,
                $formId !== '' ? 'CONSENT_FORM_UPDATED' : 'CONSENT_FORM_CREATED',
                sprintf('%s (%d question%s)', $clean['name'], count($clean['fields']), count($clean['fields']) === 1 ? '' : 's')
            );
            setToast(
                $formId !== '' ? 'Form saved' : 'Form created',
                $clean['is_active']
                    ? $clean['name'] . ' is now offered on clinical encounters.'
                    : $clean['name'] . ' is saved but retired, so it will not appear on encounters.'
            );
            break;
    }
} catch (\InvalidArgumentException | \RuntimeException $e) {
    setToast('Not saved', $e->getMessage(), 'error');
    header("Location: {$back}" . ($formId !== '' ? '?form_id=' . urlencode($formId) : '?form_id=new'));
    exit;
} catch (\Throwable $e) {
    error_log('[consent_form_save] ' . $e->getMessage());
    setToast('Not saved', 'The form could not be saved. Try again, or contact your administrator.', 'error');
    header("Location: {$back}");
    exit;
}

header("Location: {$back}");
exit;

function audit(PDO $pdo, string $tenantId, string $event, string $detail): void
{
    try {
        (new AuditService($pdo))->log(
            $event,
            $detail,
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_name'] ?? null,
            nxCurrentRole(),
            $tenantId
        );
    } catch (\Throwable $e) {
        error_log('[consent_form_save] audit: ' . $e->getMessage());
    }
}
