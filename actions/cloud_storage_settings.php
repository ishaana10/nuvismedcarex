<?php
declare(strict_types=1);

/**
 * Developer → Document storage.
 *
 * POST  action=save        the default drive, how copies are kept, the folder
 * POST  action=credentials one provider's client id and secret
 * POST  action=connect     start the browser sign-in for a provider
 * POST  action=disconnect  forget a provider's authorisation
 * POST  action=retry|backfill  copy files that have not reached the drive
 * GET   action=test        connect, upload a small file, read it back, remove it
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\AuditService;
use ClinicFlow\Services\Cloud\Cloud;
use ClinicFlow\Services\Cloud\CloudConfig;
use ClinicFlow\Services\Cloud\FileStore;

requireAuth();
requireAccess('developer', 'full');

$pdo = getDB();
$back = '../developer.php?dev_tab=cloud';
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

function nxCloudAudit(PDO $pdo, string $event, string $detail): void
{
    try {
        (new AuditService($pdo))->log(
            $event,
            $detail,
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_name'] ?? null,
            nxCurrentRole(),
            \ClinicFlow\Shared\TenantContext::getTenantId()
        );
    } catch (\Throwable $e) {
        error_log('[cloud_storage_settings] audit: ' . $e->getMessage());
    }
}

/* ----------------------------------------------------------- connection test */
if ($action === 'test' || $action === 'test_connection') {
    header('Content-Type: application/json; charset=utf-8');
    $cfg = CloudConfig::get();
    $providerId = (string) ($_GET['drive'] ?? $_GET['provider'] ?? $cfg['provider']);
    $logs = [];
    $stamp = static fn(string $m): string => '[' . date('H:i:s') . '] ' . $m;

    $provider = Cloud::provider($providerId, $cfg);
    if (!$provider) {
        echo json_encode([
            'success' => false, 'status' => 'warn',
            'error' => 'Nothing to test: documents are kept on this server only.',
            'logs' => [$stamp('Storage is set to this server.')],
        ]);
        exit;
    }

    $logs[] = $stamp('Checking ' . $provider->label() . '.');
    if (!CloudConfig::isConnected($providerId, $cfg)) {
        $logs[] = $stamp('No authorisation on file for this provider.');
        echo json_encode([
            'success' => false, 'status' => 'warn',
            'error' => $provider->label() . ' is not connected yet. Save the credentials, then use Connect.',
            'logs' => $logs,
        ]);
        exit;
    }

    try {
        $account = $provider->whoami();
        $logs[] = $stamp('Signed in as ' . $account . '.');

        $tmp = tempnam(sys_get_temp_dir(), 'nxct');
        file_put_contents($tmp, "Nuvis Medcare X storage test, " . date('c') . "\n");
        $segments = array_merge(FileStore::remoteSegments($cfg), ['_connection_test']);
        $logs[] = $stamp('Uploading a test file to ' . implode('/', $segments) . ' …');

        $result = $provider->upload($tmp, $segments, 'test-' . date('His') . '.txt', 'text/plain');
        $logs[] = $stamp('Uploaded. Reading it back …');

        $readBack = '';
        $provider->download($result['id'], function ($chunk) use (&$readBack) {
            $readBack .= $chunk;
        });
        $provider->delete($result['id']);
        @unlink($tmp);

        if (trim($readBack) === '') {
            throw new \RuntimeException('The test file uploaded but came back empty.');
        }
        $logs[] = $stamp('Read it back and removed it again.');

        CloudConfig::setConnection($providerId, [
            'account' => $account,
            'connected_at' => $cfg[$providerId]['connected_at'] ?: date('Y-m-d H:i:s'),
        ]);
        nxCloudAudit($pdo, 'CLOUD_STORAGE_TESTED', $provider->label() . ' test passed as ' . $account);
        echo json_encode([
            'success' => true, 'status' => 'ok',
            'message' => 'Connected as ' . $account . '. A test file was uploaded, read back and removed.',
            'logs' => $logs,
        ]);
    } catch (\Throwable $e) {
        @unlink($tmp ?? '');
        $logs[] = $stamp($e->getMessage());
        nxCloudAudit($pdo, 'CLOUD_STORAGE_TESTED', $provider->label() . ' test failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'status' => 'fail', 'error' => $e->getMessage(), 'logs' => $logs]);
    }
    exit;
}

/* ----------------------------------------------------------------- the rest */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: {$back}");
    exit;
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not saved', 'That request expired. Reload the page and try again.', 'error');
    header("Location: {$back}");
    exit;
}

if (!CloudConfig::isWritable()) {
    setToast(
        'Cannot save',
        'The settings file ' . basename(CloudConfig::file()) . ' is not writable. Make the config folder writable by the web server.',
        'error'
    );
    header("Location: {$back}");
    exit;
}

