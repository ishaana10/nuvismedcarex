<?php
/**
 * Administration → Receipts: design this clinic's payment receipt
 * with a live preview.
 */
use ClinicFlow\Services\ReceiptSettings;

$pageTitle = "Receipt design - NuvisMedcareX";
$activePage = "admin";
$pageAccess = ['settings', 'view'];
include __DIR__ . '/includes/header.php';

$rsSvc = new ReceiptSettings($pdo, \ClinicFlow\Shared\TenantContext::getTenantId());
$rc = $rsSvc->load();
$canEdit = nxCan('settings', 'edit');
$csrf = htmlspecialchars(getCsrfToken());
$defaultCurrency = $rsSvc->currencySymbol(array_merge($rc, ['currency' => '']));
$dis = $canEdit ? '' : 'disabled';
?>

<div class="mb-4">
    <h1 class="nx-page-title">Administration</h1>
    <p class="nx-page-sub">Staff accounts, what each role can do, clinic details and printed documents</p>
</div>
<?php $adminSection = 'receipts'; include __DIR__ . '/includes/admin_nav.php'; ?>

<form action="actions/receipt_settings_save.php" method="POST" enctype="multipart/form-data" id="receipt-form"
      class="grid grid-cols-1 xl:grid-cols-[minmax(0,460px)_minmax(0,1fr)] gap-6 items-start">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

    <!-- Settings -->
    <div class="space-y-5 min-w-0">
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Paper and style</h2>
                <p class="text-[13px] text-slate-500 mt-0.5">Pick the printer you use at the front desk.</p>
            </div>
            <fieldset class="grid grid-cols-2 gap-2" <?= $dis ?>>
                <legend class="sr-only">Paper size</legend>
                <?php foreach (ReceiptSettings::PAPER as $k => $label): ?>
                    <label class="relative cursor-pointer">
                        <input type="radio" name="paper" value="<?= $k ?>" class="peer sr-only" <?= $rc['paper'] === $k ? 'checked' : '' ?>>
                        <span class="flex items-center gap-2.5 rounded-xl border border-slate-200 px-3 py-2.5 text-[13px] text-slate-700 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-900 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 hover:border-slate-300">
                            <span class="material-symbols-outlined text-[20px]"><?= str_starts_with($k, 'thermal') ? 'receipt' : 'description' ?></span>
                            <?= htmlspecialchars($label) ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div>
                <span class="form-label">Accent colour</span>
                <div class="flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Accent colour">
                    <?php foreach (ReceiptSettings::ACCENTS as $hex => $name): ?>
                        <label class="relative cursor-pointer" title="<?= htmlspecialchars($name) ?>">
                            <input type="radio" name="accent_choice" value="<?= $hex ?>" class="peer sr-only" <?= strtoupper($rc['accent']) === $hex ? 'checked' : '' ?> <?= $dis ?>>
                            <span class="block w-8 h-8 rounded-full ring-2 ring-offset-2 ring-transparent peer-checked:ring-slate-900 peer-focus-visible:ring-blue-500" style="background: <?= $hex ?>"></span>
                            <span class="sr-only"><?= htmlspecialchars($name) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <label class="flex items-center gap-1.5 ml-1 text-[13px] text-slate-600">
                        <input type="color" name="accent" value="<?= htmlspecialchars($rc['accent']) ?>" class="w-8 h-8 p-0 border-0 bg-transparent cursor-pointer" <?= $dis ?>> Custom
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="form-label" for="rc-size">Text size</label>
                    <select id="rc-size" name="size" class="form-select" <?= $dis ?>>
                        <?php foreach (ReceiptSettings::SIZES as $k => $l): ?><option value="<?= $k ?>" <?= $rc['size'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="rc-date">Date format</label>
                    <select id="rc-date" name="date_format" class="form-select" <?= $dis ?>>
                        <?php foreach (ReceiptSettings::DATE_FORMATS as $k => $l): ?><option value="<?= htmlspecialchars($k) ?>" <?= $rc['date_format'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="rc-copies">Copies per print</label>
                    <select id="rc-copies" name="copies" class="form-select" <?= $dis ?>>
                        <option value="1" <?= (int) $rc['copies'] === 1 ? 'selected' : '' ?>>One</option>
                        <option value="2" <?= (int) $rc['copies'] === 2 ? 'selected' : '' ?>>Two (patient + clinic)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="rc-currency">Currency symbol</label>
                    <input id="rc-currency" name="currency" maxlength="6" value="<?= htmlspecialchars($rc['currency']) ?>" placeholder="<?= htmlspecialchars(trim($defaultCurrency)) ?>" class="form-input" <?= $dis ?>>
                </div>
            </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <h2 class="text-base font-semibold text-slate-900">Logo</h2>
            <div class="flex items-center gap-4">
                <div class="w-20 h-20 rounded-xl border border-dashed border-slate-300 flex items-center justify-center overflow-hidden bg-slate-50 shrink-0" id="logo-thumb">
                    <?php if ($rc['logo']): ?><img src="<?= htmlspecialchars($rc['logo']) ?>" alt="Current logo" class="max-w-full max-h-full object-contain"><?php else: ?><span class="text-xs text-slate-400">No logo</span><?php endif; ?>
                </div>
                <div class="space-y-2 min-w-0">
                    <input type="file" name="logo_file" id="rc-logo" accept="image/png,image/jpeg,image/webp" class="block w-full text-[13px] text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-[13px] file:font-semibold file:text-slate-800 hover:file:bg-slate-200" <?= $dis ?>>
                    <p class="text-xs text-slate-500">PNG, JPG or WebP, up to 300 KB. A wide logo on a white background prints best.</p>
                    <?php if ($rc['logo']): ?>
                        <label class="flex items-center gap-2 text-[13px] text-slate-600"><input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300 text-red-600" <?= $dis ?>> Remove the current logo</label>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <span class="form-label">Header layout</span>
                <div class="flex gap-2">
                    <?php foreach (['left' => 'Logo beside the details', 'center' => 'Centred'] as $k => $l): ?>
                        <label class="relative cursor-pointer flex-1">
                            <input type="radio" name="logo_align" value="<?= $k ?>" class="peer sr-only" <?= $rc['logo_align'] === $k ? 'checked' : '' ?> <?= $dis ?>>
                            <span class="block text-center rounded-xl border border-slate-200 px-3 py-2 text-[13px] text-slate-700 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-900 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500"><?= $l ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <h2 class="text-base font-semibold text-slate-900">Wording</h2>
            <div class="grid grid-cols-[minmax(0,1fr)_120px] gap-3">
                <div>
                    <label class="form-label" for="rc-title">Title</label>
                    <input id="rc-title" name="title" maxlength="60" value="<?= htmlspecialchars($rc['title']) ?>" class="form-input" <?= $dis ?>>
                </div>
                <div>
                    <label class="form-label" for="rc-prefix">Number prefix</label>
                    <input id="rc-prefix" name="number_prefix" maxlength="12" value="<?= htmlspecialchars($rc['number_prefix']) ?>" class="form-input font-mono" <?= $dis ?>>
                </div>
            </div>
            <div>
                <label class="form-label" for="rc-thanks">Message beside the amount</label>
                <textarea id="rc-thanks" name="thank_you" rows="2" maxlength="300" class="form-textarea" <?= $dis ?>><?= htmlspecialchars($rc['thank_you']) ?></textarea>
            </div>
            <div>
                <label class="form-label" for="rc-footer">Footer note <span class="font-normal text-slate-400">(optional)</span></label>
                <textarea id="rc-footer" name="footer_note" rows="3" maxlength="500" class="form-textarea" placeholder="e.g. Refunds within 7 days with this receipt. Bula vinaka!" <?= $dis ?>><?= htmlspecialchars($rc['footer_note']) ?></textarea>
            </div>
        </section>

        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5">
            <h2 class="text-base font-semibold text-slate-900">What to print</h2>
            <div class="mt-3 grid sm:grid-cols-2 gap-x-6 gap-y-5">
                <?php foreach (ReceiptSettings::TOGGLES as $group => $toggles): ?>
                    <fieldset <?= $dis ?>>
                        <legend class="text-xs font-semibold text-slate-500 mb-1.5"><?= htmlspecialchars($group) ?></legend>
                        <?php foreach ($toggles as $k => [$label]): ?>
                            <label class="flex items-start gap-2.5 py-1 text-[13px] text-slate-700 cursor-pointer">
                                <input type="checkbox" name="<?= $k ?>" value="1" class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-500/30" <?= !empty($rc[$k]) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if ($canEdit): ?>
            <div class="sticky bottom-3 z-10 flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white/95 backdrop-blur px-4 py-3 shadow-pop">
                <span class="text-[13px] text-slate-500" id="rc-status">All changes saved</span>
                <div class="flex gap-2">
                    <button type="button" class="btn-secondary" id="rc-reset" disabled>Undo changes</button>
                    <button type="submit" class="btn-primary" id="rc-save" disabled>Save receipt design</button>
                </div>
            </div>
        <?php else: ?>
            <p class="text-[13px] text-slate-500">You can view the receipt design. Changing it needs Edit access to Clinic settings.</p>
        <?php endif; ?>
    </div>

    <!-- Preview -->
    <div class="xl:sticky xl:top-[76px] min-w-0">
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-[13px] font-semibold text-slate-700">Preview <span class="font-normal text-slate-500">· sample patient</span></h2>
            <span class="text-xs text-slate-500" id="pv-state" aria-live="polite"></span>
        </div>
        <iframe id="rc-preview" title="Receipt preview" class="w-full h-[calc(100vh-150px)] min-h-[560px] rounded-2xl border border-slate-200 bg-[#e9edf2]"></iframe>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('receipt-form');
    var frame = document.getElementById('rc-preview');
    var state = document.getElementById('pv-state');
    var status = document.getElementById('rc-status');
    var save = document.getElementById('rc-save');
    var reset = document.getElementById('rc-reset');
    var colour = form.querySelector('input[name=accent]');
    var initial = snapshot();
    var timer, seq = 0;

    function snapshot() {
        var fd = new FormData(form), out = [];
        fd.forEach(function (v, k) { if (k !== 'csrf_token') out.push(k + '=' + (v instanceof File ? v.name + v.size : v)); });
        return out.sort().join('&');
    }
    function refresh() {
        var dirty = snapshot() !== initial;
        if (save) save.disabled = !dirty;
        if (reset) reset.disabled = !dirty;
        if (status) status.textContent = dirty ? 'Unsaved changes — the preview shows them' : 'All changes saved';
    }
    function render() {
        var mine = ++seq;
        state.textContent = 'Updating…';
        fetch('print_receipt.php?preview=1', { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) { if (mine === seq) { frame.srcdoc = html; state.textContent = ''; } })
            .catch(function () { state.textContent = 'Preview unavailable'; });
    }
    function changed() { refresh(); clearTimeout(timer); timer = setTimeout(render, 250); }

    form.querySelectorAll('input[name=accent_choice]').forEach(function (r) {
        r.addEventListener('change', function () { if (r.checked) colour.value = r.value; });
    });
    colour && colour.addEventListener('input', function () {
        form.querySelectorAll('input[name=accent_choice]').forEach(function (r) { r.checked = r.value.toLowerCase() === colour.value.toLowerCase(); });
    });
    var logo = document.getElementById('rc-logo');
    logo && logo.addEventListener('change', function () {
        var f = logo.files && logo.files[0], thumb = document.getElementById('logo-thumb');
        if (!f) return;
        if (f.size > 300 * 1024) { if (window.NX) NX.toast('Logo too large', 'Choose an image under 300 KB.', 'error'); logo.value = ''; return; }
        var img = new Image(); img.className = 'max-w-full max-h-full object-contain'; img.alt = 'New logo';
        img.src = URL.createObjectURL(f); thumb.innerHTML = ''; thumb.appendChild(img);
    });
    form.addEventListener('input', changed);
    form.addEventListener('change', changed);
    reset && reset.addEventListener('click', function () { window.location.reload(); });
    form.addEventListener('submit', function () { initial = snapshot(); });
    window.addEventListener('beforeunload', function (e) { if (save && !save.disabled) { e.preventDefault(); e.returnValue = ''; } });
    render();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
