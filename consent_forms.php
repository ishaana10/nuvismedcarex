<?php
/**
 * Administration → Consent forms: the forms this clinic asks patients to sign,
 * and the designer for building them.
 */
use ClinicFlow\Services\ConsentForms;

$pageTitle = "Consent forms - NuvisMedcareX";
$activePage = "admin";
$pageAccess = ['settings', 'view'];
include __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/consent_form_render.php';

$pdo = getDB();
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$formsSvc = new ConsentForms($pdo, $tenantId);
$ready = ConsentForms::isAvailable($pdo);
$canEdit = nxCan('settings', 'edit');
$csrf = htmlspecialchars(getCsrfToken());

$forms = $ready ? $formsSvc->all() : [];
$useCounts = $ready ? $formsSvc->useCounts() : [];

// Which form the designer is open on
$editId = (string) ($_GET['form_id'] ?? '');
$editing = null;
if ($editId === 'new') {
    $editing = ConsentForms::blank();
} elseif ($editId !== '') {
    $editing = $formsSvc->find($editId);
}
?>

<div class="mb-4">
    <h1 class="nx-page-title">Administration</h1>
    <p class="nx-page-sub">Staff accounts, what each role can do, clinic details and printed documents</p>
</div>
<?php $adminSection = 'consent'; include __DIR__ . '/includes/admin_nav.php'; ?>

<?php if (!$ready): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-[13px] text-amber-900 flex items-start gap-2">
        <span class="material-symbols-outlined text-[18px]">database</span>
        <span>
            Consent forms need a database change that has not been applied yet.
            <?php if (nxCan('developer', 'view')): ?>
                <a href="developer.php?dev_tab=database" class="underline font-semibold">Apply it in Developer &rarr; Database</a>.
            <?php else: ?>
                Ask your developer to apply the pending database updates.
            <?php endif; ?>
        </span>
    </div>
<?php else: ?>

<div class="mb-6 flex flex-col lg:flex-row lg:items-end justify-between gap-3">
    <div>
        <h2 class="text-base font-semibold text-slate-900">Consent forms</h2>
        <p class="text-[13px] text-slate-500 mt-0.5 max-w-2xl">
            The forms <?= htmlspecialchars(nxClinicName()) ?> asks patients to sign. Each one is yours to word and
            arrange; active forms appear on every clinical encounter, ready for the patient to sign.
        </p>
    </div>
    <?php if ($canEdit): ?>
        <a href="consent_forms.php?form_id=new" class="btn-primary">
            <span class="material-symbols-outlined text-[18px]">note_add</span>
            <span>New form</span>
        </a>
    <?php endif; ?>
</div>

<?php if (!$forms && $canEdit): ?>
    <!-- Nothing yet: offer the ready-made templates rather than an empty designer -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-2xs p-5 mb-6">
        <h3 class="text-[13px] font-semibold text-slate-900">Start from a template</h3>
        <p class="text-[12px] text-slate-500 mt-0.5 mb-4">
            Each one is a complete, editable form. Install it and change the wording to suit the clinic.
        </p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <?php foreach (ConsentForms::samples() as $key => [$label, $blurb, $sample]): ?>
                <form action="actions/consent_form_save.php" method="POST" class="border border-slate-200 rounded-xl p-4 flex flex-col gap-2 hover:border-slate-300 transition">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="install_sample">
                    <input type="hidden" name="sample" value="<?= htmlspecialchars($key) ?>">
                    <div class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-[20px] text-blue-700">assignment</span>
                        <div>
                            <div class="text-[13px] font-semibold text-slate-900"><?= htmlspecialchars($label) ?></div>
                            <p class="text-[11px] text-slate-500 mt-0.5"><?= htmlspecialchars($blurb) ?></p>
                        </div>
                    </div>
                    <div class="text-[11px] text-slate-400 mt-auto pt-2">
                        <?= count($sample['fields']) ?> question<?= count($sample['fields']) === 1 ? '' : 's' ?>
                    </div>
                    <button type="submit" class="btn-secondary w-full">Use this template</button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- The clinic's forms -->
