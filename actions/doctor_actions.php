<?php
/**
 * Action Handler: User / Doctor Management (Add/Edit/Delete/Toggle User, Signatures & Stamps)
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: ../login.php");
    exit;
}

// Who may manage staff is decided by Administration → Access & roles
// ("Staff & access": edit to add/change, full to delete). See includes/access.php.
require_once __DIR__ . '/../includes/security.php';
$currentUserRole = nxCurrentRole();

$pdo = getDB();
$action = $_POST['action'] ?? $_GET['action'] ?? 'save';
$tenantIdCtx = \ClinicFlow\Shared\TenantContext::getTenantId();
$selfId = (string) ($_SESSION['user_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not saved', 'That request expired. Reload the page and try again.', 'error');
    header("Location: ../admin.php?tab=users");
    exit;
}

/** Load a staff account that belongs to the active clinic. */
$loadUser = function (string $id) use ($pdo, $tenantIdCtx): ?array {
    if ($id === '') return null;
    $st = $pdo->prepare("SELECT id, name, role, is_active FROM doctors WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)");
    $st->execute([$id, $tenantIdCtx]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
/** Would this change leave the clinic without an active administrator? */
$isLastAdmin = function (string $id) use ($pdo, $tenantIdCtx): bool {
    $st = $pdo->prepare("SELECT id, role FROM doctors WHERE (tenant_id = ? OR tenant_id IS NULL) AND is_active = 1");
    $st->execute([$tenantIdCtx]);
    $admins = array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($u) => in_array(\ClinicFlow\Services\AccessControl::normaliseRole((string) $u['role']), ['Administrator', 'Developer'], true));
    return count($admins) === 1 && (string) reset($admins)['id'] === $id;
};
$bounce = function (string $title, string $msg) {
    setToast($title, $msg, 'error');
    header("Location: ../admin.php?tab=users");
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'save' || $action === 'save_doctor')) {
    $docId = !empty($_POST['doctor_id']) ? trim($_POST['doctor_id']) : ('usr-' . time());
    $isEdit = !empty($_POST['doctor_id']);

    $name = trim($_POST['name'] ?? '');
    $specialty = trim($_POST['specialty'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = trim((string) ($_POST['role'] ?? 'Doctor'));
    $assignable = nxAccess()->roles($currentUserRole === 'Developer');
    if (!in_array($role, $assignable, true)) {
        $bounce('Role not available', "“{$role}” isn't a role at this clinic. Pick one from the list.");
    }
    if ($isEdit) {
        $existing = $loadUser($docId);
        if (!$existing) $bounce('Account not found', 'That staff account belongs to another clinic or was removed.');
        $wasAdmin = in_array(\ClinicFlow\Services\AccessControl::normaliseRole((string) $existing['role']), ['Administrator', 'Developer'], true);
        $staysAdmin = in_array($role, ['Administrator', 'Developer'], true);
        if ($wasAdmin && !$staysAdmin && $isLastAdmin($docId)) {
            $bounce('Role not changed', 'This is the only administrator. Make someone else an administrator first.');
        }
        if (\ClinicFlow\Services\AccessControl::normaliseRole((string) $existing['role']) === 'Developer' && $currentUserRole !== 'Developer') {
            $bounce('Not allowed', 'Only a developer can change a developer account.');
        }
    }
    $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    $color = $_POST['color'] ?? '#10B981';
    $dotColorClass = $_POST['dot_color_class'] ?? 'bg-emerald-500';
    $prcNumber = trim($_POST['prc_number'] ?? '');
    $ptrNumber = trim($_POST['ptr_number'] ?? '');
    $avatar = trim($_POST['avatar'] ?? '');

    // Handle Profile Image Upload
    if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['avatar_file']['tmp_name'];
        $origName = $_FILES['avatar_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) ?: 'png';
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
            $ext = 'png';
        }
        $avatarDir = __DIR__ . '/../uploads/avatars/';
        if (!file_exists($avatarDir)) {
            @mkdir($avatarDir, 0777, true);
        }
        $filename = 'avatar_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $docId) . '_' . time() . '.' . $ext;
        $targetPath = $avatarDir . $filename;
        if (move_uploaded_file($tmpName, $targetPath)) {
            $avatar = 'uploads/avatars/' . $filename;
        }
    }

    if (empty($name) || empty($email)) {
        setToast("Missing Data", "User name and email are required.", "error");
        header("Location: ../admin.php?tab=users");
        exit;
    }

    // Handle E-Signature upload / string / file save
    $esignature = $_POST['existing_esignature'] ?? '';
    if (isset($_FILES['esignature_file']) && $_FILES['esignature_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['esignature_file']['tmp_name'];
        $origName = $_FILES['esignature_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) ?: 'png';
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'])) {
            $ext = 'png';
        }
        $sigDir = __DIR__ . '/../uploads/signatures/';
        if (!file_exists($sigDir)) {
            @mkdir($sigDir, 0777, true);
        }
        $filename = 'sig_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $docId) . '_' . time() . '.' . $ext;
        $targetPath = $sigDir . $filename;
        if (move_uploaded_file($tmpName, $targetPath)) {
            $esignature = 'uploads/signatures/' . $filename;
        } else {
            $mime = mime_content_type($tmpName);
            $data = file_get_contents($tmpName);
            $esignature = 'data:' . $mime . ';base64,' . base64_encode($data);
        }
    } elseif (!empty($_POST['esignature_data'])) {
        $esignature = trim($_POST['esignature_data']);
    }

    // Handle Digital Stamp upload / string / file save
    $digitalStamp = $_POST['existing_digital_stamp'] ?? '';
    if (isset($_FILES['stamp_file']) && $_FILES['stamp_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['stamp_file']['tmp_name'];
        $origName = $_FILES['stamp_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) ?: 'png';
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'])) {
            $ext = 'png';
        }
        $stampDir = __DIR__ . '/../uploads/stamps/';
        if (!file_exists($stampDir)) {
            @mkdir($stampDir, 0777, true);
        }
        $filename = 'stamp_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $docId) . '_' . time() . '.' . $ext;
        $targetPath = $stampDir . $filename;
        if (move_uploaded_file($tmpName, $targetPath)) {
            $digitalStamp = 'uploads/stamps/' . $filename;
        } else {
            $mime = mime_content_type($tmpName);
            $data = file_get_contents($tmpName);
            $digitalStamp = 'data:' . $mime . ';base64,' . base64_encode($data);
        }
    } elseif (!empty($_POST['digital_stamp_data'])) {
        $digitalStamp = trim($_POST['digital_stamp_data']);
    }

    if ($isEdit) {
        // Fetch existing
        $stmt = $pdo->prepare("SELECT * FROM doctors WHERE id = ?");
        $stmt->execute([$docId]);
        $existingDoc = $stmt->fetch();

        if (!$existingDoc) {
            setToast("Error", "User record not found.", "error");
            header("Location: ../admin.php?tab=users");
            exit;
        }

        $passwordHash = $existingDoc['password_hash'];
        if (!empty($password)) {
            if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
                setToast("Weak Password", "Password must be at least 8 characters long and contain at least one uppercase letter and one number.", "error");
                header("Location: ../admin.php?tab=users");
                exit;
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }

        $updateStmt = $pdo->prepare("UPDATE doctors SET name = ?, specialty = ?, email = ?, password_hash = ?, role = ?, is_active = ?, color = ?, dot_color_class = ?, avatar = ?, prc_number = ?, ptr_number = ?, esignature = ?, digital_stamp = ? WHERE id = ?");
        $updateStmt->execute([$name, $specialty, $email, $passwordHash, $role, $isActive, $color, $dotColorClass, $avatar, $prcNumber, $ptrNumber, $esignature, $digitalStamp, $docId]);

        setToast("User Updated", "User information and credentials updated successfully.");
    } else {
        $initialPassword = !empty($password) ? $password : 'ClinicPass123';
        if (strlen($initialPassword) < 8 || !preg_match('/[A-Z]/', $initialPassword) || !preg_match('/[0-9]/', $initialPassword)) {
            setToast("Weak Password", "Password must be at least 8 characters long and contain at least one uppercase letter and one number.", "error");
            header("Location: ../admin.php?tab=users");
            exit;
        }
        $passwordHash = password_hash($initialPassword, PASSWORD_DEFAULT);

        $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
        $insertStmt = $pdo->prepare("INSERT INTO doctors (id, tenant_id, name, specialty, email, password_hash, role, is_active, color, dot_color_class, avatar, prc_number, ptr_number, esignature, digital_stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->execute([$docId, $tenantId, $name, $specialty, $email, $passwordHash, $role, $isActive, $color, $dotColorClass, $avatar, $prcNumber, $ptrNumber, $esignature, $digitalStamp]);

        setToast("User Added", "New user account created successfully.");
    }

    header("Location: ../admin.php?tab=users");
    exit;
}

if ($action === 'toggle_status') {
    $userId = $_POST['user_id'] ?? '';
    $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

    $target = $loadUser((string) $userId);
    if (!$target) $bounce('Account not found', 'That staff account belongs to another clinic or was removed.');
    if ($status === 0 && $userId === $selfId) $bounce('Not changed', "You can't deactivate your own account.");
    if ($status === 0 && $isLastAdmin((string) $userId)) $bounce('Not changed', 'This is the only active administrator. Make someone else an administrator first.');
    if (!empty($userId)) {
        $stmt = $pdo->prepare("UPDATE doctors SET is_active = ? WHERE id = ?");
        $stmt->execute([$status, $userId]);
        $statusMsg = $status === 1 ? "User account activated." : "User account deactivated.";
        setToast("Status Changed", $statusMsg);
    }
    header("Location: ../admin.php?tab=users");
    exit;
}

if ($action === 'delete') {
    $docId = (string) ($_POST['id'] ?? '');
    $target = $loadUser($docId);
    if (!$target) $bounce('Account not found', 'That staff account belongs to another clinic or was removed.');
    if ($docId === $selfId) $bounce('Not deleted', "You can't delete your own account.");
    if ($isLastAdmin($docId)) $bounce('Not deleted', 'This is the only active administrator. Make someone else an administrator first.');
    if (\ClinicFlow\Services\AccessControl::normaliseRole((string) $target['role']) === 'Developer' && $currentUserRole !== 'Developer') {
        $bounce('Not allowed', 'Only a developer can remove a developer account.');
    }
    if (!empty($docId)) {
        $stmt = $pdo->prepare("DELETE FROM doctors WHERE id = ?");
        $stmt->execute([$docId]);
        setToast("User Removed", "User account deleted successfully.");
    }
    header("Location: ../admin.php?tab=users");
    exit;
}

header("Location: ../admin.php?tab=users");
exit;
