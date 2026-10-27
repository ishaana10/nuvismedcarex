<?php
/**
 * Printable Z-Report (FRCS daily fiscal summary, or a range summary). Laid out
 * for A4 so the browser's "Save as PDF" gives a file that can be filed or sent
 * to an accountant; it also prints cleanly on a receipt roll.
 */
require_once __DIR__ . '/core/SessionBootstrap.php';
if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/includes/autoloader.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';

requireAccess('billing', 'view');

$pdo = getDB();
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
require_once __DIR__ . '/includes/zreport_query.php';

$clinic = nxClinic();
$settings = nxSettings();
$cur = nxCurrencySymbol();
$z = $zReportData;

$sellerTin = (string) ($settings['vms_seller_tin'] ?? $clinic['tin']);
$businessLocation = (string) ($settings['vms_business_location'] ?? $clinic['address']);
$posNumber = (string) ($settings['vms_pos_number'] ?? '');
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($settings['clinic_accent'] ?? '')) ? (string) $settings['clinic_accent'] : '#0F2B5C';
$title = $z['is_range'] ? 'Fiscal summary report' : 'Daily summary (Z-Report)';
$reconOff = abs($z['reconciliation']) >= 0.01;
$paid = array_filter($z['by_payment'], static fn($amt) => abs((float) $amt) >= 0.01);
arsort($paid);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title . ' — ' . $clinic['name']) ?></title>
<style>
    :root { --accent: <?= htmlspecialchars($accent) ?>; --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; }
    * { box-sizing: border-box; }
    body {
        margin: 0; background: #f1f5f9; color: var(--ink);
        font: 400 12px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .toolbar {
        position: sticky; top: 0; z-index: 10; display: flex; gap: .5rem; align-items: center;
        justify-content: space-between; padding: .6rem 1rem; background: #fff; border-bottom: 1px solid var(--line);
    }
    .toolbar a, .toolbar button {
        font: 600 12px/1 inherit; padding: .55rem .9rem; border-radius: 10px;
        border: 1px solid var(--line); background: #fff; color: var(--ink); cursor: pointer; text-decoration: none;
    }
    .toolbar .primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    .sheet {
        width: 210mm; min-height: 297mm; margin: 1.5rem auto; padding: 16mm 14mm;
        background: #fff; box-shadow: 0 10px 30px -12px rgb(15 23 42 / .25);
    }
    .head { display: flex; gap: 1rem; align-items: flex-start; border-bottom: 2px solid var(--accent); padding-bottom: .8rem; }
    .head img { max-height: 54px; max-width: 150px; object-fit: contain; }
    .head h1 { margin: 0; font-size: 16px; letter-spacing: -.01em; }
    .head .meta { font-size: 10.5px; color: var(--muted); margin-top: .15rem; }
    .head .right { margin-left: auto; text-align: right; }
    .head .right .title { font-size: 13px; font-weight: 700; color: var(--accent); }
    h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin: 1.4rem 0 .5rem; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th { font-size: 9.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); text-align: left; padding: .35rem .4rem; border-bottom: 1px solid var(--line); }
    td { padding: .35rem .4rem; border-bottom: 1px solid #f1f5f9; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    tfoot td { border-top: 1.5px solid var(--ink); border-bottom: 0; font-weight: 700; }
    .muted td { color: var(--muted); }
    .cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: .5rem; }
    .card { border: 1px solid var(--line); border-radius: 8px; padding: .55rem .65rem; }
    .card .k { font-size: 9.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
    .card .v { font-size: 15px; font-weight: 700; margin-top: .15rem; font-variant-numeric: tabular-nums; }
    .card .n { font-size: 9.5px; color: var(--muted); }
    .totals { margin-top: 1.2rem; margin-left: auto; width: 62%; }
    .totals tr td:first-child { color: var(--muted); }
    .totals .grand td { font-size: 13px; font-weight: 700; border-top: 1.5px solid var(--ink); border-bottom: 0; color: var(--ink); }
    .flag { margin-top: 1rem; border: 1px solid #fcd34d; background: #fffbeb; color: #78350f; border-radius: 8px; padding: .5rem .65rem; font-size: 10.5px; }
    .sign { margin-top: 2.2rem; display: flex; gap: 2.5rem; }
    .sign div { flex: 1; border-top: 1px solid var(--ink); padding-top: .3rem; font-size: 10px; color: var(--muted); }
    .foot { margin-top: 1.6rem; border-top: 1px solid var(--line); padding-top: .5rem; font-size: 9.5px; color: var(--muted); display: flex; justify-content: space-between; }
    .empty { padding: 2rem; text-align: center; color: var(--muted); font-size: 11px; }
    @page { size: A4; margin: 12mm; }
    @media print {
        body { background: #fff; }
        .toolbar { display: none !important; }
        .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a href="billing.php?tab=zreport&<?= $zQueryString ?>">&larr; Back to billing</a>
    <span style="font-size:11px;color:var(--muted)">
        Use your browser's print dialogue and choose “Save as PDF” to keep a copy.
    </span>
    <span style="display:flex;gap:.5rem">
        <a href="actions/zreport_export.php?<?= $zQueryString ?>">Download CSV</a>
        <button type="button" class="primary" onclick="window.print()">Print / Save as PDF</button>
    </span>
</div>

<div class="sheet">
    <div class="head">
        <?php if (($clinic['logo'] ?? '') !== ''): ?>
            <img src="<?= htmlspecialchars($clinic['logo']) ?>" alt="">
        <?php endif; ?>
        <div>
            <h1><?= htmlspecialchars($clinic['name']) ?></h1>
            <?php if ($businessLocation !== ''): ?>
                <div class="meta"><?= htmlspecialchars($businessLocation) ?></div>
            <?php endif; ?>
            <div class="meta">
                <?= $sellerTin !== '' ? 'TIN ' . htmlspecialchars($sellerTin) : 'TIN not set' ?>
                <?= $posNumber !== '' ? ' · POS ' . htmlspecialchars($posNumber) : '' ?>
            </div>
        </div>
        <div class="right">
            <div class="title"><?= htmlspecialchars($title) ?></div>
            <div class="meta"><?= htmlspecialchars($zPeriodLabel) ?></div>
            <div class="meta">Generated <?= htmlspecialchars(nxDate(date('Y-m-d H:i:s'), true)) ?></div>
            <?php if ($z['first_invoice'] !== ''): ?>
                <div class="meta">Invoices <?= htmlspecialchars($z['first_invoice']) ?> – <?= htmlspecialchars($z['last_invoice']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($z['total_invoices'] === 0): ?>
        <div class="empty">No invoices were raised in this period.</div>
    <?php else: ?>

    <h2>At a glance</h2>
    <div class="cards">
        <div class="card">
            <div class="k">Invoices</div>
            <div class="v"><?= number_format($z['total_invoices']) ?></div>
            <div class="n"><?= $z['sales_count'] ?> sales · <?= $z['refund_count'] ?> refunds</div>
        </div>
        <div class="card">
            <div class="k">Turnover</div>
            <div class="v"><?= $cur . number_format($z['total_sales'] - $z['total_refunds'], 2) ?></div>
            <div class="n">Net of VAT <?= $cur . number_format($z['net_sales'], 2) ?></div>
        </div>
        <div class="card">
            <div class="k">VAT</div>
            <div class="v"><?= $cur . number_format($z['total_vat'], 2) ?></div>
            <div class="n">Collected on these invoices</div>
        </div>
        <div class="card">
            <div class="k">Fiscalised</div>
            <div class="v"><?= $z['fiscalised_count'] ?> / <?= $z['total_invoices'] ?></div>
            <div class="n"><?= $z['unfiscalised_count'] ? $z['unfiscalised_count'] . ' not yet signed' : 'All signed' ?></div>
        </div>
    </div>

    <h2>By invoice and transaction type</h2>
    <table>
        <thead>
            <tr>
                <th>Type</th>
                <th class="num">Count</th>
                <th class="num">Amount</th>
                <th class="num">VAT</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($z['by_type'] as $type => $data): ?>
                <tr class="<?= $data['count'] === 0 ? 'muted' : '' ?>">
                    <td><?= htmlspecialchars(str_replace('-', ' · ', (string) $type)) ?></td>
                    <td class="num"><?= $data['count'] ?></td>
                    <td class="num"><?= $cur . number_format($data['amount'], 2) ?></td>
                    <td class="num"><?= $cur . number_format($data['tax'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="num"><?= $z['total_invoices'] ?></td>
                <td class="num"><?= $cur . number_format($z['total_sales'] + $z['total_refunds'], 2) ?></td>
                <td class="num"><?= $cur . number_format($z['total_vat'], 2) ?></td>
            </tr>
        </tfoot>
    </table>

    <?php if (!empty($z['by_tax'])): ?>
        <h2>VAT by tax label</h2>
        <table>
            <thead>
                <tr>
                    <th>Label</th>
                    <th class="num">Rate</th>
                    <th class="num">Lines</th>
                    <th class="num">Net</th>
                    <th class="num">VAT</th>
                    <th class="num">Gross</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($z['by_tax'] as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['label']) ?></td>
                        <td class="num"><?= $t['rate'] === null ? '—' : number_format((float) $t['rate'], 2) . '%' ?></td>
                        <td class="num"><?= $t['lines'] ?></td>
                        <td class="num"><?= $cur . number_format($t['net'], 2) ?></td>
                        <td class="num"><?= $cur . number_format($t['tax'], 2) ?></td>
                        <td class="num"><?= $cur . number_format($t['gross'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>How it was paid</h2>
    <table>
        <thead><tr><th>Method</th><th class="num">Amount</th></tr></thead>
        <tbody>
            <?php if ($paid === []): ?>
                <tr><td colspan="2" class="muted">No payments recorded against these invoices.</td></tr>
            <?php else: ?>
                <?php foreach ($paid as $type => $amount): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $type) ?></td>
                        <td class="num"><?= $cur . number_format((float) $amount, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Recorded in total</td>
                <td class="num"><?= $cur . number_format($z['payments_total'], 2) ?></td>
            </tr>
        </tfoot>
    </table>

    <table class="totals">
        <tbody>
            <tr><td>Sales</td><td class="num"><?= $cur . number_format($z['total_sales'], 2) ?></td></tr>
            <tr><td>Refunds and credits</td><td class="num">−<?= $cur . number_format($z['total_refunds'], 2) ?></td></tr>
            <tr><td>Billed to insurers</td><td class="num"><?= $cur . number_format($z['insurance_covered'], 2) ?></td></tr>
            <tr><td>Paid by patients</td><td class="num"><?= $cur . number_format($z['patient_share'], 2) ?></td></tr>
            <tr><td>VAT component</td><td class="num"><?= $cur . number_format($z['total_vat'], 2) ?></td></tr>
            <tr class="grand"><td>Turnover for the period</td><td class="num"><?= $cur . number_format($z['total_sales'] - $z['total_refunds'], 2) ?></td></tr>
        </tbody>
    </table>

    <?php if ($reconOff): ?>
        <p class="flag">
            Payments recorded differ from turnover by <strong><?= $cur . number_format(abs($z['reconciliation']), 2) ?></strong>
            (<?= $z['reconciliation'] > 0 ? 'more paid than billed' : 'less paid than billed' ?>).
            Check for invoices with no payment lines recorded, or balances still outstanding.
        </p>
    <?php endif; ?>

    <?php if ($z['unfiscalised_count'] > 0): ?>
        <p class="flag">
            <?= $z['unfiscalised_count'] ?> invoice<?= $z['unfiscalised_count'] === 1 ? '' : 's' ?>
            in this period <?= $z['unfiscalised_count'] === 1 ? 'has' : 'have' ?> no FRCS signature yet.
        </p>
    <?php endif; ?>

    <div class="sign">
        <div>Prepared by — <?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></div>
        <div>Checked by and clinic stamp</div>
    </div>

    <?php endif; ?>

    <div class="foot">
        <span><?= htmlspecialchars($clinic['name']) ?> · <?= htmlspecialchars($title) ?></span>
        <span>FRCS VMS fiscal summary · Nuvis Medcare X</span>
    </div>
</div>

</body>
</html>
