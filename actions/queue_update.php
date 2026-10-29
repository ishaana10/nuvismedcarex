<?php
/**
 * Patient queue actions: call into a room, send back to waiting, complete.
 * Scoped to the active tenant and protected by CSRF.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Action blocked', 'Your session token expired. Reload the page and try again.', 'error');
    header('Location: ../index.php');
    exit;
}

$queueId  = trim((string) ($_POST['queue_id'] ?? ''));
$action   = (string) ($_POST['action'] ?? '');

// Calling a patient through to a room, sending them back, and closing a visit
// are all clinical decisions. Booking and checking people in at the desk are
// not, and stay on the appointments permission this script already carries.
if (in_array($action, ['check_in', 'send_back', 'complete'], true)) {
    nxRequireClinician(match ($action) {
        'check_in'  => 'call a patient through to a room',
        'send_back' => 'send a patient back to the waiting room',
        default     => 'close a visit',
    });
}
$room     = trim((string) ($_POST['room'] ?? ''));
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$pdo = getDB();

$row = null;
if ($queueId !== '') {
    $stmt = $pdo->prepare("SELECT id, patient_name FROM queue WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$queueId, $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$row) {
    setToast('Patient not found in queue', 'They may already have been seen. The queue has been refreshed.', 'error');
} elseif ($action === 'check_in') {
    $room = $room !== '' ? mb_substr($room, 0, 40) : 'Room 1';
    $stmt = $pdo->prepare("UPDATE queue SET status = 'In Room', room = ?, check_in_time = ? WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$room, date('H:i'), $queueId, $tenantId]);
    setToast('Called in', "{$row['patient_name']} is now in {$room}.", 'info');
} elseif ($action === 'send_back') {
    $stmt = $pdo->prepare("UPDATE queue SET status = 'Waiting', room = NULL WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$queueId, $tenantId]);
    setToast('Back to waiting', "{$row['patient_name']} has been moved back to the waiting list.", 'info');
} elseif ($action === 'complete') {
    $stmt = $pdo->prepare("DELETE FROM queue WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$queueId, $tenantId]);
    setToast('Visit completed', "{$row['patient_name']} has been removed from the queue.");
}

// Return to the page the user came from (same host only)
$back = '../index.php';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer !== '' && parse_url($referer, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '') ) {
    $back = $referer;
} elseif ($referer !== '' && parse_url($referer, PHP_URL_HOST) === parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
    $back = $referer;
}
header('Location: ' . $back);
exit;
