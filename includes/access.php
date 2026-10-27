<?php
/**
 * Access control helpers + request guard.
 *
 *   nxCan('billing')              → may the signed-in user view billing?
 *   nxCan('billing', 'edit')      → may they create/change billing records?
 *   nxCan('inventory', 'full')    → may they delete inventory?
 *   requireAccess('users', 'full') → stop the request with a friendly message if not
 *
 * nxGuardRequest() runs on every request (from core/SessionBootstrap.php) and
 * checks action endpoints and print pages against NX_SCRIPT_ACCESS below, so
 * permissions hold even when someone posts to an endpoint directly.
 * Pages rendered through includes/header.php are checked by page id.
 */

use ClinicFlow\Services\AccessControl;

if (!function_exists('nxAccess')) {

    /** Page id ($activePage) → [module, level] */
    define('NX_PAGE_ACCESS', [
        'patients'         => ['patients', 'view'],
        'register-patient' => ['patients', 'edit'],
        'calendar'         => ['appointments', 'view'],
        'clinical-visit'   => ['encounters', 'view'],
        'billing'          => ['billing', 'view'],
        'inventory'        => ['inventory', 'view'],
        'uploads'          => ['documents', 'view'],
        'settings'         => null,  // everyone may open their own settings
        'admin'            => ['users', 'view'],
        'access'           => ['users', 'view'],
        'receipts'         => ['settings', 'view'],
        'developer'        => ['developer', 'view'],
    ]);

    /**
     * Script (relative to the app root) → [module, level], or
     * [module, level, [sub-action => level, ...]] where the sub-action is read
     * from the 'action' request parameter.
     */
    define('NX_SCRIPT_ACCESS', [
        // Patients
        'actions/patient_add.php'         => ['patients', 'edit'],
        'actions/patient_update.php'      => ['patients', 'edit'],
        // Appointments & queue
        'actions/appointment_add.php'     => ['appointments', 'edit'],
        'actions/appointment_edit.php'    => ['appointments', 'edit'],
        'actions/appointment_delete.php'  => ['appointments', 'full'],
        'actions/queue_update.php'        => ['appointments', 'edit'],
        // Clinical
        'actions/encounter_save.php'      => ['encounters', 'edit', ['delete_rx' => 'full', 'create_invoice' => 'edit']],
        'actions/medical_certificate_save.php' => ['encounters', 'edit'],
        'actions/lab_order_save.php'      => ['encounters', 'edit'],
        'print_prescription.php'          => ['encounters', 'view'],
        'print_medical_certificate.php'   => ['encounters', 'view'],
        'print_lab_order.php'             => ['encounters', 'view'],
        'actions/send_email.php'          => ['encounters', 'view'],
        // Billing
        'actions/billing_pay.php'         => ['billing', 'edit'],
        'actions/vms_create_invoice.php'  => ['billing', 'edit'],
        'actions/vms_cancel_invoice.php'  => ['billing', 'full'],
        'actions/insurance_claim_save.php' => ['billing', 'edit', ['delete_claim' => 'full']],
        'actions/insurance_report_save.php'   => ['billing', 'edit'],
        'actions/insurance_report_export.php' => ['billing', 'view'],
        'actions/zreport_export.php'      => ['billing', 'view'],
        'print_insurance_report.php'      => ['billing', 'view'],
        'actions/send_receipt_email.php'  => ['billing', 'view'],
        'print_invoice.php'               => ['billing', 'view'],
        'print_receipt.php'               => ['billing', 'view'],
        'print_zreport.php'               => ['billing', 'view'],
        // Inventory
        'actions/inventory_add.php'       => ['inventory', 'edit'],
        'actions/inventory_edit.php'      => ['inventory', 'edit'],
        'actions/inventory_restock.php'   => ['inventory', 'edit'],
        'actions/inventory_delete.php'    => ['inventory', 'full'],
        'actions/inventory_report_export.php' => ['inventory', 'view'],
        // Documents
        'actions/upload_file.php'         => ['documents', 'edit'],
        'actions/view_file.php'           => ['documents', 'view'],
        'actions/delete_uploaded_file.php' => ['documents', 'full'],
        // Clinic settings
        'actions/admin_save_settings.php' => ['settings', 'edit'],
        'actions/receipt_settings_save.php' => ['settings', 'edit'],
        'actions/vms_save_settings.php'   => ['settings', 'edit'],
        'actions/vms_actions.php'         => ['settings', 'edit'],
        'actions/cloud_storage_settings.php' => ['settings', 'edit'],
        'actions/email_actions.php'       => ['settings', 'view', ['resend_email' => 'edit']],
        'actions/test_email.php'          => ['settings', 'edit'],
        // Staff & access
        'actions/doctor_actions.php'      => ['users', 'edit', ['delete' => 'full']],
        // Your own profile and preferences need no special access
        'actions/admin_save_doctor.php'   => ['users', 'edit'],
        'actions/access_save.php'         => ['users', 'full'],
        // Developer
        'actions/git_actions.php'         => ['developer', 'full'],
        'actions/redis_actions.php'       => ['developer', 'full'],
        'actions/integration_actions.php' => ['developer', 'full'],
    ]);

    define('NX_LEVELS', ['view' => AccessControl::VIEW, 'edit' => AccessControl::EDIT, 'full' => AccessControl::FULL]);

    function nxAccess(): AccessControl
    {
        static $ac = null, $forTenant = null;
        $tenant = \ClinicFlow\Shared\TenantContext::getTenantId();
        if ($ac === null || $forTenant !== $tenant) {
            $ac = new AccessControl(getDB(), $tenant);
            $forTenant = $tenant;
        }
        return $ac;
    }

    function nxCurrentRole(): string
    {
        return AccessControl::normaliseRole((string) ($_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Staff'));
    }

    function nxCan(string $module, string $level = 'view'): bool
    {
        if (empty($_SESSION['authenticated'])) return false;
        try {
            return nxAccess()->can(nxCurrentRole(), $module, NX_LEVELS[$level] ?? AccessControl::VIEW);
        } catch (\Throwable $e) {
            error_log('[access] ' . $e->getMessage());
            return false;
        }
    }

    /** Stop the request with a clear message when the user lacks access. */
    function requireAccess(string $module, string $level = 'view'): void
    {
        if (nxCan($module, $level)) return;

        $label = AccessControl::MODULES[$module]['label'] ?? $module;
        $verb = ['view' => 'open', 'edit' => 'make changes in', 'full' => 'delete records in'][$level] ?? 'use';
        $role = nxCurrentRole();
        $message = "Your role ({$role}) can't {$verb} {$label}. Ask a clinic administrator if you need this.";

        $wantsJson = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if ($wantsJson) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $message, 'message' => $message]);
            exit;
        }

        setToast('No access', $message, 'error');
        $root = nxAppBasePath();
        $back = $root . 'index.php';
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $self = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)
            && parse_url($ref, PHP_URL_PATH) !== parse_url($self, PHP_URL_PATH)) {
            $back = $ref;
        }
        header('Location: ' . $back);
        exit;
    }

    /** URL path of the app root (works when installed in a sub-folder). */
    function nxAppBasePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
        $rel = nxScriptRelativePath();
        if ($rel !== null && str_ends_with($script, $rel)) {
            return substr($script, 0, -strlen($rel));
        }
        return rtrim(dirname($script), '/') . '/';
    }

    function nxScriptRelativePath(): ?string
    {
        $file = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $root = realpath(dirname(__DIR__));
        if (!$file || !$root || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) return null;
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
    }

    /**
     * Re-read the signed-in user's role and status once per request, so role
     * changes apply on the next page load and deactivated accounts are signed
     * out straight away instead of keeping access until they log out.
     */
    function nxRefreshSessionUser(): void
    {
        if (PHP_SAPI === 'cli' || empty($_SESSION['authenticated']) || empty($_SESSION['user_id'])) return;
        try {
            $st = getDB()->prepare("SELECT role, is_active FROM doctors WHERE id = ? LIMIT 1");
            $st->execute([$_SESSION['user_id']]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[access] refresh user: ' . $e->getMessage());
            return; // never lock everyone out because of a transient DB error
        }
        if (!$row || (int) ($row['is_active'] ?? 1) !== 1) {
            $_SESSION = [];
            session_regenerate_id(true);
            setToast('Signed out', 'Your account has been deactivated. Contact a clinic administrator.', 'error');
            $wantsJson = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
            if ($wantsJson) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Account deactivated']);
                exit;
            }
            if (!str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/event-stream')) {
                header('Location: ' . nxAppBasePath() . 'login.php');
            }
            exit;
        }
        $_SESSION['user_role'] = (string) $row['role'];
    }

    /** Check the current script against NX_SCRIPT_ACCESS (signed-in users only). */
    function nxGuardRequest(): void
    {
        if (PHP_SAPI === 'cli' || empty($_SESSION['authenticated'])) return;
        $rel = nxScriptRelativePath();
        if ($rel === null || !isset(NX_SCRIPT_ACCESS[$rel])) return;

        $rule = NX_SCRIPT_ACCESS[$rel];
        [$module, $level] = $rule;
        $sub = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
        if ($sub !== '' && isset($rule[2][$sub])) {
            $level = $rule[2][$sub];
        }
        requireAccess($module, $level);
    }
}
