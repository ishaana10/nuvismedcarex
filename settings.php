<?php
/**
 * Settings: preferences belonging to the person signed in.
 *
 * Clinic-wide set-up — branding, printed documents, email, inventory, staff
 * and roles — lives in Administration. Nothing here affects anyone else.
 */
$pageTitle = "Settings - NuvisMedcareX";
$activePage = "settings";
include __DIR__ . '/includes/header.php';

use ClinicFlow\Services\UserPreferences;

$prefs = nxPrefs();
$userId = $_SESSION['user_id'] ?? '';
$csrf = htmlspecialchars(getCsrfToken());

$me = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, specialty, email, role, avatar, esignature, digital_stamp, mfa_enabled FROM doctors WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    error_log('[settings] profile: ' . $e->getMessage());
}

$tabs = [
    'profile'    => ['Your profile', 'badge'],
    'appearance' => ['Appearance', 'palette'],
    'alerts'     => ['Alerts', 'notifications'],
    'security'   => ['Sign-in & security', 'lock'],
    'plan'       => ['Clinic plan', 'workspace_premium'],
];
$tab = $_GET['tab'] ?? 'profile';
if (!isset($tabs[$tab])) $tab = 'profile';

$clinicDefaultPageSize = (int) nxSetting('default_pagination_limit', 10);
?>

<div class="mb-4">
    <h1 class="nx-page-title">Settings</h1>
    <p class="nx-page-sub">Your own preferences. Clinic-wide set-up is in <a href="admin.php" class="text-blue-700 hover:underline">Administration</a>.</p>
</div>

<nav class="mb-6 -mx-1 overflow-x-auto" aria-label="Settings sections">
    <ul class="flex gap-1 px-1 border-b border-slate-200 min-w-max">
        <?php foreach ($tabs as $key => [$label, $icon]): $on = $tab === $key; ?>
            <li>
                <a href="settings.php?tab=<?= $key ?>" <?= $on ? 'aria-current="page"' : '' ?>
                   class="relative inline-flex items-center gap-2 px-3 py-2.5 text-[13px] font-medium transition <?= $on ? 'text-blue-800' : 'text-slate-500 hover:text-slate-900' ?>">
                    <span class="material-symbols-outlined text-[18px]"><?= $icon ?></span><?= htmlspecialchars($label) ?>
                    <?php if ($on): ?><span class="absolute left-2 right-2 -bottom-px h-0.5 rounded-full bg-blue-700"></span><?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>

<?php if ($tab === 'profile'): ?>
    <form action="actions/profile_save.php" method="POST" enctype="multipart/form-data" class="space-y-6 max-w-3xl">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <div>
                <h2 class="text-base font-semibold text-slate-900">How you appear</h2>
                <p class="text-[13px] text-slate-500 mt-0.5">Your name and title show on the records you create and the documents you sign.</p>
            </div>

            <div class="flex flex-col sm:flex-row gap-5">
                <div class="shrink-0">
                    <span class="form-label">Photo</span>
                    <div class="w-24 h-24 rounded-full border border-slate-200 bg-slate-50 overflow-hidden flex items-center justify-center" id="avatar-thumb">
                        <?php if (!empty($me['avatar'])): ?>
                            <img src="<?= htmlspecialchars($me['avatar']) ?>" alt="" class="w-full h-full object-cover">
                        <?php else: ?>
                            <span class="text-lg font-semibold text-slate-400"><?= htmlspecialchars(nxInitials($me['name'] ?? '?')) ?></span>
                        <?php endif; ?>
                    </div>
                    <label class="btn-secondary !py-1.5 !px-3 mt-2 w-full cursor-pointer !text-xs">
                        <input type="file" name="avatar_file" id="avatar-file" accept="image/png,image/jpeg,image/webp" class="sr-only">
                        Change photo
                    </label>
                </div>

                <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-4 min-w-0">
                    <div class="md:col-span-2">
                        <label class="form-label" for="p-name">Full name</label>
                        <input id="p-name" type="text" name="name" required maxlength="120" value="<?= htmlspecialchars($me['name'] ?? '') ?>" class="form-input">
                    </div>
                    <div>
                        <label class="form-label" for="p-specialty">Job title or specialty</label>
                        <input id="p-specialty" type="text" name="specialty" maxlength="120" value="<?= htmlspecialchars($me['specialty'] ?? '') ?>" placeholder="e.g. Practice Nurse" class="form-input">
                    </div>
                    <div>
                        <label class="form-label" for="p-email">Email</label>
                        <input id="p-email" type="email" name="email" maxlength="160" value="<?= htmlspecialchars($me['email'] ?? '') ?>" class="form-input">
                        <p class="text-xs text-slate-500 mt-1">You sign in with this address.</p>
                    </div>
                    <div class="md:col-span-2">
                        <span class="form-label">Role</span>
                        <p class="text-[13px] text-slate-600"><?= htmlspecialchars($me['role'] ?? 'Staff') ?> — <span class="text-slate-500">only an administrator can change this</span></p>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Signature and stamp</h2>
                <p class="text-[13px] text-slate-500 mt-0.5">Printed on the prescriptions, certificates and receipts you issue. PNG with a transparent background works best.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <?php foreach ([['esignature', 'Signature', 'sig'], ['digital_stamp', 'Clinic stamp', 'stamp']] as [$field, $label, $id]): ?>
                    <div>
                        <span class="form-label"><?= $label ?></span>
                        <div class="h-24 rounded-xl border border-slate-200 bg-slate-50 flex items-center justify-center overflow-hidden p-2" id="<?= $id ?>-thumb">
                            <?php if (!empty($me[$field])): ?>
                                <img src="<?= htmlspecialchars($me[$field]) ?>" alt="Current <?= strtolower($label) ?>" class="max-h-full max-w-full object-contain">
                            <?php else: ?>
                                <span class="text-xs text-slate-400">Not set</span>
                            <?php endif; ?>
                        </div>
                        <label class="btn-secondary !py-1.5 !px-3 mt-2 w-full cursor-pointer !text-xs">
                            <input type="file" name="<?= $field ?>_file" id="<?= $id ?>-file" accept="image/png,image/jpeg,image/webp" class="sr-only" data-preview="<?= $id ?>-thumb">
                            Upload
                        </label>
                        <?php if (!empty($me[$field])): ?>
                            <label class="flex items-center gap-1.5 mt-2 text-xs text-slate-600">
                                <input type="checkbox" name="remove_<?= $field ?>" value="1" class="rounded border-slate-300 text-blue-700 focus:ring-blue-500/30">
                                Remove
                            </label>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="sticky bottom-3 z-10 flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white/95 backdrop-blur px-4 py-3 shadow-pop">
            <span class="text-[13px] text-slate-500">Only you can see this page</span>
            <button type="submit" class="btn-primary">Save profile</button>
        </div>
    </form>

