<?php
/**
 * Printable insurance report. Laid out for A4 so the browser's "Save as PDF"
 * produces the file a provider can be sent, with the columns and wording saved
 * for that provider.
 */
require_once __DIR__ . '/core/SessionBootstrap.php';
if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/includes/autoloader.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';

use ClinicFlow\Services\InsuranceReportService;

requireAccess('billing', 'view');

$pdo = getDB();
require_once __DIR__ . '/includes/insurance_report_query.php';

$clinic = nxClinic();
$cur = nxCurrencySymbol();
$totals = $insReport['totals'];
$table = InsuranceReportService::tabulate($insReport, $insLayout);
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) nxSetting('clinic_accent', '')) ? nxSetting('clinic_accent') : '#0F2B5C';
$logo = !empty($insLayout['show_logo']) ? (string) ($clinic['logo'] ?? '') : '';

$claimWord = static fn(int $n): string => $n . ($n === 1 ? ' claim' : ' claims');
$cell = static function ($value, string $type) use ($cur): string {
    if ($type === 'money') return $cur . number_format((float) $value, 2);
    if ($type === 'number') return $value === '' ? '—' : number_format((float) $value);
    if ($type === 'date') return $value === '' || $value === null ? '—' : nxDate((string) $value);
    return $value === '' || $value === null ? '—' : (string) $value;
};

/** A signature cell: the drawing itself, with who signed and when beneath it. */
$signatureCell = static function ($value): string {
    $image = is_array($value) ? (string) ($value['image'] ?? '') : '';
    $by = is_array($value) ? trim((string) ($value['by'] ?? '')) : '';
    $at = is_array($value) ? (string) ($value['at'] ?? '') : '';
    if ($image === '') {
        return '<span class="unsigned">Not signed</span>';
    }
    // Only ever an inline image written by the signature pad
    $safe = preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $image) ? $image : '';
    $out = $safe !== ''
        ? '<img class="sigimg" src="' . htmlspecialchars($safe) . '" alt="Signature">'
        : '<span class="unsigned">Signature unavailable</span>';
    $caption = array_filter([$by, $at !== '' ? nxDate($at) : '']);
    if ($caption !== []) {
        $out .= '<span class="sigby">' . htmlspecialchars(implode(' · ', $caption)) . '</span>';
    }
    return $out;
};

