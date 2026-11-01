<?php
/**
 * Add staff / toggle staff status.
 *
 * Hardened: this endpoint now enforces the same authorization as the main staff
 * handler. The Developer role can only be granted (and Developer accounts only
 * modified) by a developer, and new staff are scoped to the active clinic.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/access.php';

if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not saved', 'That request expired. Reload the page and try again.', 'error');
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../admin.php?tab=users'));
    exit;
}

// Managing staff requires the "users" permission at edit level.
requireAccess('users', 'edit');

$pdo = getDB();
$currentUserRole = nxCurrentRole();
$isDeveloper = ($currentUserRole === 'Developer');
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$selfId = (string) ($_SESSION['user_id'] ?? '');
$back = $_SERVER['HTTP_REFERER'] ?? '../admin.php?tab=users';
$action = $_POST['action'] ?? 'add';

/** Load a staff account visible to the active clinic. */
$loadUser = function (string $id) use ($pdo, $tenantId): ?array {
    $st = $pdo->prepare("SELECT id, name, role, is_active FROM doctors WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)");
    $st->execute([$id, $tenantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

// --- Toggle active status ---------------------------------------------------
if ($action === 'toggle_status') {
    $docId = (string) ($_POST['doctor_id'] ?? $_POST['id'] ?? '');
    $target = $loadUser($docId);
    if (!$target) {
        setToast('Account not found', 'That staff account belongs to another clinic or was removed.', 'error');
        header('Location: ' . $back);
        exit;
    }
    // Only a developer may change a developer account.
    if (\ClinicFlow\Services\AccessControl::normaliseRole((string) $target['role']) === 'Developer' && !$isDeveloper) {
        setToast('Not allowed', 'Only a developer can change a developer account.', 'error');
        header('Location: ' . $back);
        exit;
    }
    if ($docId === $selfId) {
        setToast('Not changed', "You can't change your own access here.", 'error');
        header('Location: ' . $back);
        exit;
    }
    $new = ((int) $target['is_active'] === 1) ? 0 : 1;
    $pdo->prepare("UPDATE doctors SET is_active = ? WHERE id = ?")->execute([$new, $docId]);
    setToast('Status changed', $new ? 'Account access restored.' : 'Account access revoked.');
    header('Location: ' . $back);
    exit;
}

// --- Add a staff member -----------------------------------------------------
$name = trim($_POST['name'] ?? '');
$specialty = trim($_POST['specialty'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$role = trim($_POST['role'] ?? 'Doctor');

if ($name === '' || $email === '') {
    setToast('Error', 'Staff name and email are required.', 'error');
    header('Location: ' . $back);
    exit;
}

// The role must be one the current user is allowed to assign. Developer is only
// in this list for developers, so a clinic admin cannot create a developer.
$assignable = nxAccess()->roles($isDeveloper);
if (!in_array($role, $assignable, true)) {
    setToast('Role not available', "\u{201C}{$role}\u{201D} isn't a role you can assign.", 'error');
    header('Location: ' . $back);
    exit;
}

$docId = 'doc-' . time();
$passHash = password_hash($password !== '' ? $password : bin2hex(random_bytes(6)), PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("INSERT INTO doctors (id, tenant_id, name, specialty, email, password_hash, role, color, dot_color_class) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$docId, $tenantId, $name, $specialty, $email, $passHash, $role, '#3B82F6', 'bg-blue-500']);
    setToast('Staff added', "New staff member {$name} ({$role}) added.");
} catch (\Throwable $e) {
    setToast('Error', 'Could not add the staff member. The email may already be in use.', 'error');
}
header('Location: ' . $back);
exit;
