<?php
declare(strict_types=1);

/**
 * Inbound webhook from NuvisERPX.
 *
 * NuvisERPX calls this endpoint when an invoice is issued/updated or a payment
 * is reconciled. Authentication is by HMAC-SHA256 signature over the raw body
 * (header: X-Nuvis-Signature), so no session or CSRF applies here.
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_NUVIS_SIGNATURE']
    ?? $_SERVER['HTTP_X_SIGNATURE']
    ?? ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');

try {
    $erp = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\NuvisErpService::class);
    [$ok, $message, $code] = $erp->handleWebhook($rawBody, (string) $signature);
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal error']);
}
