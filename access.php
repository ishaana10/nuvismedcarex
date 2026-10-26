<?php
/**
 * Administration → Access & roles
 * Decide, per clinic, what each role can do in each area of the app.
 */
use ClinicFlow\Services\AccessControl;

$pageTitle = "Access & roles - NuvisMedcareX";
$activePage = "admin";
$pageAccess = ['users', 'view'];
include __DIR__ . '/includes/header.php';

$ac = nxAccess();
$me = nxCurrentRole();
$canManage = nxCan('users', 'full');
$isDeveloper = $me === 'Developer';
$roles = $ac->roles(false);
$matrix = $ac->matrix($roles);
$tenantIdAc = \ClinicFlow\Shared\TenantContext::getTenantId();

// Staff in this clinic, grouped by role
$staff = [];
try {
    $st = $pdo->prepare("SELECT id, name, email, role, specialty, is_active, avatar FROM doctors WHERE (tenant_id = ? OR tenant_id IS NULL) ORDER BY is_active DESC, name ASC");
    $st->execute([$tenantIdAc]);
    $staff = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    error_log('[access] staff: ' . $e->getMessage());
}
$countByRole = [];
foreach ($staff as $u) {
    if ((int) $u['is_active'] !== 1) continue;
    $r = AccessControl::normaliseRole((string) $u['role']);
    $countByRole[$r] = ($countByRole[$r] ?? 0) + 1;
}
$assignableRoles = $ac->roles($isDeveloper);
$levelTone = [
    0 => 'bg-slate-100 text-slate-500 border-slate-200',
    1 => 'bg-sky-50 text-sky-800 border-sky-200',
    2 => 'bg-blue-50 text-blue-800 border-blue-200',
    3 => 'bg-emerald-50 text-emerald-800 border-emerald-200',
];
$levelHelp = [
    0 => 'Hidden from the menu; links and actions are blocked.',
    1 => 'Can open and read records, print and email documents.',
    2 => 'Can also create and change records.',
    3 => 'Can also delete and cancel records.',
];
$initials = static function (string $name): string {
    $name = preg_replace('/^(Dr\.?|Mr\.?|Mrs\.?|Ms\.?)\s+/i', '', trim($name));
    $p = preg_split('/\s+/', $name) ?: [];
    return mb_strtoupper(mb_substr($p[0] ?? '?', 0, 1) . mb_substr($p[1] ?? '', 0, 1));
};
$csrf = htmlspecialchars(getCsrfToken());
?>

<div class="mb-4">
    <h1 class="nx-page-title">Administration</h1>
    <p class="nx-page-sub">Staff accounts, what each role can do, clinic details and printed documents</p>
</div>
<?php $adminSection = 'access'; include __DIR__ . '/includes/admin_nav.php'; ?>