<?php elseif ($tab === 'appearance'): ?>
    <form action="actions/preferences_save.php" method="POST" class="space-y-6 max-w-3xl" id="prefs-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="section" value="appearance">

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-5">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Theme</h2>
                <p class="text-[13px] text-slate-500 mt-0.5">Applies on every device you sign in from.</p>
            </div>
            <fieldset class="grid grid-cols-3 gap-2 max-w-md">
                <legend class="sr-only">Theme</legend>
                <?php foreach (UserPreferences::THEMES as $key => $label): ?>
                    <label class="relative cursor-pointer">
                        <input type="radio" name="theme" value="<?= $key ?>" class="peer sr-only" data-theme-choice <?= $prefs['theme'] === $key ? 'checked' : '' ?>>
                        <span class="block rounded-xl border border-slate-200 px-3 py-2.5 text-[13px] text-center text-slate-700 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-900 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 hover:border-slate-300"><?= htmlspecialchars($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label" for="pr-density">Spacing</label>
                    <select id="pr-density" name="density" class="form-select">
                        <?php foreach (UserPreferences::DENSITIES as $k => $label): ?>
                            <option value="<?= $k ?>" <?= $prefs['density'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Compact fits more rows on screen.</p>
                </div>
                <div>
                    <label class="form-label" for="pr-landing">Open this page after signing in</label>
                    <select id="pr-landing" name="landing_page" class="form-select">
                        <?php foreach (UserPreferences::LANDING as $url => $label): ?>
                            <option value="<?= htmlspecialchars($url) ?>" <?= $prefs['landing_page'] === $url ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="pr-page-size">Rows per page</label>
                    <select id="pr-page-size" name="page_size" class="form-select">
                        <option value="0" <?= (int) $prefs['page_size'] === 0 ? 'selected' : '' ?>>Use the clinic default (<?= $clinicDefaultPageSize ?>)</option>
                        <?php foreach (UserPreferences::PAGE_SIZES as $n): ?>
                            <option value="<?= $n ?>" <?= (int) $prefs['page_size'] === $n ? 'selected' : '' ?>><?= $n ?> rows</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="pr-date">Date format</label>
                    <select id="pr-date" name="date_format" class="form-select">
                        <?php foreach (UserPreferences::DATE_FORMATS as $fmt => $example): ?>
                            <option value="<?= htmlspecialchars($fmt) ?>" <?= $prefs['date_format'] === $fmt ? 'selected' : '' ?>><?= htmlspecialchars($example) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="pr-time">Time format</label>
                    <select id="pr-time" name="time_format" class="form-select">
                        <?php foreach (UserPreferences::TIME_FORMATS as $fmt => $example): ?>
                            <option value="<?= htmlspecialchars($fmt) ?>" <?= $prefs['time_format'] === $fmt ? 'selected' : '' ?>><?= htmlspecialchars($example) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="space-y-2 pt-1">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="sidebar_collapsed" value="1" class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500/30" <?= !empty($prefs['sidebar_collapsed']) ? 'checked' : '' ?>>
                    <span class="text-[13px] text-slate-700">Start with the sidebar collapsed
                        <span class="block text-xs text-slate-500 mt-0.5">You can still expand it with <kbd>[</kbd>.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="show_help_hints" value="1" class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500/30" <?= !empty($prefs['show_help_hints']) ? 'checked' : '' ?>>
                    <span class="text-[13px] text-slate-700">Show the hint text under form fields</span>
                </label>
            </div>
        </section>

        <div class="sticky bottom-3 z-10 flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white/95 backdrop-blur px-4 py-3 shadow-pop">
            <!-- Reset submits its own form, so it is never what Enter triggers -->
            <button type="submit" form="prefs-reset-form" class="text-[13px] text-slate-500 hover:text-slate-800 hover:underline"
                    data-nx-confirm="Put all of your preferences back to their defaults?">Reset to defaults</button>
            <button type="submit" class="btn-primary">Save preferences</button>
        </div>
    </form>

    <form action="actions/preferences_save.php" method="POST" id="prefs-reset-form" class="hidden">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="section" value="appearance">
        <input type="hidden" name="reset" value="1">
    </form>

<?php elseif ($tab === 'alerts'): ?>
    <form action="actions/preferences_save.php" method="POST" class="space-y-6 max-w-3xl">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="section" value="alerts">

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Tell me when</h2>
                <p class="text-[13px] text-slate-500 mt-0.5">Alerts appear while you have the app open. Choose which ones are useful to you.</p>
            </div>
            <?php if (nxSetting('sse_notifications_enabled', '1') !== '1'): ?>
                <p class="flex items-start gap-2 rounded-xl bg-amber-50 border border-amber-200 px-3 py-2.5 text-[13px] text-amber-900">
                    <span class="material-symbols-outlined text-[18px]">info</span>
                    <span>Live alerts are switched off for this clinic, so none of these will appear. An administrator can turn them back on in Administration → Clinic &amp; branding.</span>
                </p>
            <?php endif; ?>
            <div class="space-y-1">
                <?php foreach (UserPreferences::ALERTS as $key => [$label, $def]): ?>
                    <label class="flex items-center justify-between gap-3 py-2.5 border-b border-slate-100 last:border-0 cursor-pointer">
                        <span class="text-[13px] text-slate-700"><?= htmlspecialchars($label) ?></span>
                        <input type="checkbox" name="<?= $key ?>" value="1" class="rounded border-slate-300 text-blue-700 focus:ring-blue-500/30" <?= !empty($prefs[$key]) ? 'checked' : '' ?>>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="sticky bottom-3 z-10 flex items-center justify-end gap-3 rounded-2xl border border-slate-200 bg-white/95 backdrop-blur px-4 py-3 shadow-pop">
            <button type="submit" class="btn-primary">Save alert preferences</button>
        </div>
    </form>

<?php elseif ($tab === 'security'): ?>
    <div class="space-y-6 max-w-3xl">
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5">
            <h2 class="text-base font-semibold text-slate-900">Password</h2>
            <p class="text-[13px] text-slate-500 mt-0.5 mb-3">At least 8 characters, with one capital letter and one number.</p>
            <button type="button" onclick="openChangePasswordModal()" class="btn-secondary">Change password</button>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5">
            <h2 class="text-base font-semibold text-slate-900">This session</h2>
            <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-[13px]">
                <div><dt class="text-slate-500">Signed in as</dt><dd class="text-slate-900 font-medium"><?= htmlspecialchars($me['email'] ?? '') ?></dd></div>
                <div><dt class="text-slate-500">Role</dt><dd class="text-slate-900 font-medium"><?= htmlspecialchars($me['role'] ?? 'Staff') ?></dd></div>
                <div><dt class="text-slate-500">Clinic</dt><dd class="text-slate-900 font-medium"><?= htmlspecialchars(nxClinicName()) ?></dd></div>
                <div><dt class="text-slate-500">Last activity</dt><dd class="text-slate-900 font-medium"><?= htmlspecialchars(nxDate($_SESSION['last_activity'] ?? time(), true)) ?></dd></div>
            </dl>
            <p class="text-xs text-slate-500 mt-4">You are signed out automatically after two hours without activity.</p>
            <a href="actions/logout.php" class="btn-secondary mt-3 inline-flex">Sign out</a>
        </section>

        <?php if (nxCan('users', 'view')): ?>
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5">
            <h2 class="text-base font-semibold text-slate-900">Staff and roles</h2>
            <p class="text-[13px] text-slate-500 mt-0.5 mb-3">Who can sign in, and what each role is allowed to do.</p>
            <div class="flex flex-wrap gap-2">
                <a href="admin.php?tab=users" class="btn-secondary">Staff accounts</a>
                <a href="access.php" class="btn-secondary">Access &amp; roles</a>
                <a href="admin.php?tab=audit" class="btn-secondary">Audit log</a>
            </div>
        </section>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'plan'): ?>
    <?php
    $activeTenant = [];
    try {
        $activeTenant = \ClinicFlow\Shared\Container::getInstance()
            ->get(\ClinicFlow\Services\TenantService::class)
            ->getTenantById($currentTenantId) ?? [];
    } catch (\Throwable $e) {
        error_log('[settings] plan: ' . $e->getMessage());
    }
    $subStatus = strtolower((string) ($activeTenant['subscription_status'] ?? 'active'));
    $statusTone = $subStatus === 'active' ? 'bg-emerald-50 text-emerald-800 ring-emerald-200'
        : ($subStatus === 'trial' ? 'bg-blue-50 text-blue-800 ring-blue-200' : 'bg-amber-50 text-amber-800 ring-amber-200');
    $storageMb = (int) ($activeTenant['storage_limit_mb'] ?? 500);
    ?>
    <div class="space-y-6 max-w-3xl">
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h2 class="text-base font-semibold text-slate-900"><?= htmlspecialchars(nxClinicName()) ?></h2>
                    <p class="text-[13px] text-slate-500 mt-0.5">What this clinic's subscription includes. Read-only.</p>
                </div>
                <span class="inline-flex px-2.5 py-1 rounded-full text-[12px] font-semibold ring-1 <?= $statusTone ?>"><?= htmlspecialchars(ucfirst($subStatus)) ?></span>
            </div>

            <dl class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                    <dt class="text-[13px] text-slate-500">Plan</dt>
                    <dd class="text-lg font-semibold text-slate-900 mt-1"><?= htmlspecialchars(ucfirst((string) ($activeTenant['plan'] ?? 'Standard'))) ?></dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                    <dt class="text-[13px] text-slate-500">Cost</dt>
                    <dd class="text-lg font-semibold text-slate-900 mt-1 tabular">
                        <?= htmlspecialchars(nxCurrencySymbol()) ?><?= number_format((float) ($activeTenant['plan_price'] ?? 0), 2) ?>
                        <span class="text-[13px] font-normal text-slate-500">/ <?= htmlspecialchars((string) ($activeTenant['billing_cycle'] ?? 'monthly')) ?></span>
                    </dd>
                    <?php if (!empty($activeTenant['next_renewal_date'])): ?>
                        <dd class="text-xs text-slate-500 mt-1">Renews <?= htmlspecialchars(nxDate($activeTenant['next_renewal_date'])) ?></dd>
                    <?php endif; ?>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                    <dt class="text-[13px] text-slate-500">Document storage</dt>
                    <dd class="text-lg font-semibold text-slate-900 mt-1"><?= $storageMb >= 1024 ? round($storageMb / 1024, 1) . ' GB' : $storageMb . ' MB' ?></dd>
                    <?php
                        $nxDocDrive = (string) (\ClinicFlow\Services\Cloud\CloudConfig::get()['provider'] ?? 'local');
                        $nxDocDriveLabel = $nxDocDrive === 'local'
                            ? 'this server'
                            : (\ClinicFlow\Services\Cloud\Cloud::labels()[$nxDocDrive] ?? $nxDocDrive);
                    ?>
                    <dd class="text-xs text-slate-500 mt-1">Kept on <?= htmlspecialchars($nxDocDriveLabel) ?></dd>
                </div>
            </dl>
            <p class="text-[13px] text-slate-500 mt-4">To change the plan, contact Nuvis Technologies.</p>
        </section>
    </div>
<?php endif; ?>

<script>
(function () {
    // Preview a picked image straight away
    document.querySelectorAll('input[type=file][accept^="image"]').forEach(function (input) {
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f) return;
            if (f.size > 600 * 1024) {
                if (window.NX) NX.toast('Image too large', 'Choose a file under 600 KB.', 'error');
                input.value = '';
                return;
            }
            var target = document.getElementById(input.getAttribute('data-preview') || 'avatar-thumb');
            if (!target) return;
            var img = new Image();
            img.src = URL.createObjectURL(f);
            img.alt = 'New image';
            img.className = target.id === 'avatar-thumb' ? 'w-full h-full object-cover' : 'max-h-full max-w-full object-contain';
            target.innerHTML = '';
            target.appendChild(img);
        });
    });

    // Show the theme as soon as it is picked, so the choice is obvious
    document.querySelectorAll('[data-theme-choice]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (!radio.checked || !window.NX) return;
            NX.applyTheme(radio.value);
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
