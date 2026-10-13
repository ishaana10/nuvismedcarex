<?php
declare(strict_types=1);

if (!defined('NU_ROOT')) {
    define('NU_ROOT', dirname(__DIR__));
}

require_once NU_ROOT . '/config/database.php';

$appConfig = getAppConfig();
$nuConfig = $GLOBALS['nuConfig'] ?? $appConfig;

$useRedisSessions = false;

if (!empty($nuConfig['redis']['enabled']) && extension_loaded('redis')) {
    try {
        require_once NU_ROOT . '/core/Cache.php';
        require_once NU_ROOT . '/core/RedisSessionHandler.php';

        $cache = \NuCache::getInstance();
        if ($cache->isEnabled() && $cache->client()) {
            $handler = new \NuRedisSessionHandler(
                $cache->client(),
                ($nuConfig['redis']['prefix'] ?? 'nuvis:') . 'sess:',
                (int)($nuConfig['sessionTimeout'] ?? 1800)
            );
            session_set_save_handler($handler, true);
            $useRedisSessions = true;
        }
    } catch (\Throwable $e) {
        error_log('[SessionBootstrap] Redis sessions failed: ' . $e->getMessage());
    }
}

// Fall back to default file sessions if Redis failed or is disabled
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
