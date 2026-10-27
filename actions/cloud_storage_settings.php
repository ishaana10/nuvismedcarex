<?php
/**
 * Developer → Document storage: save or test one cloud drive's credentials.
 *
 * Each drive is handled on its own, so saving Google Drive never clears the
 * OneDrive settings. Secrets are kept when the field is left blank, which is
 * what the form does rather than printing them back into the page.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\CloudDrives;

requireAuth();
requireAccess('developer', 'edit');

$container = \ClinicFlow\Shared\Container::getInstance();
$service = $container->get(\ClinicFlow\Services\FileUploadService::class);
$logger = $container->get(\ClinicFlow\Shared\Logger::class);

$back = '../developer.php?dev_tab=cloud';
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$drive = (string) ($_GET['drive'] ?? $_POST['drive'] ?? '');
if (!isset(CloudDrives::DRIVES[$drive])) {
    $drive = '';
}

/* ------------------------------------------------------------ connection test */
if ($action === 'test_connection' || $action === 'test_gdrive_connection') {
    header('Content-Type: application/json');
    if ($drive === '') {
        $drive = 'googledrive'; // the old endpoint only ever tested Google Drive
    }
    $settings = $service->getCloudSettings();
    $result = $drive === 'onedrive'
        ? testOneDrive($settings)
        : testGoogleDrive($settings);
    $logger->info('[Cloud test] ' . $drive . ': ' . ($result['success'] ? 'ok' : 'failed'));
    echo json_encode($result);
    exit;
}

/* --------------------------------------------------------------------- saving */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: {$back}");
    exit;
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not saved', 'That request expired. Reload the page and try again.', 'error');
    header("Location: {$back}");
    exit;
}

// Without a drive, fall back to every key so an older form still works
$keys = $drive !== '' ? CloudDrives::keys($drive) : CloudDrives::allKeys();
$label = $drive !== '' ? CloudDrives::DRIVES[$drive]['label'] : 'Cloud storage';
$current = $service->getCloudSettings();

if (($_POST['action'] ?? '') === 'clear') {
    $service->saveCloudSettings(array_fill_keys($keys, ''));
    $logger->warning('[Cloud] ' . $label . ' credentials cleared by ' . ($_SESSION['user_name'] ?? 'unknown'));
    setToast('Credentials cleared', $label . ' is no longer configured. Documents already stored there are untouched.');
    header("Location: {$back}");
    exit;
}

$settings = [];
$kept = 0;
foreach ($keys as $key) {
    $posted = trim((string) ($_POST[$key] ?? ''));
    if ($posted === '' && CloudDrives::isSecret($key) && trim((string) ($current[$key] ?? '')) !== '') {
        // Blank secret means "leave it as it is", not "delete it"
        $kept++;
        continue;
    }
    $settings[$key] = $posted;
}

try {
    $service->saveCloudSettings($settings);
} catch (\Throwable $e) {
    $logger->error('Could not save cloud settings: ' . $e->getMessage());
    setToast('Not saved', 'The credentials could not be saved. Try again, or check the error log.', 'error');
    header("Location: {$back}");
    exit;
}

$status = $drive !== '' ? CloudDrives::status($drive, $service->getCloudSettings()) : ['configured' => true, 'missing' => []];
if (!$status['configured']) {
    setToast(
        $label . ' saved, not yet complete',
        'Still needed: ' . implode(', ', $status['missing']) . '.',
        'info'
    );
} else {
    setToast($label . ' saved', $kept > 0
        ? 'Settings updated; the stored secrets you left blank were kept. Use Test to check the connection.'
        : 'Settings updated. Use Test to check the connection.');
}

header("Location: {$back}");
exit;

/* ------------------------------------------------------------------- the tests */