<?php if ($forms): ?>
<div class="bg-white border border-slate-200 rounded-2xl shadow-2xs overflow-hidden mb-6">
    <div class="overflow-x-auto">
        <table class="w-full text-[13px]">
            <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="text-left font-semibold px-4 py-2">Form</th>
                    <th class="text-left font-semibold px-3 py-2">Purpose</th>
                    <th class="text-right font-semibold px-3 py-2">Questions</th>
                    <th class="text-right font-semibold px-3 py-2">Signed</th>
                    <th class="text-left font-semibold px-3 py-2">Status</th>
                    <th class="text-right font-semibold px-4 py-2">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($forms as $form): ?>
                    <tr class="hover:bg-slate-50/70">
                        <td class="px-4 py-2.5">
                            <div class="font-medium text-slate-900"><?= htmlspecialchars($form['name']) ?></div>
                            <?php if (($form['description'] ?? '') !== ''): ?>
                                <div class="text-[11px] text-slate-500 mt-0.5 max-w-md"><?= htmlspecialchars($form['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2.5 text-slate-600"><?= htmlspecialchars(($form['purpose'] ?? '') ?: '—') ?></td>
                        <td class="px-3 py-2.5 text-right tabular"><?= count($form['fields']) ?></td>
                        <td class="px-3 py-2.5 text-right tabular"><?= number_format((int) ($useCounts[$form['id']] ?? 0)) ?></td>
                        <td class="px-3 py-2.5">
                            <?php if ($form['is_active']): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700 text-[11px] font-medium">In use</span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full border border-slate-200 bg-slate-100 text-slate-600 text-[11px] font-medium">Retired</span>
                            <?php endif; ?>
                            <span class="block text-[10px] text-slate-400 mt-0.5">v<?= $form['version'] ?></span>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <div class="inline-flex items-center gap-1">
                                <a href="consent_forms.php?form_id=<?= urlencode($form['id']) ?>" class="nx-icon-btn" title="<?= $canEdit ? 'Edit' : 'View' ?>">
                                    <span class="material-symbols-outlined text-[20px]"><?= $canEdit ? 'edit' : 'visibility' ?></span>
                                </a>
                                <?php if ($canEdit): ?>
                                    <form action="actions/consent_form_save.php" method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="duplicate">
                                        <input type="hidden" name="form_id" value="<?= htmlspecialchars($form['id']) ?>">
                                        <button type="submit" class="nx-icon-btn" title="Duplicate">
                                            <span class="material-symbols-outlined text-[20px]">content_copy</span>
                                        </button>
                                    </form>
                                    <form action="actions/consent_form_save.php" method="POST" class="inline"
                                          <?= $form['is_active'] ? 'data-nx-confirm="Retire &quot;' . htmlspecialchars($form['name'], ENT_QUOTES) . '&quot;? It will stop appearing on new encounters. Consents already signed are kept."' : '' ?>>
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="<?= $form['is_active'] ? 'retire' : 'restore' ?>">
                                        <input type="hidden" name="form_id" value="<?= htmlspecialchars($form['id']) ?>">
                                        <button type="submit" class="nx-icon-btn" title="<?= $form['is_active'] ? 'Retire' : 'Put back in use' ?>">
                                            <span class="material-symbols-outlined text-[20px]"><?= $form['is_active'] ? 'archive' : 'unarchive' ?></span>
                                        </button>
                                    </form>
                                    <?php if ((int) ($useCounts[$form['id']] ?? 0) === 0): ?>
                                        <form action="actions/consent_form_save.php" method="POST" class="inline"
                                              data-nx-confirm="Delete &quot;<?= htmlspecialchars($form['name'], ENT_QUOTES) ?>&quot;? Nothing has been signed against it, so it can go for good.">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="form_id" value="<?= htmlspecialchars($form['id']) ?>">
                                            <button type="submit" class="nx-icon-btn hover:text-red-600" title="Delete">
                                                <span class="material-symbols-outlined text-[20px]">delete</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canEdit): ?>
    <details class="bg-white border border-slate-200 rounded-2xl shadow-2xs p-4 mb-6">
        <summary class="text-[13px] font-semibold text-slate-900 cursor-pointer">Add another from a template</summary>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
            <?php foreach (ConsentForms::samples() as $key => [$label, $blurb, $sample]): ?>
                <form action="actions/consent_form_save.php" method="POST" class="border border-slate-200 rounded-xl p-3 flex flex-col gap-2">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="install_sample">
                    <input type="hidden" name="sample" value="<?= htmlspecialchars($key) ?>">
                    <div class="text-[13px] font-semibold text-slate-900"><?= htmlspecialchars($label) ?></div>
                    <p class="text-[11px] text-slate-500"><?= htmlspecialchars($blurb) ?></p>
                    <button type="submit" class="btn-secondary w-full mt-auto">Use this template</button>
                </form>
            <?php endforeach; ?>
        </div>
    </details>
<?php endif; ?>
<?php endif; ?>

<!-- ------------------------------------------------------------- designer -->
<?php if ($editing !== null): ?>
<div id="modal-consent-designer" class="nx-overlay items-start justify-center p-4 overflow-y-auto" data-nx-modal data-nx-static>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl w-full max-w-5xl my-8">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-slate-900">
                    <?= $editing['id'] === '' ? 'New consent form' : ($canEdit ? 'Edit form' : 'Form') ?>
                </h2>
                <p class="text-[12px] text-slate-500 mt-0.5">
                    <?= $editing['id'] === ''
                        ? 'Write the wording, then add the questions the patient answers.'
                        : 'Saving makes this version ' . ((int) $editing['version'] + 1) . '. Consents already signed keep the wording they were signed against.' ?>
                </p>
            </div>
            <a href="consent_forms.php" class="nx-icon-btn" aria-label="Close"><span class="material-symbols-outlined">close</span></a>
        </div>

        <form action="actions/consent_form_save.php" method="POST" class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_minmax(0,460px)] gap-0">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="form_id" value="<?= htmlspecialchars($editing['id']) ?>">

            <!-- the designer -->
            <div class="p-5 space-y-4 border-b xl:border-b-0 xl:border-r border-slate-200">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="form-label" for="cf-name">Form name *</label>
                        <input id="cf-name" type="text" name="name" value="<?= htmlspecialchars($editing['name']) ?>" maxlength="160" required class="form-input w-full" placeholder="Consent to treatment and examination">
                    </div>
                    <div>
                        <label class="form-label" for="cf-purpose">Purpose</label>
                        <input id="cf-purpose" type="text" name="purpose" value="<?= htmlspecialchars($editing['purpose']) ?>" maxlength="80" class="form-input w-full" placeholder="General treatment">
                    </div>
                </div>
                <div>
                    <label class="form-label" for="cf-description">When it is used</label>
                    <input id="cf-description" type="text" name="description" value="<?= htmlspecialchars($editing['description']) ?>" maxlength="400" class="form-input w-full" placeholder="Signed at registration or before the first examination of a visit">
                </div>
                <div>
                    <label class="form-label" for="cf-intro">Wording the patient reads</label>
                    <textarea id="cf-intro" name="intro" rows="7" maxlength="8000" class="form-textarea w-full" placeholder="Explain plainly what is being consented to."><?= htmlspecialchars($editing['intro']) ?></textarea>
                </div>

                <fieldset>
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <legend class="form-label !mb-0">Questions</legend>
                        <div class="flex items-center gap-2">
                            <select id="cf-new-type" class="form-select !py-1.5 text-[12px]">
                                <?php foreach (ConsentForms::FIELD_TYPES as $type => [$label, $hint, , ]): ?>
                                    <option value="<?= $type ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" onclick="addConsentField()" class="btn-secondary !py-1.5">
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                <span>Add</span>
                            </button>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500 mb-2">Drag the handle to reorder. A "must be ticked" statement blocks signing until the patient ticks it.</p>
                    <ul id="cf-fields" class="space-y-2"></ul>
                    <p id="cf-empty" class="text-[12px] text-slate-400 italic py-3">No questions yet — the form will show only the wording above.</p>
                </fieldset>

                <div>
                    <label class="form-label" for="cf-declaration">Declaration above the signature</label>
                    <textarea id="cf-declaration" name="declaration" rows="2" maxlength="2000" class="form-textarea w-full"><?= htmlspecialchars($editing['declaration']) ?></textarea>
                </div>

                <div class="flex flex-wrap gap-4 text-[13px] text-slate-800">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="require_witness" <?= !empty($editing['require_witness']) ? 'checked' : '' ?> class="w-4 h-4 rounded border-slate-300">
                        <span>A witness must also sign</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_active" <?= !empty($editing['is_active']) ? 'checked' : '' ?> class="w-4 h-4 rounded border-slate-300">
                        <span>Offer this form on encounters</span>
                    </label>
                </div>
            </div>

            <!-- what the patient will see -->
            <div class="p-5 bg-slate-50/70 space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-[13px] font-semibold text-slate-900">What the patient sees</h3>
                    <button type="button" onclick="renderConsentPreview()" class="text-[12px] font-medium text-blue-700 hover:underline">Refresh</button>
                </div>
                <div id="cf-preview" class="bg-white border border-slate-200 rounded-xl p-4 space-y-3 max-h-[60vh] overflow-y-auto"></div>
            </div>

            <?php if ($canEdit): ?>
            <div class="xl:col-span-2 px-5 py-4 border-t border-slate-200 flex items-center justify-end gap-2">
                <a href="consent_forms.php" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-primary">
                    <span class="material-symbols-outlined text-[18px]">save</span>
                    <span><?= $editing['id'] === '' ? 'Create form' : 'Save changes' ?></span>
                </button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<template id="cf-field-template">
    <li class="border border-slate-200 rounded-xl bg-white p-3" data-field draggable="true">
        <div class="flex items-start gap-2">
            <span class="material-symbols-outlined text-[18px] text-slate-300 cursor-grab select-none mt-1.5" data-handle>drag_indicator</span>
            <div class="flex-1 min-w-0 space-y-2">
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-semibold uppercase tracking-wide" data-type-label></span>
                    <input type="hidden" name="fields[__i__][type]" data-type>
                    <input type="hidden" name="fields[__i__][key]" data-key>
                    <label class="ml-auto inline-flex items-center gap-1.5 text-[11px] text-slate-600 cursor-pointer" data-required-wrap>
                        <input type="checkbox" name="fields[__i__][required]" value="1" class="w-3.5 h-3.5 rounded border-slate-300" data-required>
                        <span>Required</span>
                    </label>
                    <button type="button" class="nx-icon-btn hover:text-red-600" data-remove aria-label="Remove question">
                        <span class="material-symbols-outlined text-[20px]">delete</span>
                    </button>
                </div>
                <input type="text" name="fields[__i__][label]" class="form-input w-full !py-2" placeholder="Wording" data-label>
                <input type="text" name="fields[__i__][help]" class="form-input w-full !py-1.5 text-[12px]" placeholder="Hint below the question (optional)" maxlength="300" data-help>
                <textarea name="fields[__i__][options]" rows="3" class="form-textarea w-full !py-1.5 text-[12px] hidden" placeholder="One choice per line" data-options></textarea>
            </div>
        </div>
    </li>
