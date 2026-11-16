<?php
/**
 * Extra fields on the "issue medical certificate" forms: the problem tick-boxes
 * and the illness / resume / review dates used by the Clinic form layout.
 * They are optional, so the Classic layout is unaffected.
 */
$__certCfg = (new \ClinicFlow\Services\DocumentTemplateSettings(getDB(), \ClinicFlow\Shared\TenantContext::getTenantId()))->load('certificate');
$__certCats = \ClinicFlow\Services\DocumentTemplateSettings::categories($__certCfg);
?>
<div class="rounded-xl border border-outline-variant/30 p-3 space-y-3">
    <div class="font-bold text-slate-700">Clinic form details <span class="font-medium text-slate-400">(optional)</span></div>
    <div>
        <div class="text-[11px] font-semibold text-slate-500 mb-1">Problem category</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
            <?php foreach ($__certCats as $__c): ?>
                <label class="flex items-center gap-2 text-slate-700"><input type="checkbox" name="problem_category[]" value="<?= htmlspecialchars($__c) ?>" class="rounded"> <?= htmlspecialchars($__c) ?></label>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div><label class="block text-[11px] font-semibold text-slate-500 mb-1">History of illness from</label><input type="date" name="illness_from" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium"></div>
        <div><label class="block text-[11px] font-semibold text-slate-500 mb-1">Should resume duties on</label><input type="date" name="resume_on" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium"></div>
        <div><label class="block text-[11px] font-semibold text-slate-500 mb-1">Review on</label><input type="date" name="review_on" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium"></div>
    </div>
</div>
