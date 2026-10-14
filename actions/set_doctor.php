<?php
/**
 * Switch Active Doctor
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}

$doctorId = $_POST['doctor_id'] ?? 'doc-1';
$_SESSION['current_doctor_id'] = $doctorId;

$referer = $_SERVER['HTTP_REFERER'] ?? '../index.php';
header("Location: " . $referer);
exit;
