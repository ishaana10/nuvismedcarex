<?php
declare(strict_types=1);

/**
 * Nuvis API Gateway Entry Point
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Cache.php';
require_once __DIR__ . '/../core/SessionBootstrap.php';

$cache = \NuCache::getInstance();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

if ($action === 'status' || $action === 'ping') {
    echo json_encode([
        'status' => 'ok',
        'app' => 'Nuvis Medico',
        'redis_enabled' => $cache->isEnabled(),
        'timestamp' => date('c')
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid API action']);
