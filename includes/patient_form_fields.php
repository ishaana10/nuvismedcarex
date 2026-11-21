<?php
/**
 * The patient details form fields, shared by "New Patient Registration" and
 * "Edit patient" so the two can never drift apart.
 *
 * Set before including:
 *   $pf     array  the patient row (empty array for a new patient)
 *   $pfNew  bool   true on the registration page
 *   $pfIds  array  [['type' => ..., 'number' => ...], ...] already on file
 *   $pfKey  string unique prefix for element ids
 */
$pf    = $pf ?? [];
$pfNew = !empty($pfNew);
$pfIds = $pfIds ?? [];
$pfKey = $pfKey ?? 'pf';
$v = static fn(string $k): string => htmlspecialchars((string) ($pf[$k] ?? ''));
$inp = 'w-full bg-surface-container-low px-3.5 py-2.5 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary focus:bg-white transition';
$sel = str_replace('font-medium', 'font-bold', $inp);
$h2  = 'text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2';
$lbl = 'block font-bold text-slate-700 mb-1';
$gender = (string) ($pf['gender'] ?? 'Female');
$blood  = (string) ($pf['blood_group'] ?? '');
$bloods = ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'];
if ($blood !== '' && !in_array($blood, $bloods, true)) $bloods[] = $blood; // keep an unusual saved value
$idTypes = \ClinicFlow\Services\PatientIdentifiers::TYPES;
if (!$pfIds) $pfIds = [['type' => '', 'number' => '']];
?>
<!-- 1. Personal -->
<div class="space-y-4">
    <h2 class="<?= $h2 ?>"><span class="material-symbols-outlined text-base">person</span><span>1. Personal & Demographics</span></h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
        <div>
            <label class="<?= $lbl ?>">First Name <span class="text-red-500">*</span></label>
            <input type="text" name="first_name" required value="<?= $v('first_name') ?>" placeholder="e.g. Sarah" class="<?= $inp ?>">
        </div>
        <div>
            <label class="<?= $lbl ?>">Last Name <span class="text-red-500">*</span></label>
            <input type="text" name="last_name" required value="<?= $v('last_name') ?>" placeholder="e.g. Jenkins" class="<?= $inp ?>">
        </div>
        <div>
            <label class="<?= $lbl ?>">Date of Birth <span class="text-red-500">*</span></label>
            <input type="date" name="dob" required value="<?= $v('dob') ?>" class="<?= $inp ?>">
        </div>
        <div>
            <label class="<?= $lbl ?>">Gender <span class="text-red-500">*</span></label>
            <select name="gender" required class="<?= $sel ?>">
                <?php foreach (['Female', 'Male', 'Other'] as $g): ?>
                    <option value="<?= $g ?>" <?= $gender === $g ? 'selected' : '' ?>><?= $g ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="<?= $lbl ?>">Phone Number <?= $pfNew ? '<span class="text-red-500">*</span>' : '' ?></label>
            <input type="tel" name="phone" <?= $pfNew ? 'required' : '' ?> value="<?= $v('phone') ?>" placeholder="+679 999 0000" class="<?= $inp ?>">
        </div>
        <div>
            <label class="<?= $lbl ?>">Email Address</label>
            <input type="email" name="email" value="<?= $v('email') ?>" placeholder="patient@example.com" class="<?= $inp ?>">
        </div>
        <div class="md:col-span-2">
            <label class="<?= $lbl ?>">Residential Address</label>
            <input type="text" name="address" value="<?= $v('address') ?>" placeholder="Street Address, City, State, ZIP" class="<?= $inp ?>">
        </div>
    </div>

    <!-- Identity documents -->
    <div class="text-xs">
        <label class="<?= $lbl ?>">ID numbers <span class="font-medium text-slate-500">(any the patient has: searchable)</span></label>
        <div id="<?= $pfKey ?>-ids" class="space-y-2">
            <?php foreach ($pfIds as $row):
                $t = (string) $row['type'];
                $isOther = $t !== '' && !in_array($t, $idTypes, true); ?>
                <div class="flex flex-wrap items-center gap-2" data-id-row>
                    <select name="id_type[]" class="<?= $sel ?>" data-css="width:12rem;max-width:100%" data-id-type aria-label="ID type">
                        <option value="">Choose type…</option>
                        <?php foreach ($idTypes as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= ($t === $opt || ($isOther && $opt === 'Other')) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="id_other[]" value="<?= $isOther ? htmlspecialchars($t) : '' ?>" maxlength="60" placeholder="Name of this ID" aria-label="Name of this ID" data-id-other class="<?= $inp ?> <?= $isOther ? '' : 'hidden' ?>" data-css="width:11rem;max-width:100%">
                    <input type="text" name="id_number[]" value="<?= htmlspecialchars((string) $row['number']) ?>" maxlength="60" placeholder="ID number" aria-label="ID number" class="<?= $inp ?> font-mono" data-css="flex:1 1 10rem;width:auto;min-width:0">
                    <button type="button" data-id-remove class="px-2 py-2 text-slate-400 hover:text-red-600" aria-label="Remove this ID"><span class="material-symbols-outlined text-[18px]">close</span></button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" data-id-add="<?= $pfKey ?>-ids" class="mt-2 text-primary font-semibold hover:underline inline-flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">add</span>Add another ID</button>
    </div>
</div>

<hr class="border-outline-variant/20">

<!-- 2. Emergency contact & insurance -->
<div class="space-y-4">
    <h2 class="<?= $h2 ?>"><span class="material-symbols-outlined text-base">shield</span><span>2. Emergency Contact & Insurance</span></h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
        <div>
            <label class="<?= $lbl ?>">Emergency Contact Name</label>
            <input type="text" name="emergency_contact_name" value="<?= $v('emergency_contact_name') ?>" placeholder="Full Name" class="<?= $inp ?>">
        </div>
        <div>
            <label class="<?= $lbl ?>">Relationship</label>
            <input type="text" name="emergency_contact_relationship" value="<?= $v('emergency_contact_relationship') ?>" placeholder="e.g. Spouse / Parent" class="<?= $inp ?>">
        </div>
        <div>
            <label class="<?= $lbl ?>">Emergency Phone</label>
            <input type="tel" name="emergency_contact_phone" value="<?= $v('emergency_contact_phone') ?>" placeholder="+679 999 0000" class="<?= $inp ?>">
        </div>
        <div>
            <label class="<?= $lbl ?>">Insurance Provider</label>
            <?php $ipValue = (string) ($pf['insurance_provider'] ?? ''); $ipId = $pfKey . '-insurance-provider'; unset($ipClass); include __DIR__ . '/insurance_provider_field.php'; ?>
        </div>
        <div>
            <label class="<?= $lbl ?>">Policy Number</label>
            <input type="text" name="insurance_policy_number" value="<?= $v('insurance_policy_number') ?>" placeholder="Policy #" class="<?= $inp ?>">
        </div>
        <div>
            <label class="<?= $lbl ?>">Group Number</label>
            <input type="text" name="insurance_group_number" value="<?= $v('insurance_group_number') ?>" placeholder="Group #" class="<?= $inp ?>">
        </div>
    </div>
</div>

<hr class="border-outline-variant/20">

<?php if (function_exists('nxCanViewCharts') && nxCanViewCharts()): /* clinical profile: doctors and administrators only */ ?>
<!-- 3. Medical profile -->
<div class="space-y-4">
    <h2 class="<?= $h2 ?>"><span class="material-symbols-outlined text-base">medical_information</span><span>3. Medical Profile & Allergies</span></h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
        <div>
            <label class="<?= $lbl ?>">Known Allergies</label>
            <input type="text" name="known_allergies" value="<?= $v('known_allergies') ?>" placeholder="e.g. Penicillin, Latex (or None)" class="<?= $inp ?>">
        </div>
        <div>
            <label class="<?= $lbl ?>">Blood Group</label>
            <select name="blood_group" class="<?= $sel ?>">
                <option value="" <?= $blood === '' ? 'selected' : '' ?>>Not recorded</option>
                <?php foreach ($bloods as $b): ?>
                    <option value="<?= htmlspecialchars($b) ?>" <?= $blood === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="<?= $lbl ?>">Past medical History/Medication History/Baseline Notes</label>
            <textarea name="chronic_conditions" rows="3" placeholder="Past illnesses, surgeries, current and past medications, baseline notes..." class="<?= str_replace(['px-3.5 py-2.5'], ['p-3'], $inp) ?>"><?= $v('chronic_conditions') ?></textarea>
        </div>
        <div class="md:col-span-2">
            <label class="<?= $lbl ?>">Clinical Notes</label>
            <textarea name="clinical_notes" rows="2" class="<?= str_replace(['px-3.5 py-2.5'], ['p-3'], $inp) ?>"><?= $v('clinical_notes') ?></textarea>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (empty($GLOBALS['nxPatientFormJs'])): $GLOBALS['nxPatientFormJs'] = true; ?>
<script nonce="<?= nxCspNonce() ?>">
(function () {
    function sync(row) {
        var sel = row.querySelector('[data-id-type]'), other = row.querySelector('[data-id-other]');
        if (!sel || !other) return;
        other.classList.toggle('hidden', sel.value !== 'Other');
    }
    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-id-type]')) sync(e.target.closest('[data-id-row]'));
    });
    document.addEventListener('click', function (e) {
        var add = e.target.closest('[data-id-add]');
        if (add) {
            var box = document.getElementById(add.getAttribute('data-id-add'));
            if (box.children.length >= <?= \ClinicFlow\Services\PatientIdentifiers::MAX_PER_PATIENT ?>) return;
            var copy = box.children[0].cloneNode(true);
            copy.querySelectorAll('input').forEach(function (i) { i.value = ''; });
            copy.querySelector('select').selectedIndex = 0;
            sync(copy);
            box.appendChild(copy);
            copy.querySelector('select').focus();
            return;
        }
        var rm = e.target.closest('[data-id-remove]');
        if (rm) {
            var row = rm.closest('[data-id-row]'), parent = row.parentNode;
            if (parent.children.length > 1) { row.remove(); }
            else { row.querySelectorAll('input').forEach(function (i) { i.value = ''; }); row.querySelector('select').selectedIndex = 0; sync(row); }
        }
    });
})();
</script>
<?php endif; ?>
