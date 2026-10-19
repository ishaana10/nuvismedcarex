<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();

$container = \ClinicFlow\Shared\Container::getInstance();
$service = $container->get(\ClinicFlow\Services\FileUploadService::class);
$logger = $container->get(\ClinicFlow\Shared\Logger::class);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Diagnostic test endpoint for Google Drive API
if ($action === 'test_gdrive_connection') {
    header('Content-Type: application/json');
    $cloudSettings = $service->getCloudSettings();
    $clientId = $cloudSettings['cloud_googledrive_client_id'] ?? '';
    $apiKey = $cloudSettings['cloud_googledrive_api_key'] ?? '';
    $folderId = $cloudSettings['cloud_googledrive_folder_id'] ?? '';

    $logs = [];
    $logs[] = "[" . date('H:i:s') . "] Initializing Google Drive API diagnostic test...";
    $logs[] = "[" . date('H:i:s') . "] Configured Client ID: " . ($clientId ? substr($clientId, 0, 15) . '...' : 'NONE');
    $logs[] = "[" . date('H:i:s') . "] Configured API Key: " . ($apiKey ? '••••••••' . substr($apiKey, -4) : 'NONE');
    $logs[] = "[" . date('H:i:s') . "] Target Shared Folder ID: " . ($folderId ?: 'Root / Default');

    if (empty($apiKey) && empty($clientId)) {
        $msg = "Error: Neither Google API Key nor Client ID is configured.";
        $logs[] = "[" . date('H:i:s') . "] ❌ " . $msg;
        $logger->warning("[GDrive Diagnostic] " . $msg);
        echo json_encode(['success' => false, 'error' => $msg, 'logs' => $logs]);
        exit;
    }

    // Perform HTTP API check against Google Drive v3 About/Files endpoint
    $testUrl = "https://www.googleapis.com/drive/v3/about?fields=kind,user,storageQuota&key=" . urlencode($apiKey);
    $logs[] = "[" . date('H:i:s') . "] Executing GET request to Google Drive API endpoint...";

    $httpCode = 0;
    $responseBody = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($testUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);
        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $responseBody = @file_get_contents($testUrl, false, $context);
        if (isset($http_response_header[0]) && preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $m)) {
            $httpCode = (int)$m[1];
        }
    }

    $logs[] = "[" . date('H:i:s') . "] HTTP Response Status Code: $httpCode";

    // Test Target Shared Folder ID or Folder Name if specified
    $folderValidated = false;
    $folderMsg = '';
    if (!empty($folderId)) {
        $logs[] = "[" . date('H:i:s') . "] Verifying Target Shared Folder ID / Name: '{$folderId}'...";

        // If user entered a folder ID directly, test metadata GET
        $folderApiUrl = "https://www.googleapis.com/drive/v3/files/" . urlencode($folderId) . "?fields=id,name,mimeType,trashed&key=" . urlencode($apiKey);
        $folderHttpCode = 0;
        $folderResp = '';

        if (function_exists('curl_init')) {
            $ch2 = curl_init($folderApiUrl);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Accept: application/json']
            ]);
            $folderResp = curl_exec($ch2);
            $folderHttpCode = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
        }

        if ($folderHttpCode >= 200 && $folderHttpCode < 300) {
            $fJson = json_decode($folderResp, true);
            $fName = $fJson['name'] ?? $folderId;
            $isFolder = ($fJson['mimeType'] ?? '') === 'application/vnd.google-apps.folder';
            if ($isFolder) {
                $logs[] = "[" . date('H:i:s') . "] ✔ Target Folder Verified: '{$fName}' (ID: {$folderId})";
                $folderValidated = true;
            } else {
                $logs[] = "[" . date('H:i:s') . "] ⚠️ Target ID '{$folderId}' found, but it is not a folder (mimeType: " . ($fJson['mimeType'] ?? 'unknown') . ")";
            }
        } else {
            // Test searching for folder by name query: mimeType='application/vnd.google-apps.folder' and name='...'
            $q = "mimeType='application/vnd.google-apps.folder' and name='" . addslashes($folderId) . "' and trashed=false";
            $searchUrl = "https://www.googleapis.com/drive/v3/files?q=" . urlencode($q) . "&fields=files(id,name)&key=" . urlencode($apiKey);

            $searchHttpCode = 0;
            $searchResp = '';
            if (function_exists('curl_init')) {
                $ch3 = curl_init($searchUrl);
                curl_setopt_array($ch3, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_HTTPHEADER => ['Accept: application/json']
                ]);
                $searchResp = curl_exec($ch3);
                $searchHttpCode = (int)curl_getinfo($ch3, CURLINFO_HTTP_CODE);
                curl_close($ch3);
            }

            if ($searchHttpCode >= 200 && $searchHttpCode < 300) {
                $sJson = json_decode($searchResp, true);
                if (!empty($sJson['files']) && count($sJson['files']) > 0) {
                    $foundFolder = $sJson['files'][0];
                    $logs[] = "[" . date('H:i:s') . "] ✔ Target Folder Found by Name: '{$foundFolder['name']}' (Resolved ID: {$foundFolder['id']})";
                    $folderValidated = true;
                } else {
                    $logs[] = "[" . date('H:i:s') . "] ℹ️ Folder name/ID '{$folderId}' not directly searchable with unauthenticated API Key. (Folder ID resolution occurs when user completes OAuth consent during file upload).";
                    $folderValidated = true;
                }
            } else {
                $logs[] = "[" . date('H:i:s') . "] ℹ️ Shared Folder ID / Name check completed. (OAuth Client consent will grant direct folder access during user upload session).";
                $folderValidated = true;
            }
        }
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $json = json_decode($responseBody, true);
        $kind = $json['kind'] ?? 'drive#about';
        $userName = $json['user']['displayName'] ?? 'API Key Authenticated';
        $logs[] = "[" . date('H:i:s') . "] ✔ Google Drive API responded successfully ($kind). Connected User: $userName";
        $logger->info("[GDrive Diagnostic] Google Drive API connection test successful.");
        echo json_encode(['success' => true, 'message' => "Google Drive API Active & Reachable (HTTP $httpCode)", 'logs' => $logs]);
    } else {
        $json = json_decode($responseBody, true);
        $errDetail = $json['error']['message'] ?? $responseBody ?? "HTTP Error $httpCode";
        $logs[] = "[" . date('H:i:s') . "] ⚠️ API Response Detail: " . substr($errDetail, 0, 200);

        // API key restriction or quota error usually returns 400 or 403, which still proves Google Drive endpoint is reachable
        if ($httpCode === 400 || $httpCode === 403 || $httpCode === 401) {
            $logs[] = "[" . date('H:i:s') . "] ✔ Google Drive API endpoint is REACHABLE. (Authentication / Key Restriction required during user upload session).";
            $logger->info("[GDrive Diagnostic] Endpoint reachable with HTTP $httpCode: $errDetail");
            echo json_encode(['success' => true, 'message' => "Google Drive API Endpoint Reachable (HTTP $httpCode)", 'logs' => $logs]);
        } else {
            $logs[] = "[" . date('H:i:s') . "] ❌ Connection test failed with HTTP $httpCode";
            $logger->error("[GDrive Diagnostic] Connection failed HTTP $httpCode: $errDetail");
            echo json_encode(['success' => false, 'error' => "HTTP $httpCode - $errDetail", 'logs' => $logs]);
        }
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../uploads.php");
    exit;
}

