<?php
/**
 * The billing section's view switcher. Pages set $activeTab before including
 * this: 'invoices', 'insurance', 'insurance_report' or 'zreport'.
 */
$nxBillingTabs = [
    'invoices'         => ['Invoices', 'receipt_long', 'billing.php?tab=invoices'],
    'insurance'        => ['Insurance claims', 'verified', 'billing.php?tab=insurance'],
    'insurance_report' => ['Insurance report', 'summarize', 'insurance_report.php'],
    'zreport'          => ['Z-Report', 'point_of_sale', 'billing.php?tab=zreport'],
];
$nxActiveTab = $activeTab ?? 'invoices';
?>
<nav class="mb-6 -mx-1 overflow-x-auto" aria-label="Billing views">
    <ul class="flex gap-1 px-1 border-b border-slate-200 min-w-max">
        <?php foreach ($nxBillingTabs as $key => [$label, $icon, $href]):
            $on = $nxActiveTab === $key; ?>
            <li>
                <a href="<?= $href ?>" <?= $on ? 'aria-current="page"' : '' ?>
                   class="relative inline-flex items-center gap-2 px-3 py-2.5 text-[13px] font-medium transition <?= $on ? 'text-blue-800' : 'text-slate-500 hover:text-slate-900' ?>">
                    <span class="material-symbols-outlined text-[18px]"><?= $icon ?></span>
                    <span><?= htmlspecialchars($label) ?></span>
                    <?php if ($on): ?>
                        <span class="absolute left-2 right-2 -bottom-px h-0.5 bg-blue-700 rounded-full"></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
