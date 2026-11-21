<?php
/**
 * Administration → Documents: customise the printed prescription and medical
 * certificate for this clinic (letterhead, logo, wording, which fields print).
 */
use ClinicFlow\Services\DocumentTemplateSettings;

$pageTitle = "Document templates - NuvisMedcareX";
$activePage = "admin";
$pageAccess = ['settings', 'view'];
include __DIR__ . '/includes/header.php';

$docType = $_GET['doc'] ?? 'prescription';
if (!in_array($docType, DocumentTemplateSettings::TYPES, true)) {
    $docType = 'prescription';
}
$svc = new DocumentTemplateSettings($pdo, \ClinicFlow\Shared\TenantContext::getTenantId());
$cfg = $svc->load($docType);
$canEdit = nxCan('settings', 'edit');
$dis = $canEdit ? '' : 'disabled';
$csrf = htmlspecialchars(getCsrfToken());

$clinic = nxClinic();
$clinicLogo = $clinic['logo'] ?? '';
$effectiveLogo = $cfg['logo'] ?: $clinicLogo;
$typeLabel = $docType === 'certificate' ? 'Medical certificate' : 'Prescription';
?>

<div class="mb-4">
    <h1 class="nx-page-title">Administration</h1>
    <p class="nx-page-sub">Staff accounts, what each role can do, clinic details and printed documents</p>
</div>
<?php $adminSection = 'documents'; include __DIR__ . '/includes/admin_nav.php'; ?>

<!-- Document type switch -->
<div class="flex gap-2 mb-5">
    <?php foreach (['prescription' => 'Prescription', 'certificate' => 'Medical certificate'] as $k => $label): ?>
        <a href="document_settings.php?doc=<?= $k ?>" class="px-4 py-2 rounded-xl text-sm font-semibold border transition <?= $docType === $k ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200 hover:border-slate-300' ?>">
            <?= htmlspecialchars($label) ?>
        </a>
    <?php endforeach; ?>
</div>

