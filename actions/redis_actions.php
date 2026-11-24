<?php
/**
 * Action: Redis Diagnostics & Cache Management API Handler (AJAX)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../core/Cache.php';

header('Content-Type: application/json');

if (empty($_SESSION['authenticated'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit();
}

$userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Doctor';
if (!in_array($userRole, ['Administrator', 'Developer'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied: Administrator or Developer role required']);
    exit();
}

$jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? ($jsonInput['action'] ?? ($_POST['action'] ?? ''));
nxRequireCsrfForMutations($action, ['get_redis_status', 'test_redis_ping']);

try {
    require_once __DIR__ . '/../core/RedisConnector.php';
    $cache = \NuCache::getInstance();
    $cfg = getAppConfig()['redis'] ?? [];
    $info = \NuRedisConnector::describe($cfg);
    $socketPath = $info['socket_found'] ?? (\NuRedisConnector::socketCandidates($cfg)[0] ?? '');
    $socketExists = $info['socket_found'] !== null;

    if ($action === 'get_redis_status') {
        $isConnected = $cache->isEnabled();
        $client = $cache->client();

        $redisVersion = 'N/A';
        $usedMemory = 'N/A';
        $connectedClients = 0;
        $cachedKeysCount = 0;
        $activeSessionsCount = 0;
        $keysList = [];

        if ($isConnected && $client) {
            try {
                $server = $client->info();
                $redisVersion = $server['redis_version'] ?? 'Unknown';
                $usedMemory = $server['used_memory_human'] ?? 'Unknown';
                $connectedClients = (int)($server['connected_clients'] ?? 1);

                $prefix = $cache->prefix();
                $allKeys = $cache->listKeys(2000);
                $sessionKeys = array_filter($allKeys, fn($k) => str_contains($k, 'sess:') && !str_contains($k, 'sess:lock:'));
                $activeSessionsCount = count($sessionKeys);

                // Show cache keys, not sessions (a session key is a login credential)
                $shown = array_filter($allKeys, fn($k) => !str_contains($k, 'sess:'));
                $cachedKeysCount = count($shown);
                foreach (array_slice(array_values($shown), 0, 15) as $k) {
                    $ttl = $client->ttl($k);
                    $keysList[] = [
                        'key' => str_replace($prefix, '', $k),
                        'full_key' => $k,
                        'ttl' => $ttl > 0 ? $ttl . 's' : ($ttl === -1 ? 'No expiry' : 'Expired')
                    ];
                }
            } catch (\Throwable $e) {
                error_log('[Redis Status Action] Error: ' . $e->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'redis_extension' => extension_loaded('redis'),
            'config_enabled' => $info['mode'] !== 'off',
            'mode' => $info['mode'],
            'is_connected' => $isConnected,
            'via' => $info['via'],
            'reason' => $cache->reason(),
            'home_dir' => $info['home'],
            'flag_file' => $info['flag_file'],
            'flag_exists' => $info['flag_exists'],
            'sockets_checked' => $info['socket_checked'],
            'scheme' => $info['via'] ?: ($cfg['scheme'] ?? 'unix'),
            'socket_path' => $socketPath,
            'socket_exists' => $socketExists,
            'redis_version' => $redisVersion,
            'used_memory' => $usedMemory,
            'connected_clients' => $connectedClients,
            'cached_keys_count' => $cachedKeysCount,
            'active_sessions_count' => $activeSessionsCount,
            'keys_sample' => $keysList,
            'prefix' => $cache->prefix(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    if ($action === 'test_redis_ping') {
        if (!$cache->isEnabled()) {
            echo json_encode([
                'success' => false,
                'error' => 'Redis is not connected: ' . $cache->reason()
            ]);
            exit();
        }

        $start = microtime(true);
        $testKey = 'ping_test_' . uniqid();
        $testVal = 'OK_' . microtime(true);

        $cache->set($testKey, $testVal, 10);
        $retrieved = $cache->get($testKey);
        $cache->delete($testKey);

        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        if ($retrieved === $testVal) {
            echo json_encode([
                'success' => true,
                'latency_ms' => $latencyMs,
                'message' => "SET/GET/DEL benchmark completed in {$latencyMs} ms. Cache reads and writes are working."
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Benchmark read mismatch or key storage failure.'
            ]);
        }
        exit();
    }

    if ($action === 'start_redis_daemon') {
        // Ask the host for the daemon and give it a few seconds. Redis is switched to 'auto'
        // (not forced on), so it keeps working wherever the site is moved.
        $asked = \NuRedisConnector::requestDaemon();
        $sock = \NuRedisConnector::waitForSocket($cfg, $asked === 'created' ? 3.0 : 0.5);

        if (\NuRedisConnector::mode($cfg) === 'off') {
            nxWriteRedisMode('auto');
        }
        \NuCache::resetInstance();
        $cache = \NuCache::getInstance();

        echo json_encode([
            'success' => $asked !== 'failed',
            'socket_exists' => $sock !== null,
            'is_connected' => $cache->isEnabled(),
            'message' => $asked === 'failed'
                ? 'Could not create the ~/.redis.on file: the home folder was not found or is not writable.'
                : ($cache->isEnabled()
                    ? 'Redis is running and connected.'
                    : 'Asked the host to start Redis. It can take a minute, so check again shortly. (' . $cache->reason() . ')')
        ]);
        exit();
    }

    if ($action === 'toggle_redis_config') {
        $enable = !empty($jsonInput['enable']);
        nxWriteRedisMode($enable ? 'auto' : false);
        \NuCache::resetInstance();

        echo json_encode([
            'success' => true,
            'enabled' => $enable,
            'message' => 'Redis ' . ($enable ? 'turned on (automatic)' : 'turned off') . '.'
        ]);
        exit();
    }

    if ($action === 'flush_cache') {
        $flushed = $cache->flush();
        echo json_encode([
            'success' => $flushed,
            'message' => $flushed ? 'Cached data cleared. People stay signed in.' : 'Redis is not connected, so there was nothing to clear.'
        ]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action specified']);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Change only the Redis on/off setting in the local config file. Other settings, including
 * passwords, are left exactly as they are (an older version rewrote the whole file).
 */
function nxWriteRedisMode(string|bool $mode): void
{
    $dir = __DIR__ . '/../config/';
    $file = file_exists($dir . 'config.local.php') ? $dir . 'config.local.php' : $dir . 'config.php';
    if (!is_file($file) || !is_writable($file)) {
        throw new \RuntimeException('The configuration file is missing or not writable.');
    }
    $php = \NuRedisConnector::setModeInConfigText((string) file_get_contents($file), $mode);
    if (@file_put_contents($file, $php, LOCK_EX) === false) {
        throw new \RuntimeException('Could not save the configuration file.');
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
}
