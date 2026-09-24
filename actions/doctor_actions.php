<?php
/**
 * Action Handler: User / Doctor Management (Add/Edit/Delete/Toggle User, Signatures & Stamps)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: ../login.php");
    exit;
}

// Ensure Admin or Developer role
$currentUserRole = $_SESSION['user_role'] ?? $_SESSION['user']['role'] ?? '';
if (!in_array($currentUserRole, ['Developer', 'Administrator', 'Admin', 'Doctor'])) {
    setToast("Access Denied", "Only Administrators and Developers can manage user accounts.", "error");
    header("Location: ../admin.php?tab=users");
    exit;
}

$pdo = getDB();
$action = $_POST['action'] ?? $_GET['action'] ?? 'save';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'save' || $action === 'save_doctor')) {
    $docId = !empty($_POST['doctor_id']) ? trim($_POST['doctor_id']) : ('usr-' . time());
    $isEdit = !empty($_POST['doctor_id']);

    $name = trim($_POST['name'] ?? '');
    $specialty = trim($_POST['specialty'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'Doctor';
    $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    $color = $_POST['color'] ?? '#10B981';
    $dotColorClass = $_POST['dot_color_class'] ?? 'bg-emerald-500';
    $prcNumber = trim($_POST['prc_number'] ?? '');
    $ptrNumber = trim($_POST['ptr_number'] ?? '');
    $avatar = trim($_POST['avatar'] ?? '');

    if (empty($name) || empty($email)) {
        setToast("Missing Data", "User name and email are required.", "error");
        header("Location: ../admin.php?tab=users");
        exit;
    }

    // Handle E-Signature upload / string
    $esignature = $_POST['existing_esignature'] ?? '';
    if (isset($_FILES['esignature_file']) && $_FILES['esignature_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['esignature_file']['tmp_name'];
        $mime = mime_content_type($tmpName);
        $data = file_get_contents($tmpName);
        $esignature = 'data:' . $mime . ';base64,' . base64_encode($data);
    } elseif (!empty($_POST['esignature_data'])) {
        $esignature = trim($_POST['esignature_data']);
    }

    // Handle Digital Stamp upload / string
    $digitalStamp = $_POST['existing_digital_stamp'] ?? '';
    if (isset($_FILES['stamp_file']) && $_FILES['stamp_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['stamp_file']['tmp_name'];
        $mime = mime_content_type($tmpName);
        $data = file_get_contents($tmpName);
        $digitalStamp = 'data:' . $mime . ';base64,' . base64_encode($data);
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

        $insertStmt = $pdo->prepare("INSERT INTO doctors (id, name, specialty, email, password_hash, role, is_active, color, dot_color_class, avatar, prc_number, ptr_number, esignature, digital_stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->execute([$docId, $name, $specialty, $email, $passwordHash, $role, $isActive, $color, $dotColorClass, $avatar, $prcNumber, $ptrNumber, $esignature, $digitalStamp]);

        setToast("User Added", "New user account created successfully.");
    }

    header("Location: ../admin.php?tab=users");
    exit;
}

if ($action === 'toggle_status') {
    $userId = $_POST['user_id'] ?? '';
    $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;

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
    $docId = $_GET['id'] ?? '';
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
