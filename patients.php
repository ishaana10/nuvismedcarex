<?php
$pageTitle = "Patients Directory - NuvisMedcareX";
$activePage = "patients";
require_once __DIR__ . '/includes/pagination.php';
include __DIR__ . '/includes/header.php';

$search = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'name';
$filter = $_GET['filter'] ?? 'all';
$sortOptions = [
    'name'   => ['Name (A–Z)', 'last_name ASC, first_name ASC'],
    'recent' => ['Recently registered', 'registration_date DESC, created_at DESC'],
    'age'    => ['Oldest first', 'age DESC, last_name ASC'],
];
$filterOptions = [
    'all'       => 'All patients',
    'allergies' => 'With allergies',
    'selfpay'   => 'Self pay',
    'new'       => 'New this month',
];
if (!isset($sortOptions[$sort])) $sort = 'name';
if (!isset($filterOptions[$filter])) $filter = 'all';

$patients = [];
$currentTenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$pagination = getPaginationParams(10, [5, 10, 25, 50, 100], $pdo);
$totalPatients = 0;
$tenantTotal = 0;

$where = ['tenant_id = :tid'];
$params = ['tid' => $currentTenantId];
if ($search !== '') {
    $where[] = "(first_name LIKE :s1 OR last_name LIKE :s2 OR mrn LIKE :s3 OR phone LIKE :s4 OR email LIKE :s5 OR CONCAT(first_name, ' ', last_name) LIKE :s6)";
    $term = "%{$search}%";
    foreach (['s1', 's2', 's3', 's4', 's5', 's6'] as $k) { $params[$k] = $term; }
}
$noAllergy = "(known_allergies IS NULL OR TRIM(known_allergies) = '' OR known_allergies IN ('None', 'None reported', 'NKDA', 'NKA', 'N/A'))";
if ($filter === 'allergies') $where[] = "NOT {$noAllergy}";
if ($filter === 'selfpay')   $where[] = "(insurance_provider IS NULL OR TRIM(insurance_provider) = '' OR insurance_provider = 'Self Pay')";
if ($filter === 'new')       { $where[] = "registration_date >= :since"; $params['since'] = date('Y-m-01'); }
$whereSql = implode(' AND ', $where);

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE {$whereSql}");
    $countStmt->execute($params);
    $totalPatients = (int) $countStmt->fetchColumn();

    $allStmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE tenant_id = ?");
    $allStmt->execute([$currentTenantId]);
    $tenantTotal = (int) $allStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM patients WHERE {$whereSql} ORDER BY {$sortOptions[$sort][1]} LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) { $stmt->bindValue(':' . $k, $v); }
    $stmt->bindValue(':limit', $pagination['limit'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $patients = $stmt->fetchAll() ?: [];
} catch (\Throwable $e) {
    error_log("Patients directory query error: " . $e->getMessage());
}

$queryState = array_filter(['q' => $search, 'sort' => $sort !== 'name' ? $sort : '', 'filter' => $filter !== 'all' ? $filter : ''], fn($v) => $v !== '');
$hasAllergy = static function (?string $a): bool {
    $a = trim((string) $a);
    return $a !== '' && !in_array(strtolower($a), ['none', 'none reported', 'nkda', 'nka', 'n/a'], true);
};
?>

<div class="mb-6 flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <h1 class="nx-page-title">Patients</h1>
        <p class="nx-page-sub"><?= number_format($tenantTotal) ?> registered at this clinic</p>
    </div>
    <a href="register_patient.php" class="btn-primary">
        <span class="material-symbols-outlined text-[18px]">person_add</span>
        <span>Register patient</span>
    </a>
</div>

<!-- Search, filters and sort -->
<form action="patients.php" method="GET" class="mb-4 flex flex-col lg:flex-row lg:items-center gap-3" id="patient-search-form" data-nx-no-lock>
    <div class="relative flex-1 lg:max-w-md">
        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-[20px]">search</span>
        <label for="patient-q" class="sr-only">Search patients</label>
        <input id="patient-q" type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name, MRN, phone or email" autocomplete="off" class="form-input !pl-10 !py-2.5 bg-white">
    </div>
    <div class="flex flex-wrap items-center gap-1.5" role="radiogroup" aria-label="Filter patients">
        <?php foreach ($filterOptions as $key => $label): $on = $filter === $key; ?>
            <label class="relative cursor-pointer">
                <input type="radio" name="filter" value="<?= $key ?>" class="sr-only peer" <?= $on ? 'checked' : '' ?> onchange="this.form.submit()">
                <span class="inline-flex items-center h-9 px-3 rounded-full border text-[13px] font-medium transition peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 <?= $on ? 'bg-blue-700 border-blue-700 text-white' : 'bg-white border-slate-200 text-slate-700 hover:border-slate-300' ?>"><?= htmlspecialchars($label) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <div class="lg:ml-auto flex items-center gap-2">
        <label for="patient-sort" class="text-[13px] text-slate-500 whitespace-nowrap">Sort by</label>
        <select id="patient-sort" name="sort" onchange="this.form.submit()" class="h-9 text-[13px] rounded-[10px] border-slate-200 bg-white text-slate-800 py-0 pl-3 pr-8 focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500">
            <?php foreach ($sortOptions as $key => [$label]): ?>
                <option value="<?= $key ?>" <?= $sort === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden">
    <div class="flex items-center justify-between px-4 py-2.5 border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
        <span>
            <?php if ($search !== '' || $filter !== 'all'): ?>
                <?= number_format($totalPatients) ?> <?= $totalPatients === 1 ? 'match' : 'matches' ?><?= $search !== '' ? ' for “' . htmlspecialchars($search) . '”' : '' ?>
            <?php else: ?>
                Showing <?= count($patients) ?> of <?= number_format($totalPatients) ?>
            <?php endif; ?>
        </span>
        <?php if ($search !== '' || $filter !== 'all'): ?>
            <a href="patients.php" class="font-medium text-blue-700 hover:underline">Clear search</a>
        <?php endif; ?>
    </div>
    <div class="relative overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="border-b border-slate-200 text-[12px] font-semibold text-slate-500">
                    <th class="py-2.5 px-4">Patient</th>
                    <th class="py-2.5 px-4 hidden sm:table-cell">Age / sex</th>
                    <th class="py-2.5 px-4 hidden lg:table-cell">Contact</th>
                    <th class="py-2.5 px-4 hidden xl:table-cell">Insurance</th>
                    <th class="py-2.5 px-4">Allergies</th>
                    <th class="py-2.5 px-4 text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($patients)): ?>
                    <tr data-nx-empty-row>
                        <td colspan="6" class="px-4">
                            <div class="nx-empty">
                                <span class="material-symbols-outlined">person_search</span>
                                <?php if ($search !== ''): ?>
                                    <p>No patient matches “<?= htmlspecialchars($search) ?>”. Check the spelling or search by MRN or phone.</p>
                                    <a href="register_patient.php" class="btn-outline-blue !text-[13px] !py-1.5 mt-1">Register a new patient</a>
                                <?php elseif ($filter !== 'all'): ?>
                                    <p>No patients in “<?= htmlspecialchars($filterOptions[$filter]) ?>”.</p>
                                <?php else: ?>
                                    <p>No patients registered yet.</p>
                                    <a href="register_patient.php" class="btn-primary !text-[13px] !py-1.5 mt-1">Register the first patient</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($patients as $p):
                    $fullName = trim(($p['first_name'] ?? 'Patient') . ' ' . ($p['last_name'] ?? ''));
                    $initials = $p['initials'] ?: mb_strtoupper(mb_substr($p['first_name'] ?? 'P', 0, 1) . mb_substr($p['last_name'] ?? '', 0, 1));
                    $detailUrl = 'patient_detail.php?id=' . urlencode($p['id'] ?? '');
                ?>
                    <tr class="hover:bg-slate-50 transition group">
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="nx-avatar w-9 h-9 text-xs">
                                    <?php if (!empty($p['avatar'])): ?><img src="<?= htmlspecialchars($p['avatar']) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?>
                                    <span><?= htmlspecialchars($initials) ?></span>
                                </span>
                                <div class="min-w-0">
                                    <a href="<?= $detailUrl ?>" class="block text-[13px] font-semibold text-slate-900 hover:text-blue-700 truncate"><?= htmlspecialchars($fullName) ?></a>
                                    <div class="text-[11px] font-mono text-slate-500"><?= htmlspecialchars($p['mrn'] ?? '') ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3 px-4 text-[13px] text-slate-700 hidden sm:table-cell whitespace-nowrap">
                            <?= htmlspecialchars((string) ($p['age'] ?? '–')) ?> · <?= htmlspecialchars($p['gender'] ?? '–') ?>
                            <div class="text-[11px] text-slate-500"><?= !empty($p['dob']) ? 'Born ' . htmlspecialchars(date('j M Y', strtotime($p['dob']))) : '' ?></div>
                        </td>
                        <td class="py-3 px-4 text-[13px] text-slate-700 hidden lg:table-cell">
                            <?php if (!empty($p['phone'])): ?><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $p['phone'])) ?>" class="hover:text-blue-700"><?= htmlspecialchars($p['phone']) ?></a><?php endif; ?>
                            <div class="text-[11px] text-slate-500 truncate max-w-[220px]"><?= htmlspecialchars($p['email'] ?? '') ?></div>
                        </td>
                        <td class="py-3 px-4 hidden xl:table-cell">
                            <span class="inline-flex px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 text-[12px] font-medium"><?= htmlspecialchars(($p['insurance_provider'] ?? '') ?: 'Self pay') ?></span>
                        </td>
                        <td class="py-3 px-4">
                            <?php if ($hasAllergy($p['known_allergies'] ?? '')): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-red-50 text-red-700 ring-1 ring-red-200 text-[12px] font-semibold max-w-[220px]" title="Known allergies: <?= htmlspecialchars($p['known_allergies']) ?>">
                                    <span class="material-symbols-outlined !text-[14px]">warning</span>
                                    <span class="truncate"><?= htmlspecialchars($p['known_allergies']) ?></span>
                                </span>
                            <?php else: ?>
                                <span class="text-slate-500 text-[12px]" title="No known drug allergies">None known</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4 text-right whitespace-nowrap">
                            <a href="<?= $detailUrl ?>" class="hidden sm:inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-200 text-[12px] font-semibold text-slate-700 hover:bg-white hover:border-slate-300">Chart</a>
                            <a href="clinical_visit.php?patient_id=<?= urlencode($p['id'] ?? '') ?>" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-blue-700 hover:bg-blue-800 text-white text-[12px] font-semibold ml-1">Start visit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination Controls -->
<?php
echo renderPagination($totalPatients, $pagination['page'], $pagination['limit'], 'patients.php', $queryState);
?>

<script>
// Search as you type (server-side, so results cover every page)
(function () {
    var input = document.getElementById('patient-q'), form = document.getElementById('patient-search-form'), t;
    if (!input) return;
    var last = input.value;
    input.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () {
            if (input.value.trim() === last.trim()) return;
            if (input.value.trim().length === 1) return;
            try { sessionStorage.setItem('nx-patient-q-focus', '1'); } catch (e) {}
            form.submit();
        }, 450);
    });
    try {
        if (sessionStorage.getItem('nx-patient-q-focus')) {
            sessionStorage.removeItem('nx-patient-q-focus');
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        }
    } catch (e) {}
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
