<?php
$pageTitle = "Insurance Report - NuvisMedcareX";
$activePage = "billing";
$activeTab = "insurance_report";
include __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/security.php';

use ClinicFlow\Services\InsuranceReportSettings;

$pdo = getDB();
require_once __DIR__ . '/includes/insurance_report_query.php';

$cur = nxCurrencySymbol();
$csrfToken = getCsrfToken();
$canEditLayout = nxCan('settings', 'edit') || nxCan('billing', 'edit');
$totals = $insReport['totals'];
$table = \ClinicFlow\Services\InsuranceReportService::tabulate($insReport, $insLayout);

/** Format one cell for the screen. */
$fmt = function ($value, string $type) use ($cur): string {
    if ($type === 'money') {
        return $cur . number_format((float) $value, 2);
    }
    if ($type === 'date') {
        return $value === '' || $value === null ? '—' : nxDate((string) $value);
    }
    if ($type === 'number') {
        return $value === '' ? '—' : number_format((float) $value);
    }
    return $value === '' || $value === null ? '—' : (string) $value;
};
$statusTone = [
    'Approved'  => 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'Paid'      => 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'Rejected'  => 'bg-red-50 text-red-700 border-red-200',
    'Pending'   => 'bg-amber-50 text-amber-700 border-amber-200',
    'Submitted' => 'bg-blue-50 text-blue-700 border-blue-200',
];
?>

<div class="mb-6 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
    <div>
        <h1 class="nx-page-title">Insurance report</h1>
        <p class="nx-page-sub">
            Claims <?= $insFilters['date_basis'] === 'service' ? 'by date of service' : 'by date submitted' ?>,
            <?= htmlspecialchars(nxDate($insFilters['from'])) ?> to <?= htmlspecialchars(nxDate($insFilters['to'])) ?>
            <?php if ($insProvider !== ''): ?> &middot; <?= htmlspecialchars($insProvider) ?><?php endif; ?>
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="actions/insurance_report_export.php?<?= $insQueryString ?>" class="btn-secondary">
            <span class="material-symbols-outlined text-[18px]">table_view</span>
            <span>Download CSV</span>
        </a>
        <a href="print_insurance_report.php?<?= $insQueryString ?>" target="_blank" rel="noopener" class="btn-outline-blue">
            <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>
            <span>Print / PDF</span>
        </a>
        <?php if ($canEditLayout): ?>
            <button type="button" onclick="NX.openModal('modal-insurance-layout')" class="btn-primary">
                <span class="material-symbols-outlined text-[18px]">tune</span>
                <span>Customise layout</span>
            </button>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/billing_tabs.php'; ?>

<?php foreach (['flash_success' => 'emerald', 'flash_error' => 'red'] as $key => $tone): ?>
    <?php if (!empty($_SESSION[$key])): ?>
        <div class="mb-4 p-3 bg-<?= $tone ?>-50 border border-<?= $tone ?>-200 text-<?= $tone ?>-800 rounded-xl text-[13px] font-medium">
            <?= htmlspecialchars($_SESSION[$key]) ?>
        </div>
        <?php unset($_SESSION[$key]); ?>
    <?php endif; ?>
<?php endforeach; ?>

