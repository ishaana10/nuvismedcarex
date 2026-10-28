<?php
/**
 * A signed consent, as it was signed. Laid out for A4 so the patient can be
 * given a copy and the clinic can file one.
 */
require_once __DIR__ . '/core/SessionBootstrap.php';
if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/includes/autoloader.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/consent_form_render.php';

use ClinicFlow\Services\ConsentRecords;

requireAccess('encounters', 'view');

$pdo = getDB();
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$record = (new ConsentRecords($pdo, $tenantId))->find((string) ($_GET['id'] ?? ''));

if (!$record) {
    http_response_code(404);
    echo '<p style="font:14px system-ui;padding:2rem">That consent record was not found.</p>';
    exit;
}

$pStmt = $pdo->prepare("SELECT first_name, last_name, mrn, dob, phone, address FROM patients WHERE id = ? AND tenant_id = ? LIMIT 1");
$pStmt->execute([$record['patient_id'], $tenantId]);
$patient = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$clinic = nxClinic();
$snapshot = $record['snapshot'];
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) nxSetting('clinic_accent', '')) ? nxSetting('clinic_accent') : '#0F2B5C';
$patientName = trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '')) ?: '(patient removed)';
$signedAt = (string) ($record['signed_at'] ?: $record['created_at']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($record['form_name'] . ' — ' . $patientName) ?></title>
<style>
    :root { --accent: <?= htmlspecialchars($accent) ?>; --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; }
    * { box-sizing: border-box; }
    body {
        margin: 0; background: #f1f5f9; color: var(--ink);
        font: 400 12.5px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
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
    .head h1 { margin: 0; font-size: 16px; }
    .head .meta { font-size: 10.5px; color: var(--muted); margin-top: .15rem; }
    .head .right { margin-left: auto; text-align: right; }
    .head .right .title { font-size: 13px; font-weight: 700; color: var(--accent); }
    h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin: 1.3rem 0 .5rem; }
    .who { display: grid; grid-template-columns: repeat(4, 1fr); gap: .5rem; }
    .who div { border: 1px solid var(--line); border-radius: 8px; padding: .5rem .6rem; }
    .who .k { font-size: 9.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
    .who .v { font-weight: 600; margin-top: .1rem; }
    .intro { white-space: pre-line; border: 1px solid var(--line); border-radius: 8px; padding: .8rem; background: #f8fafc; }
    .answers { margin-top: .8rem; }
    .answers .row { padding: .45rem 0; border-bottom: 1px solid #f1f5f9; page-break-inside: avoid; }
    .answers .q { font-size: 10.5px; color: var(--muted); }
    .answers .a { font-weight: 600; }
    .answers .tick { display: flex; gap: .5rem; align-items: flex-start; }
    .answers .tick .box { font-size: 14px; line-height: 1.1; }
    .answers h4 { margin: .9rem 0 .2rem; font-size: 12.5px; border-top: 1px solid var(--line); padding-top: .6rem; }
    .declaration { margin-top: 1rem; border-left: 3px solid var(--accent); padding-left: .8rem; font-weight: 600; white-space: pre-line; }
    .sigs { display: flex; gap: 2rem; margin-top: 1.4rem; page-break-inside: avoid; }
    .sigs .sig { flex: 1; }
    .sigs img { height: 56px; display: block; }
    .sigs .line { border-top: 1px solid var(--ink); margin-top: .2rem; padding-top: .3rem; font-size: 10.5px; color: var(--muted); }
    .withdrawn { margin-top: 1rem; border: 1px solid #fca5a5; background: #fef2f2; color: #7f1d1d; border-radius: 8px; padding: .6rem .8rem; font-size: 11px; }
    .foot { margin-top: 1.6rem; border-top: 1px solid var(--line); padding-top: .5rem; font-size: 9.5px; color: var(--muted); display: flex; justify-content: space-between; }
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
    <a href="clinical_visit.php?patient_id=<?= urlencode((string) $record['patient_id']) ?><?= $record['visit_id'] ? '&visit_id=' . urlencode((string) $record['visit_id']) : '' ?>">&larr; Back to the encounter</a>
    <span style="font-size:11px;color:var(--muted)">Print this, or save it as a PDF, to give the patient a copy.</span>
    <button type="button" class="primary" onclick="window.print()">Print / Save as PDF</button>
</div>

<div class="sheet">
    <div class="head">
        <?php if (($clinic['logo'] ?? '') !== ''): ?>
            <img src="<?= htmlspecialchars($clinic['logo']) ?>" alt="">
        <?php endif; ?>
        <div>
            <h1><?= htmlspecialchars($clinic['name']) ?></h1>
            <div class="meta"><?= htmlspecialchars(implode(' · ', array_filter([$clinic['address'], $clinic['phone']]))) ?></div>
        </div>
        <div class="right">
            <div class="title"><?= htmlspecialchars($record['form_name']) ?></div>
            <?php if (($snapshot['purpose'] ?? '') !== ''): ?>
                <div class="meta"><?= htmlspecialchars((string) $snapshot['purpose']) ?></div>
            <?php endif; ?>
            <div class="meta">Signed <?= htmlspecialchars(nxDate($signedAt, true)) ?></div>
            <div class="meta">Form version <?= (int) $record['form_version'] ?></div>
        </div>
    </div>

    <h2>Patient</h2>
    <div class="who">
        <div><div class="k">Name</div><div class="v"><?= htmlspecialchars($patientName) ?></div></div>
        <div><div class="k">MRN</div><div class="v"><?= htmlspecialchars((string) ($patient['mrn'] ?? '—')) ?></div></div>
        <div><div class="k">Date of birth</div><div class="v"><?= htmlspecialchars(($patient['dob'] ?? '') !== '' ? nxDate((string) $patient['dob']) : '—') ?></div></div>
        <div><div class="k">Phone</div><div class="v"><?= htmlspecialchars((string) ($patient['phone'] ?? '—')) ?></div></div>
    </div>

    <?php if (trim((string) ($snapshot['intro'] ?? '')) !== ''): ?>
        <h2>What was agreed</h2>
        <div class="intro"><?= htmlspecialchars((string) $snapshot['intro']) ?></div>
    <?php endif; ?>

    <?php if (!empty($snapshot['fields'])): ?>
        <h2>Answers given</h2>
        <div class="answers">
            <?php foreach ($snapshot['fields'] as $field):
                $type = (string) ($field['type'] ?? '');
                $key = (string) ($field['key'] ?? '');
                $label = (string) ($field['label'] ?? '');
                $value = $record['answers'][$key] ?? '';
                if ($type === 'heading'): ?>
                    <h4><?= htmlspecialchars($label) ?></h4>
                <?php continue; endif;
                if ($type === 'paragraph'): ?>
                    <p style="white-space:pre-line"><?= htmlspecialchars($label) ?></p>
                <?php continue; endif;
                if ($type === 'acknowledge' || $type === 'checkbox'): ?>
                    <div class="row tick">
                        <span class="box"><?= !empty($value) ? '&#9745;' : '&#9744;' ?></span>
                        <span><?= htmlspecialchars($label) ?></span>
                    </div>
                <?php continue; endif; ?>
                <div class="row">
                    <div class="q"><?= htmlspecialchars($label) ?></div>
                    <div class="a"><?= $value !== '' && $value !== null
                        ? htmlspecialchars($type === 'date' ? nxDate((string) $value) : (string) $value)
                        : '—' ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (trim((string) ($snapshot['declaration'] ?? '')) !== ''): ?>
        <p class="declaration"><?= htmlspecialchars((string) $snapshot['declaration']) ?></p>
    <?php endif; ?>

    <div class="sigs">
        <div class="sig">
            <?php if (!empty($record['patient_signature'])): ?>
                <img src="<?= htmlspecialchars((string) $record['patient_signature']) ?>" alt="Signature">
            <?php endif; ?>
            <div class="line">
                <?= htmlspecialchars((string) $record['signed_by_name']) ?>
                <?php if (($record['signed_relationship'] ?? 'Patient') !== 'Patient'): ?>
                    (<?= htmlspecialchars((string) $record['signed_relationship']) ?>)
                <?php endif; ?>
                &middot; <?= htmlspecialchars(nxDate($signedAt, true)) ?>
            </div>
        </div>
        <?php if (!empty($record['witness_signature']) || !empty($record['witness_name'])): ?>
            <div class="sig">
                <?php if (!empty($record['witness_signature'])): ?>
                    <img src="<?= htmlspecialchars((string) $record['witness_signature']) ?>" alt="Witness signature">
                <?php endif; ?>
                <div class="line">Witness — <?= htmlspecialchars((string) $record['witness_name']) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($record['withdrawn_at'])): ?>
        <div class="withdrawn">
            <strong>This consent was withdrawn on <?= htmlspecialchars(nxDate((string) $record['withdrawn_at'], true)) ?>.</strong>
            <?= ($record['withdrawn_reason'] ?? '') !== '' ? '<br>' . htmlspecialchars((string) $record['withdrawn_reason']) : '' ?>
        </div>
    <?php endif; ?>

    <div class="foot">
        <span><?= htmlspecialchars($clinic['name']) ?> · <?= htmlspecialchars($record['form_name']) ?></span>
        <span>Recorded by <?= htmlspecialchars((string) ($record['recorded_by'] ?? '—')) ?></span>
    </div>
</div>

</body>
</html>
