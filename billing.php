<?php
$pageTitle = "Billing & VMS Fiscal Invoices - ClinicFlow";
$activePage = "billing";
require_once __DIR__ . '/includes/autoloader.php';
include __DIR__ . '/includes/header.php';

use ClinicFlow\Services\VMSService;

$vmsService = new VMSService($pdo);

// Fetch settings
$stmtSet = $pdo->query("SELECT setting_key, setting_value FROM clinic_settings WHERE setting_key LIKE 'vms_%'");
$vmsSettings = $stmtSet->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch all patients for dropdown selection
$patientsList = $pdo->query("SELECT id, first_name, last_name, mrn, phone FROM patients ORDER BY first_name ASC")->fetchAll();

// Fetch active inventory items for invoice line items
$inventoryList = $pdo->query("SELECT id, name, sku, current_stock, unit_price, vms_tax_code FROM inventory WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Fetch invoices
$invoices = $pdo->query("SELECT * FROM invoices ORDER BY created_at DESC")->fetchAll();

$totalPending = array_reduce($invoices, fn($acc, $i) => $i['status'] === 'Pending' ? $acc + $i['patient_owed'] : $acc, 0);
$totalOverdue = array_reduce($invoices, fn($acc, $i) => $i['status'] === 'Overdue' ? $acc + $i['patient_owed'] : $acc, 0);
$totalCollected = array_reduce($invoices, fn($acc, $i) => $i['status'] === 'Paid' ? $acc + $i['amount'] : $acc, 0);
$totalVat = array_reduce($invoices, fn($acc, $i) => $acc + (float)($i['total_tax'] ?? 0), 0);

$activeTab = $_GET['tab'] ?? 'invoices';
$selectedDate = $_GET['report_date'] ?? date('Y-m-d');
$zReportData = $vmsService->getDailyFiscalReport($selectedDate);
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-on-surface">Billing & VMS Fiscal Invoices</h1>
        <p class="text-xs text-outline font-medium">FRCS VMS Phase 3 compliant Electronic Fiscal Device (EFD / POS) billing management</p>
    </div>
    <div class="flex items-center gap-2">
        <button onclick="document.getElementById('createInvoiceModal').classList.remove('hidden')" class="px-4 py-2 bg-primary text-on-primary rounded-xl text-xs font-bold shadow-xs hover:bg-primary-hover transition flex items-center gap-1.5">
            <span class="material-symbols-outlined text-base">receipt_long</span>
            Create VMS Fiscal Invoice
        </button>
    </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs font-medium flex items-center justify-between">
        <span><?= htmlspecialchars($_SESSION['flash_success']) ?></span>
        <button onclick="this.parentElement.remove()" class="text-emerald-600 font-bold">&times;</button>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-800 rounded-xl text-xs font-medium flex items-center justify-between">
        <span><?= htmlspecialchars($_SESSION['flash_error']) ?></span>
        <button onclick="this.parentElement.remove()" class="text-red-600 font-bold">&times;</button>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- Financial & VMS Summary Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 flex items-center justify-between shadow-xs">
        <div>
            <p class="text-[11px] font-bold text-outline uppercase tracking-wider">Collected Payments</p>
            <h3 class="text-2xl font-bold text-emerald-600 mt-1">$<?= number_format($totalCollected, 2) ?></h3>
            <p class="text-[11px] text-outline mt-1">Paid in full</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
            <span class="material-symbols-outlined text-2xl">check_circle</span>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 flex items-center justify-between shadow-xs">
        <div>
            <p class="text-[11px] font-bold text-outline uppercase tracking-wider">Pending Balances</p>
            <h3 class="text-2xl font-bold text-amber-600 mt-1">$<?= number_format($totalPending, 2) ?></h3>
            <p class="text-[11px] text-outline mt-1">Awaiting patient payment</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
            <span class="material-symbols-outlined text-2xl">pending</span>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 flex items-center justify-between shadow-xs">
        <div>
            <p class="text-[11px] font-bold text-outline uppercase tracking-wider">Overdue Accounts</p>
            <h3 class="text-2xl font-bold text-red-600 mt-1">$<?= number_format($totalOverdue, 2) ?></h3>
            <p class="text-[11px] text-outline mt-1">Past due date</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">
            <span class="material-symbols-outlined text-2xl">error</span>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 flex items-center justify-between shadow-xs">
        <div>
            <p class="text-[11px] font-bold text-outline uppercase tracking-wider">Total VAT Collected</p>
            <h3 class="text-2xl font-bold text-blue-600 mt-1">$<?= number_format($totalVat, 2) ?></h3>
            <p class="text-[11px] text-outline mt-1">FRCS 15% VAT component</p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
            <span class="material-symbols-outlined text-2xl">account_balance</span>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="flex border-b border-outline-variant/30 mb-6 space-x-6 text-xs font-bold">
    <a href="billing.php?tab=invoices" class="pb-3 border-b-2 <?= $activeTab === 'invoices' ? 'border-primary text-primary' : 'border-transparent text-outline hover:text-on-surface' ?>">
        Fiscal Invoices List
    </a>
    <a href="billing.php?tab=zreport" class="pb-3 border-b-2 <?= $activeTab === 'zreport' ? 'border-primary text-primary' : 'border-transparent text-outline hover:text-on-surface' ?>">
        Daily Fiscal Summary (Z-Report)
    </a>
    <a href="billing.php?tab=settings" class="pb-3 border-b-2 <?= $activeTab === 'settings' ? 'border-primary text-primary' : 'border-transparent text-outline hover:text-on-surface' ?>">
        VMS / EFD Configuration
    </a>
</div>

<?php if ($activeTab === 'invoices'): ?>
<!-- Invoices Table -->
<div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-5 shadow-xs">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="border-b border-outline-variant/30 text-outline uppercase text-[10px] font-bold tracking-wider">
                    <th class="py-2.5 px-3">Invoice # / Type</th>
                    <th class="py-2.5 px-3">Patient / Buyer TIN</th>
                    <th class="py-2.5 px-3">Service Date</th>
                    <th class="py-2.5 px-3">Total Amount</th>
                    <th class="py-2.5 px-3">VAT (15%)</th>
                    <th class="py-2.5 px-3">SDC Status</th>
                    <th class="py-2.5 px-3">Status</th>
                    <th class="py-2.5 px-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/20">
                <?php foreach ($invoices as $inv): ?>
                    <tr class="hover:bg-surface-container-low transition">
                        <td class="py-3 px-3 font-mono font-bold text-primary">
                            <?= htmlspecialchars($inv['invoice_number']) ?>
                            <span class="block text-[10px] font-sans font-bold text-slate-500 uppercase">
                                <?= htmlspecialchars($inv['invoice_type']) ?>-<?= htmlspecialchars($inv['transaction_type']) ?>
                            </span>
                        </td>
                        <td class="py-3 px-3 font-bold text-on-surface">
                            <?= htmlspecialchars($inv['patient_name']) ?>
                            <span class="block text-[10px] font-mono text-outline">
                                MRN: <?= htmlspecialchars($inv['patient_mrn']) ?>
                                <?= !empty($inv['buyer_tin']) ? '| TIN: ' . htmlspecialchars($inv['buyer_tin']) : '' ?>
                            </span>
                        </td>
                        <td class="py-3 px-3 text-outline font-medium"><?= htmlspecialchars($inv['service_date']) ?></td>
                        <td class="py-3 px-3 font-bold text-on-surface">$<?= number_format($inv['amount'], 2) ?></td>
                        <td class="py-3 px-3 font-semibold text-blue-600">$<?= number_format($inv['total_tax'] ?? 0, 2) ?></td>
                        <td class="py-3 px-3">
                            <?php if ($inv['is_fiscalized']): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                    <span class="material-symbols-outlined text-[12px]">verified</span> Fiscalized
                                </span>
                                <span class="block text-[9px] font-mono text-outline mt-0.5"><?= htmlspecialchars($inv['sdc_invoice_no']) ?></span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">
                                    Pending SDC
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-3">
                            <?php
                            $statusStyle = match($inv['status']) {
                                'Paid' => 'bg-emerald-100 text-emerald-800',
                                'Overdue' => 'bg-red-100 text-red-800',
                                'Cancelled' => 'bg-slate-200 text-slate-700',
                                default => 'bg-amber-100 text-amber-800'
                            };
                            ?>
                            <span class="inline-flex px-2.5 py-0.5 rounded-full text-[10px] font-bold <?= $statusStyle ?>">
                                <?= htmlspecialchars($inv['status']) ?>
                            </span>
                        </td>
                        <td class="py-3 px-3 text-right space-x-1 whitespace-nowrap">
                            <a href="print_invoice.php?id=<?= htmlspecialchars($inv['id']) ?>" target="_blank" class="inline-block px-2.5 py-1 bg-surface-container-high text-primary rounded-lg text-[11px] font-semibold hover:bg-surface-container-highest transition">
                                Fiscal Invoice
                            </a>
                            <?php if ($inv['status'] === 'Paid'): ?>
                                <a href="print_receipt.php?id=<?= htmlspecialchars($inv['id']) ?>" target="_blank" class="inline-block px-2.5 py-1 bg-emerald-100 text-emerald-800 rounded-lg text-[11px] font-semibold hover:bg-emerald-200 transition">
                                    Receipt
                                </a>
                            <?php endif; ?>
                            <?php if ($inv['status'] !== 'Cancelled' && $inv['is_fiscalized']): ?>
                                <form action="actions/vms_cancel_invoice.php" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to cancel this fiscal invoice? A refund/cancellation fiscal document will be generated.');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="invoice_id" value="<?= htmlspecialchars($inv['id']) ?>">
                                    <button type="submit" class="px-2 py-1 bg-red-100 text-red-700 rounded-lg text-[11px] font-semibold hover:bg-red-200 transition">
                                        Cancel
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($activeTab === 'zreport'): ?>
<!-- Daily Z-Report View -->
<div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs max-w-3xl">
    <form method="GET" action="billing.php" class="flex items-center gap-3 mb-6">
        <input type="hidden" name="tab" value="zreport">
        <label class="text-xs font-bold text-on-surface">Select Date:</label>
        <input type="date" name="report_date" value="<?= htmlspecialchars($selectedDate) ?>" class="px-3 py-1.5 border border-outline-variant/40 rounded-xl text-xs font-medium focus:ring-1 focus:ring-primary">
        <button type="submit" class="px-3 py-1.5 bg-primary text-on-primary rounded-xl text-xs font-bold hover:bg-primary-hover transition">
            Generate Report
        </button>
    </form>

    <div class="border border-outline-variant/30 rounded-xl p-5 font-mono text-xs bg-slate-50">
        <div class="text-center pb-4 border-b border-dashed border-slate-300">
            <h2 class="text-base font-bold text-slate-800 uppercase">FRCS EFD DAILY FISCAL REPORT (Z-REPORT)</h2>
            <p class="text-[11px] text-slate-500">TIN: <?= htmlspecialchars($vmsSettings['vms_seller_tin'] ?? '502579006') ?> | POS ID: <?= htmlspecialchars($vmsSettings['vms_pos_number'] ?? 'ASDF238/1.2') ?></p>
            <p class="text-[11px] text-slate-500">Date: <?= htmlspecialchars($zReportData['date']) ?></p>
        </div>

        <div class="py-4 border-b border-dashed border-slate-300 space-y-2">
            <h3 class="font-bold text-slate-800">SUMMARY BY INVOICE / TRANSACTION TYPE</h3>
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] text-slate-500 uppercase border-b border-slate-200">
                        <th class="py-1">Type</th>
                        <th class="py-1 text-center">Count</th>
                        <th class="py-1 text-right">Amount (FJD)</th>
                        <th class="py-1 text-right">VAT Tax (FJD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($zReportData['by_type'] as $type => $data): ?>
                        <tr>
                            <td class="py-1 font-bold"><?= htmlspecialchars($type) ?></td>
                            <td class="py-1 text-center"><?= $data['count'] ?></td>
                            <td class="py-1 text-right">$<?= number_format($data['amount'], 2) ?></td>
                            <td class="py-1 text-right">$<?= number_format($data['tax'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="py-4 border-b border-dashed border-slate-300 space-y-2">
            <h3 class="font-bold text-slate-800">SUMMARY BY PAYMENT TYPE</h3>
            <div class="grid grid-cols-2 gap-2 text-[11px]">
                <?php foreach ($zReportData['by_payment'] as $pType => $pAmt): ?>
                    <div class="flex justify-between py-0.5 border-b border-slate-200/50">
                        <span><?= htmlspecialchars($pType) ?>:</span>
                        <span class="font-bold">$<?= number_format($pAmt, 2) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="pt-4 space-y-1 text-right font-bold text-sm text-slate-900">
            <div>Total Turnover Sales: $<?= number_format($zReportData['total_sales'], 2) ?></div>
            <div>Total Refunds / Credits: $<?= number_format($zReportData['total_refunds'], 2) ?></div>
            <div class="text-blue-600">Total VAT Collected: $<?= number_format($zReportData['total_vat'], 2) ?></div>
        </div>
    </div>
</div>

<?php elseif ($activeTab === 'settings'): ?>
<!-- VMS Settings Form -->
<div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs max-w-2xl">
    <h2 class="text-base font-bold text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-primary">settings</span>
        VMS Phase 3 & EFD Integration Settings
    </h2>

    <form action="actions/vms_save_settings.php" method="POST" class="space-y-4 text-xs">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="flex items-center gap-2 p-3 bg-slate-50 border border-slate-200 rounded-xl">
            <input type="checkbox" id="vms_enabled" name="vms_enabled" value="1" <?= ($vmsSettings['vms_enabled'] ?? '1') === '1' ? 'checked' : '' ?> class="w-4 h-4 text-primary rounded focus:ring-primary">
            <label for="vms_enabled" class="font-bold text-on-surface">Enable FRCS VMS Fiscalization for all created invoices</label>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block font-bold text-outline mb-1">Taxpayer Seller TIN</label>
                <input type="text" name="vms_seller_tin" value="<?= htmlspecialchars($vmsSettings['vms_seller_tin'] ?? '502579006') ?>" required class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-mono">
            </div>
            <div>
                <label class="block font-bold text-outline mb-1">Accredited POS Number</label>
                <input type="text" name="vms_pos_number" value="<?= htmlspecialchars($vmsSettings['vms_pos_number'] ?? 'ASDF238/1.2') ?>" required class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-mono">
            </div>
        </div>

        <div>
            <label class="block font-bold text-outline mb-1">Business Location Address</label>
            <input type="text" name="vms_business_location" value="<?= htmlspecialchars($vmsSettings['vms_business_location'] ?? 'Suva Central Clinic, 2 Woodstand Road, Suva') ?>" required class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl">
        </div>

        <div>
            <label class="block font-bold text-outline mb-1">SDC / VMS Sandbox API Base URL</label>
            <input type="url" name="vms_sdc_url" value="<?= htmlspecialchars($vmsSettings['vms_sdc_url'] ?? 'https://tap.sandbox.vms.frcs.org.fj') ?>" required class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-mono">
        </div>

        <div class="pt-2 border-t border-outline-variant/20">
            <h3 class="font-bold text-on-surface mb-2">VMS Tax Label Rates (%)</h3>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-medium text-outline mb-1">Label A (Standard VAT %)</label>
                    <input type="number" step="0.01" name="vms_tax_rate_a" value="<?= htmlspecialchars($vmsSettings['vms_tax_rate_a'] ?? '15.00') ?>" class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-mono">
                </div>
                <div>
                    <label class="block font-medium text-outline mb-1">Label E (Exempt %)</label>
                    <input type="number" step="0.01" name="vms_tax_rate_e" value="<?= htmlspecialchars($vmsSettings['vms_tax_rate_e'] ?? '0.00') ?>" class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-mono">
                </div>
                <div>
                    <label class="block font-medium text-outline mb-1">Label F (Zero-Rated %)</label>
                    <input type="number" step="0.01" name="vms_tax_rate_f" value="<?= htmlspecialchars($vmsSettings['vms_tax_rate_f'] ?? '0.00') ?>" class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-mono">
                </div>
                <div>
                    <label class="block font-medium text-outline mb-1">Label P (Special Tax %)</label>
                    <input type="number" step="0.01" name="vms_tax_rate_p" value="<?= htmlspecialchars($vmsSettings['vms_tax_rate_p'] ?? '0.25') ?>" class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-mono">
                </div>
            </div>
        </div>

        <button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-xl text-xs font-bold hover:bg-primary-hover transition">
            Save VMS Configuration
        </button>
    </form>
</div>
<?php endif; ?>

<!-- Create VMS Invoice Modal -->
<div id="createInvoiceModal" class="hidden fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 w-full max-w-3xl p-6 shadow-xl max-h-[90vh] overflow-y-auto text-xs">
        <div class="flex items-center justify-between pb-3 border-b border-outline-variant/30 mb-4">
            <h2 class="text-base font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">receipt</span>
                New VMS Fiscal Invoice (POS Mode)
            </h2>
            <button onclick="document.getElementById('createInvoiceModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg font-bold">&times;</button>
        </div>

        <form action="actions/vms_create_invoice.php" method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-outline mb-1">Select Patient</label>
                    <select name="patient_id" onchange="autoFillPatient(this)" class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl bg-surface focus:ring-1 focus:ring-primary">
                        <option value="">-- Select Patient --</option>
                        <?php foreach ($patientsList as $p): ?>
                            <option value="<?= htmlspecialchars($p['id']) ?>" data-name="<?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?>" data-mrn="<?= htmlspecialchars($p['mrn']) ?>">
                                <?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?> (<?= htmlspecialchars($p['mrn']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-outline mb-1">Patient Name *</label>
                    <input type="text" id="modal_patient_name" name="patient_name" required class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl">
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block font-bold text-outline mb-1">Patient MRN *</label>
                    <input type="text" id="modal_patient_mrn" name="patient_mrn" required class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-mono">
                </div>
                <div>
                    <label class="block font-bold text-outline mb-1">Invoice Type</label>
                    <select name="invoice_type" class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-bold text-primary">
                        <option value="Normal">Normal Invoice</option>
                        <option value="Advance">Advance Invoice</option>
                        <option value="Proforma">Proforma Invoice</option>
                        <option value="Copy">Copy Invoice</option>
                        <option value="Training">Training Invoice</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-outline mb-1">Transaction Type</label>
                    <select name="transaction_type" class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-bold">
                        <option value="Sale">Sale (+)</option>
                        <option value="Refund">Refund (-)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 bg-slate-50 p-3 rounded-xl border border-slate-200">
                <div>
                    <label class="block font-bold text-outline mb-1">Buyer TIN (Optional for B2B / Cancel)</label>
                    <input type="text" name="buyer_tin" placeholder="e.g. 502579006" class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-mono">
                </div>
                <div>
                    <label class="block font-bold text-outline mb-1">Buyer Cost Center (Optional)</label>
                    <input type="text" name="buyer_cost_center" placeholder="e.g. COST-01" class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-mono">
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="font-bold text-on-surface">Invoice Line Items</label>
                    <button type="button" onclick="addInvoiceRow()" class="px-2.5 py-1 bg-emerald-600 text-white rounded-lg text-[11px] font-bold hover:bg-emerald-700 transition">
                        + Add Custom Row
                    </button>
                </div>

                <div class="border border-outline-variant/30 rounded-xl overflow-hidden">
                    <table class="w-full text-left" id="invoiceItemsTable">
                        <thead class="bg-surface-container-high text-[10px] font-bold uppercase text-outline">
                            <tr>
                                <th class="py-2 px-3">Select Inventory Item or Custom Service</th>
                                <th class="py-2 px-2">SKU / GTIN</th>
                                <th class="py-2 px-2 w-16">Qty</th>
                                <th class="py-2 px-2 w-24">Price ($)</th>
                                <th class="py-2 px-2 w-20">Tax Label</th>
                                <th class="py-2 px-2 w-10"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/20">
                            <tr>
                                <td class="py-2 px-3 space-y-1">
                                    <select onchange="onInventoryItemSelect(this)" class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-semibold text-xs text-primary bg-white">
                                        <option value="">-- Choose from Inventory Stock (Optional) --</option>
                                        <?php foreach ($inventoryList as $invItem): ?>
                                            <option value="<?= htmlspecialchars($invItem['id']) ?>"
                                                    data-name="<?= htmlspecialchars($invItem['name']) ?>"
                                                    data-sku="<?= htmlspecialchars($invItem['sku']) ?>"
                                                    data-price="<?= htmlspecialchars($invItem['unit_price']) ?>"
                                                    data-tax="<?= htmlspecialchars($invItem['vms_tax_code'] ?: 'A') ?>"
                                                    data-stock="<?= (int)$invItem['current_stock'] ?>">
                                                <?= htmlspecialchars($invItem['name']) ?> (Stock: <?= (int)$invItem['current_stock'] ?> | $<?= number_format($invItem['unit_price'], 2) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="inventory_id[]" value="">
                                    <input type="text" name="item_name[]" value="Medical Consultation & Diagnosis" required placeholder="Item / Service Name" class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg text-xs font-medium">
                                </td>
                                <td class="py-2 px-2">
                                    <input type="text" name="gtin[]" value="10009812" placeholder="SKU/GTIN" class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-mono text-[11px]">
                                </td>
                                <td class="py-2 px-2">
                                    <input type="number" step="0.5" name="quantity[]" value="1" required class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-mono">
                                </td>
                                <td class="py-2 px-2">
                                    <input type="number" step="0.01" name="unit_price[]" value="150.00" required class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-mono">
                                </td>
                                <td class="py-2 px-2">
                                    <select name="tax_label[]" class="w-full px-1 py-1 border border-outline-variant/40 rounded-lg font-bold">
                                        <option value="A" selected>A (15% VAT)</option>
                                        <option value="E">E (Exempt 0%)</option>
                                        <option value="F">F (Zero 0%)</option>
                                        <option value="P">P (0.25%)</option>
                                    </select>
                                </td>
                                <td class="py-2 px-2 text-center">
                                    <button type="button" onclick="this.closest('tr').remove()" class="text-red-500 font-bold">&times;</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <label class="block font-bold text-on-surface mb-1">Payment Method</label>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <select name="payment_type[]" class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-bold">
                            <option value="Cash">Cash</option>
                            <option value="Card">Card</option>
                            <option value="Check">Check</option>
                            <option value="Wire Transfer">Wire Transfer</option>
                            <option value="Voucher">Voucher</option>
                            <option value="Mobile Money">Mobile Money</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div>
                        <input type="number" step="0.01" name="payment_amount[]" placeholder="Amount Paid ($)" class="w-full px-3 py-2 border border-outline-variant/40 rounded-xl font-mono">
                    </div>
                </div>
            </div>

            <div class="pt-3 border-t border-outline-variant/30 flex items-center justify-end gap-2">
                <button type="button" onclick="document.getElementById('createInvoiceModal').classList.add('hidden')" class="px-4 py-2 bg-surface-container-high text-outline rounded-xl font-bold hover:bg-surface-container-highest transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-emerald-600 text-white rounded-xl font-bold hover:bg-emerald-700 transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">task_alt</span>
                    Create & Fiscalize Invoice
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const inventoryList = <?= json_encode($inventoryList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function autoFillPatient(select) {
    const opt = select.options[select.selectedIndex];
    if (opt.value) {
        document.getElementById('modal_patient_name').value = opt.getAttribute('data-name');
        document.getElementById('modal_patient_mrn').value = opt.getAttribute('data-mrn');
    }
}

function onInventoryItemSelect(select) {
    const tr = select.closest('tr');
    const opt = select.options[select.selectedIndex];

    const invIdInput = tr.querySelector('input[name="inventory_id[]"]');
    const itemNameInput = tr.querySelector('input[name="item_name[]"]');
    const gtinInput = tr.querySelector('input[name="gtin[]"]');
    const unitPriceInput = tr.querySelector('input[name="unit_price[]"]');
    const taxSelect = tr.querySelector('select[name="tax_label[]"]');

    if (opt.value) {
        invIdInput.value = opt.value;
        itemNameInput.value = opt.getAttribute('data-name') || '';
        gtinInput.value = opt.getAttribute('data-sku') || '';
        unitPriceInput.value = parseFloat(opt.getAttribute('data-price') || 0).toFixed(2);

        const taxCode = opt.getAttribute('data-tax') || 'A';
        if (taxSelect) {
            taxSelect.value = taxCode;
        }
    } else {
        invIdInput.value = '';
    }
}

function addInvoiceRow() {
    const tbody = document.querySelector('#invoiceItemsTable tbody');
    const tr = document.createElement('tr');

    let invOptions = '<option value="">-- Choose from Inventory Stock (Optional) --</option>';
    if (Array.isArray(inventoryList)) {
        inventoryList.forEach(item => {
            const stock = parseInt(item.current_stock) || 0;
            const price = parseFloat(item.unit_price) || 0;
            const tax = item.vms_tax_code || 'A';
            invOptions += `<option value="${item.id}" data-name="${item.name}" data-sku="${item.sku}" data-price="${price}" data-tax="${tax}" data-stock="${stock}">${item.name} (Stock: ${stock} | $${price.toFixed(2)})</option>`;
        });
    }

    tr.innerHTML = `
        <td class="py-2 px-3 space-y-1">
            <select onchange="onInventoryItemSelect(this)" class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-semibold text-xs text-primary bg-white">
                ${invOptions}
            </select>
            <input type="hidden" name="inventory_id[]" value="">
            <input type="text" name="item_name[]" placeholder="Item / Service Name" required class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg text-xs font-medium">
        </td>
        <td class="py-2 px-2">
            <input type="text" name="gtin[]" placeholder="SKU/GTIN" class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-mono text-[11px]">
        </td>
        <td class="py-2 px-2">
            <input type="number" step="0.5" name="quantity[]" value="1" required class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-mono">
        </td>
        <td class="py-2 px-2">
            <input type="number" step="0.01" name="unit_price[]" value="0.00" required class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-mono">
        </td>
        <td class="py-2 px-2">
            <select name="tax_label[]" class="w-full px-1 py-1 border border-outline-variant/40 rounded-lg font-bold">
                <option value="A">A (15% VAT)</option>
                <option value="E">E (Exempt 0%)</option>
                <option value="F">F (Zero 0%)</option>
                <option value="P">P (0.25%)</option>
            </select>
        </td>
        <td class="py-2 px-2 text-center">
            <button type="button" onclick="this.closest('tr').remove()" class="text-red-500 font-bold">&times;</button>
        </td>
    `;
    tbody.appendChild(tr);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
