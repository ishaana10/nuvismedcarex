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
    'database' => 'disconnected'
];

try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT 1");
    if ($stmt && $stmt->fetchColumn() == 1) {
        $response['database'] = 'connected';
    }
} catch (Throwable $e) {
    // The reason goes to the server log; an open health URL must not describe the database.
    error_log('[health] ' . $e->getMessage());
    $response['status'] = 'ERROR';
    http_response_code(500);
    echo json_encode($response);
    exit;
}

http_response_code(200);
echo json_encode($response);
