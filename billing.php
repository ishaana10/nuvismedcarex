<?php
$pageTitle = "Billing & Invoices - NuvisMedcareX";
$activePage = "billing";
require_once __DIR__ . '/includes/autoloader.php';
require_once __DIR__ . '/includes/pagination.php';
include __DIR__ . '/includes/header.php';

use ClinicFlow\Services\VMSService;

$vmsService = new VMSService($pdo);

$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

// VMS settings belong to the clinic, so read them through nxSettings() rather
// than the installation-wide table, which held one clinic's values for all.
$vmsSettings = array_filter(
    nxSettings(),
    static fn($k) => str_starts_with((string) $k, 'vms_'),
    ARRAY_FILTER_USE_KEY
);

// Fetch all patients for dropdown selection (Tenant Scoped)
$patientsStmt = $pdo->prepare("SELECT id, first_name, last_name, mrn, phone FROM patients WHERE tenant_id = ? ORDER BY first_name ASC");
$patientsStmt->execute([$currentTenantId]);
$patientsList = $patientsStmt->fetchAll();

// Fetch active inventory items for invoice line items (Tenant Scoped)
$inventoryStmt = $pdo->prepare("SELECT id, name, sku, current_stock, unit_price, vms_tax_code FROM inventory WHERE tenant_id = ? AND is_active = 1 ORDER BY name ASC");
$inventoryStmt->execute([$currentTenantId]);
$inventoryList = $inventoryStmt->fetchAll();

// Fetch invoices (Tenant Scoped)
$invoicesStmt = $pdo->prepare("SELECT * FROM invoices WHERE tenant_id = ? ORDER BY created_at DESC");
$invoicesStmt->execute([$currentTenantId]);
$invoices = $invoicesStmt->fetchAll();

$totalPending = array_reduce($invoices, fn($acc, $i) => $i['status'] === 'Pending' ? $acc + $i['patient_owed'] : $acc, 0);
$totalOverdue = array_reduce($invoices, fn($acc, $i) => $i['status'] === 'Overdue' ? $acc + $i['patient_owed'] : $acc, 0);
$totalCollected = array_reduce($invoices, fn($acc, $i) => $i['status'] === 'Paid' ? $acc + $i['amount'] : $acc, 0);
$totalVat = array_reduce($invoices, fn($acc, $i) => $acc + (float)($i['total_tax'] ?? 0), 0);

$activeTab = (string) ($_GET['tab'] ?? 'invoices');
if (!in_array($activeTab, ['invoices', 'insurance', 'zreport'], true)) {
    $activeTab = 'invoices';
}

$totalInvoicesCount = count($invoices);
$paginationInvoices = getPaginationParams(10, [5, 10, 25, 50, 100], $pdo);
$paginatedInvoices = array_slice($invoices, $paginationInvoices['offset'], $paginationInvoices['limit']);

// Which period the Z-Report covers (validated, and shared with the printable
// version and the CSV export)
require_once __DIR__ . '/includes/zreport_query.php';
$cur = nxCurrencySymbol();
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-on-surface">Billing & Invoices</h1>
        <p class="text-xs text-outline font-medium">Patient billing and invoice management</p>
    </div>
    <div class="flex items-center gap-2">
        <button onclick="NX.openModal('createInvoiceModal')" class="px-4 py-2 bg-primary text-on-primary rounded-xl text-xs font-bold shadow-xs hover:bg-primary-hover transition flex items-center gap-1.5">
            <span class="material-symbols-outlined text-base">receipt_long</span>
            Create Invoice
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

<?php include __DIR__ . '/includes/billing_tabs.php'; ?>

