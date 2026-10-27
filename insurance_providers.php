<?php
/**
 * Administration → Insurance: the insurers this clinic bills, so claims are
 * raised by picking from a list instead of retyping a name.
 */
use ClinicFlow\Services\InsuranceProviders;

$pageTitle = "Insurance providers - NuvisMedcareX";
$activePage = "admin";
$pageAccess = ['settings', 'view'];
include __DIR__ . '/includes/header.php';

$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$providerSvc = new InsuranceProviders($pdo, $tenantId);
$providers = $providerSvc->all();
$unlisted = $providerSvc->unlisted();
$canEdit = nxCan('settings', 'edit');
$csrf = htmlspecialchars(getCsrfToken());
$dis = $canEdit ? '' : 'disabled';

// How much each insurer is owed right now, so the list is worth reading
$claimTotals = [];
try {
    $st = $pdo->prepare("SELECT provider_name, COUNT(*) AS claims,
                                SUM(CASE WHEN status IN ('Submitted','Pending') THEN claim_amount ELSE 0 END) AS open_amount
                           FROM insurance_claims WHERE tenant_id = ? GROUP BY provider_name");
    $st->execute([$tenantId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $claimTotals[mb_strtolower((string) $row['provider_name'])] = $row;
    }
} catch (\Throwable $e) {
    error_log('[insurance_providers] totals: ' . $e->getMessage());
}
$cur = nxCurrencySymbol();
?>

<div class="mb-4">
    <h1 class="nx-page-title">Administration</h1>
    <p class="nx-page-sub">Staff accounts, what each role can do, clinic details and printed documents</p>
</div>
<?php $adminSection = 'insurance'; include __DIR__ . '/includes/admin_nav.php'; ?>

<div class="mb-6 flex flex-col lg:flex-row lg:items-end justify-between gap-3">
    <div>
        <h2 class="text-base font-semibold text-slate-900">Insurance providers</h2>
        <p class="text-[13px] text-slate-500 mt-0.5 max-w-2xl">
            The insurers <?= htmlspecialchars(nxClinicName()) ?> bills. They appear in the claim form, in the
            insurance report filters, and as the providers whose report layout you can customise.
        </p>
    </div>
    <div class="flex items-center gap-2">
        <a href="insurance_report.php" class="btn-secondary">
            <span class="material-symbols-outlined text-[18px]">summarize</span>
            <span>Insurance report</span>
        </a>
        <?php if ($canEdit): ?>
            <button type="button" onclick="addProviderRow()" class="btn-primary">
                <span class="material-symbols-outlined text-[18px]">add</span>
                <span>Add provider</span>
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!$canEdit): ?>
    <p class="mb-4 text-[12px] text-slate-600 bg-slate-100 border border-slate-200 rounded-xl px-3 py-2">
        You can see this list but not change it. Ask an administrator for “Clinic settings: Edit”.
    </p>
<?php endif; ?>

<?php if ($unlisted && $canEdit): ?>
<form action="actions/insurance_providers_save.php" method="POST" class="mb-6 bg-amber-50 border border-amber-200 rounded-2xl p-4">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="adopt">
    <h3 class="text-[13px] font-semibold text-amber-900 flex items-center gap-1.5">
        <span class="material-symbols-outlined text-[18px]">playlist_add</span>
        Names already in use but not on the list
    </h3>
    <p class="text-[12px] text-amber-800 mt-0.5 mb-3">
        These were typed into patient records or claims. Add the ones you use so they stop being retyped.
    </p>
    <div class="flex flex-wrap gap-2 mb-3">
        <?php foreach ($unlisted as $name): ?>
            <label class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border border-amber-300 bg-white text-[12px] font-medium cursor-pointer hover:border-amber-400">
                <input type="checkbox" name="adopt[]" value="<?= htmlspecialchars($name) ?>" class="w-3.5 h-3.5 rounded border-amber-300" checked>
                <span><?= htmlspecialchars($name) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <button type="submit" class="btn-primary">Add the ticked providers</button>
</form>
<?php endif; ?>

<form action="actions/insurance_providers_save.php" method="POST" id="providers-form">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save">

    <div id="provider-rows" class="space-y-3">
        <?php
        $rows = $providers;
        if (!$rows) {
            $rows = [array_merge(InsuranceProviders::blank(), ['active' => true])];
        }
        foreach ($rows as $i => $p):
            $stats = $claimTotals[mb_strtolower($p['name'])] ?? null; ?>
            <section class="bg-white border border-slate-200 rounded-2xl shadow-2xs p-4 provider-row" data-row>
                <div class="flex flex-wrap items-center gap-3 mb-3">
                    <span class="material-symbols-outlined text-slate-300 text-[20px]">health_and_safety</span>
                    <div class="flex-1 min-w-[12rem]">
                        <label class="form-label" for="p-name-<?= $i ?>">Provider name *</label>
                        <input id="p-name-<?= $i ?>" type="text" name="providers[<?= $i ?>][name]" value="<?= htmlspecialchars($p['name']) ?>"
                               placeholder="e.g. BSP Life Health" maxlength="80" class="form-input w-full" <?= $dis ?>>
                    </div>
                    <div class="w-28">
                        <label class="form-label" for="p-code-<?= $i ?>">Short code</label>
                        <input id="p-code-<?= $i ?>" type="text" name="providers[<?= $i ?>][code]" value="<?= htmlspecialchars($p['code']) ?>"
                               placeholder="BSP" maxlength="12" class="form-input w-full uppercase" <?= $dis ?>>
                    </div>
                    <label class="inline-flex items-center gap-2 text-[13px] text-slate-700 cursor-pointer mt-5">
                        <input type="checkbox" name="providers[<?= $i ?>][active]" <?= !empty($p['active']) ? 'checked' : '' ?> class="w-4 h-4 rounded border-slate-300" <?= $dis ?>>
                        <span>In use</span>
                    </label>
                    <button type="button" onclick="toggleProviderDetails(this)" class="nx-icon-btn mt-5" aria-expanded="false" aria-label="Show the details for this provider" title="Details">
                        <span class="material-symbols-outlined">expand_more</span>
                    </button>
                    <?php if ($canEdit): ?>
                        <button type="button" onclick="removeProviderRow(this)" class="nx-icon-btn mt-5" aria-label="Remove this provider" title="Remove">
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    <?php endif; ?>
                </div>

                <div class="hidden" data-details>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label class="form-label" for="p-label-<?= $i ?>">What they call the policy number</label>
                        <input id="p-label-<?= $i ?>" type="text" name="providers[<?= $i ?>][policy_label]" value="<?= htmlspecialchars($p['policy_label']) ?>"
                               placeholder="Member number" maxlength="40" class="form-input w-full" <?= $dis ?>>
                    </div>
                    <div>
                        <label class="form-label" for="p-cov-<?= $i ?>">Usual share of the bill</label>
                        <div class="relative">
                            <input id="p-cov-<?= $i ?>" type="number" min="0" max="100" step="0.5" name="providers[<?= $i ?>][default_coverage]"
                                   value="<?= htmlspecialchars((string) $p['default_coverage']) ?>" class="form-input w-full pr-8" <?= $dis ?>>
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-slate-400">%</span>
                        </div>
                        <p class="text-[11px] text-slate-500 mt-1">Used to suggest the claim amount</p>
                    </div>
                    <div>
                        <label class="form-label" for="p-win-<?= $i ?>">Claim within</label>
                        <div class="relative">
                            <input id="p-win-<?= $i ?>" type="number" min="0" max="365" name="providers[<?= $i ?>][claim_window_days]"
                                   value="<?= htmlspecialchars((string) $p['claim_window_days']) ?>" class="form-input w-full pr-14" <?= $dis ?>>
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-slate-400">days</span>
                        </div>
                        <p class="text-[11px] text-slate-500 mt-1">0 if they set no deadline</p>
                    </div>
                    <div>
                        <label class="form-label" for="p-email-<?= $i ?>">Claims email</label>
                        <input id="p-email-<?= $i ?>" type="email" name="providers[<?= $i ?>][contact_email]" value="<?= htmlspecialchars($p['contact_email']) ?>"
                               placeholder="claims@insurer.com.fj" maxlength="120" class="form-input w-full" <?= $dis ?>>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-[14rem_minmax(0,1fr)] gap-3 mt-3">
                    <div>
                        <label class="form-label" for="p-phone-<?= $i ?>">Claims phone</label>
                        <input id="p-phone-<?= $i ?>" type="text" name="providers[<?= $i ?>][contact_phone]" value="<?= htmlspecialchars($p['contact_phone']) ?>"
                               maxlength="40" class="form-input w-full" <?= $dis ?>>
                    </div>
                    <div>
                        <label class="form-label" for="p-notes-<?= $i ?>">Notes for whoever raises the claim</label>
                        <input id="p-notes-<?= $i ?>" type="text" name="providers[<?= $i ?>][notes]" value="<?= htmlspecialchars($p['notes']) ?>"
                               placeholder="e.g. pre-authorisation needed above FJ$500" maxlength="400" class="form-input w-full" <?= $dis ?>>
                    </div>
                </div>

                <?php if ($stats): ?>
                    <p class="text-[11px] text-slate-500 mt-3 pt-3 border-t border-slate-100">
                        <?= (int) $stats['claims'] ?> claim<?= (int) $stats['claims'] === 1 ? '' : 's' ?> on file<?php
                        if ((float) $stats['open_amount'] > 0): ?>,
                            <strong class="text-amber-700"><?= $cur . number_format((float) $stats['open_amount'], 2) ?></strong> still outstanding<?php
                        endif; ?>.
                        <a href="insurance_report.php?preset=this_year&provider=<?= urlencode($p['name']) ?>" class="text-blue-700 hover:underline">Open the report</a>
                    </p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>

    <?php if ($canEdit): ?>
        <div class="flex flex-wrap items-center gap-2 mt-5">
            <button type="submit" class="btn-primary">Save providers</button>
            <button type="button" onclick="addProviderRow()" class="btn-secondary">Add another</button>
            <?php if (!$providers): ?>
                <span class="text-[12px] text-slate-500 ml-2">
                    Common Fiji insurers:
                    <?php foreach (InsuranceProviders::SUGGESTED as $n => $name): ?>
                        <button type="button" onclick="addProviderRow(<?= htmlspecialchars(json_encode($name), ENT_QUOTES) ?>)" class="text-blue-700 hover:underline"><?= htmlspecialchars($name) ?></button><?= $n < count(InsuranceProviders::SUGGESTED) - 1 ? ',' : '' ?>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</form>

<template id="provider-row-template">
    <section class="bg-white border border-slate-200 rounded-2xl shadow-2xs p-4 provider-row" data-row>
        <div class="flex flex-wrap items-center gap-3 mb-3">
            <span class="material-symbols-outlined text-slate-300 text-[20px]">health_and_safety</span>
            <div class="flex-1 min-w-[12rem]">
                <label class="form-label">Provider name *</label>
                <input type="text" name="providers[__i__][name]" placeholder="e.g. BSP Life Health" maxlength="80" class="form-input w-full" data-name>
            </div>
            <div class="w-28">
                <label class="form-label">Short code</label>
                <input type="text" name="providers[__i__][code]" placeholder="BSP" maxlength="12" class="form-input w-full uppercase">
            </div>
            <label class="inline-flex items-center gap-2 text-[13px] text-slate-700 cursor-pointer mt-5">
                <input type="checkbox" name="providers[__i__][active]" checked class="w-4 h-4 rounded border-slate-300">
                <span>In use</span>
            </label>
            <button type="button" onclick="toggleProviderDetails(this)" class="nx-icon-btn mt-5" aria-expanded="true" aria-label="Hide the details for this provider" title="Details">
                <span class="material-symbols-outlined">expand_less</span>
            </button>
            <button type="button" onclick="removeProviderRow(this)" class="nx-icon-btn mt-5" aria-label="Remove this provider" title="Remove">
                <span class="material-symbols-outlined">delete</span>
            </button>
        </div>
        <div data-details>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <label class="form-label">What they call the policy number</label>
                <input type="text" name="providers[__i__][policy_label]" value="Policy / member number" maxlength="40" class="form-input w-full">
            </div>
            <div>
                <label class="form-label">Usual share of the bill</label>
                <div class="relative">
                    <input type="number" min="0" max="100" step="0.5" name="providers[__i__][default_coverage]" value="0" class="form-input w-full pr-8">
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-slate-400">%</span>
                </div>
                <p class="text-[11px] text-slate-500 mt-1">Used to suggest the claim amount</p>
            </div>
            <div>
                <label class="form-label">Claim within</label>
                <div class="relative">
                    <input type="number" min="0" max="365" name="providers[__i__][claim_window_days]" value="0" class="form-input w-full pr-14">
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-slate-400">days</span>
                </div>
                <p class="text-[11px] text-slate-500 mt-1">0 if they set no deadline</p>
            </div>
            <div>
                <label class="form-label">Claims email</label>
                <input type="email" name="providers[__i__][contact_email]" placeholder="claims@insurer.com.fj" maxlength="120" class="form-input w-full">
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-[14rem_minmax(0,1fr)] gap-3 mt-3">
            <div>
                <label class="form-label">Claims phone</label>
                <input type="text" name="providers[__i__][contact_phone]" maxlength="40" class="form-input w-full">
            </div>
            <div>
                <label class="form-label">Notes for whoever raises the claim</label>
                <input type="text" name="providers[__i__][notes]" placeholder="e.g. pre-authorisation needed above FJ$500" maxlength="400" class="form-input w-full">
            </div>
        </div>
        </div>
    </section>
</template>

<script>
let providerIndex = <?= count($rows) ?>;

function addProviderRow(name) {
    const tpl = document.getElementById('provider-row-template');
    const html = tpl.innerHTML.replace(/__i__/g, String(providerIndex++));
    const holder = document.createElement('div');
    holder.innerHTML = html.trim();
    const row = holder.firstElementChild;
    document.getElementById('provider-rows').appendChild(row);
    const field = row.querySelector('[data-name]');
    if (field) {
        if (name) field.value = name;
        field.focus();
    }
    row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

// With a dozen insurers on file the full form for each is unreadable, so the
// detail only opens for the row being worked on.
function toggleProviderDetails(btn) {
    const row = btn.closest('[data-row]');
    const details = row && row.querySelector('[data-details]');
    if (!details) return;
    const open = details.classList.toggle('hidden') === false;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('aria-label', (open ? 'Hide' : 'Show') + ' the details for this provider');
    const icon = btn.querySelector('.material-symbols-outlined');
    if (icon) icon.textContent = open ? 'expand_less' : 'expand_more';
    if (open) details.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function removeProviderRow(btn) {
    const row = btn.closest('[data-row]');
    const rows = document.querySelectorAll('#provider-rows [data-row]');
    const name = row.querySelector('input[name$="[name]"]');
    const doIt = () => {
        if (rows.length > 1) { row.remove(); } else { row.querySelectorAll('input').forEach(i => { i.value = ''; }); }
    };
    // Only stop to ask when there is something to lose
    if (name && name.value.trim() !== '' && window.NX && NX.confirm) {
        NX.confirm('Remove ' + name.value.trim() + ' from the list? Claims already raised keep the name.').then(ok => { if (ok) doIt(); });
    } else {
        doIt();
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
