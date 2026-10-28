<?php

/**
 * Attach a document.
 *
 * A file uploaded here is written to this server and then copied to whichever
 * drive the installation is set to use (Developer → Document storage). Picking
 * a file that already lives in someone's own OneDrive or Google Drive is a
 * different thing — that records a link, and is left as it was.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\Cloud\Cloud;
use ClinicFlow\Services\Cloud\CloudConfig;

requireAuth();

// The document centre posts this with XHR and then reloads. Answering a
// redirect there means the XHR itself follows it and consumes the message,
// so the reload shows nothing: answer those in JSON instead.
$wantsJson = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

/** @param string $tone success|error */
function nxUploadDone(bool $wantsJson, string $redirectTo, string $title, string $message, string $tone = 'success'): never
{
    // The message is queued either way: the document centre reloads itself
    // after the upload, and that reload is where it is read.
    setToast($title, $message, $tone);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $tone !== 'error', 'title' => $title, 'message' => $message]);
        exit;
    }
    header("Location: {$redirectTo}");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../uploads.php");
    exit;
}

// The result of this check used to be thrown away, so CSRF was not enforced
if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    nxUploadDone($wantsJson, '../uploads.php', 'Not uploaded', 'That request expired. Reload the page and try again.', 'error');
}
requireAccess('documents', 'edit');

$container = \ClinicFlow\Shared\Container::getInstance();
$service = $container->get(\ClinicFlow\Services\FileUploadService::class);

$provider = trim((string) ($_POST['storage_provider'] ?? 'server'));
$patientId = trim((string) ($_POST['patient_id'] ?? '')) ?: null;
$category = trim((string) ($_POST['category'] ?? 'General'));
$uploadedBy = $_SESSION['user_name'] ?? 'System User';

// Only ever return to a page of this application
$redirectTo = '../uploads.php';
$wanted = trim((string) ($_POST['redirect_to'] ?? ''));
if ($wanted !== '' && preg_match('#^\.\./[A-Za-z0-9_\-/]+\.php(\?[A-Za-z0-9_\-=&%.]*)?$#', $wanted)) {
    $redirectTo = $wanted;
}

$hasUpload = isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

try {
    if (!$hasUpload && $provider === 'googledrive') {
        // A file chosen from the person's own Drive: recorded as a link
        $name = trim((string) ($_POST['gdrive_file_name'] ?? $_POST['file_name'] ?? ''));
        $url = trim((string) ($_POST['gdrive_file_url'] ?? $_POST['file_url'] ?? ''));
        if ($name === '' || $url === '') {
            throw new RuntimeException('Choose a file from Google Drive, or paste its sharing link.');
        }
        $file = $service->linkGoogleDriveFile($name, $url, trim((string) ($_POST['gdrive_file_id'] ?? '')) ?: null, $patientId, $category, 0, $uploadedBy);
        nxUploadDone($wantsJson, $redirectTo, 'Google Drive file linked', "'{$file['original_name']}' is now listed against this patient.");
    } elseif (!$hasUpload && $provider === 'onedrive') {
        $name = trim((string) ($_POST['onedrive_file_name'] ?? $_POST['file_name'] ?? ''));
        $url = trim((string) ($_POST['onedrive_file_url'] ?? $_POST['file_url'] ?? ''));
        if ($name === '' || $url === '') {
            throw new RuntimeException('Choose a file from OneDrive, or paste its sharing link.');
        }
        $file = $service->linkOneDriveFile($name, $url, trim((string) ($_POST['onedrive_file_id'] ?? '')) ?: null, $patientId, $category, 0, $uploadedBy);
        nxUploadDone($wantsJson, $redirectTo, 'OneDrive file linked', "'{$file['original_name']}' is now listed against this patient.");
    } elseif (!$hasUpload) {
        $why = match ((int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE)) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than this server accepts.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
            default => 'Choose a file to upload.',
        };
        throw new RuntimeException($why);
    } else {
        // The real thing: saved here, then copied to the configured drive
        $file = $service->uploadToServer($_FILES['file'], $patientId, $category, $uploadedBy);

        $cfg = CloudConfig::get();
        $where = $file['storage_provider'] ?? 'server';
        $status = $file['remote_status'] ?? 'none';

        if ($status === 'synced') {
            $label = Cloud::labels()[$where] ?? $where;
            nxUploadDone($wantsJson, $redirectTo, 'Document uploaded', "'{$file['original_name']}' was saved and copied to {$label}.");
        } elseif ($status === 'failed') {
            nxUploadDone(
                $wantsJson,
                $redirectTo,
                'Uploaded, but not copied to the drive',
                "'{$file['original_name']}' is safe on this server. " . ($file['remote_error'] ?: 'The drive refused the copy.')
                . ' A developer can retry it from Developer → Document storage.',
                'error'
            );
        } elseif ($cfg['provider'] !== 'local' && !CloudConfig::isConnected($cfg['provider'], $cfg)) {
            nxUploadDone(
                $wantsJson,
                $redirectTo,
                'Document uploaded',
                "'{$file['original_name']}' is on this server. The chosen drive is not connected yet, so nothing was copied to it."
            );
        } else {
            nxUploadDone($wantsJson, $redirectTo, 'Document uploaded', "'{$file['original_name']}' has been saved.");
        }
    }
} catch (Throwable $e) {
    nxUploadDone($wantsJson, $redirectTo, 'Not uploaded', $e->getMessage(), 'error');
}

header("Location: {$redirectTo}");
exit;
