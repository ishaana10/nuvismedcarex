<?php
declare(strict_types=1);

/**
 * Where Google, Microsoft and Dropbox send the browser back after the
 * developer signs in to the account documents will be stored in.
 *
 * Register this address in the provider's developer console — Developer →
 * Document storage shows the exact address to use.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\AuditService;
use ClinicFlow\Services\Cloud\Cloud;
use ClinicFlow\Services\Cloud\CloudConfig;

$back = '../developer.php?dev_tab=cloud';

function nxOauthFinish(string $back, bool $ok, string $message): never
{
    setToast($ok ? 'Drive connected' : 'Not connected', $message, $ok ? 'success' : 'error');
    header('Location: ' . $back);
    exit;
}

if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}
requireAccess('developer', 'full');

$state = $_SESSION['nx_cloud_oauth'] ?? null;
unset($_SESSION['nx_cloud_oauth']);

if (
    !$state
    || ($state['exp'] ?? 0) < time()
    || !hash_equals((string) ($state['state'] ?? ''), (string) ($_GET['state'] ?? ''))
    || (string) ($state['user'] ?? '') !== (string) ($_SESSION['user_id'] ?? '')
) {
    nxOauthFinish($back, false, 'That sign-in link expired or did not match. Start the connection again.');
}

if (!empty($_GET['error'])) {
    nxOauthFinish($back, false, 'The provider reported: ' . mb_substr((string) ($_GET['error_description'] ?? $_GET['error']), 0, 200));
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    nxOauthFinish($back, false, 'No authorisation code came back from the provider.');
}

$providerId = (string) $state['provider'];
$cfg = CloudConfig::get(true);

try {
    $provider = Cloud::provider($providerId, $cfg);
    if (!$provider) {
        nxOauthFinish($back, false, 'That provider is not one this installation knows about.');
    }
    $result = $provider->exchangeCode($code, CloudConfig::redirectUri($cfg));
    CloudConfig::setConnection($providerId, [
        'refresh_token' => $result['refresh_token'],
        'account' => $result['account'],
        'connected_at' => date('Y-m-d H:i:s'),
    ]);

    try {
        (new AuditService(getDB()))->log(
            'CLOUD_STORAGE_CONNECTED',
            $provider->label() . ' connected' . ($result['account'] !== '' ? ' as ' . $result['account'] : ''),
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_name'] ?? null,
            nxCurrentRole(),
            \ClinicFlow\Shared\TenantContext::getTenantId()
        );
    } catch (\Throwable $e) {
        error_log('[cloud_oauth] audit: ' . $e->getMessage());
    }

    nxOauthFinish(
        $back,
        true,
        $provider->label() . ' connected' . ($result['account'] !== '' ? ' as ' . $result['account'] : '')
            . '. Make it the default drive to start sending uploads there.'
    );
} catch (\Throwable $e) {
    error_log('[cloud_oauth] ' . $e->getMessage());
    nxOauthFinish($back, false, $e->getMessage());
}
