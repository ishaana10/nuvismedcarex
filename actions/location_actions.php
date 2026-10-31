<?php
/**
 * Clinic multi-location actions: create/update/deactivate a location and switch
 * the active location for the current session.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Shared\TenantContext;
use ClinicFlow\Services\LocationService;
use ClinicFlow\Shared\Logger;

requireAuth();
validateCsrfRequest();

$action = $_POST['action'] ?? '';
$tenantId = TenantContext::getTenantId();
$locationService = Container::getInstance()->get(LocationService::class);
$logger = Container::getInstance()->get(Logger::class);

$back = $_SERVER['HTTP_REFERER'] ?? '../admin.php?tab=locations';

if ($action === 'set_current_location') {
    $locationId = trim($_POST['location_id'] ?? '');
    if ($locationService->setCurrentLocationId($tenantId, $locationId)) {
        $loc = $locationId !== '' ? $locationService->getLocation($locationId) : null;
        setToast('Location switched', $loc ? "Now working in '{$loc['name']}'." : 'Location cleared.');
    } else {
        setToast('Switch failed', 'That location is not available for this clinic.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

// Managing locations is an administrative action.
requireRole(['Administrator', 'Developer']);

if ($action === 'save_location') {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    if ($name === '' || $code === '') {
        setToast('Validation error', 'Location name and code are required.', 'error');
        header('Location: ' . $back);
        exit;
    }
    try {
        $locationService->saveLocation($tenantId, [
            'id'         => $_POST['location_id'] ?? '',
            'name'       => $name,
            'code'       => $code,
            'address'    => $_POST['address'] ?? '',
            'phone'      => $_POST['phone'] ?? '',
            'email'      => $_POST['email'] ?? '',
            'timezone'   => $_POST['timezone'] ?? 'Pacific/Fiji',
            'is_primary' => isset($_POST['is_primary']),
            'is_active'  => isset($_POST['is_active']) ? 1 : (empty($_POST['location_id']) ? 1 : 0),
        ]);
        setToast('Location saved', "Clinic location '{$name}' saved.");
    } catch (\Throwable $e) {
        $logger->error('Failed to save location: ' . $e->getMessage());
        setToast('Error', 'Could not save the location. The code may already be in use.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'deactivate_location') {
    $id = trim($_POST['location_id'] ?? '');
    if ($id !== '') {
        try {
            $locationService->deactivateLocation($tenantId, $id);
            setToast('Location deactivated', 'The location was deactivated. Existing records are preserved.');
        } catch (\Throwable $e) {
            setToast('Error', 'Could not deactivate the location.', 'error');
        }
    }
    header('Location: ' . $back);
    exit;
}

header('Location: ' . $back);
exit;
