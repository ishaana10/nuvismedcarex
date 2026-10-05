<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../uploads.php");
    exit;
}

verifyCsrfToken($_POST['csrf_token'] ?? '');

$fileId = trim($_POST['file_id'] ?? '');
$redirectTo = trim($_POST['redirect_to'] ?? '../uploads.php');

if (empty($fileId)) {
    setToast('Error', 'Missing file ID.', 'error');
    header("Location: $redirectTo");
    exit;
}

$container = \ClinicFlow\Shared\Container::getInstance();
$service = $container->get(\ClinicFlow\Services\FileUploadService::class);

$file = $service->getFileById($fileId);
if (!$file) {
    setToast('Error', 'File not found or permission denied.', 'error');
    header("Location: $redirectTo");
    exit;
}

$deleted = $service->deleteFile($fileId);
if ($deleted) {
    setToast('File Deleted', "Document '{$file['original_name']}' has been deleted.", 'success');
} else {
    setToast('Error', 'Failed to delete file.', 'error');
}

header("Location: $redirectTo");
exit;
