<?php
/**
 * Administration → Appearance: brand colour, defaults for staff, menu items and a
 * dashboard notice, all for this clinic only.
 */
use ClinicFlow\Services\AppearanceSettings as Appearance;
use ClinicFlow\Services\UserPreferences;

$pageTitle = "Appearance - NuvisMedcareX";
$activePage = "admin";
$pageAccess = ['settings', 'view'];
include __DIR__ . '/includes/header.php';

$cfg = Appearance::load(nxSettings());
$canEdit = nxCan('settings', 'edit');
$dis = $canEdit ? '' : 'disabled';
$csrf = htmlspecialchars(getCsrfToken());
$isPreset = isset(Appearance::ACCENTS[$cfg['accent']]);
?>

<div class="mb-4">
    <h1 class="nx-page-title">Administration</h1>
    <p class="nx-page-sub">Staff accounts, what each role can do, clinic details and printed documents</p>
</div>
<?php $adminSection = 'appearance'; include __DIR__ . '/includes/admin_nav.php'; ?>

<div class="flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-[13px] text-blue-900 mb-5">
    <span class="material-symbols-outlined text-[20px]">info</span>
    <p>These choices belong to <strong><?= htmlspecialchars(nxClinicName()) ?></strong>. Each person can still set their own theme and display options under Settings; the defaults below apply to anyone who has not.</p>
</div>

<form action="actions/appearance_save.php" method="POST" class="space-y-5 max-w-3xl" id="appearance-form">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

    <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Brand colour</h2>
            <p class="text-[13px] text-slate-500 mt-0.5">Used for buttons, links, highlights and the active menu item, in light and dark mode.</p>
        </div>
        <div class="flex flex-wrap gap-3" role="radiogroup" aria-label="Brand colour">
            <?php foreach (Appearance::ACCENTS as $hex => $name): ?>
                <label class="cursor-pointer">
                    <input type="radio" name="accent_choice" value="<?= $hex ?>" class="sr-only peer" data-accent <?= $cfg['accent'] === $hex ? 'checked' : '' ?> <?= $dis ?>>
                    <span class="flex flex-col items-center gap-1.5 w-20 rounded-xl border border-slate-200 p-2 peer-checked:ring-2 peer-checked:ring-blue-600 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-600">
                        <span class="w-9 h-9 rounded-full border border-black/10" data-css="background: <?= $hex ?>"></span>
                        <span class="text-[11px] font-medium text-slate-700 text-center leading-tight"><?= htmlspecialchars($name) ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="flex items-center gap-3">
            <label for="ap-custom" class="form-label !mb-0">Or choose your own</label>
            <input id="ap-custom" type="color" name="accent" value="<?= htmlspecialchars($cfg['accent']) ?>" class="h-10 w-14 rounded-lg border border-slate-300 bg-white p-1" <?= $dis ?>>
            <span class="text-xs text-slate-500">A colour too light for white text is darkened automatically.</span>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 flex flex-wrap items-center gap-3" aria-label="Preview">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Preview</span>
            <span id="ap-prev-btn" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold text-white" data-css="background: <?= htmlspecialchars($cfg['accent']) ?>">Register patient</span>
            <span id="ap-prev-link" class="text-sm font-semibold underline" data-css="color: <?= htmlspecialchars($cfg['accent']) ?>">Open schedule</span>
        </div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Defaults for staff</h2>
            <p class="text-[13px] text-slate-500 mt-0.5">Starting point for new accounts and anyone who has not changed these in their own Settings.</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="form-label" for="ap-theme">Theme</label>
                <select id="ap-theme" name="theme" class="form-select" <?= $dis ?>>
                    <?php foreach (UserPreferences::THEMES as $k => $label): ?><option value="<?= $k ?>" <?= $cfg['theme'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="ap-density">Spacing</label>
                <select id="ap-density" name="density" class="form-select" <?= $dis ?>>
                    <?php foreach (UserPreferences::DENSITIES as $k => $label): ?><option value="<?= $k ?>" <?= $cfg['density'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="ap-date">Date format</label>
                <select id="ap-date" name="date_format" class="form-select" <?= $dis ?>>
                    <?php foreach (UserPreferences::DATE_FORMATS as $k => $label): ?><option value="<?= htmlspecialchars($k) ?>" <?= $cfg['date_format'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="ap-time">Time format</label>
                <select id="ap-time" name="time_format" class="form-select" <?= $dis ?>>
                    <?php foreach (UserPreferences::TIME_FORMATS as $k => $label): ?><option value="<?= htmlspecialchars($k) ?>" <?= $cfg['time_format'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="ap-landing">First page after signing in</label>
                <select id="ap-landing" name="landing_page" class="form-select" <?= $dis ?>>
                    <?php foreach (UserPreferences::LANDING as $k => $label): ?><option value="<?= htmlspecialchars($k) ?>" <?= $cfg['landing_page'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="ap-sidebar">Side menu</label>
                <select id="ap-sidebar" name="sidebar" class="form-select" <?= $dis ?>>
                    <?php foreach (Appearance::SIDEBARS as $k => $label): ?><option value="<?= $k ?>" <?= $cfg['sidebar'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-3">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Menu</h2>
            <p class="text-[13px] text-slate-500 mt-0.5">Untick what this clinic does not use to keep the menu short. This only tidies the menu; what each role is allowed to open is still set under Access &amp; roles.</p>
        </div>
        <?php foreach (Appearance::OPTIONAL_NAV as $id => $label): ?>
            <label class="flex items-center gap-3 text-sm text-slate-800">
                <input type="checkbox" name="hidden_nav[]" value="<?= $id ?>" <?= in_array($id, $cfg['hidden_nav'], true) ? 'checked' : '' ?> <?= $dis ?> class="rounded border-slate-300">
                <span>Hide <strong><?= htmlspecialchars($label) ?></strong> from the menu</span>
            </label>
        <?php endforeach; ?>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-3">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Dashboard notice</h2>
            <p class="text-[13px] text-slate-500 mt-0.5">One short message every staff member sees at the top of the dashboard, such as opening hours or a closure. Leave empty for none.</p>
        </div>
        <label class="sr-only" for="ap-note">Notice</label>
        <textarea id="ap-note" name="announcement" rows="2" maxlength="<?= Appearance::ANNOUNCEMENT_MAX ?>" class="form-input" placeholder="e.g. Closed Friday 12 December for staff training" <?= $dis ?>><?= htmlspecialchars($cfg['announcement']) ?></textarea>
    </section>

    <?php if ($canEdit): ?>
    <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary"><span class="material-symbols-outlined text-[18px]">save</span> Save appearance</button>
        <button type="submit" name="reset" value="1" class="btn-secondary" data-nx-confirm="Reset this clinic to the standard colour, defaults and menu?">
            <span class="material-symbols-outlined text-[18px]">restart_alt</span> Reset to standard
        </button>
    </div>
    <?php endif; ?>
</form>

<script nonce="<?= nxCspNonce() ?>">
(function () {
    var custom = document.getElementById('ap-custom');
    var btn = document.getElementById('ap-prev-btn'), link = document.getElementById('ap-prev-link');
    function show(hex) { btn.style.background = hex; link.style.color = hex; }
    document.querySelectorAll('[data-accent]').forEach(function (r) {
        r.addEventListener('change', function () { custom.value = r.value; show(r.value); });
    });
    custom.addEventListener('input', function () {
        document.querySelectorAll('[data-accent]').forEach(function (r) { r.checked = false; });
        show(custom.value);
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
