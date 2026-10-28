<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();

$container = \ClinicFlow\Shared\Container::getInstance();
$service = $container->get(\ClinicFlow\Services\FileUploadService::class);

$fileId = trim($_GET['id'] ?? '');

if (empty($fileId)) {
    http_response_code(400);
    echo "Document ID is required.";
    exit;
}

$file = $service->getFileById($fileId);

if (!$file) {
    http_response_code(404);
    echo "Document not found or access denied.";
    exit;
}

$embed = !empty($_GET['embed']);

if ($file['storage_provider'] === 'googledrive') {
    $externalUrl = $file['external_url'] ?? '';
    $externalId = $file['external_id'] ?? '';

    // Extract file ID if external_id is not set or is fallback prefix
    if ((empty($externalId) || str_starts_with($externalId, 'gdrive_')) && preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $externalUrl, $matches)) {
        $externalId = $matches[1];
    }

    if (!empty($externalId) && str_starts_with($externalId, 'gdrive_') === false) {
        $previewUrl = "https://drive.google.com/file/d/" . $externalId . "/preview";
    } elseif (!empty($externalUrl) && str_starts_with($externalUrl, 'http')) {
        $previewUrl = $externalUrl;
    } else {
        $previewUrl = null;
    }

    if ($previewUrl) {
        if ($embed) {
            header("Location: " . $previewUrl);
            exit;
        } else {
            header("Content-Type: text/html; charset=UTF-8");
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title><?= htmlspecialchars($file['original_name']) ?> - Document Viewer</title>
                <style>
                    body, html { margin: 0; padding: 0; height: 100%; overflow: hidden; background-color: #0f172a; font-family: sans-serif; }
                    iframe { width: 100%; height: 100%; border: none; }
                </style>
            </head>
            <body>
                <iframe src="<?= htmlspecialchars($previewUrl) ?>" allow="autoplay"></iframe>
            </body>
            </html>
            <?php
            exit;
        }
    }
}

if ($file['storage_provider'] === 'onedrive') {
    $externalUrl = $file['external_url'] ?? '';
    if (!empty($externalUrl) && str_starts_with($externalUrl, 'http')) {
        header("Location: " . $externalUrl);
        exit;
    }
}

// Anything stored by this installation's own file store: served from disk
// when the copy is still here, and streamed from the drive when it is not.
\ClinicFlow\Services\Cloud\FileStore::stream(
    $file,
    dirname(__DIR__),
    !empty($_GET['download'])
);
exit;