<div class="grid grid-cols-1 2xl:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
<div class="space-y-6 min-w-0">

    <!-- Permission matrix -->
    <form action="actions/access_save.php" method="POST" class="bg-white rounded-2xl border border-slate-200 shadow-2xs" id="access-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="save_matrix">

        <div class="flex flex-col md:flex-row md:items-start justify-between gap-4 px-5 pt-5 pb-4">
            <div class="max-w-2xl">
                <h2 class="text-base font-semibold text-slate-900">What each role can do</h2>
                <p class="text-[13px] text-slate-500 mt-1">
                    These settings apply to <span class="font-medium text-slate-700"><?= htmlspecialchars($activeClinicName) ?></span> only.
                    Changes take effect the next time someone opens a page.
                </p>
            </div>
            <?php if ($canManage): ?>
                <div class="flex items-center gap-2 shrink-0">
                    <span class="text-xs text-slate-500 hidden" data-unsaved>Unsaved changes</span>
                    <button type="submit" class="btn-primary" data-save-btn disabled>Save changes</button>
                </div>
            <?php endif; ?>
        </div>

        <div class="relative overflow-x-auto border-t border-slate-200">
            <table class="w-full text-left text-[13px]">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 align-bottom">
                        <th class="py-3 pl-5 pr-3 font-semibold text-slate-500 text-xs min-w-[220px] sticky left-0 bg-slate-50 z-10">Area</th>
                        <?php foreach ($roles as $role): $isCustom = $ac->isCustomRole($role); ?>
                            <th class="py-3 px-2 min-w-[132px]">
                                <div class="text-[13px] font-semibold text-slate-900 flex items-center gap-1">
                                    <?= htmlspecialchars($role) ?>
                                    <?php if ($isCustom): ?><span class="text-[10px] font-medium text-slate-500 bg-slate-100 rounded px-1">Custom</span><?php endif; ?>
                                </div>
                                <div class="text-[11px] font-normal text-slate-500"><?= (int) ($countByRole[$role] ?? 0) ?> active <?= ($countByRole[$role] ?? 0) === 1 ? 'person' : 'people' ?></div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach (AccessControl::MODULES as $mod => $meta):
                        if ($mod === 'developer') continue; ?>
                        <tr class="hover:bg-slate-50/60">
                            <th scope="row" class="py-3 pl-5 pr-3 font-normal sticky left-0 bg-white z-10">
                                <div class="font-semibold text-slate-900"><?= htmlspecialchars($meta['label']) ?></div>
                                <div class="text-[11px] text-slate-500 leading-snug max-w-[240px]"><?= htmlspecialchars($meta['help']) ?></div>
                            </th>
                            <?php foreach ($roles as $role):
                                $lvl = $matrix[$role][$mod];
                                $locked = in_array($role, AccessControl::LOCKED_ROLES, true) && $mod === 'users'; ?>
                                <td class="py-2.5 px-2">
                                    <?php if ($locked): ?>
                                        <span class="inline-flex items-center gap-1 h-9 px-2.5 rounded-lg border text-xs font-semibold <?= $levelTone[3] ?>" title="Administrators always keep full control of staff and access, so the clinic can't be locked out.">
                                            <span class="material-symbols-outlined !text-[15px]">lock</span> Full
                                        </span>
                                    <?php else: ?>
                                        <label class="sr-only" for="m-<?= md5($role . $mod) ?>"><?= htmlspecialchars($role . ' — ' . $meta['label']) ?></label>
                                        <select id="m-<?= md5($role . $mod) ?>" name="matrix[<?= htmlspecialchars($role) ?>][<?= $mod ?>]" data-level-select data-initial="<?= $lvl ?>"
                                                class="h-9 w-full rounded-lg border text-xs font-semibold py-0 pl-2.5 pr-7 focus:ring-2 focus:ring-blue-500/30 <?= $levelTone[$lvl] ?>" <?= $canManage ? '' : 'disabled' ?>>
                                            <?php foreach (AccessControl::LEVEL_LABELS as $v => $lab): ?>
                                                <option value="<?= $v ?>" <?= $v === $lvl ? 'selected' : '' ?>><?= $lab ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="px-5 py-4 border-t border-slate-200 grid sm:grid-cols-2 xl:grid-cols-4 gap-3">
            <?php foreach (AccessControl::LEVEL_LABELS as $v => $lab): ?>
                <div class="flex items-start gap-2">
                    <span class="mt-0.5 shrink-0 inline-flex items-center h-6 px-2 rounded-md border text-[11px] font-semibold <?= $levelTone[$v] ?>"><?= $lab ?></span>
                    <span class="text-xs text-slate-600 leading-snug"><?= $levelHelp[$v] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </form>

    <!-- Staff by role -->
    <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs" aria-labelledby="staff-roles-title">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-5 pt-5 pb-3">
            <div>
                <h2 id="staff-roles-title" class="text-base font-semibold text-slate-900">Who has which role</h2>
                <p class="text-[13px] text-slate-500 mt-0.5">Change a person's role here. Edit their details on the <a href="admin.php?tab=users" class="text-blue-700 hover:underline">Staff</a> tab.</p>
            </div>
            <div class="relative sm:w-64">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-[18px]">search</span>
                <input type="search" placeholder="Find a person" data-nx-filter="#staff-role-table" class="form-input !pl-9 !py-2" aria-label="Find a person">
            </div>
        </div>
        <div class="relative overflow-x-auto">
            <table class="w-full text-left text-[13px]" id="staff-role-table">
                <thead>
                    <tr class="border-y border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500">
                        <th class="py-2 pl-5 pr-3">Person</th>
                        <th class="py-2 px-3 hidden md:table-cell">Specialty</th>
                        <th class="py-2 px-3">Status</th>
                        <th class="py-2 pl-3 pr-5 w-[220px]">Role</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($staff as $u):
                        $uRole = AccessControl::normaliseRole((string) $u['role']);
                        $isSelf = ($u['id'] ?? '') === ($_SESSION['user_id'] ?? '');
                        $editable = $canManage && !$isSelf && ($uRole !== 'Developer' || $isDeveloper); ?>
                        <tr>
                            <td class="py-2.5 pl-5 pr-3">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <span class="nx-avatar w-8 h-8 text-[11px]"><?= htmlspecialchars($initials((string) $u['name'])) ?></span>
                                    <div class="min-w-0">
                                        <div class="font-semibold text-slate-900 truncate"><?= htmlspecialchars($u['name']) ?><?= $isSelf ? ' <span class="text-[11px] font-normal text-slate-500">(you)</span>' : '' ?></div>
                                        <div class="text-[11px] text-slate-500 truncate"><?= htmlspecialchars($u['email'] ?? '') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-2.5 px-3 text-slate-600 hidden md:table-cell"><?= htmlspecialchars($u['specialty'] ?? '') ?></td>
                            <td class="py-2.5 px-3">
                                <?php if ((int) $u['is_active'] === 1): ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs text-emerald-700"><span class="nx-dot bg-emerald-500"></span>Active</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs text-slate-500"><span class="nx-dot bg-slate-300"></span>Deactivated</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 pl-3 pr-5">
                                <?php if ($editable): ?>
                                    <form action="actions/access_save.php" method="POST" class="flex items-center gap-1.5">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="assign_role">
                                        <input type="hidden" name="user_id" value="<?= htmlspecialchars($u['id']) ?>">
                                        <label class="sr-only" for="role-<?= htmlspecialchars($u['id']) ?>">Role for <?= htmlspecialchars($u['name']) ?></label>
                                        <select id="role-<?= htmlspecialchars($u['id']) ?>" name="role" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()" class="h-9 w-full rounded-lg border-slate-200 bg-white text-[13px] py-0 pl-2.5 pr-8 focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500">
                                            <?php foreach ($assignableRoles as $r): ?>
                                                <option value="<?= htmlspecialchars($r) ?>" <?= $r === $uRole ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                                            <?php endforeach; ?>
                                            <?php if (!in_array($uRole, $assignableRoles, true)): ?>
                                                <option value="<?= htmlspecialchars($uRole) ?>" selected><?= htmlspecialchars($uRole) ?> (no longer defined)</option>
                                            <?php endif; ?>
                                        </select>
                                    </form>
                                <?php else: ?>
                                    <span class="inline-flex items-center h-9 text-[13px] text-slate-700" title="<?= $isSelf ? "You can't change your own role." : '' ?>"><?= htmlspecialchars($uRole) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$staff): ?>
                        <tr data-nx-empty-row><td colspan="4" class="px-5"><div class="nx-empty"><span class="material-symbols-outlined">group_off</span>No staff accounts yet.</div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<!-- Side column: roles -->