/** Google Drive: ask the Drive API about the configured key and folder. */
function testGoogleDrive(array $s): array
{
    $logs = [];
    $stamp = static fn(string $m): string => '[' . date('H:i:s') . '] ' . $m;
    $clientId = (string) ($s['cloud_googledrive_client_id'] ?? '');
    $apiKey = (string) ($s['cloud_googledrive_api_key'] ?? '');
    $folderId = (string) ($s['cloud_googledrive_folder_id'] ?? '');

    $logs[] = $stamp('Checking Google Drive.');
    $logs[] = $stamp('OAuth client ID: ' . ($clientId !== '' ? substr($clientId, 0, 14) . '…' : 'not set'));
    $logs[] = $stamp('API key: ' . ($apiKey !== '' ? \ClinicFlow\Services\CloudDrives::mask($apiKey) : 'not set'));
    $logs[] = $stamp('Target folder: ' . ($folderId !== '' ? $folderId : 'drive root'));

    if ($apiKey === '' && $clientId === '') {
        $logs[] = $stamp('Nothing to test — no client ID and no API key.');
        return ['success' => false, 'error' => 'Google Drive has no credentials saved yet.', 'logs' => $logs];
    }
    if ($apiKey === '') {
        $logs[] = $stamp('No API key, so the endpoint cannot be reached from the server.');
        return ['success' => false, 'error' => 'Add the API key to test the connection. The picker may still work for staff.', 'logs' => $logs];
    }

    $logs[] = $stamp('GET https://www.googleapis.com/drive/v3/about');
    [$code, $body] = httpGet('https://www.googleapis.com/drive/v3/about?fields=kind,user,storageQuota&key=' . urlencode($apiKey));
    $logs[] = $stamp('HTTP ' . $code);

    if ($code >= 200 && $code < 300) {
        $json = json_decode((string) $body, true);
        $user = $json['user']['displayName'] ?? 'the API key';
        $logs[] = $stamp('Google Drive answered. Authenticated as ' . $user . '.');
        if ($folderId !== '') {
            [$fCode, $fBody] = httpGet('https://www.googleapis.com/drive/v3/files/' . urlencode($folderId) . '?fields=id,name,mimeType,trashed&key=' . urlencode($apiKey));
            $fJson = json_decode((string) $fBody, true);
            if ($fCode >= 200 && $fCode < 300 && ($fJson['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
                $logs[] = $stamp('Target folder found: ' . ($fJson['name'] ?? $folderId) . '.');
            } elseif ($fCode >= 200 && $fCode < 300) {
                $logs[] = $stamp('That ID exists but is not a folder.');
            } else {
                $logs[] = $stamp('The folder could not be read with the API key alone (HTTP ' . $fCode . '). It may still be reachable once a user signs in.');
            }
        }
        return ['success' => true, 'message' => 'Google Drive is reachable.', 'logs' => $logs];
    }

    $json = json_decode((string) $body, true);
    $detail = $json['error']['message'] ?? ('HTTP ' . $code);
    $logs[] = $stamp('Response: ' . substr((string) $detail, 0, 200));

    if (in_array($code, [400, 401, 403], true)) {
        // The endpoint answered, which is what this test can prove without a user signed in
        $logs[] = $stamp('Google Drive is reachable; this call needs a signed-in user or a less restricted key.');
        return ['success' => true, 'message' => 'Google Drive is reachable (HTTP ' . $code . ').', 'logs' => $logs];
    }
    if ($code === 0) {
        return ['success' => false, 'error' => 'The server could not reach googleapis.com. Check outbound internet access or the proxy.', 'logs' => $logs];
    }
    return ['success' => false, 'error' => 'HTTP ' . $code . ' — ' . $detail, 'logs' => $logs];
}

/** OneDrive: ask Entra ID for a token with the saved app credentials. */
function testOneDrive(array $s): array
{
    $logs = [];
    $stamp = static fn(string $m): string => '[' . date('H:i:s') . '] ' . $m;
    $clientId = (string) ($s['cloud_onedrive_client_id'] ?? '');
    $secret = (string) ($s['cloud_onedrive_client_secret'] ?? '');
    $tenant = (string) ($s['cloud_onedrive_tenant_id'] ?? '') ?: 'common';

    $logs[] = $stamp('Checking Microsoft OneDrive.');
    $logs[] = $stamp('Client ID: ' . ($clientId !== '' ? substr($clientId, 0, 14) . '…' : 'not set'));
    $logs[] = $stamp('Client secret: ' . ($secret !== '' ? \ClinicFlow\Services\CloudDrives::mask($secret) : 'not set'));
    $logs[] = $stamp('Tenant: ' . $tenant);

    if ($clientId === '' || $secret === '') {
        $logs[] = $stamp('Nothing to test — the client ID and secret are both needed.');
        return ['success' => false, 'error' => 'OneDrive needs a client ID and client secret.', 'logs' => $logs];
    }

    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
    $logs[] = $stamp('POST ' . $url);
    [$code, $body] = httpPost($url, [
        'client_id'     => $clientId,
        'client_secret' => $secret,
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials',
    ]);
    $logs[] = $stamp('HTTP ' . $code);
    $json = json_decode((string) $body, true);

    if ($code >= 200 && $code < 300 && !empty($json['access_token'])) {
        $logs[] = $stamp('Entra ID issued a token, valid for ' . (int) ($json['expires_in'] ?? 0) . ' seconds.');
        return ['success' => true, 'message' => 'OneDrive credentials are accepted.', 'logs' => $logs];
    }
    if ($code === 0) {
        return ['success' => false, 'error' => 'The server could not reach login.microsoftonline.com. Check outbound internet access or the proxy.', 'logs' => $logs];
    }

    $detail = $json['error_description'] ?? $json['error'] ?? ('HTTP ' . $code);
    // Microsoft puts the useful part on the first line
    $firstLine = trim(strtok((string) $detail, "\r\n") ?: (string) $detail);
    $logs[] = $stamp($firstLine);
    $friendly = match (true) {
        str_contains((string) $detail, 'AADSTS7000215') => 'The client secret is wrong or has expired.',
        str_contains((string) $detail, 'AADSTS700016') => 'No application with that client ID exists in this tenant.',
        str_contains((string) $detail, 'AADSTS90002')  => 'That tenant ID does not exist.',
        default => $firstLine,
    };
    return ['success' => false, 'error' => $friendly, 'logs' => $logs];
}

/** @return array{0:int, 1:string} HTTP status and body; status 0 means unreachable. */
function httpGet(string $url): array
{
    return httpRequest($url, null);
}

/** @return array{0:int, 1:string} */
function httpPost(string $url, array $form): array
{
    return httpRequest($url, http_build_query($form));
}

function httpRequest(string $url, ?string $body): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if ($body !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, (string) $response];
    }

    $context = stream_context_create(['http' => [
        'method' => $body === null ? 'GET' : 'POST',
        'header' => "Accept: application/json\r\n" . ($body === null ? '' : "Content-Type: application/x-www-form-urlencoded\r\n"),
        'content' => $body ?? '',
        'timeout' => 12,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/HTTP\/\d(?:\.\d)?\s+(\d+)/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    return [$code, (string) $response];
}
