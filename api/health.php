<?php
/**
 * ClinicFlow Health Check Endpoint
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/version.php';

$response = [
    'status' => 'OK',
    'timestamp' => date('c'),
    'version' => defined('APP_VERSION') ? APP_VERSION : 'v2.1.0-VMS3',
    'database' => 'disconnected'
];

try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT 1");
    if ($stmt && $stmt->fetchColumn() == 1) {
        $response['database'] = 'connected';
    }
} catch (Throwable $e) {
    $response['status'] = 'ERROR';
    $response['database_error'] = $e->getMessage();
    http_response_code(500);
    echo json_encode($response);
    exit;
}

http_response_code(200);
echo json_encode($response);
