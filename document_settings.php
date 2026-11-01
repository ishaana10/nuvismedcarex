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

<form action="actions/document_settings_save.php" method="POST" enctype="multipart/form-data"
      class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_minmax(0,420px)] gap-6 items-start">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="doc_type" value="<?= htmlspecialchars($docType) ?>">

    <div class="space-y-5 min-w-0">
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

    <!-- Preview -->
    <aside class="xl:sticky xl:top-20">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden">
            <div class="px-4 py-2.5 border-b border-slate-200 text-xs font-bold text-slate-500 uppercase tracking-wider">Letterhead preview</div>
            <div class="p-5" style="font-family: <?= DocumentTemplateSettings::fontStack($cfg['font']) ?>;">
                <div class="flex items-center gap-3 <?= $cfg['logo_align'] === 'center' ? 'justify-center text-center flex-col' : ($cfg['logo_align'] === 'right' ? 'flex-row-reverse text-right' : '') ?>">
                    <?php if (!empty($cfg['show_logo']) && $effectiveLogo): ?>
                        <img src="<?= htmlspecialchars($effectiveLogo) ?>" alt="" style="width: <?= DocumentTemplateSettings::logoWidth($cfg['logo_size']) ?>px" class="object-contain shrink-0">
                    <?php endif; ?>
                    <div class="min-w-0">
                        <div class="font-bold text-lg leading-tight" style="color: <?= htmlspecialchars($cfg['accent']) ?>"><?= htmlspecialchars($clinic['name']) ?></div>
                        <?php if (!empty($cfg['show_address']) && !empty($clinic['address'])): ?><div class="text-[11px] text-slate-500"><?= htmlspecialchars($clinic['address']) ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t-2" style="border-color: <?= htmlspecialchars($cfg['accent']) ?>">
                    <div class="text-center font-bold tracking-wide" style="color: <?= htmlspecialchars($cfg['accent']) ?>"><?= htmlspecialchars($cfg['header_title']) ?></div>
                    <?php if (!empty($cfg['header_subtitle'])): ?><div class="text-center text-[11px] text-slate-500"><?= htmlspecialchars($cfg['header_subtitle']) ?></div><?php endif; ?>
                </div>
                <p class="text-[11px] text-slate-400 mt-4 italic"><?= htmlspecialchars($cfg['disclaimer']) ?></p>
            </div>
        </div>
        <p class="text-[11px] text-slate-400 mt-2 px-1">This is a quick letterhead preview. Print a real <?= htmlspecialchars(strtolower($typeLabel)) ?> to see the full layout with your choices applied.</p>
    </aside>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
