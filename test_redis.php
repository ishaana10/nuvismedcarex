<?php
// Diagnostic script: CLI only (php test_redis.php). Never reachable over HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Cache.php';

$cache = NuCache::getInstance();

if (!$cache->isEnabled()) {
    require_once __DIR__ . '/core/RedisConnector.php';
    $d = NuRedisConnector::describe(getAppConfig()['redis'] ?? []);
    echo "Redis is NOT connected.\n  Why: " . $cache->reason() . "\n";
    echo "  Home folder:   " . ($d['home'] ?? 'not found') . "\n";
    echo "  Request file:  " . ($d['flag_file'] ?? '-') . ($d['flag_exists'] ? ' (present)' : ' (missing)') . "\n";
    echo "  Sockets tried: " . implode(', ', $d['socket_checked']) . "\n";
    exit(1);
}

$cache->set('test_key', 'Hello from Nuvis Cache ' . date('H:i:s'), 30);
echo "SET OK\n";
echo "GET: " . $cache->get('test_key') . "\n";

$client = $cache->client();
if ($client) {
    try {
        $info = $client->info();
        echo "Redis INFO (server): " . ($info['redis_version'] ?? 'unknown') . "\n";
    } catch (\Throwable $e) {
        echo "Redis INFO failed: " . $e->getMessage() . "\n";
    }
}
echo "All good!\n";
