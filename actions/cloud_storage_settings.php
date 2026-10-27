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

/**
 * Google Drive. The Drive API rejects a bare API key outright — "API keys are
 * not supported by this API" — so a key alone proves nothing and must not be
 * reported as a working connection. The test signs in the way the server would
 * for an unattended upload (refresh token, else service account) and only then
 * asks Drive about the account and the target folder.
 */
function testGoogleDrive(array $s): array
{
    $logs = [];
    $stamp = static fn(string $m): string => '[' . date('H:i:s') . '] ' . $m;
    $clientId = (string) ($s['cloud_googledrive_client_id'] ?? '');
    $apiKey = (string) ($s['cloud_googledrive_api_key'] ?? '');
    $folder = (string) ($s['cloud_googledrive_folder_id'] ?? '');
    $refresh = (string) ($s['cloud_googledrive_refresh_token'] ?? '');
    $secret = (string) ($s['cloud_googledrive_client_secret'] ?? '');
    $saJson = (string) ($s['cloud_googledrive_service_account_json'] ?? '');

    $logs[] = $stamp('Checking Google Drive.');
    $logs[] = $stamp('OAuth client ID: ' . ($clientId !== '' ? substr($clientId, 0, 14) . '…' : 'not set'));
    $logs[] = $stamp('API key: ' . ($apiKey !== '' ? \ClinicFlow\Services\CloudDrives::mask($apiKey) : 'not set'));
    $logs[] = $stamp('Refresh token: ' . ($refresh !== '' ? 'set' : 'not set')
        . ' · Service account: ' . ($saJson !== '' ? 'set' : 'not set'));
    $logs[] = $stamp('Target folder: ' . ($folder !== '' ? $folder : 'drive root'));

    if ($clientId === '' && $apiKey === '' && $refresh === '' && $saJson === '') {
        $logs[] = $stamp('Nothing to test — no credentials saved yet.');
        return ['success' => false, 'status' => 'fail', 'error' => 'Google Drive has no credentials saved yet.', 'logs' => $logs];
    }

    // Sign in the way an unattended upload would
    [$token, $how, $tokenLogs] = googleAccessToken($refresh, $clientId, $secret, $saJson);
    foreach ($tokenLogs as $line) {
        $logs[] = $stamp($line);
    }

    if ($token === null) {
        // Only the browser-side Picker is configured. That may be all this
        // clinic wants, but nothing about it can be proved from the server.
        $logs[] = $stamp('No server credentials, so the connection cannot be verified here.');
        if ($clientId !== '') {
            $logs[] = $stamp('Staff may still be able to pick files: the Picker signs in from the browser.');
        }
        if ($apiKey !== '') {
            $logs[] = $stamp('Note: the Drive API refuses API keys on its own — a key is only used by the Picker.');
        }
        return [
            'success' => false,
            'status'  => 'warn',
            'error'   => 'Only the browser file picker is set up. Add a refresh token or a service account to let the server upload on its own, and to test it from here.',
            'logs'    => $logs,
        ];
    }

    $logs[] = $stamp('Signed in with the ' . $how . '.');
    [$code, $body] = httpGet('https://www.googleapis.com/drive/v3/about?fields=user,storageQuota', ['Authorization: Bearer ' . $token]);
    $logs[] = $stamp('GET /drive/v3/about → HTTP ' . $code);

    if ($code < 200 || $code >= 300) {
        $json = json_decode((string) $body, true);
        $detail = $json['error']['message'] ?? ('HTTP ' . $code);
        $logs[] = $stamp('Response: ' . substr((string) $detail, 0, 200));
        if ($code === 403 && str_contains((string) $detail, 'has not been used')) {
            return ['success' => false, 'status' => 'fail', 'error' => 'The Drive API is not enabled on that Google Cloud project.', 'logs' => $logs];
        }
        return ['success' => false, 'status' => 'fail', 'error' => (string) $detail, 'logs' => $logs];
    }

    $json = json_decode((string) $body, true);
    $who = $json['user']['emailAddress'] ?? ($json['user']['displayName'] ?? 'the connected account');
    $logs[] = $stamp('Connected as ' . $who . '.');
    if (isset($json['storageQuota']['limit'], $json['storageQuota']['usage'])) {
        $used = (float) $json['storageQuota']['usage'];
        $limit = (float) $json['storageQuota']['limit'];
        $logs[] = $stamp(sprintf('Drive storage: %s of %s used.', humanBytes($used), $limit > 0 ? humanBytes($limit) : 'unlimited'));
    }

    $message = 'Google Drive is connected as ' . $who . '.';
    if ($folder !== '') {
        [$found, $folderLogs] = googleFindFolder($token, $folder);
        foreach ($folderLogs as $line) {
            $logs[] = $stamp($line);
        }
        if ($found === null) {
            return [
                'success' => false,
                'status'  => 'warn',
                'error'   => 'Signed in fine, but the target folder "' . $folder . '" was not found or is not shared with this account.',
                'logs'    => $logs,
            ];
        }
        $message .= ' Uploads will go to "' . $found . '".';
    }

    return ['success' => true, 'status' => 'ok', 'message' => $message, 'logs' => $logs];
}

/**
 * An access token for Drive, the way the server would get one.
 *
 * @return array{0: ?string, 1: string, 2: string[]} token, how it was obtained, log lines
 */
