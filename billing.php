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
$patientsStmt = $pdo->prepare("SELECT id, first_name, last_name, mrn, phone, insurance_provider, insurance_policy_number FROM patients WHERE tenant_id = ? ORDER BY first_name ASC");
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

// Insurers this clinic has configured, for the claim form
$providerSvc = new \ClinicFlow\Services\InsuranceProviders($pdo, $currentTenantId);
$claimProviders = $providerSvc->active();

// What the claim form needs to know about each invoice, so picking an invoice
// fills in its patient, their insurer and a suggested amount.
$patientInsurance = [];
foreach ($patientsList as $p) {
    $patientInsurance[$p['id']] = [
        'name'     => trim($p['first_name'] . ' ' . $p['last_name']),
        'mrn'      => (string) ($p['mrn'] ?? ''),
        'provider' => (string) ($p['insurance_provider'] ?? ''),
        'policy'   => (string) ($p['insurance_policy_number'] ?? ''),
    ];
}
$claimInvoices = [];
foreach ($invoices as $inv) {
    $pat = $patientInsurance[$inv['patient_id']] ?? null;
    $claimInvoices[] = [
        'id'        => (string) $inv['id'],
        'number'    => (string) ($inv['invoice_number'] ?? ''),
        'amount'    => (float) ($inv['amount'] ?? 0),
        'covered'   => (float) ($inv['insurance_covered'] ?? 0),
        'patient'   => $pat['name'] ?? (string) ($inv['patient_name'] ?? ''),
        'mrn'       => $pat['mrn'] ?? (string) ($inv['patient_mrn'] ?? ''),
        'provider'  => $pat['provider'] ?? '',
        'policy'    => $pat['policy'] ?? '',
    ];
}
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

