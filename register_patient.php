<?php
$pageTitle = "Register New Patient - NuvisMedcareX";
$activePage = "register-patient";
include __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header Title Bar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">New Patient Registration</h1>
            <p class="text-xs text-slate-500 font-medium">Create a new medical chart and generate patient MRN file</p>
        </div>
        <a href="patients.php" class="px-3.5 py-2 bg-surface-container-high text-on-surface text-xs font-semibold rounded-xl hover:bg-surface-variant transition flex items-center gap-1.5 self-start md:self-auto">
            <span class="material-symbols-outlined text-base">arrow_back</span>
            <span>Back to Directory</span>
        </a>
    </div>

    <!-- Registration Form Card -->
    <form action="actions/patient_add.php" method="POST" class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-6">
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

        <?php $pf = []; $pfNew = true; $pfIds = []; $pfKey = 'reg'; include __DIR__ . '/includes/patient_form_fields.php'; ?>

        <!-- Submit Actions -->
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-outline-variant/20">
            <a href="patients.php" class="px-4 py-2.5 bg-surface-container-high text-on-surface text-xs font-semibold rounded-xl hover:bg-surface-variant transition">
                Cancel
            </a>
            <button type="submit" class="px-5 py-2.5 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition shadow-xs flex items-center gap-1.5">
                <span class="material-symbols-outlined text-base">how_to_reg</span>
                <span>Register & Open Chart</span>
            </button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
