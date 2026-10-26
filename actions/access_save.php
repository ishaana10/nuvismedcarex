<?php
/**
 * Administration → Access & roles: save the role matrix, add/delete/reset roles,
 * and assign a role to a staff member. Requires "Staff & access: Full"
 * (enforced by the request guard in includes/access.php).
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\AccessControl;
use ClinicFlow\Services\AuditService;

$back = '../access.php';
if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not saved', 'That request expired. Reload the page and try again.', 'error');
    header("Location: {$back}");
    exit;
}

$pdo = getDB();
$ac = nxAccess();
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$me = nxCurrentRole();
$action = (string) ($_POST['action'] ?? '');

$audit = function (string $what, string $details) use ($pdo, $tenantId, $me) {
    try {
        (new AuditService($pdo))->log($what, $details, $_SESSION['user_id'] ?? null, $_SESSION['user_name'] ?? null, $me, $tenantId);
    } catch (\Throwable $e) {
        error_log('[access_save] audit: ' . $e->getMessage());
    }
};
$done = function (string $title, string $msg, string $type = 'success') use ($back) {
    setToast($title, $msg, $type);
    header("Location: {$back}");
    exit;
};

try {
    switch ($action) {
        case 'save_matrix':
            $changes = $ac->saveMatrix(is_array($_POST['matrix'] ?? null) ? $_POST['matrix'] : []);
            if (!$changes) {
                $done('No changes', 'Access was already set that way.', 'info');
            }
            $lines = [];
            foreach ($changes as $role => $mods) {
                foreach ($mods as $mod => [$old, $new]) {
                    $lines[] = sprintf('%s · %s: %s → %s', $role, AccessControl::MODULES[$mod]['label'] ?? $mod,
                        AccessControl::LEVEL_LABELS[$old], AccessControl::LEVEL_LABELS[$new]);
                }
            }
            $audit('ACCESS_MATRIX_UPDATED', implode('; ', $lines));
            $n = count($lines);
            $done('Access updated', $n . ($n === 1 ? ' setting' : ' settings') . ' changed. People see the change on their next page load.');

        case 'add_role':
            $name = trim((string) ($_POST['name'] ?? ''));
            $copy = (string) ($_POST['copy_from'] ?? '');
            if ($copy !== '' && !in_array($copy, $ac->roles(false), true)) $copy = '';
            $ac->addRole($name, (string) ($_POST['description'] ?? ''), $copy);
            $audit('ACCESS_ROLE_ADDED', "Added role {$name}" . ($copy ? " (copied from {$copy})" : ''));
            $done('Role added', "“{$name}” is ready. Adjust its access in the table, then assign it to people.");

        case 'delete_role':
            $role = (string) ($_POST['role'] ?? '');
            if (!$ac->isCustomRole($role)) {
                $done('Not deleted', 'Built-in roles can be reset but not deleted.', 'error');
            }
            $mv = $pdo->prepare("UPDATE doctors SET role = 'Staff' WHERE role = ? AND (tenant_id = ? OR tenant_id IS NULL)");
            $mv->execute([$role, $tenantId]);
            $moved = $mv->rowCount();
            $ac->deleteRole($role);
            $audit('ACCESS_ROLE_DELETED', "Deleted role {$role}; {$moved} account(s) moved to Staff");
            $done('Role deleted', $moved ? "{$moved} " . ($moved === 1 ? 'person was' : 'people were') . ' moved to Staff. Give them a new role below.' : "“{$role}” has been removed.");

        case 'reset_role':
            $role = (string) ($_POST['role'] ?? '');
            if (!isset(AccessControl::DEFAULT_MATRIX[$role])) {
                $done('Not reset', 'Only built-in roles have defaults to go back to.', 'error');
            }
            $ac->resetRole($role);
            $audit('ACCESS_ROLE_RESET', "Reset {$role} to default access");
            $done('Role reset', "{$role} is back to its original access.");

        case 'assign_role':
            $userId = (string) ($_POST['user_id'] ?? '');
            $role = trim((string) ($_POST['role'] ?? ''));
            if (!in_array($role, $ac->roles($me === 'Developer'), true)) {
                $done('Role not changed', "“{$role}” isn't a role at this clinic.", 'error');
            }
            if ($userId === ($_SESSION['user_id'] ?? '')) {
                $done('Role not changed', "You can't change your own role. Ask another administrator.", 'error');
            }
            $st = $pdo->prepare("SELECT id, name, role FROM doctors WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)");
            $st->execute([$userId, $tenantId]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $done('Role not changed', 'That account belongs to another clinic or was removed.', 'error');
            }
            $oldRole = AccessControl::normaliseRole((string) $user['role']);
            if ($oldRole === 'Developer' && $me !== 'Developer') {
                $done('Role not changed', 'Only a developer can change a developer account.', 'error');
            }
            if (in_array($oldRole, AccessControl::LOCKED_ROLES, true) && !in_array($role, AccessControl::LOCKED_ROLES, true)) {
                $c = $pdo->prepare("SELECT role FROM doctors WHERE (tenant_id = ? OR tenant_id IS NULL) AND is_active = 1 AND id <> ?");
                $c->execute([$tenantId, $userId]);
                $others = array_filter($c->fetchAll(PDO::FETCH_COLUMN), fn($r) => in_array(AccessControl::normaliseRole((string) $r), AccessControl::LOCKED_ROLES, true));
                if (!$others) {
                    $done('Role not changed', "{$user['name']} is the only administrator. Make someone else an administrator first.", 'error');
                }
            }
            $pdo->prepare("UPDATE doctors SET role = ? WHERE id = ?")->execute([$role, $userId]);
            $audit('ACCESS_ROLE_ASSIGNED', "{$user['name']}: {$oldRole} → {$role}");
            $done('Role changed', "{$user['name']} is now {$role}. It applies from their next page load.");

        default:
            $done('Nothing to do', 'Unknown request.', 'error');
    }
} catch (\InvalidArgumentException $e) {
    $done('Not saved', $e->getMessage(), 'error');
} catch (\Throwable $e) {
    error_log('[access_save] ' . $e->getMessage());
    $done('Not saved', 'Something went wrong while saving. Nothing was changed.', 'error');
}
