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

// Retrieve tenant configured storage provider
$usageInfo = $service->getStorageUsage();
$tenantProvider = $usageInfo['storage_provider'] ?? 'server';

// Enforce tenant assigned drive provider unless explicitly submitted
$provider = !empty($_POST['storage_provider']) ? trim($_POST['storage_provider']) : $tenantProvider;

$patientId = trim($_POST['patient_id'] ?? '') ?: null;
$category = trim($_POST['category'] ?? 'General');
$uploadedBy = $_SESSION['user_name'] ?? 'System User';
$redirectTo = trim($_POST['redirect_to'] ?? '../uploads.php');

try {
    if ($provider === 'googledrive') {
        $originalName = trim($_POST['gdrive_file_name'] ?? $_POST['file_name'] ?? '');
        $externalUrl = trim($_POST['gdrive_file_url'] ?? $_POST['file_url'] ?? '');
        $externalId = trim($_POST['gdrive_file_id'] ?? $_POST['file_id'] ?? '') ?: null;
        $accessToken = trim($_POST['gdrive_access_token'] ?? '') ?: null;

        if (!empty($originalName) && !empty($externalUrl)) {
            $file = $service->linkGoogleDriveFile($originalName, $externalUrl, $externalId, $patientId, $category, 0, $uploadedBy);
            setToast('Google Drive File Linked', "Google Drive document '{$file['original_name']}' linked under tenant folder successfully.", 'success');
        } elseif (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $file = $service->uploadToGoogleDrive($_FILES['file'], $patientId, $category, $uploadedBy, $accessToken);
            setToast('File Uploaded to Google Drive', "Document '{$file['original_name']}' uploaded under tenant Google Drive folder allocation.", 'success');
        } else {
            setToast('Google Drive Error', 'Please select a file or enter Google Drive link details.', 'error');
        }
    } elseif ($provider === 'onedrive') {
        $originalName = trim($_POST['onedrive_file_name'] ?? $_POST['file_name'] ?? '');
        $externalUrl = trim($_POST['onedrive_file_url'] ?? $_POST['file_url'] ?? '');
        $externalId = trim($_POST['onedrive_file_id'] ?? $_POST['file_id'] ?? '') ?: null;

        if (!empty($originalName) && !empty($externalUrl)) {
            $file = $service->linkOneDriveFile($originalName, $externalUrl, $externalId, $patientId, $category, 0, $uploadedBy);
            setToast('OneDrive File Linked', "OneDrive document '{$file['original_name']}' linked successfully.", 'success');
        } elseif (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $file = $service->uploadToOneDrive($_FILES['file'], $patientId, $category, $uploadedBy);
            setToast('File Uploaded to OneDrive', "Document '{$file['original_name']}' uploaded under tenant OneDrive allocation.", 'success');
        } else {
            setToast('OneDrive Error', 'Please select a file or enter OneDrive sharing URL details.', 'error');
        }
    } else {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            setToast('Upload Error', 'Please select a file to upload.', 'error');
            header("Location: $redirectTo");
            exit;
        }
        $file = $service->uploadToServer($_FILES['file'], $patientId, $category, $uploadedBy);
        setToast('File Uploaded', "File '{$file['original_name']}' successfully uploaded to server.", 'success');
    }
} catch (Exception $e) {
    setToast('Upload Failed', $e->getMessage(), 'error');
}

header("Location: $redirectTo");
exit;