<aside class="space-y-6 min-w-0">
    <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5" aria-labelledby="roles-title">
        <h2 id="roles-title" class="text-base font-semibold text-slate-900">Roles</h2>
        <ul class="mt-3 divide-y divide-slate-100">
            <?php foreach ($roles as $role):
                $isCustom = $ac->isCustomRole($role);
                $n = (int) ($countByRole[$role] ?? 0); ?>
                <li class="py-3 flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-[13px] font-semibold text-slate-900"><?= htmlspecialchars($role) ?></div>
                        <div class="text-xs text-slate-500"><?= htmlspecialchars($ac->describe($role) ?: 'Custom role') ?></div>
                    </div>
                    <?php if ($canManage): ?>
                        <?php if ($isCustom): ?>
                            <form action="actions/access_save.php" method="POST" data-nx-confirm="<?= $n ? "Delete the {$role} role? The {$n} " . ($n === 1 ? 'person' : 'people') . " with this role will be moved to Staff (read-only) until you choose another role." : "Delete the {$role} role?" ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="delete_role">
                                <input type="hidden" name="role" value="<?= htmlspecialchars($role) ?>">
                                <button type="submit" class="nx-icon-btn !w-8 !h-8 hover:!text-red-600" aria-label="Delete role <?= htmlspecialchars($role) ?>" title="Delete role"><span class="material-symbols-outlined text-[18px]">delete</span></button>
                            </form>
                        <?php else: ?>
                            <form action="actions/access_save.php" method="POST" data-nx-confirm="Put the <?= htmlspecialchars($role) ?> role back to its original access?" data-nx-no-lock>
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="reset_role">
                                <input type="hidden" name="role" value="<?= htmlspecialchars($role) ?>">
                                <button type="submit" class="nx-icon-btn !w-8 !h-8" aria-label="Reset <?= htmlspecialchars($role) ?> to defaults" title="Reset to defaults"><span class="material-symbols-outlined text-[18px]">restart_alt</span></button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($canManage): ?>
            <form action="actions/access_save.php" method="POST" class="mt-4 pt-4 border-t border-slate-200 space-y-3">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="add_role">
                <h3 class="text-[13px] font-semibold text-slate-900">Add a role</h3>
                <div>
                    <label class="form-label" for="new-role-name">Name</label>
                    <input id="new-role-name" name="name" required maxlength="40" class="form-input" placeholder="e.g. Pharmacist, Lab technician">
                </div>
                <div>
                    <label class="form-label" for="new-role-desc">What they do <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="new-role-desc" name="description" maxlength="120" class="form-input" placeholder="e.g. Dispenses medicines and manages stock">
                </div>
                <div>
                    <label class="form-label" for="new-role-copy">Start with the same access as</label>
                    <select id="new-role-copy" name="copy_from" class="form-select">
                        <option value="">View patients and the schedule only</option>
                        <?php foreach ($roles as $r): ?><option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-secondary w-full">Add role</button>
            </form>
        <?php else: ?>
            <p class="mt-4 pt-4 border-t border-slate-200 text-xs text-slate-500">You can view these settings. Changing them needs <span class="font-medium text-slate-700">Full</span> access to Staff &amp; access.</p>
        <?php endif; ?>
    </section>
