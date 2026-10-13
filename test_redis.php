<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Cache.php';

$cache = NuCache::getInstance();

if (!$cache->isEnabled()) {
    die("Redis is NOT enabled or connection failed\n");
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
