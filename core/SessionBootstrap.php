<?php
declare(strict_types=1);

if (!defined('NU_ROOT')) {
    define('NU_ROOT', dirname(__DIR__));
}

require_once NU_ROOT . '/config/database.php';

// Clinic-local time: "today", greetings and timestamps follow the clinic, not the server.
// Override with 'timezone' in config/config.php or the APP_TIMEZONE environment variable.
if (!defined('NU_TIMEZONE_SET')) {
    define('NU_TIMEZONE_SET', true);
    $nuTz = getenv('APP_TIMEZONE') ?: ((getAppConfig()['timezone'] ?? '') ?: 'Pacific/Fiji');
    if (in_array($nuTz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($nuTz);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session cookie attributes before session start
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    $appConfig = getAppConfig();
    $nuConfig = $GLOBALS['nuConfig'] ?? $appConfig;

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
            }
        } catch (\Throwable $e) {
            error_log('[SessionBootstrap] Redis sessions failed: ' . $e->getMessage());
        }
    }

    session_start();
}

// Settings for the active clinic (see includes/settings.php)
require_once NU_ROOT . '/includes/settings.php';

// Role-based access: block action endpoints and print pages the signed-in
// user's role isn't allowed to use (see includes/access.php).
require_once NU_ROOT . '/includes/access.php';
if (!defined('NU_ACCESS_GUARDED')) {
    define('NU_ACCESS_GUARDED', true);
    nxRefreshSessionUser();
    nxGuardRequest();
}
