<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../uploads.php");
    exit;
}

// The result of this check was being thrown away, so CSRF was not enforced
if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not deleted', 'That request expired. Reload the page and try again.', 'error');
    header('Location: ../uploads.php');
    exit;
}
requireAccess('documents', 'full');

$fileId = trim($_POST['file_id'] ?? '');

// Only ever return to a page of this application: the posted address used to
// be followed as given, which would send someone anywhere.
$redirectTo = '../uploads.php';
$wanted = trim((string) ($_POST['redirect_to'] ?? ''));
if ($wanted !== '' && preg_match('#^\.\./[A-Za-z0-9_\-/]+\.php(\?[A-Za-z0-9_\-=&%.]*)?$#', $wanted)) {
    $redirectTo = $wanted;
}

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

$warning = '';
$deleted = $service->deleteFile($fileId, $warning);
if ($deleted && $warning !== '') {
    setToast('Deleted here, not on the drive', "'{$file['original_name']}' was removed from Nuvis Medcare X, but the copy on the drive could not be deleted: {$warning}", 'error');
} elseif ($deleted) {
    setToast('File deleted', "'{$file['original_name']}' has been deleted.");
} else {
    setToast('Not deleted', 'The file could not be deleted.', 'error');
}

header("Location: $redirectTo");
exit;