// The provider's layout decides what a printed copy carries and its shape
$insScope = ($insLayout['export_scope'] ?? 'full') === 'table' ? 'table' : 'full';
$insFull = $insScope === 'full';
$insLandscape = ($insLayout['orientation'] ?? 'portrait') === 'landscape';
$groups = $table['groups'] !== [] ? $table['groups'] : [['label' => '', 'rows' => $table['rows'], 'claimed' => $totals['claimed']]];
$periodLabel = nxDate($insFilters['from']) . ' – ' . nxDate($insFilters['to']);
$basisLabel = $insFilters['date_basis'] === 'service' ? 'by date of service' : 'by date submitted';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($insLayout['title'] . ' — ' . $clinic['name']) ?></title>
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
    td { padding: .35rem .4rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; white-space: nowrap; }
    td.wrap, th.wrap { white-space: normal; min-width: 10rem; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    tfoot td { border-top: 1.5px solid var(--ink); border-bottom: 0; font-weight: 700; }
    .group td { background: #f8fafc; font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #475569; }
    .cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: .5rem; }
    .card { border: 1px solid var(--line); border-radius: 8px; padding: .55rem .65rem; }
    .card .k { font-size: 9.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
    .card .v { font-size: 15px; font-weight: 700; margin-top: .15rem; font-variant-numeric: tabular-nums; }
    .card .n { font-size: 9.5px; color: var(--muted); }
    .note { font-size: 10.5px; color: #475569; border-left: 2px solid var(--line); padding-left: .6rem; margin-top: 1.2rem; }
    .sign { margin-top: 2.2rem; display: flex; gap: 2.5rem; }
    .sign div { flex: 1; border-top: 1px solid var(--ink); padding-top: .3rem; font-size: 10px; color: var(--muted); }
    .foot { margin-top: 1.6rem; border-top: 1px solid var(--line); padding-top: .5rem; font-size: 9.5px; color: var(--muted); display: flex; justify-content: space-between; }
    .empty { padding: 2rem; text-align: center; color: var(--muted); font-size: 11px; }
    td .sigimg {
        display: block; max-height: 34px; max-width: 150px;
        object-fit: contain; object-position: left center;
        /* signatures are dark ink on a transparent background */
        background: #fff;
    }
    td .sigby { display: block; font-size: 9px; color: var(--muted); margin-top: .1rem; white-space: nowrap; }
    td .unsigned { color: var(--muted); font-style: italic; }
    @page { size: A4 <?= $insLandscape ? 'landscape' : 'portrait' ?>; margin: 12mm; }
    <?php if ($insLandscape): ?>
    .sheet { width: 297mm; min-height: 210mm; }
    .cards { grid-template-columns: repeat(4, 1fr); }
    <?php endif; ?>
    @media print {
        body { background: #fff; }
        .toolbar { display: none !important; }
        .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a href="insurance_report.php?<?= $insQueryString ?>">&larr; Back to the report</a>
    <span style="font-size:11px;color:var(--muted)">
        Use your browser's print dialogue and choose “Save as PDF” to send this on.
    </span>
    <span style="display:flex;gap:.5rem">
        <a href="actions/insurance_report_export.php?<?= $insQueryString ?>">Download CSV</a>
        <button type="button" class="primary" onclick="window.print()">Print / Save as PDF</button>
    </span>
</div>

<div class="sheet">
    <div class="head">
        <?php if ($logo !== ''): ?>
            <img src="<?= htmlspecialchars($logo) ?>" alt="">
        <?php endif; ?>
        <div>
            <h1><?= htmlspecialchars($clinic['name']) ?></h1>
            <?php if (!empty($insLayout['show_logo'])): ?>
                <div class="meta">
                    <?= htmlspecialchars(implode(' · ', array_filter([$clinic['address'], $clinic['phone'], $clinic['email']]))) ?>
                </div>
                <?php if (($clinic['tin'] ?? '') !== ''): ?>
                    <div class="meta">TIN <?= htmlspecialchars($clinic['tin']) ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="right">
            <div class="title"><?= htmlspecialchars($insLayout['title']) ?></div>
            <div class="meta"><?= htmlspecialchars($insProvider !== '' ? $insProvider : 'All providers') ?></div>
            <div class="meta"><?= htmlspecialchars($periodLabel) ?></div>
            <div class="meta"><?= htmlspecialchars($basisLabel) ?></div>
            <div class="meta">Generated <?= htmlspecialchars(nxDate(date('Y-m-d H:i:s'), true)) ?></div>
        </div>
    </div>

    <?php if ($insFull && !empty($insLayout['show_summary'])): ?>
        <h2>Summary</h2>
        <div class="cards">
            <div class="card">
                <div class="k">Claims</div>
                <div class="v"><?= number_format($totals['count']) ?></div>
                <div class="n"><?= $cur . number_format($totals['claimed'], 2) ?> claimed</div>
            </div>
            <div class="card">
                <div class="k">Approved</div>
                <div class="v"><?= $cur . number_format($totals['approved'], 2) ?></div>
                <div class="n"><?= $claimWord($totals['approved_count']) ?> · <?= $totals['approval_rate'] ?>% of decided</div>
            </div>
            <div class="card">
                <div class="k">Rejected</div>
                <div class="v"><?= $cur . number_format($totals['rejected'], 2) ?></div>
                <div class="n"><?= $claimWord($totals['rejected_count']) ?></div>
            </div>
            <div class="card">
                <div class="k">Outstanding</div>
                <div class="v"><?= $cur . number_format($totals['open'], 2) ?></div>
                <div class="n"><?= $totals['open_count'] ?> open · oldest <?= $totals['oldest_open_days'] ?> days</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($insFull && count($insReport['by_provider']) > 1): ?>
        <h2>By provider</h2>
        <table>
            <thead>
                <tr>
                    <th>Provider</th>
                    <th class="num">Claims</th>
                    <th class="num">Claimed</th>
                    <th class="num">Approved</th>
                    <th class="num">Rejected</th>
                    <th class="num">Outstanding</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($insReport['by_provider'] as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['provider']) ?></td>
                        <td class="num"><?= number_format($p['count']) ?></td>
                        <td class="num"><?= $cur . number_format($p['claimed'], 2) ?></td>
                        <td class="num"><?= $cur . number_format($p['approved'], 2) ?></td>
                        <td class="num"><?= $cur . number_format($p['rejected'], 2) ?></td>
                        <td class="num"><?= $cur . number_format($p['open'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td class="num"><?= number_format($totals['count']) ?></td>
                    <td class="num"><?= $cur . number_format($totals['claimed'], 2) ?></td>
                    <td class="num"><?= $cur . number_format($totals['approved'], 2) ?></td>
                    <td class="num"><?= $cur . number_format($totals['rejected'], 2) ?></td>
                    <td class="num"><?= $cur . number_format($totals['open'], 2) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <?php if ($insFull && !empty($insLayout['show_aging']) && $totals['open_count'] > 0): ?>
        <h2>How long open claims have been waiting</h2>
        <table>
            <thead><tr><th>Age</th><th class="num">Claims</th><th class="num">Amount</th></tr></thead>
            <tbody>
                <?php foreach ($insReport['aging'] as $bucket): ?>
                    <tr>
                        <td><?= htmlspecialchars($bucket['label']) ?></td>
                        <td class="num"><?= $bucket['count'] ?></td>
                        <td class="num"><?= $cur . number_format($bucket['claimed'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2><?= htmlspecialchars($insLayout['title']) ?></h2>
    <?php if (empty($insReport['claims'])): ?>
        <div class="empty">No claims match these filters.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <?php foreach ($table['headers'] as $i => $header): ?>
                        <th class="<?= in_array($table['types'][$i], ['money', 'number'], true) ? 'num' : ($table['keys'][$i] === 'notes' ? 'wrap' : '') ?>"><?= htmlspecialchars($header) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $group): ?>
                    <?php if ($group['label'] !== ''): ?>
                        <tr class="group">
                            <td colspan="<?= count($table['headers']) ?>">
                                <?= htmlspecialchars($group['label']) ?> — <?= $claimWord(count($group['rows'])) ?>, <?= $cur . number_format($group['claimed'], 2) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($group['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($row as $i => $value): ?>
                                <td class="<?= in_array($table['types'][$i], ['money', 'number'], true) ? 'num' : ($table['keys'][$i] === 'notes' ? 'wrap' : '') ?>">
                                    <?php if ($table['types'][$i] === 'signature'): ?>
                                        <?= $signatureCell($value) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars($cell($value, $table['types'][$i])) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
            <?php
            // Total the money columns so the page foots without a calculator
            $moneyIndexes = array_keys($table['types'], 'money', true);
            if ($moneyIndexes !== [] && count($table['rows']) > 1): ?>
                <tfoot>
                    <tr>
                        <?php foreach ($table['headers'] as $i => $_):
                            if (in_array($i, $moneyIndexes, true)) {
                                $sum = array_sum(array_map(static fn($r) => (float) $r[$i], $table['rows'])); ?>
                                <td class="num"><?= $cur . number_format($sum, 2) ?></td>
                            <?php } else { ?>
                                <td><?= $i === 0 ? 'Total · ' . $claimWord(count($table['rows'])) : '' ?></td>
                            <?php }
                        endforeach; ?>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    <?php endif; ?>

    <?php if (!empty($insLayout['footer_note'])): ?>
        <p class="note"><?= nl2br(htmlspecialchars($insLayout['footer_note'])) ?></p>
    <?php endif; ?>

    <?php if (!empty($insLayout['show_signature'])): ?>
        <div class="sign">
            <div>Prepared by — <?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></div>
            <div>Authorised signature and clinic stamp</div>
        </div>
    <?php endif; ?>

    <div class="foot">
        <span><?= htmlspecialchars($clinic['name']) ?> · <?= htmlspecialchars($insLayout['title']) ?></span>
        <span><?= htmlspecialchars($periodLabel . ' · ' . $basisLabel) ?></span>
    </div>
</div>

</body>
</html>
