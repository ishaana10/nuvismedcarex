<?php
/**
 * Patient Queue Action Handler (Check-In & Complete)
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../config/database.php';

$queueId = $_POST['queue_id'] ?? '';
$action = $_POST['action'] ?? '';

$pdo = getDB();

if ($action === 'check_in' && $queueId !== '') {
    $stmt = $pdo->prepare("UPDATE queue SET status = 'In Room', room = 'Room 2' WHERE id = ?");
    $stmt->execute([$queueId]);
    setToast("Patient Checked In", "Moved patient to Room 2.", "info");
} elseif ($action === 'complete' && $queueId !== '') {
    $stmt = $pdo->prepare("DELETE FROM queue WHERE id = ?");
    $stmt->execute([$queueId]);
    setToast("Queue Updated", "Patient encounter completed and removed from queue.");
}

$referer = $_SERVER['HTTP_REFERER'] ?? '../index.php';
header("Location: " . $referer);
exit;
