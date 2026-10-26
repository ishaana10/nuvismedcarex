<?php
/**
 * Administration sub-navigation shared by admin.php, access.php and receipt_settings.php.
 * $adminSection: users | access | clinic | receipts | email | inventory | audit
 */
$adminSection = $adminSection ?? 'users';
$adminTabs = [
    'users'     => ['Staff',           'group',           'admin.php?tab=users',     ['users', 'view']],
    'access'    => ['Access & roles',  'shield_person',   'access.php',              ['users', 'view']],
    'clinic'    => ['Clinic & branding', 'domain',        'admin.php?tab=clinic',    ['settings', 'view']],
    'receipts'  => ['Receipts',        'receipt',         'receipt_settings.php',    ['settings', 'view']],
    'email'     => ['Email',           'mail',            'admin.php?tab=email',     ['settings', 'view']],
    'inventory' => ['Inventory setup', 'inventory_2',     'admin.php?tab=inventory', ['settings', 'view']],
    'audit'     => ['Audit log',       'history',         'admin.php?tab=audit',     ['users', 'view']],
];
?>
<nav class="mb-6 -mx-1 overflow-x-auto" aria-label="Administration sections">
    <ul class="flex gap-1 px-1 border-b border-slate-200 min-w-max">
        <?php foreach ($adminTabs as $key => [$label, $icon, $url, $need]):
            if (!nxCan($need[0], $need[1])) continue;
            $on = $adminSection === $key; ?>
            <li>
                <a href="<?= $url ?>" <?= $on ? 'aria-current="page"' : '' ?>
                   class="relative inline-flex items-center gap-2 px-3 py-2.5 text-[13px] font-medium transition <?= $on ? 'text-blue-800' : 'text-slate-500 hover:text-slate-900' ?>">
                    <span class="material-symbols-outlined text-[18px]"><?= $icon ?></span><?= htmlspecialchars($label) ?>
                    <?php if ($on): ?><span class="absolute left-2 right-2 -bottom-px h-0.5 rounded-full bg-blue-700"></span><?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
