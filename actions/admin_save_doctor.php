<?php
/**
 * Add / Edit Doctor & Staff Handler
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin.php");
    exit;
}

$name = trim($_POST['name'] ?? '');
$specialty = trim($_POST['specialty'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$role = $_POST['role'] ?? 'Doctor';

if ($name === '' || $email === '') {
    setToast("Error", "Doctor name and email are required.", "error");
    header("Location: ../admin.php");
    exit;
}

$pdo = getDB();
$docId = "doc-" . time();
$passHash = password_hash($password ?: 'password', PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO doctors (id, name, specialty, email, password_hash, role, color, dot_color_class) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
    $docId, $name, $specialty, $email, $passHash, $role, '#3B82F6', 'bg-blue-500'
]);

setToast("Doctor Added", "New physician $name ($role) added successfully.");
header("Location: ../admin.php");
exit;