try {
    switch ($action) {
        case 'save':
            $new = CloudConfig::save([
                'provider'   => (string) ($_POST['provider'] ?? 'local'),
                'mode'       => (string) ($_POST['mode'] ?? 'mirror'),
                'folder'     => (string) ($_POST['folder'] ?? ''),
                'by_clinic'  => !empty($_POST['by_clinic']),
                'by_patient' => !empty($_POST['by_patient']),
                'redirect_uri' => (string) ($_POST['redirect_uri'] ?? ''),
            ]);
            nxCloudAudit($pdo, 'CLOUD_STORAGE_SAVED', sprintf(
                'Default drive %s, %s, folder %s',
                $new['provider'],
                $new['mode'] === 'cloud_only' ? 'drive only' : 'drive and this server',
                $new['folder']
            ));

            if ($new['provider'] !== 'local' && !CloudConfig::isConnected($new['provider'], $new)) {
                setToast(
                    'Saved, but not connected yet',
                    Cloud::labels()[$new['provider']] . ' has no authorisation on file, so uploads stay on this server until you connect it.',
                    'error'
                );
            } elseif ($new['provider'] === 'local') {
                setToast('Saved', 'Documents are kept on this server only.');
            } else {
                setToast(
                    'Saved',
                    'New uploads anywhere in the application now go to ' . Cloud::labels()[$new['provider']]
                    . ($new['mode'] === 'cloud_only' ? ', and the local copy is removed once they arrive.' : ', with a copy kept on this server.')
                );
            }
            break;

        case 'credentials':
            $providerId = (string) ($_POST['drive'] ?? '');
            if (!in_array($providerId, ['gdrive', 'onedrive', 'dropbox', 'webdav'], true)) {
                throw new \InvalidArgumentException('Unknown storage provider.');
            }
            $fields = [];
            foreach (array_keys(CloudConfig::defaults()[$providerId]) as $key) {
                if (array_key_exists($key, $_POST)) {
                    $fields[$key] = (string) $_POST[$key];
                }
            }
            CloudConfig::save([$providerId => $fields]);
            nxCloudAudit($pdo, 'CLOUD_STORAGE_SAVED', 'Credentials updated for ' . $providerId);
            setToast(
                Cloud::labels()[$providerId] . ' saved',
                $providerId === 'webdav'
                    ? 'Use Test to check the address and password.'
                    : 'Now use Connect to sign in to the account documents should go to.'
            );
            break;

        case 'connect':
            $providerId = (string) ($_POST['drive'] ?? '');
            if (!in_array($providerId, ['gdrive', 'onedrive', 'dropbox'], true)) {
                throw new \InvalidArgumentException('That provider does not use browser sign-in.');
            }
            $cfg = CloudConfig::get(true);
            $idKey = $providerId === 'dropbox' ? 'app_key' : 'client_id';
            $secretKey = $providerId === 'dropbox' ? 'app_secret' : 'client_secret';
            if (($cfg[$providerId][$idKey] ?? '') === '' || ($cfg[$providerId][$secretKey] ?? '') === '') {
                throw new \InvalidArgumentException('Save the client ID and secret first.');
            }
            $state = bin2hex(random_bytes(16));
            $_SESSION['nx_cloud_oauth'] = [
                'state' => $state,
                'provider' => $providerId,
                'exp' => time() + 600,
                'user' => $_SESSION['user_id'] ?? '',
            ];
            $provider = Cloud::provider($providerId, $cfg);
            header('Location: ' . $provider->authUrl(CloudConfig::redirectUri($cfg), $state));
            exit;

        case 'disconnect':
            $providerId = (string) ($_POST['drive'] ?? '');
            if (!in_array($providerId, ['gdrive', 'onedrive', 'dropbox', 'webdav'], true)) {
                throw new \InvalidArgumentException('Unknown storage provider.');
            }
            CloudConfig::setConnection($providerId, $providerId === 'webdav'
                ? ['password' => '', 'account' => '', 'connected_at' => '']
                : ['refresh_token' => '', 'account' => '', 'connected_at' => '']);
            nxCloudAudit($pdo, 'CLOUD_STORAGE_DISCONNECTED', Cloud::labels()[$providerId] . ' disconnected');
            setToast(
                'Disconnected',
                Cloud::labels()[$providerId] . ' is no longer authorised. Documents already there are untouched.'
            );
            break;

        case 'retry':
        case 'backfill':
            $service = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\FileUploadService::class);
            $result = FileStore::sweep(
                $pdo,
                dirname($service->uploadBasePath()),
                $action === 'retry' ? 'failed' : 'pending',
                25
            );
            nxCloudAudit($pdo, 'CLOUD_STORAGE_SWEEP', sprintf(
                '%s: %d copied, %d failed, %d left',
                $action,
                $result['synced'],
                $result['failed'],
                $result['remaining']
            ));
            if ($result['synced'] === 0 && $result['failed'] === 0) {
                setToast('Nothing to do', 'Every file is already on the drive.', 'info');
            } elseif ($result['failed'] > 0) {
                setToast(
                    $result['synced'] . ' copied, ' . $result['failed'] . ' failed',
                    $result['last_error'] ?: 'Check the error log for the reason.',
                    'error'
                );
            } else {
                setToast(
                    $result['synced'] . ($result['synced'] === 1 ? ' file copied' : ' files copied'),
                    $result['remaining'] > 0
                        ? $result['remaining'] . ' still to go — run it again.'
                        : 'Every file is now on the drive.'
                );
            }
            break;

        default:
            setToast('Nothing to do', 'That action is not one this screen offers.', 'info');
    }
} catch (\InvalidArgumentException | \RuntimeException $e) {
    setToast('Not saved', $e->getMessage(), 'error');
} catch (\Throwable $e) {
    error_log('[cloud_storage_settings] ' . $e->getMessage());
    setToast('Not saved', 'The storage settings could not be saved. Check the error log.', 'error');
}

header("Location: {$back}");
exit;