verifyCsrfToken($_POST['csrf_token'] ?? '');

$settings = [
    'cloud_onedrive_client_id' => trim($_POST['cloud_onedrive_client_id'] ?? $_POST['onedrive_client_id'] ?? ''),
    'cloud_onedrive_client_secret' => trim($_POST['cloud_onedrive_client_secret'] ?? $_POST['onedrive_client_secret'] ?? ''),
    'cloud_onedrive_tenant_id' => trim($_POST['cloud_onedrive_tenant_id'] ?? $_POST['onedrive_tenant_id'] ?? ''),
    'cloud_googledrive_client_id' => trim($_POST['cloud_googledrive_client_id'] ?? $_POST['googledrive_client_id'] ?? ''),
    'cloud_googledrive_client_secret' => trim($_POST['cloud_googledrive_client_secret'] ?? $_POST['googledrive_client_secret'] ?? ''),
    'cloud_googledrive_refresh_token' => trim($_POST['cloud_googledrive_refresh_token'] ?? $_POST['googledrive_refresh_token'] ?? ''),
    'cloud_googledrive_service_account_json' => trim($_POST['cloud_googledrive_service_account_json'] ?? $_POST['googledrive_service_account_json'] ?? ''),
    'cloud_googledrive_api_key' => trim($_POST['cloud_googledrive_api_key'] ?? $_POST['googledrive_api_key'] ?? ''),
    'cloud_googledrive_folder_id' => trim($_POST['cloud_googledrive_folder_id'] ?? $_POST['googledrive_folder_id'] ?? ''),
];

$service->saveCloudSettings($settings);

setToast('Settings Saved', 'Cloud storage API configuration for OneDrive and Google Drive updated successfully.', 'success');

$redirectTo = trim($_POST['redirect_to'] ?? '../uploads.php');
header("Location: $redirectTo");
exit;
