<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../uploads.php");
    exit;
}

verifyCsrfToken($_POST['csrf_token'] ?? '');

$container = \ClinicFlow\Shared\Container::getInstance();
$service = $container->get(\ClinicFlow\Services\FileUploadService::class);

$settings = [
    'cloud_onedrive_client_id' => trim($_POST['cloud_onedrive_client_id'] ?? $_POST['onedrive_client_id'] ?? ''),
    'cloud_onedrive_client_secret' => trim($_POST['cloud_onedrive_client_secret'] ?? $_POST['onedrive_client_secret'] ?? ''),
    'cloud_onedrive_tenant_id' => trim($_POST['cloud_onedrive_tenant_id'] ?? $_POST['onedrive_tenant_id'] ?? ''),
    'cloud_googledrive_client_id' => trim($_POST['cloud_googledrive_client_id'] ?? $_POST['googledrive_client_id'] ?? ''),
    'cloud_googledrive_api_key' => trim($_POST['cloud_googledrive_api_key'] ?? $_POST['googledrive_api_key'] ?? ''),
    'cloud_googledrive_folder_id' => trim($_POST['cloud_googledrive_folder_id'] ?? $_POST['googledrive_folder_id'] ?? ''),
];

$service->saveCloudSettings($settings);

setToast('Settings Saved', 'Cloud storage API configuration for OneDrive and Google Drive updated successfully.', 'success');

$redirectTo = trim($_POST['redirect_to'] ?? '../uploads.php');
header("Location: $redirectTo");
exit;