<?php if ($activeTab === 'invoices'): ?>
<!-- Invoices Table -->
<div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-5 shadow-xs">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="border-b border-outline-variant/30 text-outline uppercase text-[10px] font-bold tracking-wider">
                    <th class="py-2.5 px-3">Invoice # / Type</th>
                    <th class="py-2.5 px-3">Patient</th>
                    <th class="py-2.5 px-3">Service Date</th>
                    <th class="py-2.5 px-3">Total Amount</th>
                    <th class="py-2.5 px-3">Tax</th>
                    <th class="py-2.5 px-3">Status</th>
                    <th class="py-2.5 px-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/20">
                <?php if (empty($paginatedInvoices)): ?>
                    <tr>
                        <td colspan="7" class="py-6 text-center text-outline italic">No invoices found.</td>
                    </tr>
                <?php else: ?>
                <?php foreach ($paginatedInvoices as $inv): ?>
                    <tr>
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
                            </span>
                        </td>
                        <td class="py-3 px-3 text-outline font-medium"><?= htmlspecialchars($inv['service_date']) ?></td>
                        <td class="py-3 px-3 font-bold text-on-surface">$<?= number_format($inv['amount'], 2) ?></td>
                        <td class="py-3 px-3 font-semibold text-blue-600">$<?= number_format($inv['total_tax'] ?? 0, 2) ?></td>
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
                                View Invoice
                            </a>
                            <?php if ($inv['status'] === 'Paid'): ?>
                                <a href="print_receipt.php?id=<?= htmlspecialchars($inv['id']) ?>" target="_blank" class="inline-block px-2.5 py-1 bg-emerald-100 text-emerald-800 rounded-lg text-[11px] font-semibold hover:bg-emerald-200 transition">
                                    Receipt
                                </a>
                                <a href="actions/send_receipt_email.php?invoice_id=<?= htmlspecialchars($inv['id']) ?>" data-nx-confirm="Send payment receipt via email to patient?" class="inline-block px-2 py-1 bg-blue-100 text-blue-800 rounded-lg text-[11px] font-semibold hover:bg-blue-200 transition" title="Email Receipt">
                                    Email
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= renderPagination($totalInvoicesCount, $paginationInvoices['page'], $paginationInvoices['limit'], 'billing.php', ['tab' => 'invoices']) ?>
</div>

<?php elseif ($activeTab === 'insurance'): ?>
<!-- Insurance Claims Management Tab -->
<?php
$insuranceClaimsStmt = $pdo->prepare("SELECT * FROM insurance_claims WHERE tenant_id = ? ORDER BY created_at DESC");
$insuranceClaimsStmt->execute([$currentTenantId]);
$insuranceClaims = $insuranceClaimsStmt->fetchAll();

$totalClaimsCount = count($insuranceClaims);
$paginationClaims = getPaginationParams(10, [5, 10, 25, 50, 100], $pdo);
$paginatedClaims = array_slice($insuranceClaims, $paginationClaims['offset'], $paginationClaims['limit']);

