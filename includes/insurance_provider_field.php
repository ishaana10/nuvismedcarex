<?php
/**
 * The insurance provider picker used on the patient forms. Draws on the
 * clinic's own list (Administration → Insurance) so the same insurer is not
 * spelled three different ways across patient records.
 *
 * Set before including: $ipValue (current provider), and optionally
 * $ipId (field id prefix) and $ipClass (classes for the controls).
 */
$ipValue = trim((string) ($ipValue ?? ''));
$ipId = $ipId ?? 'insurance-provider';
$ipClass = $ipClass ?? 'w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary focus:bg-white transition';

$ipList = [];
try {
    $ipList = (new \ClinicFlow\Services\InsuranceProviders($pdo, \ClinicFlow\Shared\TenantContext::getTenantId()))->names();
} catch (\Throwable $e) {
    error_log('[insurance_provider_field] ' . $e->getMessage());
}

$ipKnown = $ipValue === '' || $ipValue === 'Self Pay';
foreach ($ipList as $ipName) {
    if (strcasecmp($ipName, $ipValue) === 0) {
        $ipKnown = true;
        $ipValue = $ipName; // adopt the list's spelling
        break;
    }
}
?>
<select name="insurance_provider" id="<?= htmlspecialchars($ipId) ?>" class="<?= $ipClass ?>"
        onchange="nxToggleInsuranceOther(this)">
    <option value="" <?= $ipValue === '' ? 'selected' : '' ?>>Self pay / no insurance</option>
    <?php foreach ($ipList as $ipName): ?>
        <option value="<?= htmlspecialchars($ipName) ?>" <?= strcasecmp($ipName, $ipValue) === 0 ? 'selected' : '' ?>>
            <?= htmlspecialchars($ipName) ?>
        </option>
    <?php endforeach; ?>
    <?php if (!$ipKnown): ?>
        <option value="<?= htmlspecialchars($ipValue) ?>" selected>
            <?= htmlspecialchars($ipValue) ?> (not on the clinic's list)
        </option>
    <?php endif; ?>
    <option value="__other__">Another insurer…</option>
</select>
<input type="text" name="insurance_provider_other" id="<?= htmlspecialchars($ipId) ?>-other"
       placeholder="Name of the insurer" maxlength="80"
       class="<?= $ipClass ?> mt-2 hidden">
<?php if (!$ipList): ?>
    <p class="text-[11px] text-outline mt-1">
        <?php if (nxCan('settings', 'edit')): ?>
            No insurers set up yet — <a href="insurance_providers.php" class="underline hover:text-primary">add them in Administration &rarr; Insurance</a>.
        <?php else: ?>
            No insurers are set up for this clinic yet.
        <?php endif; ?>
    </p>
<?php endif; ?>
<?php if (empty($GLOBALS['nxInsuranceFieldScript'])): $GLOBALS['nxInsuranceFieldScript'] = true; ?>
<script>
function nxToggleInsuranceOther(select) {
    var other = document.getElementById(select.id + '-other');
    if (!other) return;
    var isOther = select.value === '__other__';
    other.classList.toggle('hidden', !isOther);
    other.required = isOther;
    if (isOther) other.focus();
}
</script>
<?php endif; ?>
