<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

use ClinicFlow\Services\VMSService;

requireAuth();

// FRCS VMS (fiscalization) is not part of this release: Developer role only.
if (($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') !== 'Developer') {
    http_response_code(403);
    echo json_encode(['error' => 'VMS is not available for your account.']);
    exit;
}

$pdo = getDB();
$vms = new VMSService($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'rates';

    if ($action === 'report') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $report = $vms->getDailyFiscalReport($date);
        echo json_encode(['success' => true, 'data' => $report]);
        exit;
    }

    echo json_encode(['success' => true, 'data' => ['rates' => $vms->getTaxRates()]]);
    exit;
}

if ($method === 'POST') {
    validateCsrfRequest();
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $action = $input['action'] ?? 'fiscalize';

    try {
        if ($action === 'cancel') {
            $invoiceId = $input['invoice_id'] ?? null;
            if (!$invoiceId) {
                http_response_code(400);
                echo json_encode(['error' => 'invoice_id is required']);
                exit;
            }
            $cancelInvId = $vms->cancelInvoice($invoiceId, $_SESSION['user_name'] ?? 'Admin');
            echo json_encode(['success' => true, 'cancelled_invoice_id' => $cancelInvId]);
            exit;
        }

        $invoiceId = $input['invoice_id'] ?? null;
        if (!$invoiceId) {
            http_response_code(400);
            echo json_encode(['error' => 'invoice_id is required']);
            exit;
        }

        $res = $vms->fiscalizeInvoice($invoiceId);
        echo json_encode(['success' => true, 'data' => $res]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
