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

$provider = trim($_POST['storage_provider'] ?? 'server');
$patientId = trim($_POST['patient_id'] ?? '') ?: null;
$category = trim($_POST['category'] ?? 'General');
$uploadedBy = $_SESSION['user_name'] ?? 'System User';
$redirectTo = trim($_POST['redirect_to'] ?? '../uploads.php');

try {
    if ($provider === 'server') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            setToast('Upload Error', 'Please select a file to upload.', 'error');
            header("Location: $redirectTo");
            exit;
        }
        $file = $service->uploadToServer($_FILES['file'], $patientId, $category, $uploadedBy);
        setToast('File Uploaded', "File '{$file['original_name']}' successfully uploaded to server.", 'success');
    } elseif ($provider === 'onedrive') {
        $originalName = trim($_POST['onedrive_file_name'] ?? '');
        $externalUrl = trim($_POST['onedrive_file_url'] ?? '');
        $externalId = trim($_POST['onedrive_file_id'] ?? '') ?: null;

        if (empty($originalName) || empty($externalUrl)) {
            setToast('OneDrive Error', 'File name and sharing URL are required for OneDrive integration.', 'error');
            header("Location: $redirectTo");
            exit;
        }

        $file = $service->linkOneDriveFile($originalName, $externalUrl, $externalId, $patientId, $category, 0, $uploadedBy);
        setToast('OneDrive File Linked', "OneDrive document '{$file['original_name']}' linked successfully.", 'success');
    } elseif ($provider === 'googledrive') {
        $originalName = trim($_POST['gdrive_file_name'] ?? '');
        $externalUrl = trim($_POST['gdrive_file_url'] ?? '');
        $externalId = trim($_POST['gdrive_file_id'] ?? '') ?: null;

        if (empty($originalName) || empty($externalUrl)) {
            setToast('Google Drive Error', 'File name and sharing URL are required for Google Drive integration.', 'error');
            header("Location: $redirectTo");
            exit;
        }

        $file = $service->linkGoogleDriveFile($originalName, $externalUrl, $externalId, $patientId, $category, 0, $uploadedBy);
        setToast('Google Drive File Linked', "Google Drive document '{$file['original_name']}' linked successfully.", 'success');
    } else {
        setToast('Upload Error', 'Invalid storage provider specified.', 'error');
    }
} catch (Exception $e) {
    setToast('Upload Failed', $e->getMessage(), 'error');
}

header("Location: $redirectTo");
exit;