function googleAccessToken(string $refresh, string $clientId, string $secret, string $saJson): array
{
    $logs = [];

    if ($refresh !== '' && $clientId !== '' && $secret !== '') {
        $logs[] = 'Exchanging the refresh token for an access token…';
        [$code, $body] = httpPost('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $secret,
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);
        $json = json_decode((string) $body, true);
        if ($code >= 200 && $code < 300 && !empty($json['access_token'])) {
            return [(string) $json['access_token'], 'refresh token', $logs];
        }
        $detail = $json['error_description'] ?? $json['error'] ?? ('HTTP ' . $code);
        $logs[] = 'The refresh token was refused: ' . $detail;
    } elseif ($refresh !== '') {
        $logs[] = 'A refresh token is saved but the OAuth client ID and secret are needed to use it.';
    }

    if ($saJson !== '') {
        $logs[] = 'Signing a request with the service account…';
        [$token, $error] = serviceAccountToken($saJson);
        if ($token !== null) {
            return [$token, 'service account', $logs];
        }
        $logs[] = 'The service account could not be used: ' . $error;
    }

    return [null, '', $logs];
}

/**
 * Exchange a service account key for an access token (RFC 7523 JWT bearer).
 *
 * @return array{0: ?string, 1: string} token, or null and the reason
 */
function serviceAccountToken(string $json): array
{
    $key = json_decode($json, true);
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
        return [null, 'the JSON is not a service account key (client_email and private_key are needed)'];
    }
    $now = time();
    $claims = [
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive',
        'aud'   => $key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $b64 = static fn(array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
    $input = $b64(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $b64($claims);

    $signature = '';
    $pkey = @openssl_pkey_get_private($key['private_key']);
    if ($pkey === false) {
        return [null, 'the private key in the JSON could not be read'];
    }
    if (!@openssl_sign($input, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
        return [null, 'the assertion could not be signed'];
    }
    $jwt = $input . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    [$code, $body] = httpPost((string) $claims['aud'], [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);
    $res = json_decode((string) $body, true);
    if ($code >= 200 && $code < 300 && !empty($res['access_token'])) {
        return [(string) $res['access_token'], ''];
    }
    return [null, (string) ($res['error_description'] ?? $res['error'] ?? ('HTTP ' . $code))];
}

/**
 * Resolve the configured target folder, which people often enter as a name
 * rather than an ID.
 *
 * @return array{0: ?string, 1: string[]} the folder's name, and log lines
 */
function googleFindFolder(string $token, string $folder): array
{
    $logs = [];
    $auth = ['Authorization: Bearer ' . $token];

    [$code, $body] = httpGet(
        'https://www.googleapis.com/drive/v3/files/' . rawurlencode($folder)
            . '?fields=id,name,mimeType,trashed&supportsAllDrives=true',
        $auth
    );
    $json = json_decode((string) $body, true);
    if ($code >= 200 && $code < 300) {
        if (($json['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
            $logs[] = 'Target folder found by ID: ' . ($json['name'] ?? $folder) . '.';
            return [(string) ($json['name'] ?? $folder), $logs];
        }
        $logs[] = 'That ID exists but is a file, not a folder.';
        return [null, $logs];
    }

    $logs[] = 'Not an ID, so looking for a folder with that name…';
    $query = "mimeType='application/vnd.google-apps.folder' and name='" . str_replace("'", "\\'", $folder) . "' and trashed=false";
    [$code, $body] = httpGet(
        'https://www.googleapis.com/drive/v3/files?q=' . rawurlencode($query)
            . '&fields=files(id,name)&includeItemsFromAllDrives=true&supportsAllDrives=true',
        $auth
    );
    $json = json_decode((string) $body, true);
    if ($code >= 200 && $code < 300 && !empty($json['files'])) {
        $first = $json['files'][0];
        $logs[] = 'Found "' . $first['name'] . '" (ID ' . $first['id'] . '). Save that ID to skip the lookup.';
        return [(string) $first['name'], $logs];
    }
    $logs[] = 'No folder of that name is visible to this account (HTTP ' . $code . ').';
    return [null, $logs];
}

function humanBytes(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
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
        return ['success' => false, 'status' => 'fail', 'error' => 'OneDrive needs a client ID and client secret.', 'logs' => $logs];
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
        return ['success' => true, 'status' => 'ok', 'message' => 'OneDrive credentials are accepted.', 'logs' => $logs];
    }
    if ($code === 0) {
        return ['success' => false, 'status' => 'fail', 'error' => 'The server could not reach login.microsoftonline.com. Check outbound internet access or the proxy.', 'logs' => $logs];
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
    return ['success' => false, 'status' => 'fail', 'error' => $friendly, 'logs' => $logs];
}

/** @return array{0:int, 1:string} HTTP status and body; status 0 means unreachable. */
function httpGet(string $url, array $headers = []): array
{
    return httpRequest($url, null, $headers);
}

/** @return array{0:int, 1:string} */
function httpPost(string $url, array $form, array $headers = []): array
{
    return httpRequest($url, http_build_query($form), $headers);
}

function httpRequest(string $url, ?string $body, array $headers = []): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
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
        'header' => "Accept: application/json\r\n"
            . ($body === null ? '' : "Content-Type: application/x-www-form-urlencoded\r\n")
            . ($headers ? implode("\r\n", $headers) . "\r\n" : ''),
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
