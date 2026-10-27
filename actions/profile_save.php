<?php
/**
 * Save your own profile: name, job title, email, photo, signature and stamp.
 * Role and clinic are deliberately not editable here — only an administrator
 * can change those, from Administration → Staff.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\ClinicSettings;

requireAuth();

$back = '../settings.php?tab=profile';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not saved', 'That request expired. Reload the page and try again.', 'error');
    header('Location: ' . $back);
    exit;
}

$userId = (string) ($_SESSION['user_id'] ?? '');
if ($userId === '') {
    header('Location: ../login.php');
    exit;
}

$pdo = getDB();
$name = trim((string) ($_POST['name'] ?? ''));
$specialty = trim((string) ($_POST['specialty'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));

if ($name === '') {
    setToast('Not saved', 'Your name cannot be empty.', 'error');
    header('Location: ' . $back);
    exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    setToast('Not saved', 'That email address is not valid.', 'error');
    header('Location: ' . $back);
    exit;
}

// You sign in with your email, so it has to stay unique
if ($email !== '') {
    try {
        $dupe = $pdo->prepare("SELECT COUNT(*) FROM doctors WHERE email = ? AND id <> ?");
        $dupe->execute([$email, $userId]);
        if ((int) $dupe->fetchColumn() > 0) {
            setToast('Not saved', 'Another account already uses that email address.', 'error');
            header('Location: ' . $back);
            exit;
        }
    } catch (\Throwable $e) {
        error_log('[profile_save] email check: ' . $e->getMessage());
    }
}

$fields = ['name' => $name, 'specialty' => $specialty, 'email' => $email];
$warnings = [];

/** Images are stored inline so they travel with the record and print reliably. */
$imageFields = ['avatar' => 'avatar_file', 'esignature' => 'esignature_file', 'digital_stamp' => 'digital_stamp_file'];
foreach ($imageFields as $column => $inputName) {
    if (!empty($_POST['remove_' . $column])) {
        $fields[$column] = '';
    }
    $file = $_FILES[$inputName] ?? null;
    if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        continue;
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        $warnings[] = 'The ' . str_replace('_', ' ', $column) . ' could not be uploaded.';
        continue;
    }
    try {
        $fields[$column] = ClinicSettings::logoToDataUri($file);
    } catch (\InvalidArgumentException $e) {
        $warnings[] = $e->getMessage();
    }
}

try {
    $set = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($fields)));
    $stmt = $pdo->prepare("UPDATE doctors SET {$set} WHERE id = :id");
    $stmt->execute(array_merge($fields, ['id' => $userId]));

    $_SESSION['user_name'] = $name;
    if ($email !== '') {
        $_SESSION['user_email'] = $email;
    }
} catch (\Throwable $e) {
    error_log('[profile_save] ' . $e->getMessage());
    setToast('Not saved', 'Your profile could not be saved. Try again.', 'error');
    header('Location: ' . $back);
    exit;
}

if ($warnings) {
    setToast('Saved with one problem', implode(' ', $warnings), 'error');
} else {
    setToast('Profile saved', 'Your details are up to date.');
}
header('Location: ' . $back);
exit;