$editClaimId = $_GET['claim_id'] ?? '';
$editClaim = null;
if (($_GET['action'] ?? '') === 'edit_claim' && !empty($editClaimId)) {
    $editClaimStmt = $pdo->prepare("SELECT * FROM insurance_claims WHERE id = ? AND tenant_id = ?");
    $editClaimStmt->execute([$editClaimId, $currentTenantId]);
    $editClaim = $editClaimStmt->fetch();
}
?>
<div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 overflow-hidden shadow-xs">
    <div class="p-4 bg-surface-container-low border-b border-outline-variant/30 flex items-center justify-between">
        <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-base">verified</span>
            <span>Submitted Insurance Claims</span>
        </h3>
        <button onclick="NX.openModal('createClaimModal')" class="px-3 py-1.5 bg-primary text-white rounded-xl text-xs font-bold hover:bg-primary-hover transition flex items-center gap-1">
            <span class="material-symbols-outlined text-sm">add</span>
            <span>Submit Claim</span>
        </button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead class="bg-surface-container text-outline uppercase font-semibold text-[10px]">
                <tr>
                    <th class="py-3 px-4">Claim ID</th>
                    <th class="py-3 px-4">Invoice ID</th>
                    <th class="py-3 px-4">Provider</th>
                    <th class="py-3 px-4">Policy No</th>
                    <th class="py-3 px-4">Claim Amount</th>
                    <th class="py-3 px-4">Status</th>
                    <th class="py-3 px-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/20 font-medium">
                <?php if (empty($paginatedClaims)): ?>
                    <tr>
                        <td colspan="7" class="py-6 text-center text-outline italic">No insurance claims submitted yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($paginatedClaims as $claim): ?>
                        <tr class="hover:bg-surface-container-low/50">
                            <td class="py-3 px-4 font-mono font-bold"><?= htmlspecialchars(\ClinicFlow\Services\InsuranceReportService::claimReference($claim['id'], (string) ($claim['created_at'] ?? ''))) ?></td>
                            <td class="py-3 px-4 font-mono"><?= htmlspecialchars($claim['invoice_id']) ?></td>
                            <td class="py-3 px-4"><?= htmlspecialchars($claim['provider_name']) ?></td>
                            <td class="py-3 px-4 font-mono"><?= htmlspecialchars($claim['policy_number']) ?></td>
                            <td class="py-3 px-4 font-bold">$<?= number_format((float)$claim['claim_amount'], 2) ?></td>
                            <td class="py-3 px-4">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $claim['status'] === 'Approved' ? 'bg-emerald-100 text-emerald-800' : ($claim['status'] === 'Rejected' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800') ?>">
                                    <?= htmlspecialchars($claim['status']) ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="inline-flex items-center gap-1 justify-end">
                                    <form action="actions/insurance_claim_save.php" method="POST" class="inline-flex gap-1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="claim_id" value="<?= htmlspecialchars($claim['id']) ?>">
                                        <button type="submit" name="status" value="Approved" class="px-2 py-0.5 bg-emerald-600 text-white rounded text-[10px] font-bold hover:bg-emerald-700">Approve</button>
                                        <button type="submit" name="status" value="Rejected" class="px-2 py-0.5 bg-red-600 text-white rounded text-[10px] font-bold hover:bg-red-700">Reject</button>
                                    </form>
                                    <a href="billing.php?tab=insurance&action=edit_claim&claim_id=<?= htmlspecialchars($claim['id']) ?>" class="p-1 text-slate-500 hover:text-blue-600 rounded-lg hover:bg-slate-100 transition" title="Edit Claim">
                                        <span class="material-symbols-outlined text-base">edit</span>
                                    </a>
                                    <form action="actions/insurance_claim_save.php" method="POST" class="inline" data-nx-confirm="Are you sure you want to delete this insurance claim?">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_claim">
                                        <input type="hidden" name="claim_id" value="<?= htmlspecialchars($claim['id']) ?>">
                                        <button type="submit" class="p-1 text-slate-500 hover:text-red-600 rounded-lg hover:bg-slate-100 transition" title="Delete Claim">
                                            <span class="material-symbols-outlined text-base">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= renderPagination($totalClaimsCount, $paginationClaims['page'], $paginationClaims['limit'], 'billing.php', ['tab' => 'insurance']) ?>
</div>

<!-- Modal Edit Insurance Claim -->
<?php if ($editClaim): ?>
<div class="nx-overlay items-center justify-center p-4" data-nx-modal data-nx-static>
    <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/30 shadow-2xl max-w-md w-full overflow-hidden">
        <div class="p-5 bg-slate-900 text-white flex items-center justify-between">
            <h3 class="font-bold text-sm">Edit Insurance Claim</h3>
            <a href="billing.php?tab=insurance" class="text-slate-400 hover:text-white">
                <span class="material-symbols-outlined">close</span>
            </a>
        </div>
        <form action="actions/insurance_claim_save.php" method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="edit_claim">
            <input type="hidden" name="claim_id" value="<?= htmlspecialchars($editClaim['id']) ?>">

            <div>
                <label class="block font-bold text-outline mb-1">Insurance Provider Name *</label>
                <input type="text" name="provider_name" required value="<?= htmlspecialchars($editClaim['provider_name']) ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-outline mb-1">Policy / Member Number *</label>
                <input type="text" name="policy_number" required value="<?= htmlspecialchars($editClaim['policy_number']) ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-outline mb-1">Claim Amount ($) *</label>
                <input type="number" step="0.01" name="claim_amount" required value="<?= htmlspecialchars($editClaim['claim_amount']) ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-outline mb-1">Status</label>
                <select name="status" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
                    <?php foreach (['Submitted', 'Approved', 'Rejected', 'Pending'] as $st): ?>
                        <option value="<?= $st ?>" <?= $editClaim['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-bold text-outline mb-1">Notes / Remarks</label>
                <textarea name="notes" rows="2" class="w-full bg-surface-container-low p-2.5 rounded-xl border border-outline-variant/40 font-medium"><?= htmlspecialchars($editClaim['notes'] ?? '') ?></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-3">
                <a href="billing.php?tab=insurance" class="px-4 py-2 bg-slate-100 text-slate-700 font-bold rounded-xl hover:bg-slate-200">Cancel</a>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary-hover shadow-xs">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal Submit Insurance Claim -->
<div id="createClaimModal" class="nx-overlay hidden items-center justify-center p-4" data-nx-modal>
    <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/30 shadow-2xl max-w-md w-full overflow-hidden">
        <div class="p-5 bg-slate-900 text-white flex items-center justify-between">
            <h3 class="font-bold text-sm">Submit New Insurance Claim</h3>
            <button type="button" onclick="NX.closeModal('createClaimModal')" class="text-slate-400 hover:text-white">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form action="actions/insurance_claim_save.php" method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_claim">

            <div>
                <label class="block font-bold text-outline mb-1">Select Invoice *</label>
                <select name="invoice_id" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
                    <?php foreach ($invoices as $inv): ?>
                        <option value="<?= htmlspecialchars($inv['id']) ?>">
                            <?= htmlspecialchars($inv['invoice_number']) ?> - <?= htmlspecialchars($inv['patient_name']) ?> ($<?= number_format((float)$inv['amount'], 2) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-bold text-outline mb-1">Patient ID *</label>
                <input type="text" name="patient_id" required value="<?= htmlspecialchars($invoices[0]['patient_id'] ?? 'pat-1') ?>" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-outline mb-1">Insurance Provider Name</label>
                <input type="text" name="provider_name" value="Fiji National Health Insurance" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-outline mb-1">Policy / Member Number</label>
                <input type="text" name="policy_number" placeholder="e.g. POL-882019" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-outline mb-1">Claim Amount ($) *</label>
                <input type="number" step="0.01" name="claim_amount" required placeholder="100.00" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-outline-variant/20">
                <button type="button" onclick="NX.closeModal('createClaimModal')" class="px-4 py-2 bg-surface-container rounded-xl font-semibold">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90">Submit Claim</button>
            </div>
        </form>
    </div>
</div>

<?php elseif ($activeTab === 'zreport'): ?>
<?php
$z = $zReportData;
$hasRows = $z['total_invoices'] > 0;
$reconOff = abs($z['reconciliation']) >= 0.01;
?>

<!-- Period ----------------------------------------------------------------- -->
<form method="GET" action="billing.php" class="bg-white border border-slate-200 rounded-2xl p-4 mb-6 shadow-2xs space-y-4">
    <input type="hidden" name="tab" value="zreport">
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mr-1">Period</span>
        <?php foreach ($zPresets as $key => [$label, , ]):
            $on = $zPreset === $key; ?>
            <a href="billing.php?tab=zreport&preset=<?= $key ?>"
               class="px-2.5 py-1 rounded-full text-[12px] font-medium border transition <?= $on ? 'bg-blue-700 text-white border-blue-700' : 'bg-white text-slate-600 border-slate-200 hover:border-slate-300' ?>">
                <?= htmlspecialchars($label) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="flex flex-wrap items-end gap-3 pt-3 border-t border-slate-100">
        <div>
            <label class="form-label" for="z-date">Single day</label>
            <input id="z-date" type="date" name="report_date" value="<?= htmlspecialchars($zSelectedDate) ?>" class="form-input !py-2">
        </div>
        <span class="text-[11px] text-slate-400 pb-2.5">or a range</span>
        <div>
            <label class="form-label" for="z-from">From</label>
            <input id="z-from" type="date" name="from_date" value="<?= htmlspecialchars($zFrom ?? '') ?>" class="form-input !py-2">
        </div>
        <div>
            <label class="form-label" for="z-to">To</label>
            <input id="z-to" type="date" name="to_date" value="<?= htmlspecialchars($zTo ?? '') ?>" class="form-input !py-2">
        </div>
        <button type="submit" class="btn-primary">Show report</button>
        <a href="billing.php?tab=zreport" class="btn-secondary">Today</a>
        <div class="flex items-center gap-2 ml-auto">
            <a href="actions/zreport_export.php?<?= $zQueryString ?>" class="btn-secondary">
                <span class="material-symbols-outlined text-[18px]">table_view</span>
                <span>CSV</span>
            </a>
            <a href="print_zreport.php?<?= $zQueryString ?>" target="_blank" rel="noopener" class="btn-outline-blue">
                <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>
                <span>Print / PDF</span>
            </a>
        </div>
    </div>
</form>

<div class="mb-4 flex flex-wrap items-baseline gap-x-3 gap-y-1">
    <h2 class="text-[15px] font-semibold text-slate-900">
        <?= $z['is_range'] ? 'Fiscal summary' : 'Daily Z-Report' ?>
    </h2>
    <span class="text-[13px] text-slate-500"><?= htmlspecialchars($zPeriodLabel) ?></span>
    <?php if ($hasRows): ?>
        <span class="text-[12px] text-slate-500">
            <?= $z['total_invoices'] ?> invoice<?= $z['total_invoices'] === 1 ? '' : 's' ?>
            <?php if ($z['first_invoice'] !== ''): ?>
                &middot; <?= htmlspecialchars($z['first_invoice']) ?> to <?= htmlspecialchars($z['last_invoice']) ?>
            <?php endif; ?>
        </span>
    <?php endif; ?>
</div>

<?php if (!$hasRows): ?>
    <div class="bg-white border border-slate-200 rounded-2xl shadow-2xs">
        <div class="nx-empty">
            <span class="material-symbols-outlined">receipt_long</span>
            No invoices were raised in this period. Pick another day or widen the range.
        </div>
    </div>
<?php else: ?>

<!-- Headline figures ------------------------------------------------------- -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php
    $zCards = [
        ['label' => 'Turnover', 'value' => $cur . number_format($z['total_sales'] - $z['total_refunds'], 2), 'note' => $z['sales_count'] . ' sales, ' . $z['refund_count'] . ' refunds', 'icon' => 'point_of_sale', 'tone' => 'bg-blue-50 text-blue-700'],
        ['label' => 'Net of VAT', 'value' => $cur . number_format($z['net_sales'], 2), 'note' => 'VAT ' . $cur . number_format($z['total_vat'], 2), 'icon' => 'account_balance', 'tone' => 'bg-emerald-50 text-emerald-700'],
        ['label' => 'Paid by patients', 'value' => $cur . number_format($z['patient_share'], 2), 'note' => $z['insurance_covered'] > 0 ? $cur . number_format($z['insurance_covered'], 2) . ' billed to insurers' : 'No insurance on these invoices', 'icon' => 'payments', 'tone' => 'bg-slate-100 text-slate-600'],
        ['label' => 'Fiscalised', 'value' => $z['fiscalised_count'] . ' / ' . $z['total_invoices'], 'note' => $z['unfiscalised_count'] ? $z['unfiscalised_count'] . ' not sent to FRCS' : 'All invoices signed', 'icon' => $z['unfiscalised_count'] ? 'gpp_maybe' : 'verified_user', 'tone' => $z['unfiscalised_count'] ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700', 'warn' => $z['unfiscalised_count'] > 0],
    ];
    foreach ($zCards as $c): ?>
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

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-6">
    <!-- By invoice type -->
    <div class="xl:col-span-2 bg-white border border-slate-200 rounded-2xl shadow-2xs overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
            <h3 class="text-[13px] font-semibold text-slate-900">By invoice and transaction type</h3>
            <p class="text-[11px] text-slate-500 mt-0.5">The breakdown FRCS expects on a Z-Report</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="text-left font-semibold px-4 py-2">Type</th>
                        <th class="text-right font-semibold px-3 py-2">Count</th>
                        <th class="text-right font-semibold px-3 py-2">Amount</th>
                        <th class="text-right font-semibold px-4 py-2">VAT</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($z['by_type'] as $type => $data):
                        $used = $data['count'] > 0; ?>
                        <tr class="<?= $used ? '' : 'text-slate-400' ?>">
                            <td class="px-4 py-2 <?= $used ? 'font-medium text-slate-900' : '' ?>"><?= htmlspecialchars(str_replace('-', ' · ', $type)) ?></td>
                            <td class="px-3 py-2 text-right tabular"><?= $data['count'] ?></td>
                            <td class="px-3 py-2 text-right tabular"><?= $cur . number_format($data['amount'], 2) ?></td>
                            <td class="px-4 py-2 text-right tabular"><?= $cur . number_format($data['tax'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-slate-50 font-semibold text-slate-900">
                    <tr>
                        <td class="px-4 py-2">Total</td>
                        <td class="px-3 py-2 text-right tabular"><?= $z['total_invoices'] ?></td>
                        <td class="px-3 py-2 text-right tabular"><?= $cur . number_format($z['total_sales'] + $z['total_refunds'], 2) ?></td>
                        <td class="px-4 py-2 text-right tabular"><?= $cur . number_format($z['total_vat'], 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- How it was paid -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-2xs overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100">
            <h3 class="text-[13px] font-semibold text-slate-900">How it was paid</h3>
            <p class="text-[11px] text-slate-500 mt-0.5">Insurance is shown separately so the lines add up to turnover</p>
        </div>
        <ul class="divide-y divide-slate-100">
            <?php
            $paid = array_filter($z['by_payment'], static fn($amt) => abs((float) $amt) >= 0.01);
            if ($paid === []): ?>
                <li class="px-4 py-6 text-center text-[12px] text-slate-500">No payments recorded against these invoices.</li>
            <?php else:
                arsort($paid);
                $paidTotal = array_sum($paid);
                foreach ($paid as $type => $amount):
                    $share = $paidTotal > 0 ? ($amount / $paidTotal * 100) : 0; ?>
                    <li class="px-4 py-2.5">
                        <div class="flex items-baseline justify-between gap-2 text-[13px]">
                            <span class="text-slate-700"><?= htmlspecialchars((string) $type) ?></span>
                            <span class="tabular font-medium text-slate-900"><?= $cur . number_format((float) $amount, 2) ?></span>
                        </div>
                        <div class="mt-1.5 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full rounded-full <?= $type === 'Insurance / third party' ? 'bg-violet-500' : 'bg-blue-600' ?>" style="width: <?= number_format(max($share, 2), 2) ?>%"></div>
                        </div>
                    </li>
                <?php endforeach;
            endif; ?>
        </ul>
        <div class="px-4 py-3 border-t border-slate-100 text-[12px] flex items-center justify-between">
            <span class="text-slate-500">Recorded against turnover</span>
            <span class="tabular font-semibold <?= $reconOff ? 'text-amber-700' : 'text-emerald-700' ?>">
                <?= $cur . number_format($z['payments_total'], 2) ?>
            </span>
        </div>
        <?php if ($reconOff): ?>
            <p class="px-4 pb-3 text-[11px] text-amber-800">
                <?= $z['reconciliation'] > 0 ? 'Payments exceed turnover by ' : 'Turnover exceeds payments by ' ?>
                <strong><?= $cur . number_format(abs($z['reconciliation']), 2) ?></strong>.
                Usually an invoice with no payment lines recorded, or a part payment still outstanding.
            </p>
        <?php endif; ?>
    </div>
</div>

<!-- VAT by tax label ------------------------------------------------------- -->
<?php if (!empty($z['by_tax'])): ?>
<div class="bg-white border border-slate-200 rounded-2xl shadow-2xs overflow-hidden mb-6">
    <div class="px-4 py-3 border-b border-slate-100">
        <h3 class="text-[13px] font-semibold text-slate-900">VAT by tax label</h3>
        <p class="text-[11px] text-slate-500 mt-0.5">From the invoice line items — A standard, E exempt, F zero rated, P prescribed</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-[13px]">
            <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="text-left font-semibold px-4 py-2">Label</th>
                    <th class="text-right font-semibold px-3 py-2">Rate</th>
                    <th class="text-right font-semibold px-3 py-2">Lines</th>
                    <th class="text-right font-semibold px-3 py-2">Net</th>
                    <th class="text-right font-semibold px-3 py-2">VAT</th>
                    <th class="text-right font-semibold px-4 py-2">Gross</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($z['by_tax'] as $row): ?>
                    <tr>
                        <td class="px-4 py-2 font-medium text-slate-900"><?= htmlspecialchars($row['label']) ?></td>
                        <td class="px-3 py-2 text-right tabular"><?= $row['rate'] === null ? '—' : number_format((float) $row['rate'], 2) . '%' ?></td>
                        <td class="px-3 py-2 text-right tabular"><?= $row['lines'] ?></td>
                        <td class="px-3 py-2 text-right tabular"><?= $cur . number_format($row['net'], 2) ?></td>
                        <td class="px-3 py-2 text-right tabular"><?= $cur . number_format($row['tax'], 2) ?></td>
                        <td class="px-4 py-2 text-right tabular"><?= $cur . number_format($row['gross'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($z['unfiscalised_count'] > 0): ?>
    <p class="mb-6 text-[12px] text-amber-900 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 flex items-start gap-2">
        <span class="material-symbols-outlined text-[18px]">gpp_maybe</span>
        <span>
            <?= $z['unfiscalised_count'] ?> invoice<?= $z['unfiscalised_count'] === 1 ? ' has' : 's have' ?>
            no FRCS signature yet, so <?= $z['unfiscalised_count'] === 1 ? 'it is' : 'they are' ?> included in these
            totals but not yet reported to the revenue service.
        </span>
    </p>
<?php endif; ?>

<?php endif; // has rows ?>

<?php endif; ?>

<!-- Create Invoice Modal -->
<div id="createInvoiceModal" class="nx-overlay hidden items-center justify-center p-4" data-nx-modal>
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 w-full max-w-3xl p-6 shadow-xl max-h-[90vh] overflow-y-auto text-xs">
        <div class="flex items-center justify-between pb-3 border-b border-outline-variant/30 mb-4">
            <h2 class="text-base font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">receipt</span>
                New Medical Invoice
            </h2>
            <button onclick="NX.closeModal('createInvoiceModal')" class="text-slate-400 hover:text-slate-600 text-lg font-bold">&times;</button>
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
                                <th class="py-2 px-3">Item / Service Name</th>
                                <th class="py-2 px-2 w-16">Qty</th>
                                <th class="py-2 px-2 w-24">Price ($)</th>
                                <th class="py-2 px-2 w-10"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/20">
                            <tr>
                                <td class="py-2 px-3 space-y-1">
                                    <select onchange="onInventoryItemSelect(this)" class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-semibold text-xs text-primary bg-white">
                                        <option value="">-- Choose Inventory Stock Item (Optional) --</option>
                                        <?php foreach ($inventoryList as $invItem): ?>
                                            <option value="<?= htmlspecialchars($invItem['id']) ?>"
                                                    data-name="<?= htmlspecialchars($invItem['name']) ?>"
                                                    data-price="<?= htmlspecialchars($invItem['unit_price']) ?>">
                                                <?= htmlspecialchars($invItem['name']) ?> ($<?= number_format($invItem['unit_price'], 2) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="inventory_id[]" value="">
                                    <input type="text" name="item_name[]" value="Clinical Consultation & Examination" required placeholder="Item / Service Name" class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg text-xs font-medium">
                                </td>
                                <td class="py-2 px-2">
                                    <input type="number" step="0.5" name="quantity[]" value="1" oninput="recalculateBillingInvoiceTotals()" required class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-mono">
                                </td>
                                <td class="py-2 px-2">
                                    <input type="number" step="0.01" name="unit_price[]" value="150.00" oninput="recalculateBillingInvoiceTotals()" required class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-mono">
                                </td>
                                <td class="py-2 px-2 text-center">
                                    <button type="button" onclick="this.closest('tr').remove(); recalculateBillingInvoiceTotals();" class="text-red-500 font-bold">&times;</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Auto-Calculated Totals & Tax Summary -->
            <div class="p-3 bg-surface-container-high/60 rounded-xl border border-outline-variant/30 space-y-2">
                <div class="flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-outline">Tax Rate:</span>
                        <select id="billing_tax_rate_select" onchange="recalculateBillingInvoiceTotals()" class="px-2 py-0.5 border border-outline-variant/40 rounded-lg bg-white font-bold text-xs">
                            <option value="15.00" selected>15% (VAT / Tax)</option>
                            <option value="0.00">0% (Exempt)</option>
                            <option value="9.00">9%</option>
                            <option value="10.00">10%</option>
                        </select>
                    </div>
                    <div class="text-right font-mono text-xs">
                        <span class="text-outline font-semibold">Tax Amount:</span>
                        <span class="font-bold text-slate-800 ml-1" id="billing_tax_amount_display">$19.57</span>
                    </div>
                </div>
                <div class="flex items-center justify-between border-t border-outline-variant/20 pt-2 text-xs">
                    <span class="font-bold text-slate-700">Subtotal (Pre-Tax): <span class="font-mono text-slate-900" id="billing_subtotal_display">$130.43</span></span>
                    <span class="font-extrabold text-primary text-sm">Total Amount: <span class="font-mono" id="billing_total_display">$150.00</span></span>
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
                <button type="button" onclick="NX.closeModal('createInvoiceModal')" class="px-4 py-2 bg-surface-container-high text-outline rounded-xl font-bold hover:bg-surface-container-highest transition">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-emerald-600 text-white rounded-xl font-bold hover:bg-emerald-700 transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">task_alt</span>
                    Create Invoice
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

function recalculateBillingInvoiceTotals() {
    let totalGross = 0;
    const rows = document.querySelectorAll('#invoiceItemsTable tbody tr');
    rows.forEach(tr => {
        const qty = parseFloat(tr.querySelector('input[name="quantity[]"]')?.value) || 0;
        const price = parseFloat(tr.querySelector('input[name="unit_price[]"]')?.value) || 0;
        totalGross += (qty * price);
    });

    const taxRate = parseFloat(document.getElementById('billing_tax_rate_select')?.value) || 0;
    let preTaxSubtotal = totalGross;
    let taxAmount = 0;

    if (taxRate > 0) {
        preTaxSubtotal = totalGross / (1 + (taxRate / 100));
        taxAmount = totalGross - preTaxSubtotal;
    }

    if (document.getElementById('billing_subtotal_display')) {
        document.getElementById('billing_subtotal_display').innerText = '$' + preTaxSubtotal.toFixed(2);
    }
    if (document.getElementById('billing_tax_amount_display')) {
        document.getElementById('billing_tax_amount_display').innerText = '$' + taxAmount.toFixed(2);
    }
    if (document.getElementById('billing_total_display')) {
        document.getElementById('billing_total_display').innerText = '$' + totalGross.toFixed(2);
    }

    // Update payment amount input if default single payment method
    const paymentAmtInput = document.querySelector('input[name="payment_amount[]"]');
    if (paymentAmtInput && (!paymentAmtInput.value || parseFloat(paymentAmtInput.value) === 0)) {
        paymentAmtInput.value = totalGross.toFixed(2);
    }
}

function onInventoryItemSelect(select) {
    const tr = select.closest('tr');
    const opt = select.options[select.selectedIndex];

    const invIdInput = tr.querySelector('input[name="inventory_id[]"]');
    const itemNameInput = tr.querySelector('input[name="item_name[]"]');
    const unitPriceInput = tr.querySelector('input[name="unit_price[]"]');

    if (opt.value) {
        invIdInput.value = opt.value;
        itemNameInput.value = opt.getAttribute('data-name') || '';
        unitPriceInput.value = parseFloat(opt.getAttribute('data-price') || 0).toFixed(2);
    } else {
        invIdInput.value = '';
    }
    recalculateBillingInvoiceTotals();
}

function addInvoiceRow() {
    const tbody = document.querySelector('#invoiceItemsTable tbody');
    const tr = document.createElement('tr');

    let invOptions = '<option value="">-- Choose Inventory Stock Item (Optional) --</option>';
    if (Array.isArray(inventoryList)) {
        inventoryList.forEach(item => {
            const price = parseFloat(item.unit_price) || 0;
            invOptions += `<option value="${item.id}" data-name="${item.name}" data-price="${price}">${item.name} ($${price.toFixed(2)})</option>`;
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
            <input type="number" step="0.5" name="quantity[]" value="1" oninput="recalculateBillingInvoiceTotals()" required class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-mono">
        </td>
        <td class="py-2 px-2">
            <input type="number" step="0.01" name="unit_price[]" value="0.00" oninput="recalculateBillingInvoiceTotals()" required class="w-full px-2 py-1 border border-outline-variant/40 rounded-lg font-mono">
        </td>
        <td class="py-2 px-2 text-center">
            <button type="button" onclick="this.closest('tr').remove(); recalculateBillingInvoiceTotals();" class="text-red-500 font-bold">&times;</button>
        </td>
    `;
    tbody.appendChild(tr);
    recalculateBillingInvoiceTotals();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