</template>

<script>
(function () {
    const TYPES = <?= json_encode(array_map(static fn($t) => ['label' => $t[0], 'hint' => $t[1], 'options' => $t[2], 'static' => $t[3]], ConsentForms::FIELD_TYPES), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const EXISTING = <?= json_encode($editing['fields'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const list = document.getElementById('cf-fields');
    const empty = document.getElementById('cf-empty');
    const preview = document.getElementById('cf-preview');
    let index = 0;

    function reindex() {
        list.querySelectorAll('[data-field]').forEach((li, i) => {
            li.querySelectorAll('[name]').forEach(el => {
                el.name = el.name.replace(/fields\[\d+\]/, 'fields[' + i + ']');
            });
        });
        empty.classList.toggle('hidden', list.children.length > 0);
    }

    window.addConsentField = function (field) {
        const type = field ? field.type : document.getElementById('cf-new-type').value;
        const meta = TYPES[type];
        if (!meta) return;
        const html = document.getElementById('cf-field-template').innerHTML.replace(/__i__/g, String(index++));
        const holder = document.createElement('div');
        holder.innerHTML = html.trim();
        const li = holder.firstElementChild;

        li.querySelector('[data-type]').value = type;
        li.querySelector('[data-type-label]').textContent = meta.label;
        li.querySelector('[data-key]').value = field ? (field.key || '') : '';
        li.querySelector('[data-label]').value = field ? (field.label || '') : '';
        li.querySelector('[data-label]').placeholder = meta.hint;
        li.querySelector('[data-help]').value = field ? (field.help || '') : '';

        const options = li.querySelector('[data-options]');
        if (meta.options) {
            options.classList.remove('hidden');
            options.value = field && field.options ? field.options.join('\n') : '';
        }
        // Static text is never answered, and an acknowledgement is always required
        const reqWrap = li.querySelector('[data-required-wrap]');
        const req = li.querySelector('[data-required]');
        if (meta.static) {
            reqWrap.classList.add('hidden');
            li.querySelector('[data-help]').classList.add('hidden');
        } else if (type === 'acknowledge') {
            reqWrap.classList.add('hidden');
            req.checked = true;
        } else {
            req.checked = field ? !!field.required : false;
        }

        li.querySelector('[data-remove]').addEventListener('click', function () {
            li.remove();
            reindex();
            renderConsentPreview();
        });
        li.querySelectorAll('input, textarea').forEach(el => el.addEventListener('input', renderConsentPreview));

        list.appendChild(li);
        reindex();
        if (!field) {
            li.querySelector('[data-label]').focus();
            li.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        renderConsentPreview();
    };

    // Reordering: the order on screen is the order the patient reads
    let dragging = null;
    list.addEventListener('dragstart', e => {
        const li = e.target.closest('[data-field]');
        if (!li) return;
        dragging = li;
        li.classList.add('opacity-50');
        e.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragend', () => {
        if (dragging) dragging.classList.remove('opacity-50');
        dragging = null;
        reindex();
        renderConsentPreview();
    });
    list.addEventListener('dragover', e => {
        if (!dragging) return;
        e.preventDefault();
        const over = e.target.closest('[data-field]');
        if (!over || over === dragging) return;
        const rect = over.getBoundingClientRect();
        list.insertBefore(dragging, (e.clientY - rect.top) > rect.height / 2 ? over.nextSibling : over);
    });

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    window.renderConsentPreview = function () {
        const intro = document.getElementById('cf-intro').value.trim();
        const declaration = document.getElementById('cf-declaration').value.trim();
        let html = '';
        if (intro) {
            html += '<div class="text-[13px] text-slate-700 leading-relaxed whitespace-pre-line bg-slate-50 border border-slate-200 rounded-xl p-3">' + esc(intro) + '</div>';
        }
        list.querySelectorAll('[data-field]').forEach(li => {
            const type = li.querySelector('[data-type]').value;
            const meta = TYPES[type] || {};
            const label = li.querySelector('[data-label]').value || meta.hint || '';
            const help = li.querySelector('[data-help]').value;
            const required = li.querySelector('[data-required]').checked || type === 'acknowledge';
            const star = required ? ' <span class="text-red-600">*</span>' : '';
            const hint = help ? '<p class="text-[11px] text-slate-500 mt-0.5">' + esc(help) + '</p>' : '';
            const opts = (li.querySelector('[data-options]').value || '').split('\n').map(s => s.trim()).filter(Boolean);

            if (type === 'heading') {
                html += '<h4 class="text-[13px] font-semibold text-slate-900 pt-2 border-t border-slate-200">' + esc(label) + '</h4>';
            } else if (type === 'paragraph') {
                html += '<p class="text-[13px] text-slate-700 whitespace-pre-line">' + esc(label) + '</p>';
            } else if (type === 'acknowledge' || type === 'checkbox') {
                html += '<label class="flex items-start gap-2 text-[13px] text-slate-800"><input type="checkbox" disabled class="mt-0.5 w-4 h-4 rounded border-slate-300"><span>' + esc(label) + star + hint + '</span></label>';
            } else if (type === 'radio' || type === 'select') {
                html += '<div><div class="text-[12px] font-semibold text-slate-700">' + esc(label) + star + '</div>' + hint
                     + '<div class="flex flex-wrap gap-1.5 mt-1">'
                     + (opts.length ? opts.map(o => '<span class="px-2 py-0.5 rounded-full border border-slate-200 text-[11px] text-slate-600">' + esc(o) + '</span>').join('')
                                    : '<span class="text-[11px] text-amber-700">Add one choice per line</span>')
                     + '</div></div>';
            } else {
                const box = type === 'textarea'
                    ? '<div class="h-14 rounded-lg border border-slate-200 bg-slate-50 mt-1"></div>'
                    : '<div class="h-8 ' + (type === 'initials' ? 'w-24' : '') + ' rounded-lg border border-slate-200 bg-slate-50 mt-1"></div>';
                html += '<div><div class="text-[12px] font-semibold text-slate-700">' + esc(label) + star + '</div>' + hint + box + '</div>';
            }
        });
        if (declaration) {
            html += '<p class="text-[13px] font-medium text-slate-900 border-l-2 border-blue-700 pl-3 whitespace-pre-line">' + esc(declaration) + '</p>';
        }
        html += '<div class="pt-3 mt-2 border-t border-dashed border-slate-300">'
             + '<div class="text-[11px] uppercase tracking-wide text-slate-500">Signature</div>'
             + '<div class="h-16 rounded-xl border border-dashed border-slate-300 bg-slate-50 mt-1"></div></div>';
        preview.innerHTML = html || '<p class="text-[12px] text-slate-400 italic">Nothing to show yet.</p>';
    };

    (EXISTING || []).forEach(f => window.addConsentField(f));
    ['cf-intro', 'cf-declaration'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', renderConsentPreview);
    });
    reindex();
    renderConsentPreview();
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
