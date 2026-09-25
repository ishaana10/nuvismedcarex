<?php
$pageTitle = "Patients Directory - ClinicFlow";
$activePage = "patients";
include __DIR__ . '/includes/header.php';

$search = trim($_GET['q'] ?? '');
$patients = [];
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

try {
    if ($search !== '') {
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE tenant_id = :tid AND (first_name LIKE :s OR last_name LIKE :s OR mrn LIKE :s OR phone LIKE :s) ORDER BY last_name ASC");
        $stmt->execute(['tid' => $currentTenantId, 's' => "%$search%"]);
        $patients = $stmt->fetchAll() ?: [];
    } else {
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE tenant_id = :tid ORDER BY last_name ASC");
        $stmt->execute(['tid' => $currentTenantId]);
        $patients = $stmt->fetchAll() ?: [];
    }
} catch (\Throwable $e) {
    error_log("Patients directory query error: " . $e->getMessage());
}
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Patient Records</h1>
        <p class="text-xs text-slate-500 font-medium">Browse and search registered patient charts, medical history, and clinical profiles</p>
    </div>
    <a href="register_patient.php" class="btn-primary">
        <span class="material-symbols-outlined text-base">person_add</span>
        <span>Register Patient</span>
    </a>
</div>

<!-- Search & Filter Bar -->
<div class="card-container mb-6 flex flex-col md:flex-row gap-4 justify-between items-center">
    <form action="patients.php" method="GET" class="relative w-full md:w-96">
        <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, MRN, phone..." class="form-input pl-10">
    </form>
    <p class="text-xs text-slate-500 font-medium">
        Showing <span class="font-bold text-slate-900"><?= count($patients) ?></span> registered patients
    </p>
</div>

<!-- Patients Directory Table -->
<div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xs overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="bg-surface-container-low/60 border-b border-outline-variant/30 text-outline uppercase text-[10px] font-bold tracking-wider">
                    <th class="py-3 px-4">Patient Name & MRN</th>
                    <th class="py-3 px-4">Age / Gender</th>
                    <th class="py-3 px-4">Contact Details</th>
                    <th class="py-3 px-4">Insurance Provider</th>
                    <th class="py-3 px-4">Known Allergies</th>
                    <th class="py-3 px-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/20">
                <?php if (empty($patients)): ?>
                    <tr>
                        <td colspan="6" class="py-8 text-center text-outline text-xs">No matching patient records found.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($patients as $p): ?>
                    <tr class="hover:bg-surface-container-low/50 transition">
                        <td class="py-3.5 px-4">
                            <div class="flex items-center gap-3">
                                <?php if (!empty($p['avatar'])): ?>
                                    <img src="<?= htmlspecialchars($p['avatar']) ?>" class="w-9 h-9 rounded-full object-cover border border-slate-200" alt="Avatar">
                                <?php else: ?>
                                    <div class="w-9 h-9 rounded-full bg-primary-fixed text-primary font-bold text-xs flex items-center justify-center">
                                        <?= htmlspecialchars($p['initials'] ?? substr(($p['first_name'] ?? 'P'),0,1).substr(($p['last_name'] ?? ''),0,1)) ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <a href="patient_detail.php?id=<?= htmlspecialchars($p['id'] ?? '') ?>" class="font-bold text-on-surface hover:text-primary transition">
                                        <?= htmlspecialchars(($p['first_name'] ?? 'Patient') . ' ' . ($p['last_name'] ?? '')) ?>
                                    </a>
                                    <p class="text-[11px] font-mono font-medium text-slate-500"><?= htmlspecialchars($p['mrn'] ?? '#00000') ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="py-3.5 px-4 font-medium text-on-surface-variant">
                            <?= htmlspecialchars($p['age'] ?? 'N/A') ?> yrs • <?= htmlspecialchars($p['gender'] ?? 'N/A') ?>
                            <p class="text-[10px] text-outline">DOB: <?= htmlspecialchars($p['dob'] ?? 'N/A') ?></p>
                        </td>
                        <td class="py-3.5 px-4 font-medium text-on-surface-variant">
                            <div><?= htmlspecialchars($p['phone'] ?? '') ?></div>
                            <div class="text-[11px] text-outline"><?= htmlspecialchars($p['email'] ?? '') ?></div>
                        </td>
                        <td class="py-3.5 px-4 font-medium text-on-surface-variant">
                            <span class="inline-flex px-2.5 py-0.5 rounded-md bg-slate-100 text-slate-700 text-[11px] font-semibold">
                                <?= htmlspecialchars(($p['insurance_provider'] ?? '') ?: 'Self Pay') ?>
                            </span>
                        </td>
                        <td class="py-3.5 px-4">
                            <?php $allergies = $p['known_allergies'] ?? ''; ?>
                            <?php if (!empty($allergies) && $allergies !== 'None' && $allergies !== 'None reported'): ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-800 text-[10px] font-bold">
                                    <span class="material-symbols-outlined text-xs">warning</span>
                                    <?= htmlspecialchars($allergies) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-slate-400 text-[11px]">NKDA</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3.5 px-4 text-right space-x-1">
                            <a href="patient_detail.php?id=<?= htmlspecialchars($p['id'] ?? '') ?>" class="inline-block px-3 py-1.5 bg-surface-container-high text-primary rounded-xl text-xs font-semibold hover:bg-surface-container-highest transition">
                                Chart File
                            </a>
                            <a href="clinical_visit.php?patient_id=<?= htmlspecialchars($p['id'] ?? '') ?>" class="inline-block px-3 py-1.5 bg-primary text-white rounded-xl text-xs font-semibold hover:bg-primary/90 transition">
                                Encounter
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