</aside>
</div>

<script>
(function () {
    var tones = <?= json_encode($levelTone) ?>;
    var form = document.getElementById('access-form');
    var saveBtn = form.querySelector('[data-save-btn]');
    var note = form.querySelector('[data-unsaved]');
    var selects = Array.prototype.slice.call(form.querySelectorAll('[data-level-select]'));
    function paint(sel) {
        Object.keys(tones).forEach(function (k) { tones[k].split(' ').forEach(function (c) { sel.classList.remove(c); }); });
        tones[sel.value].split(' ').forEach(function (c) { sel.classList.add(c); });
    }
    function dirty() {
        var n = selects.filter(function (s) { return s.value !== s.getAttribute('data-initial'); }).length;
        if (saveBtn) { saveBtn.disabled = n === 0; saveBtn.textContent = n ? 'Save ' + n + (n === 1 ? ' change' : ' changes') : 'Save changes'; }
        if (note) note.classList.toggle('hidden', n === 0);
        return n;
    }
    selects.forEach(function (s) { s.addEventListener('change', function () { paint(s); dirty(); }); });
    window.addEventListener('beforeunload', function (e) { if (dirty() && !form.dataset.submitting) { e.preventDefault(); e.returnValue = ''; } });
    form.addEventListener('submit', function () { form.dataset.submitting = '1'; });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