// The whole claim on one screen: who, what it is against, and the signature
$viewClaim = null;
if (($_GET['action'] ?? '') === 'view_claim' && !empty($editClaimId)) {
    $viewStmt = $pdo->prepare("
        SELECT c.*, i.invoice_number, i.amount AS invoice_total, i.service_date, i.status AS invoice_status,
               p.first_name, p.last_name, p.mrn, p.phone, p.dob
          FROM insurance_claims c
          LEFT JOIN invoices i ON i.id = c.invoice_id AND i.tenant_id = c.tenant_id
          LEFT JOIN patients p ON p.id = c.patient_id AND p.tenant_id = c.tenant_id
         WHERE c.id = ? AND c.tenant_id = ?
         LIMIT 1
    ");
    $viewStmt->execute([$editClaimId, $currentTenantId]);
    $viewClaim = $viewStmt->fetch();
}

$nxSignaturesReady = \ClinicFlow\Services\InsuranceService::hasSignatureSupport($pdo);
?>
<?php if (!$nxSignaturesReady): ?>
    <p class="mb-4 text-[12px] text-amber-900 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 flex items-start gap-2">
        <span class="material-symbols-outlined text-[18px]">database</span>
        <span>
            Claim signatures are not available on this database yet &mdash; a pending update has not been applied.
            <?php if (nxCan('developer', 'view')): ?>
                <a href="developer.php?dev_tab=database" class="underline font-semibold">Apply it in Developer &rarr; Database</a>.
            <?php else: ?>
                Ask your developer to apply the pending database updates.
            <?php endif; ?>
        </span>
    </p>
<?php endif; ?>
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
                    <th class="py-3 px-4">Signed</th>
                    <th class="py-3 px-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/20 font-medium">
                <?php if (empty($paginatedClaims)): ?>
                    <tr>
                        <td colspan="8" class="py-6 text-center text-outline italic">No insurance claims submitted yet.</td>
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
                            <td class="py-3 px-4">
                                <?php if ($nxSignaturesReady && !empty($claim['patient_signature'])): ?>
                                    <span class="inline-flex items-center gap-1 text-emerald-700" title="Signed<?= !empty($claim['signed_by_name']) ? ' by ' . htmlspecialchars($claim['signed_by_name']) : '' ?><?= !empty($claim['signed_at']) ? ' on ' . htmlspecialchars(nxDate((string) $claim['signed_at'])) : '' ?>">
                                        <span class="material-symbols-outlined text-base">draw</span>
                                        <img src="<?= htmlspecialchars($claim['patient_signature']) ?>" alt="Signature" class="h-6 bg-white rounded border border-emerald-200">
                                    </span>
                                <?php else: ?>
                                    <span class="text-[11px] <?= $nxSignaturesReady ? 'text-amber-700' : 'text-slate-400' ?>"><?= $nxSignaturesReady ? 'Not signed' : '&mdash;' ?></span>
                                <?php endif; ?>
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
                                    <a href="billing.php?tab=insurance&action=view_claim&claim_id=<?= htmlspecialchars($claim['id']) ?>" class="p-1 text-slate-500 hover:text-emerald-700 rounded-lg hover:bg-slate-100 transition" title="View claim">
                                        <span class="material-symbols-outlined text-base">visibility</span>
                                    </a>
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

<!-- Modal: the whole claim on one screen -->
<?php if ($viewClaim): ?>
<?php
$vcPatient = trim(($viewClaim['first_name'] ?? '') . ' ' . ($viewClaim['last_name'] ?? '')) ?: '(patient removed)';
$vcRef = \ClinicFlow\Services\InsuranceReportService::claimReference((string) $viewClaim['id'], (string) ($viewClaim['created_at'] ?? ''));
$vcTone = [
    'Approved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'Paid'     => 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'Rejected' => 'bg-red-50 text-red-700 border-red-200',
    'Pending'  => 'bg-amber-50 text-amber-700 border-amber-200',
][$viewClaim['status']] ?? 'bg-blue-50 text-blue-700 border-blue-200';
?>
<div class="nx-overlay items-start justify-center p-4 overflow-y-auto" data-nx-modal data-nx-static>
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-2xl max-w-2xl w-full my-8 overflow-hidden">
        <div class="px-5 py-4 border-b border-outline-variant/30 flex items-start justify-between gap-3">
            <div>
                <h3 class="text-[15px] font-semibold text-on-surface">Claim <?= htmlspecialchars($vcRef) ?></h3>
                <p class="text-[12px] text-outline mt-0.5">
                    <?= htmlspecialchars((string) $viewClaim['provider_name']) ?>
                    &middot; raised <?= htmlspecialchars(nxDate((string) $viewClaim['created_at'])) ?>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-2.5 py-1 rounded-full border text-[11px] font-semibold <?= $vcTone ?>">
                    <?= htmlspecialchars((string) $viewClaim['status']) ?>
                </span>
                <a href="billing.php?tab=insurance" class="nx-icon-btn" aria-label="Close">
                    <span class="material-symbols-outlined">close</span>
                </a>
            </div>
        </div>

        <div class="p-5 space-y-5 text-[13px]">
            <div class="flex items-baseline justify-between gap-3 p-3 rounded-xl bg-surface-container-low border border-outline-variant/30">
                <span class="text-outline">Amount claimed</span>
                <span class="text-xl font-semibold text-on-surface tabular"><?= $cur . number_format((float) $viewClaim['claim_amount'], 2) ?></span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                <?php
                $vcRows = [
                    ['Patient', $vcPatient . (($viewClaim['mrn'] ?? '') !== '' ? ' · ' . $viewClaim['mrn'] : '')],
                    ['Date of birth', ($viewClaim['dob'] ?? '') !== '' ? nxDate((string) $viewClaim['dob']) : '—'],
                    ['Phone', (string) ($viewClaim['phone'] ?? '') ?: '—'],
                    ['Policy / member number', (string) ($viewClaim['policy_number'] ?? '') ?: '—'],
                    ['Invoice', (string) ($viewClaim['invoice_number'] ?? '') ?: '(invoice removed)'],
                    ['Invoice total', $viewClaim['invoice_total'] !== null ? $cur . number_format((float) $viewClaim['invoice_total'], 2) : '—'],
                    ['Date of service', ($viewClaim['service_date'] ?? '') !== '' ? nxDate((string) $viewClaim['service_date']) : '—'],
                    ['Last updated', ($viewClaim['updated_at'] ?? '') !== '' ? nxDate((string) $viewClaim['updated_at'], true) : '—'],
                ];
                foreach ($vcRows as [$label, $value]): ?>
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-outline"><?= htmlspecialchars($label) ?></div>
                        <div class="text-on-surface font-medium mt-0.5"><?= htmlspecialchars((string) $value) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (trim((string) ($viewClaim['notes'] ?? '')) !== ''): ?>
                <div>
                    <div class="text-[11px] uppercase tracking-wide text-outline mb-1">Notes</div>
                    <p class="text-on-surface bg-surface-container-low border border-outline-variant/30 rounded-xl p-3 whitespace-pre-line"><?= htmlspecialchars((string) $viewClaim['notes']) ?></p>
                </div>
            <?php endif; ?>

            <div>
                <div class="text-[11px] uppercase tracking-wide text-outline mb-1">Member's signature</div>
                <?php if ($nxSignaturesReady && !empty($viewClaim['patient_signature'])): ?>
                    <div class="rounded-xl border border-emerald-200 bg-white p-3">
                        <img src="<?= htmlspecialchars((string) $viewClaim['patient_signature']) ?>" alt="Signature" class="max-h-28">
                    </div>
                    <p class="text-[11px] text-outline mt-1">
                        Signed by <strong><?= htmlspecialchars((string) ($viewClaim['signed_by_name'] ?? $vcPatient)) ?></strong>
                        <?= !empty($viewClaim['signed_at']) ? ' on ' . htmlspecialchars(nxDate((string) $viewClaim['signed_at'], true)) : '' ?>
                    </p>
                <?php elseif (!$nxSignaturesReady): ?>
                    <p class="text-[12px] text-outline">Signatures are not available until the pending database update is applied.</p>
                <?php else: ?>
                    <p class="text-[12px] text-amber-700">Not signed yet. Open the claim for editing to capture the signature.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="px-5 py-4 border-t border-outline-variant/30 flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <?php if (!empty($viewClaim['invoice_id'])): ?>
                    <a href="print_invoice.php?id=<?= urlencode((string) $viewClaim['invoice_id']) ?>" target="_blank" rel="noopener" class="btn-secondary">
                        <span class="material-symbols-outlined text-[18px]">receipt_long</span>
                        <span>Invoice</span>
                    </a>
                <?php endif; ?>
                <?php if (!empty($viewClaim['patient_id'])): ?>
                    <a href="patient_detail.php?id=<?= urlencode((string) $viewClaim['patient_id']) ?>" class="btn-secondary">
                        <span class="material-symbols-outlined text-[18px]">person</span>
                        <span>Patient record</span>
                    </a>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-2">
                <a href="billing.php?tab=insurance" class="btn-secondary">Close</a>
                <a href="billing.php?tab=insurance&action=edit_claim&claim_id=<?= urlencode((string) $viewClaim['id']) ?>" class="btn-primary">
                    <span class="material-symbols-outlined text-[18px]">edit</span>
                    <span>Edit claim</span>
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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
                <label class="block font-bold text-outline mb-1" for="edit-claim-provider">Insurance provider *</label>
                <select id="edit-claim-provider" name="provider_name" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
                    <?php
                    $editProviderKnown = false;
                    foreach ($claimProviders as $prov):
                        $sel = strcasecmp($prov['name'], (string) $editClaim['provider_name']) === 0;
                        $editProviderKnown = $editProviderKnown || $sel; ?>
                        <option value="<?= htmlspecialchars($prov['name']) ?>" <?= $sel ? 'selected' : '' ?>><?= htmlspecialchars($prov['name']) ?></option>
                    <?php endforeach; ?>
                    <?php if (!$editProviderKnown && ($editClaim['provider_name'] ?? '') !== ''): ?>
                        <option value="<?= htmlspecialchars($editClaim['provider_name']) ?>" selected>
                            <?= htmlspecialchars($editClaim['provider_name']) ?> (not on the clinic's list)
                        </option>
                    <?php endif; ?>
                </select>
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

            <div class="pt-3 border-t border-outline-variant/20">
                <?php
                $spName = 'claim_signature';
                $spId = 'edit-claim-signature';
                $spLabel = "Patient's signature";
                $spHint = 'Sign here to add or replace the signature';
                $spValue = (string) ($editClaim['patient_signature'] ?? '');
                include __DIR__ . '/includes/signature_pad.php';
                ?>
                <div class="mt-2 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-outline mb-1" for="edit-claim-signed-by">Signed by</label>
                        <input type="text" id="edit-claim-signed-by" name="claim_signed_by" maxlength="120"
                               value="<?= htmlspecialchars((string) ($editClaim['signed_by_name'] ?? '')) ?>"
                               class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
                    </div>
                    <div class="flex items-end">
                        <?php if (!empty($editClaim['patient_signature'])): ?>
                            <label class="flex items-center gap-2 text-[11px] text-slate-600 cursor-pointer pb-2.5">
                                <input type="checkbox" name="remove_signature" value="1" class="w-3.5 h-3.5 rounded border-outline-variant">
                                <span>Remove the signature on file<?= !empty($editClaim['signed_at']) ? ' (' . htmlspecialchars(nxDate((string) $editClaim['signed_at'])) . ')' : '' ?></span>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
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
        <form action="actions/insurance_claim_save.php" method="POST" class="p-6 space-y-4 text-xs" id="create-claim-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_claim">

            <div>
                <label class="block font-bold text-outline mb-1" for="claim-invoice">Invoice *</label>
                <select id="claim-invoice" name="invoice_id" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
                    <option value="">-- Choose the invoice to claim against --</option>
                    <?php foreach ($claimInvoices as $inv): ?>
                        <option value="<?= htmlspecialchars($inv['id']) ?>">
                            <?= htmlspecialchars($inv['number']) ?> &middot; <?= htmlspecialchars($inv['patient']) ?> &middot; <?= $cur . number_format($inv['amount'], 2) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-[11px] text-outline" id="claim-patient-line">The patient and their policy are taken from the invoice.</p>
            </div>

            <div>
                <label class="block font-bold text-outline mb-1" for="claim-provider">Insurance provider *</label>
                <select id="claim-provider" name="provider_name" required class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
                    <option value="">-- Choose a provider --</option>
                    <?php foreach ($claimProviders as $prov): ?>
                        <option value="<?= htmlspecialchars($prov['name']) ?>"
                                data-coverage="<?= htmlspecialchars((string) $prov['default_coverage']) ?>"
                                data-label="<?= htmlspecialchars($prov['policy_label']) ?>"
                                data-notes="<?= htmlspecialchars($prov['notes']) ?>">
                            <?= htmlspecialchars($prov['name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__other__">Another provider…</option>
                </select>
                <?php if (!$claimProviders): ?>
                    <p class="mt-1 text-[11px] text-amber-700">
                        No providers configured yet.
                        <?php if (nxCan('settings', 'edit')): ?>
                            <a href="insurance_providers.php" class="underline">Set them up in Administration &rarr; Insurance</a>
                            so they can be picked here.
                        <?php else: ?>
                            Ask an administrator to add them in Administration &rarr; Insurance.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <p class="mt-1 text-[11px] text-outline hidden" id="claim-provider-notes"></p>
            </div>

            <div class="hidden" id="claim-other-wrap">
                <label class="block font-bold text-outline mb-1" for="claim-other">Provider name *</label>
                <input id="claim-other" type="text" name="provider_other" maxlength="80" placeholder="Name of the insurer" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
                <?php if (nxCan('settings', 'edit')): ?>
                    <label class="mt-2 inline-flex items-center gap-2 text-[11px] text-outline cursor-pointer">
                        <input type="checkbox" name="remember_provider" value="1" checked class="w-3.5 h-3.5 rounded border-outline-variant">
                        <span>Add this one to the clinic's provider list</span>
                    </label>
                <?php endif; ?>
            </div>

            <div>
                <label class="block font-bold text-outline mb-1" for="claim-policy"><span id="claim-policy-label">Policy / member number</span></label>
                <input id="claim-policy" type="text" name="policy_number" placeholder="e.g. POL-882019" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
            </div>

            <div>
                <label class="block font-bold text-outline mb-1" for="claim-amount">Claim amount (<?= htmlspecialchars($cur) ?>) *</label>
                <input id="claim-amount" type="number" step="0.01" min="0.01" name="claim_amount" required placeholder="100.00" class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium font-mono">
                <p class="mt-1 text-[11px] text-outline hidden" id="claim-amount-hint"></p>
            </div>

            <div class="pt-3 border-t border-outline-variant/20">
                <?php
                $spName = 'claim_signature';
                $spId = 'create-claim-signature';
                $spLabel = "Patient's signature";
                $spHint = 'Sign here with a finger or the mouse';
                $spValue = '';
                include __DIR__ . '/includes/signature_pad.php';
                ?>
                <div class="mt-2">
                    <label class="block font-bold text-outline mb-1" for="claim-signed-by">Signed by</label>
                    <input type="text" id="claim-signed-by" name="claim_signed_by" maxlength="120" placeholder="The patient, or the guardian signing for them"
                           class="w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium">
                </div>
            </div>

            <div>
                <label class="block font-bold text-outline mb-1" for="claim-notes">Notes</label>
                <textarea id="claim-notes" name="notes" rows="2" maxlength="500" placeholder="Pre-authorisation reference, anything the insurer asked for" class="w-full bg-surface-container-low p-2.5 rounded-xl border border-outline-variant/40 font-medium"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-outline-variant/20">
                <button type="button" onclick="NX.closeModal('createClaimModal')" class="px-4 py-2 bg-surface-container rounded-xl font-semibold">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90">Submit claim</button>
            </div>
        </form>
    </div>
</div>

<script>
// Picking the invoice decides the patient, so the claim can no longer be filed
// against whoever happened to be first in the list.
(function () {
    const invoices = <?= json_encode($claimInvoices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const byId = {};
    invoices.forEach(function (inv) { byId[inv.id] = inv; });
    const currency = <?= json_encode($cur) ?>;

    const invoiceSel = document.getElementById('claim-invoice');
    const providerSel = document.getElementById('claim-provider');
    const otherWrap = document.getElementById('claim-other-wrap');
    const otherInput = document.getElementById('claim-other');
    const policyInput = document.getElementById('claim-policy');
    const policyLabel = document.getElementById('claim-policy-label');
    const amount = document.getElementById('claim-amount');
    const amountHint = document.getElementById('claim-amount-hint');
    const patientLine = document.getElementById('claim-patient-line');
    const providerNotes = document.getElementById('claim-provider-notes');
    if (!invoiceSel || !providerSel) return;

    function money(n) { return currency + Number(n || 0).toFixed(2); }
    function show(el, text) {
        if (!el) return;
        el.textContent = text || '';
        el.classList.toggle('hidden', !text);
    }

    function syncProvider() {
        const opt = providerSel.selectedOptions[0];
        const isOther = providerSel.value === '__other__';
        otherWrap.classList.toggle('hidden', !isOther);
        otherInput.required = isOther;
        if (isOther) otherInput.focus();
        policyLabel.textContent = (opt && opt.dataset.label) || 'Policy / member number';
        show(providerNotes, opt ? opt.dataset.notes : '');
        suggestAmount();
    }

    function suggestAmount() {
        const inv = byId[invoiceSel.value];
        if (!inv) { show(amountHint, ''); return; }
        const opt = providerSel.selectedOptions[0];
        const coverage = opt ? parseFloat(opt.dataset.coverage || '0') : 0;
        let suggested = inv.covered > 0 ? inv.covered : (coverage > 0 ? inv.amount * coverage / 100 : 0);
        suggested = Math.round(suggested * 100) / 100;
        if (suggested > 0) {
            // Only fill a box the user has not typed into
            if (!amount.value || amount.dataset.auto === '1') {
                amount.value = suggested.toFixed(2);
                amount.dataset.auto = '1';
            }
            show(amountHint, inv.covered > 0
                ? 'The invoice already records ' + money(inv.covered) + ' as the insurer’s share.'
                : 'Suggested from this provider’s usual ' + coverage + '% share of ' + money(inv.amount) + '.');
        } else {
            show(amountHint, 'Invoice total ' + money(inv.amount) + '.');
        }
        amount.max = inv.amount;
    }

    invoiceSel.addEventListener('change', function () {
        const inv = byId[invoiceSel.value];
        if (!inv) {
            patientLine.textContent = 'The patient and their policy are taken from the invoice.';
            return;
        }
        patientLine.textContent = 'Claim for ' + inv.patient + (inv.mrn ? ' (' + inv.mrn + ')' : '') + '.';
        if (inv.policy && !policyInput.value) policyInput.value = inv.policy;
        if (inv.provider) {
            const match = Array.from(providerSel.options).find(function (o) {
                return o.value.toLowerCase() === inv.provider.toLowerCase();
            });
            if (match) {
                providerSel.value = match.value;
            } else if (!providerSel.value) {
                providerSel.value = '__other__';
                otherInput.value = inv.provider;
            }
            syncProvider();
        }
        suggestAmount();
    });

    providerSel.addEventListener('change', syncProvider);
    amount.addEventListener('input', function () { amount.dataset.auto = ''; });
    syncProvider();
})();
</script>

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