<style>
    @media (min-width:1280px){ .ds-grid{grid-template-columns:minmax(0,1fr) minmax(0,600px)} }
    .ds-pane{overflow:hidden;background:#eef2f7;border-radius:0 0 16px 16px;position:relative}
    .ds-pane iframe{border:0;display:block;width:820px;background:#eef2f7;transform-origin:top left}
    .ds-busy{position:absolute;top:8px;right:10px;font-size:11px;color:#64748b;background:#fff;border:1px solid #e2e8f0;border-radius:999px;padding:2px 10px;opacity:0;transition:opacity .15s}
    .ds-busy.on{opacity:1}
    @media (min-width:1280px){ .ds-aside{position:sticky;top:80px} .ds-scroll{max-height:calc(100vh - 150px);overflow:auto} }
</style>
<form action="actions/document_settings_save.php" method="POST" enctype="multipart/form-data" id="ds-form"
      class="ds-grid grid grid-cols-1 gap-6 items-start">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="doc_type" value="<?= htmlspecialchars($docType) ?>">

    <div class="space-y-5 min-w-0">
        <?php if ($docType === 'certificate'): ?>
        <!-- Template -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <h2 class="text-base font-semibold text-slate-900">Certificate template</h2>
            <p class="text-[13px] text-slate-500 -mt-2">Pick the layout your clinic prefers. You can change it any time; certificates already issued print in whichever layout is selected.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <?php foreach (DocumentTemplateSettings::LAYOUTS as $k => [$label, $blurb]): ?>
                    <label class="relative cursor-pointer">
                        <input type="radio" name="layout" value="<?= $k ?>" class="peer sr-only" <?= ($cfg['layout'] ?? 'classic') === $k ? 'checked' : '' ?> <?= $dis ?>>
                        <span class="block rounded-xl border-2 border-slate-200 p-3 h-full peer-checked:border-blue-600 peer-checked:bg-blue-50/40 hover:border-slate-300">
                            <span class="block font-semibold text-slate-900 text-sm"><?= htmlspecialchars($label) ?></span>
                            <span class="block text-[12px] text-slate-500 mt-1"><?= htmlspecialchars($blurb) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="grid grid-cols-1 gap-3">
                <div><label class="form-label">Doctor / registration line <span class="text-slate-400 font-normal">(Clinic form)</span></label>
                    <input type="text" name="doctor_line" value="<?= htmlspecialchars($cfg['doctor_line']) ?>" class="form-input" maxlength="160" placeholder="e.g. Dr Jane Doe   REGISTERED MEDICAL PRACTITIONER (MBBS) (MP 0000)" <?= $dis ?>></div>
                <div><label class="form-label">"Consultation in" services <span class="text-slate-400 font-normal">(Clinic form)</span></label>
                    <textarea name="consultation_in" rows="2" class="form-textarea" maxlength="300" placeholder="e.g. Medicine, General Surgery (Minor Operations), Skin Diseases, OB &amp; Gynae &amp; Paeds and Family Medicine" <?= $dis ?>><?= htmlspecialchars($cfg['consultation_in']) ?></textarea></div>
                <div><label class="form-label">Problem tick-boxes <span class="text-slate-400 font-normal">(one per line, up to 15)</span></label>
                    <textarea name="problem_categories" rows="6" class="form-textarea" <?= $dis ?>><?= htmlspecialchars($cfg['problem_categories']) ?></textarea></div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($docType === 'certificate'):
            $__now = new DateTimeImmutable('now');
            $__next = $svc->previewNextNumber($__now);
            $__nextRun = (int) preg_replace('/^.*?(\d+)$/', '$1', $__next);
        ?>
        <!-- Certificate numbering -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <h2 class="text-base font-semibold text-slate-900">Certificate numbers</h2>
            <p class="text-[13px] text-slate-500 -mt-2">Build the number from your clinic letters, the date and a running count so it is unique to your clinic. Numbers already issued are kept, and counting carries on after the highest one.</p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div><label class="form-label">Clinic letters</label><input type="text" name="number_prefix" value="<?= htmlspecialchars($cfg['number_prefix']) ?>" class="form-input uppercase" maxlength="8" placeholder="e.g. MTMC" pattern="[A-Za-z0-9]*" <?= $dis ?>></div>
                <div><label class="form-label">Date part</label><select name="number_date" class="form-select" <?= $dis ?>><?php foreach (DocumentTemplateSettings::NUMBER_DATES as $k => $l): ?><option value="<?= $k ?>" <?= $cfg['number_date'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></div>
                <div><label class="form-label">Separator</label><select name="number_separator" class="form-select" <?= $dis ?>><?php foreach (DocumentTemplateSettings::NUMBER_SEPARATORS as $k => $l): ?><option value="<?= htmlspecialchars((string) $k) ?>" <?= (string) $cfg['number_separator'] === (string) $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></div>
                <div><label class="form-label">Digits in count</label><select name="number_digits" class="form-select" <?= $dis ?>><?php foreach (range(3, 8) as $d): ?><option value="<?= $d ?>" <?= (int) $cfg['number_digits'] === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div><label class="form-label">Count starts again</label><select name="number_reset" class="form-select" <?= $dis ?>><?php foreach (DocumentTemplateSettings::NUMBER_RESETS as $k => $l): ?><option value="<?= $k ?>" <?= $cfg['number_reset'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select>
                    <p class="text-[12px] text-slate-400 mt-1">It can only restart as often as the date in the number changes.</p></div>
                <div><label class="form-label">Next count (optional)</label><input type="number" name="next_number" min="1" class="form-input" placeholder="<?= $__nextRun ?>" <?= $dis ?>>
                    <p class="text-[12px] text-slate-400 mt-1">Leave blank to carry on. Set it to continue from a paper pad, e.g. 5033.</p></div>
            </div>
            <div class="rounded-xl bg-slate-50 border border-slate-200 px-4 py-3 text-[13px] text-slate-600">Next certificate number: <span class="font-mono font-semibold text-slate-900"><?= htmlspecialchars($__next) ?></span></div>
        </section>
        <?php endif; ?>

        <!-- Letterhead -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <h2 class="text-base font-semibold text-slate-900">Letterhead &amp; branding</h2>

            <div class="flex items-start gap-4">
                <div class="shrink-0 w-24 h-24 rounded-xl border border-slate-200 bg-slate-50 flex items-center justify-center overflow-hidden">
                    <?php if ($effectiveLogo): ?>
                        <img src="<?= htmlspecialchars($effectiveLogo) ?>" alt="Logo" class="max-w-full max-h-full object-contain">
                    <?php else: ?>
                        <span class="material-symbols-outlined text-slate-300 text-3xl">image</span>
                    <?php endif; ?>
                </div>
                <div class="min-w-0 flex-1">
                    <label class="form-label">Logo for this document</label>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="form-input" <?= $dis ?>>
                    <p class="text-[12px] text-slate-500 mt-1">PNG, JPG or WebP up to 300 KB.
                        <?php if (!$cfg['logo'] && $clinicLogo): ?><span class="text-emerald-700 font-medium">Currently using the clinic logo.</span>
                        <?php elseif (!$effectiveLogo): ?>Add a clinic logo in Clinic &amp; branding, or upload one here.<?php endif; ?>
                    </p>
                    <?php if ($cfg['logo']): ?>
                        <label class="inline-flex items-center gap-2 text-[13px] text-slate-600 mt-2"><input type="checkbox" name="remove_logo" value="1" class="rounded" <?= $dis ?>> Remove this document's logo (fall back to clinic logo)</label>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="form-label">Logo position</label>
                    <select name="logo_align" class="form-select" <?= $dis ?>><?php foreach (DocumentTemplateSettings::ALIGNS as $k => $l): ?><option value="<?= $k ?>" <?= $cfg['logo_align'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
                </div>
                <div>
                    <label class="form-label">Logo size</label>
                    <select name="logo_size" class="form-select" <?= $dis ?>><?php foreach (DocumentTemplateSettings::LOGO_SIZES as $k => $l): ?><option value="<?= $k ?>" <?= $cfg['logo_size'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
                </div>
            </div>

            <div>
                <label class="form-label">Accent colour</label>
                <div class="flex flex-wrap gap-2">
                    <?php foreach (DocumentTemplateSettings::ACCENTS as $hex => $name): ?>
                        <label class="relative cursor-pointer" title="<?= htmlspecialchars($name) ?>">
                            <input type="radio" name="accent" value="<?= $hex ?>" class="peer sr-only" <?= strtoupper($cfg['accent']) === $hex ? 'checked' : '' ?> <?= $dis ?>>
                            <span class="w-8 h-8 rounded-full ring-2 ring-offset-2 ring-transparent peer-checked:ring-slate-900 inline-block" style="background: <?= $hex ?>"></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div><label class="form-label">Header title</label><input type="text" name="header_title" value="<?= htmlspecialchars($cfg['header_title']) ?>" class="form-input" maxlength="80" <?= $dis ?>></div>
                <div><label class="form-label">Header subtitle / tagline</label><input type="text" name="header_subtitle" value="<?= htmlspecialchars($cfg['header_subtitle']) ?>" class="form-input" maxlength="120" <?= $dis ?>></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div><label class="form-label">Paper size</label><select name="paper" class="form-select" <?= $dis ?>><?php foreach (DocumentTemplateSettings::PAPER as $k => $l): ?><option value="<?= $k ?>" <?= $cfg['paper'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                <div><label class="form-label">Font</label><select name="font" class="form-select" <?= $dis ?>><?php foreach (DocumentTemplateSettings::FONTS as $k => $l): ?><option value="<?= $k ?>" <?= $cfg['font'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                <div><label class="form-label">Date format</label><select name="date_format" class="form-select" <?= $dis ?>><?php foreach (DocumentTemplateSettings::DATE_FORMATS as $k => $l): ?><option value="<?= $k ?>" <?= $cfg['date_format'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            </div>
            <div>
                <label class="form-label">Watermark text (optional)</label>
                <input type="text" name="watermark" value="<?= htmlspecialchars($cfg['watermark']) ?>" class="form-input" maxlength="40" placeholder="e.g. COPY, ORIGINAL" <?= $dis ?>>
            </div>
        </section>

        <!-- Fields to print -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <h2 class="text-base font-semibold text-slate-900">What prints on the <?= htmlspecialchars(strtolower($typeLabel)) ?></h2>
            <?php foreach (DocumentTemplateSettings::TOGGLES[$docType] as $group => $items): ?>
                <div>
                    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2"><?= htmlspecialchars($group) ?></div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <?php foreach ($items as $k => [$label, $def]): ?>
                            <label class="flex items-center gap-2.5 rounded-xl border border-slate-200 px-3 py-2 text-[13px] text-slate-700 cursor-pointer hover:border-slate-300">
                                <input type="checkbox" name="<?= $k ?>" value="1" class="rounded" <?= !empty($cfg[$k]) ? 'checked' : '' ?> <?= $dis ?>>
                                <?= htmlspecialchars($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <!-- Wording -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs p-5 space-y-4">
            <h2 class="text-base font-semibold text-slate-900">Wording</h2>
            <div><label class="form-label">Notice / disclaimer</label><textarea name="disclaimer" rows="2" class="form-textarea" maxlength="500" <?= $dis ?>><?= htmlspecialchars($cfg['disclaimer']) ?></textarea></div>
            <div><label class="form-label">Footer note</label><textarea name="footer_note" rows="2" class="form-textarea" maxlength="500" <?= $dis ?>><?= htmlspecialchars($cfg['footer_note']) ?></textarea></div>
        </section>

        <?php if ($canEdit): ?>
        <div class="flex justify-end">
            <button type="submit" class="btn-primary"><span class="material-symbols-outlined text-[18px]">save</span>Save <?= htmlspecialchars(strtolower($typeLabel)) ?> template</button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Live preview: the real document, redrawn as you change things -->
    <aside class="ds-aside">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden">
            <div class="px-4 py-2.5 border-b border-slate-200 flex items-center justify-between gap-3">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Live preview: <?= htmlspecialchars($typeLabel) ?></span>
                <label class="text-[11px] text-slate-500 inline-flex items-center gap-1.5 cursor-pointer">
                    <input type="checkbox" id="ds-child" class="rounded border-slate-300"> Child patient (under 15)
                </label>
            </div>
            <div class="ds-scroll">
                <div class="ds-pane" id="ds-pane">
                    <span class="ds-busy" id="ds-busy" role="status">Updating…</span>
                    <iframe id="ds-frame" title="Live preview of the <?= htmlspecialchars(strtolower($typeLabel)) ?>" sandbox="allow-same-origin" height="900"></iframe>
                </div>
            </div>
        </div>
        <p class="text-[11px] text-slate-400 mt-2 px-1">The whole <?= htmlspecialchars(strtolower($typeLabel)) ?>, with a sample patient and visit, updates as you type. Your own signature, stamp and licence number are shown. Nothing changes until you press Save.</p>
    </aside>
</form>


<script nonce="<?= nxCspNonce() ?>">
(function () {
    var form = document.getElementById('ds-form'), frame = document.getElementById('ds-frame'),
        pane = document.getElementById('ds-pane'), busy = document.getElementById('ds-busy'),
        child = document.getElementById('ds-child');
    if (!form || !frame) return;
    var timer = null, seq = 0;

    function fit() {
        var w = pane.clientWidth || 600, scale = Math.min(1, w / 820), doc = frame.contentDocument;
        var h = doc && doc.documentElement ? Math.max(doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0) : 900;
        frame.style.height = h + 'px';
        frame.style.transform = 'scale(' + scale + ')';
        pane.style.height = Math.ceil(h * scale) + 'px';
    }
    frame.addEventListener('load', fit);
    window.addEventListener('resize', fit);

    function refresh() {
        var mine = ++seq, fd = new FormData(form);
        fd.set('sample_child', child && child.checked ? '1' : '');
        busy.classList.add('on');
        fetch('document_preview.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
            .then(function (html) { if (mine === seq) { frame.srcdoc = html; } })
            .catch(function () { /* keep the last good preview */ })
            .then(function () { if (mine === seq) busy.classList.remove('on'); });
    }
    function later() { clearTimeout(timer); timer = setTimeout(refresh, 350); }

    form.addEventListener('input', later);
    form.addEventListener('change', later);
    if (child) child.addEventListener('change', later);
    refresh();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