<!-- Filters ------------------------------------------------------------- -->
<form method="GET" action="insurance_report.php" class="bg-white border border-slate-200 rounded-2xl p-4 mb-6 shadow-2xs space-y-4">
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mr-1">Period</span>
        <?php foreach ($insPresets as $key => [$label, , ]):
            $on = $insPreset === $key; ?>
            <a href="insurance_report.php?<?= http_build_query(array_filter(['preset' => $key, 'provider' => $insProvider, 'basis' => $insFilters['date_basis']])) ?>"
               class="px-2.5 py-1 rounded-full text-[12px] font-medium border transition <?= $on ? 'bg-blue-700 text-white border-blue-700' : 'bg-white text-slate-600 border-slate-200 hover:border-slate-300' ?>">
                <?= htmlspecialchars($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="flex flex-wrap items-end gap-3 pt-3 border-t border-slate-100">
        <div>
            <label class="form-label" for="ins-from">From</label>
            <input id="ins-from" type="date" name="from" value="<?= htmlspecialchars($insFilters['from']) ?>" class="form-input !py-2">
        </div>
        <div>
            <label class="form-label" for="ins-to">To</label>
            <input id="ins-to" type="date" name="to" value="<?= htmlspecialchars($insFilters['to']) ?>" class="form-input !py-2">
        </div>
        <div>
            <label class="form-label" for="ins-basis">Dates mean</label>
            <select id="ins-basis" name="basis" class="form-select !py-2">
                <option value="submitted" <?= $insFilters['date_basis'] === 'submitted' ? 'selected' : '' ?>>Date claim submitted</option>
                <option value="service" <?= $insFilters['date_basis'] === 'service' ? 'selected' : '' ?>>Date of service</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="ins-provider">Provider</label>
            <select id="ins-provider" name="provider" class="form-select !py-2 min-w-[14rem]">
                <option value="">All providers</option>
                <?php foreach ($insProviders as $name): ?>
                    <option value="<?= htmlspecialchars($name) ?>" <?= strcasecmp($name, $insProvider) === 0 ? 'selected' : '' ?>>
                        <?= htmlspecialchars($name) ?><?= in_array($name, $insSettings->customisedProviders(), true) ? ' — own layout' : '' ?>
                    </option>
                <?php endforeach; ?>
                <?php if ($insProvider !== '' && !in_array($insProvider, $insProviders, true)): ?>
                    <option value="<?= htmlspecialchars($insProvider) ?>" selected><?= htmlspecialchars($insProvider) ?></option>
                <?php endif; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[12rem]">
            <label class="form-label" for="ins-q">Search</label>
            <input id="ins-q" type="search" name="q" value="<?= htmlspecialchars($insFilters['search']) ?>" placeholder="Patient, MRN, policy or invoice number" class="form-input !py-2 w-full">
        </div>
        <button type="submit" class="btn-primary">Apply</button>
        <a href="insurance_report.php" class="btn-secondary">Reset</a>
    </div>

    <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-slate-100">
        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mr-1">Status</span>
        <?php foreach (InsuranceReportSettings::STATUSES as $status):
            $on = in_array($status, $insFilters['statuses'], true); ?>
            <label class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-[12px] font-medium cursor-pointer transition <?= $on ? 'bg-blue-50 border-blue-300 text-blue-800' : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300' ?>">
                <input type="checkbox" name="status[]" value="<?= $status ?>" <?= $on ? 'checked' : '' ?> class="w-3.5 h-3.5 rounded border-slate-300">
                <span><?= $status ?></span>
            </label>
        <?php endforeach; ?>
        <span class="text-[11px] text-slate-400">Leave all unticked to include every status</span>
    </div>
</form>

<?php if ($insLayoutOwner !== ''): ?>
    <p class="mb-4 text-[12px] text-blue-800 bg-blue-50 border border-blue-200 rounded-xl px-3 py-2 flex items-center gap-2">
        <span class="material-symbols-outlined text-[18px]">assignment</span>
        Using the layout saved for <strong><?= htmlspecialchars($insLayoutOwner) ?></strong>.
        CSV and print output follow the same columns and wording.
    </p>
<?php endif; ?>

<!-- Summary ------------------------------------------------------------- -->
<?php if (!empty($insLayout['show_summary'])): ?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php
    $cards = [
        ['label' => 'Claims in the period', 'value' => number_format($totals['count']), 'note' => $cur . number_format($totals['claimed'], 2) . ' claimed', 'icon' => 'assignment_turned_in', 'tone' => 'bg-blue-50 text-blue-700'],
        ['label' => 'Approved', 'value' => $cur . number_format($totals['approved'], 2), 'note' => $totals['approved_count'] . ' of ' . ($totals['approved_count'] + $totals['rejected_count']) . ' decided (' . $totals['approval_rate'] . '%)', 'icon' => 'verified', 'tone' => 'bg-emerald-50 text-emerald-700'],
        ['label' => 'Rejected', 'value' => $cur . number_format($totals['rejected'], 2), 'note' => $totals['rejected_count'] . ' claim' . ($totals['rejected_count'] === 1 ? '' : 's'), 'icon' => 'block', 'tone' => $totals['rejected_count'] ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600', 'warn' => $totals['rejected_count'] > 0],
        ['label' => 'Still with the insurer', 'value' => $cur . number_format($totals['open'], 2), 'note' => $totals['open_count'] ? $totals['open_count'] . ' open, oldest ' . $totals['oldest_open_days'] . ' days' : 'Nothing outstanding', 'icon' => 'hourglass_top', 'tone' => $totals['open_count'] ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600', 'warn' => $totals['oldest_open_days'] > 60],
    ];
    foreach ($cards as $c): ?>
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
            <div class="flex items-start justify-between gap-2">
                <span class="text-[13px] text-slate-600"><?= htmlspecialchars($c['label']) ?></span>
                <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 <?= $c['tone'] ?>">
                    <span class="material-symbols-outlined text-[18px]"><?= $c['icon'] ?></span>
                </span>
            </div>
            <div class="text-2xl font-semibold text-slate-900 mt-2 tabular"><?= htmlspecialchars($c['value']) ?></div>
            <div class="text-[11px] mt-0.5 <?= !empty($c['warn']) ? 'text-amber-700 font-medium' : 'text-slate-500' ?>"><?= htmlspecialchars($c['note']) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-6">
    <!-- By provider -->
    <div class="xl:col-span-2 bg-white border border-slate-200 rounded-2xl shadow-2xs overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
            <h2 class="text-[13px] font-semibold text-slate-900">By provider</h2>
            <span class="text-[11px] text-slate-500"><?= count($insReport['by_provider']) ?> provider<?= count($insReport['by_provider']) === 1 ? '' : 's' ?></span>
        </div>
        <?php if (empty($insReport['by_provider'])): ?>
            <div class="nx-empty"><span class="material-symbols-outlined">inbox</span>No claims in this period.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="text-left font-semibold px-4 py-2">Provider</th>
                        <th class="text-right font-semibold px-3 py-2">Claims</th>
                        <th class="text-right font-semibold px-3 py-2">Claimed</th>
                        <th class="text-right font-semibold px-3 py-2">Approved</th>
                        <th class="text-right font-semibold px-3 py-2">Rejected</th>
                        <th class="text-right font-semibold px-4 py-2">Outstanding</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($insReport['by_provider'] as $row): ?>
                        <tr class="hover:bg-slate-50/70">
                            <td class="px-4 py-2 font-medium text-slate-900">
                                <a href="insurance_report.php?<?= http_build_query(['from' => $insFilters['from'], 'to' => $insFilters['to'], 'basis' => $insFilters['date_basis'], 'provider' => $row['provider']]) ?>" class="hover:text-blue-700 hover:underline">
                                    <?= htmlspecialchars($row['provider']) ?>
                                </a>
                            </td>
                            <td class="px-3 py-2 text-right tabular"><?= number_format($row['count']) ?></td>
                            <td class="px-3 py-2 text-right tabular"><?= $cur . number_format($row['claimed'], 2) ?></td>
                            <td class="px-3 py-2 text-right tabular text-emerald-700"><?= $cur . number_format($row['approved'], 2) ?></td>
                            <td class="px-3 py-2 text-right tabular <?= $row['rejected'] > 0 ? 'text-red-700' : 'text-slate-400' ?>"><?= $cur . number_format($row['rejected'], 2) ?></td>
                            <td class="px-4 py-2 text-right tabular <?= $row['open'] > 0 ? 'text-amber-700 font-medium' : 'text-slate-400' ?>">
                                <?= $cur . number_format($row['open'], 2) ?>
                                <?php if ($row['open_count'] > 0): ?>
                                    <span class="block text-[10px] font-normal text-slate-500"><?= $row['open_count'] ?> open, oldest <?= $row['oldest_open_days'] ?>d</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-slate-50 font-semibold text-slate-900">
                    <tr>
                        <td class="px-4 py-2">Total</td>
                        <td class="px-3 py-2 text-right tabular"><?= number_format($totals['count']) ?></td>
                        <td class="px-3 py-2 text-right tabular"><?= $cur . number_format($totals['claimed'], 2) ?></td>
                        <td class="px-3 py-2 text-right tabular"><?= $cur . number_format($totals['approved'], 2) ?></td>
                        <td class="px-3 py-2 text-right tabular"><?= $cur . number_format($totals['rejected'], 2) ?></td>
                        <td class="px-4 py-2 text-right tabular"><?= $cur . number_format($totals['open'], 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Aging -->
    <?php if (!empty($insLayout['show_aging'])): ?>
    <div class="bg-white border border-slate-200 rounded-2xl shadow-2xs overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
            <h2 class="text-[13px] font-semibold text-slate-900">How long claims have been waiting</h2>
            <p class="text-[11px] text-slate-500 mt-0.5">Submitted and pending claims only</p>
        </div>
        <?php if ($totals['open_count'] === 0): ?>
            <div class="nx-empty"><span class="material-symbols-outlined">task_alt</span>Every claim in this period has been decided.</div>
        <?php else: ?>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($insReport['aging'] as $i => $bucket):
                $share = $totals['open'] > 0 ? ($bucket['claimed'] / $totals['open'] * 100) : 0;
                $tone = $i >= 2 ? 'bg-red-500' : ($i === 1 ? 'bg-amber-500' : 'bg-blue-600'); ?>
                <li class="px-4 py-2.5">
                    <div class="flex items-baseline justify-between gap-2 text-[13px]">
                        <span class="text-slate-700"><?= htmlspecialchars($bucket['label']) ?></span>
                        <span class="tabular font-medium text-slate-900"><?= $cur . number_format($bucket['claimed'], 2) ?></span>
                    </div>
                    <div class="mt-1.5 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full <?= $tone ?>" style="width: <?= number_format(max($share, $bucket['count'] ? 2 : 0), 2) ?>%"></div>
                    </div>
                    <div class="text-[11px] text-slate-500 mt-1"><?= $bucket['count'] ?> claim<?= $bucket['count'] === 1 ? '' : 's' ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="px-4 py-3 border-t border-slate-100 text-[11px] text-slate-500">
            Average time to a decision: <strong class="text-slate-800"><?= $totals['average_settle_days'] ?> days</strong>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Claim detail --------------------------------------------------------- -->
<div class="bg-white border border-slate-200 rounded-2xl shadow-2xs overflow-hidden mb-6">
    <div class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 class="text-[13px] font-semibold text-slate-900"><?= htmlspecialchars($insLayout['title']) ?></h2>
            <p class="text-[11px] text-slate-500 mt-0.5">
                <?= count($table['rows']) ?> row<?= count($table['rows']) === 1 ? '' : 's' ?>,
                <?= count($table['headers']) ?> column<?= count($table['headers']) === 1 ? '' : 's' ?>
                <?php if (($insLayout['group_by'] ?? 'none') !== 'none'): ?>
                    &middot; <?= htmlspecialchars(InsuranceReportSettings::GROUPINGS[$insLayout['group_by']]) ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($canEditLayout): ?>
            <button type="button" onclick="NX.openModal('modal-insurance-layout')" class="text-[12px] font-medium text-blue-700 hover:underline">Choose columns</button>
        <?php endif; ?>
    </div>

    <?php if (empty($insReport['claims'])): ?>
        <div class="nx-empty">
            <span class="material-symbols-outlined">search_off</span>
            No claims match these filters. Widen the dates, or clear the provider and status filters.
        </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-[13px]">
            <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <tr>
                    <?php foreach ($table['headers'] as $i => $header): ?>
                        <th class="font-semibold px-3 py-2 whitespace-nowrap <?= in_array($table['types'][$i], ['money', 'number'], true) ? 'text-right' : 'text-left' ?>">
                            <?= htmlspecialchars($header) ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php
                $groups = $table['groups'] !== [] ? $table['groups'] : [['label' => '', 'rows' => $table['rows'], 'claimed' => $totals['claimed']]];
                foreach ($groups as $group):
                    if ($group['label'] !== ''): ?>
                        <tr class="bg-slate-50/80">
                            <td colspan="<?= count($table['headers']) ?>" class="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                                <?= htmlspecialchars($group['label']) ?>
                                <span class="font-normal normal-case tracking-normal text-slate-500">
                                    &middot; <?= count($group['rows']) ?> claim<?= count($group['rows']) === 1 ? '' : 's' ?>, <?= $cur . number_format($group['claimed'], 2) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($group['rows'] as $row): ?>
                        <tr class="hover:bg-slate-50/70">
                            <?php foreach ($row as $i => $cell):
                                $type = $table['types'][$i];
                                $key = $table['keys'][$i]; ?>
                                <td class="px-3 py-2 <?= in_array($type, ['money', 'number'], true) ? 'text-right tabular' : '' ?> <?= $key === 'notes' ? 'max-w-[18rem] truncate' : 'whitespace-nowrap' ?>"
                                    <?= $key === 'notes' && $cell !== '' ? 'title="' . htmlspecialchars((string) $cell) . '"' : '' ?>>
                                    <?php if ($key === 'status'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[11px] font-medium <?= $statusTone[$cell] ?? 'bg-slate-100 text-slate-700 border-slate-200' ?>">
                                            <?= htmlspecialchars((string) $cell) ?>
                                        </span>
                                    <?php elseif ($key === 'age_days'): ?>
                                        <span class="<?= (int) $cell > 60 ? 'text-amber-700 font-medium' : '' ?>"><?= number_format((float) $cell) ?></span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($fmt($cell, $type)) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Insurance billed but never claimed ----------------------------------- -->
<?php if (!empty($insReport['unclaimed'])): ?>
<?php $unclaimedTotal = array_sum(array_column($insReport['unclaimed'], 'insurance_covered')); ?>
<div class="bg-white border border-amber-200 rounded-2xl shadow-2xs overflow-hidden mb-6">
    <div class="px-4 py-3 border-b border-amber-100 bg-amber-50/60 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 class="text-[13px] font-semibold text-amber-900 flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[18px]">report</span>
                Insurance on invoices with no claim submitted
            </h2>
            <p class="text-[11px] text-amber-800 mt-0.5">
                <?= count($insReport['unclaimed']) ?> invoice<?= count($insReport['unclaimed']) === 1 ? '' : 's' ?>
                worth <?= $cur . number_format($unclaimedTotal, 2) ?> was billed to insurance but never claimed.
            </p>
        </div>
        <a href="billing.php?tab=insurance" class="text-[12px] font-medium text-amber-900 hover:underline">Submit a claim</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-[13px]">
            <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="text-left font-semibold px-4 py-2">Invoice</th>
                    <th class="text-left font-semibold px-3 py-2">Patient</th>
                    <th class="text-left font-semibold px-3 py-2">Provider on file</th>
                    <th class="text-left font-semibold px-3 py-2">Date of service</th>
                    <th class="text-right font-semibold px-3 py-2">Invoice total</th>
                    <th class="text-right font-semibold px-4 py-2">Insurance portion</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach (array_slice($insReport['unclaimed'], 0, 25) as $row): ?>
                    <tr class="hover:bg-slate-50/70">
                        <td class="px-4 py-2 font-medium text-slate-900"><?= htmlspecialchars($row['invoice_number']) ?></td>
                        <td class="px-3 py-2"><?= htmlspecialchars($row['patient_name']) ?><span class="text-slate-400"> &middot; <?= htmlspecialchars($row['patient_mrn']) ?></span></td>
                        <td class="px-3 py-2"><?= $row['provider'] !== '' ? htmlspecialchars($row['provider']) : '<span class="text-slate-400">none recorded</span>' ?></td>
                        <td class="px-3 py-2"><?= htmlspecialchars($row['service_date'] !== '' ? nxDate($row['service_date']) : '—') ?></td>
                        <td class="px-3 py-2 text-right tabular"><?= $cur . number_format($row['amount'], 2) ?></td>
                        <td class="px-4 py-2 text-right tabular font-medium text-amber-800"><?= $cur . number_format($row['insurance_covered'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($insReport['unclaimed']) > 25): ?>
        <p class="px-4 py-2 text-[11px] text-slate-500 border-t border-slate-100">Showing the 25 largest. The CSV export lists all <?= count($insReport['unclaimed']) ?>.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($insLayout['footer_note'])): ?>
    <p class="text-[12px] text-slate-500 border-l-2 border-slate-200 pl-3 mb-6"><?= nl2br(htmlspecialchars($insLayout['footer_note'])) ?></p>
<?php endif; ?>

<!-- Customise layout ----------------------------------------------------- -->
<?php if ($canEditLayout): ?>
<div id="modal-insurance-layout" class="nx-overlay hidden fixed inset-0 items-start justify-center p-4 overflow-y-auto" data-nx-modal>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl w-full max-w-3xl my-8">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
            <div>
                <h2 class="text-[15px] font-semibold text-slate-900">Customise the insurance report</h2>
                <p class="text-[12px] text-slate-500 mt-0.5">
                    Saved for <?= htmlspecialchars(nxClinicName()) ?>. Each provider can have its own columns and wording.
                </p>
            </div>
            <button type="button" onclick="NX.closeModal('modal-insurance-layout')" class="nx-icon-btn" aria-label="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/insurance_report_save.php" method="POST" class="p-5 space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="return" value="<?= htmlspecialchars($insQueryString) ?>">
            <input type="hidden" name="_toggles_present" value="1">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="form-label" for="lay-target">These settings apply to</label>
                    <?php
                    // Default to the provider the report is filtered to, so a
                    // provider's settings are never changed by accident.
                    $layTarget = $insProvider !== '' ? $insProvider : $insLayoutOwner;
                    ?>
                    <select id="lay-target" name="target" class="form-select w-full">
                        <option value="" <?= $layTarget === '' ? 'selected' : '' ?>>Every provider (clinic default)</option>
                        <?php foreach ($insProviders as $name): ?>
                            <option value="<?= htmlspecialchars($name) ?>" <?= strcasecmp($name, $layTarget) === 0 ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?><?= in_array($name, $insSettings->customisedProviders(), true) ? ' (has its own layout)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="__new__">A provider not in the list…</option>
                    </select>
                    <p class="text-[11px] text-slate-500 mt-1">A provider without its own settings uses the clinic default.</p>
                </div>
                <div>
                    <label class="form-label" for="lay-new">New provider name</label>
                    <input id="lay-new" type="text" name="new_provider" placeholder="e.g. BSP Health Insurance" class="form-input w-full" maxlength="80">
                    <p class="text-[11px] text-slate-500 mt-1">Only used when you pick “A provider not in the list”.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="form-label" for="lay-title">Report heading</label>
                    <input id="lay-title" type="text" name="title" value="<?= htmlspecialchars($insLayout['title']) ?>" class="form-input w-full" maxlength="90">
                </div>
                <div>
                    <label class="form-label" for="lay-ref">What this provider calls the policy number</label>
                    <input id="lay-ref" type="text" name="reference_label" value="<?= htmlspecialchars($insLayout['reference_label']) ?>" class="form-input w-full" maxlength="40" placeholder="Member number">
                </div>
                <div>
                    <label class="form-label" for="lay-prefix">Claim reference prefix</label>
                    <input id="lay-prefix" type="text" name="claim_prefix" value="<?= htmlspecialchars($insLayout['claim_prefix']) ?>" class="form-input w-full" maxlength="12" placeholder="BSP-">
                </div>
                <div>
                    <label class="form-label" for="lay-group">Arrange the claim list</label>
                    <select id="lay-group" name="group_by" class="form-select w-full">
                        <?php foreach (InsuranceReportSettings::GROUPINGS as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($insLayout['group_by'] ?? 'none') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <fieldset>
                <legend class="form-label">Columns, in the order the provider wants them</legend>
                <p class="text-[11px] text-slate-500 mb-2">Tick a column to include it. Drag the handles to reorder.</p>
                <ul id="lay-columns" class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                    <?php
                    // Chosen columns first, in order, then the rest
                    $ordered = array_values(array_filter($insLayout['columns'], static fn($k) => isset(InsuranceReportSettings::COLUMNS[$k])));
                    foreach (array_keys(InsuranceReportSettings::COLUMNS) as $key) {
                        if (!in_array($key, $ordered, true)) $ordered[] = $key;
                    }
                    foreach ($ordered as $key):
                        [$label] = InsuranceReportSettings::COLUMNS[$key];
                        $on = in_array($key, $insLayout['columns'], true); ?>
                        <li draggable="true" data-col="<?= $key ?>"
                            class="flex items-center gap-2 px-2.5 py-2 rounded-xl border bg-white cursor-grab <?= $on ? 'border-blue-300 bg-blue-50/40' : 'border-slate-200' ?>">
                            <span class="material-symbols-outlined text-[18px] text-slate-300 select-none">drag_indicator</span>
                            <label class="flex items-center gap-2 text-[13px] text-slate-800 flex-1 cursor-pointer">
                                <input type="checkbox" name="columns[]" value="<?= $key ?>" <?= $on ? 'checked' : '' ?> class="w-4 h-4 rounded border-slate-300">
                                <span><?= htmlspecialchars($label) ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </fieldset>

            <fieldset>
                <legend class="form-label">Statuses this provider's report should include</legend>
                <div class="flex flex-wrap gap-2 mt-1">
                    <?php foreach (InsuranceReportSettings::STATUSES as $status):
                        $on = $insLayout['statuses'] === [] || in_array($status, $insLayout['statuses'], true); ?>
                        <label class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border border-slate-200 text-[12px] font-medium cursor-pointer hover:border-slate-300">
                            <input type="checkbox" name="statuses[]" value="<?= $status ?>" <?= $on ? 'checked' : '' ?> class="w-3.5 h-3.5 rounded border-slate-300">
                            <span><?= $status ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset>
                <legend class="form-label">Include on the printed report</legend>
                <div class="flex flex-wrap gap-4 mt-1 text-[13px] text-slate-800">
                    <?php foreach ([
                        'show_summary'   => 'Summary figures',
                        'show_aging'     => 'How long claims have waited',
                        'show_logo'      => 'Clinic logo and address',
                        'show_signature' => 'Signature line',
                    ] as $flag => $label): ?>
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="<?= $flag ?>" <?= !empty($insLayout[$flag]) ? 'checked' : '' ?> class="w-4 h-4 rounded border-slate-300">
                            <span><?= htmlspecialchars($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div>
                <label class="form-label" for="lay-footer">Declaration or note at the foot of the report</label>
                <textarea id="lay-footer" name="footer_note" rows="3" maxlength="600" class="form-textarea w-full" placeholder="I certify that the services listed were provided as described."><?= htmlspecialchars($insLayout['footer_note']) ?></textarea>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 pt-4 border-t border-slate-200">
                <?php if ($insLayoutOwner !== ''): ?>
                    <button type="submit" name="action" value="remove" class="text-[12px] font-medium text-red-700 hover:underline"
                            data-nx-confirm="Remove the saved layout for <?= htmlspecialchars($insLayoutOwner) ?>? It will use the clinic default again.">
                        Remove <?= htmlspecialchars($insLayoutOwner) ?>'s layout
                    </button>
                <?php else: ?>
                    <span class="text-[11px] text-slate-400">Changes apply to CSV and printed output too.</span>
                <?php endif; ?>
                <div class="flex items-center gap-2 ml-auto">
                    <button type="button" onclick="NX.closeModal('modal-insurance-layout')" class="btn-secondary">Cancel</button>
                    <button type="submit" name="action" value="save" class="btn-primary">Save layout</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var list = document.getElementById('lay-columns');
    if (!list) return;

    // Keep the hidden order in step with the visible one: the checkbox order in
    // the DOM is the order the report prints.
    var dragging = null;
    list.addEventListener('dragstart', function (e) {
        var li = e.target.closest('li[data-col]');
        if (!li) return;
        dragging = li;
        li.classList.add('opacity-50');
        try { e.dataTransfer.setData('text/plain', li.dataset.col); } catch (err) {}
        e.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragend', function () {
        if (dragging) dragging.classList.remove('opacity-50');
        dragging = null;
    });
    list.addEventListener('dragover', function (e) {
        if (!dragging) return;
        e.preventDefault();
        var over = e.target.closest('li[data-col]');
        if (!over || over === dragging) return;
        var rect = over.getBoundingClientRect();
        var after = (e.clientY - rect.top) > rect.height / 2;
        list.insertBefore(dragging, after ? over.nextSibling : over);
    });

    // Tint a row as it is ticked so the chosen set reads at a glance
    list.addEventListener('change', function (e) {
        var box = e.target;
        if (box.type !== 'checkbox') return;
        var li = box.closest('li');
        if (!li) return;
        li.classList.toggle('border-blue-300', box.checked);
        li.classList.toggle('bg-blue-50/40', box.checked);
        li.classList.toggle('border-slate-200', !box.checked);
    });

    // Reveal the "new provider" box only when it is needed
    var target = document.getElementById('lay-target');
    var newBox = document.getElementById('lay-new');
    function syncTarget() {
        var isNew = target.value === '__new__';
        newBox.disabled = !isNew;
        newBox.closest('div').classList.toggle('opacity-50', !isNew);
        if (isNew) newBox.focus();
    }
    if (target && newBox) { target.addEventListener('change', syncTarget); syncTarget(); }
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
