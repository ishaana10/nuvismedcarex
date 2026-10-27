<?php
$pageTitle = "Pharmacy & Supplies Inventory - NuvisMedcareX";
$activePage = "inventory";
include __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/security.php';

$userRole = $_SESSION['user_role'] ?? 'Staff';
$isAdminOrDev = in_array($userRole, ['Administrator', 'Developer']);

require_once __DIR__ . '/includes/pagination.php';
$pdo = getDB();
$inventoryService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\InventoryService::class);

// Fetch Clinic Inventory Settings & Custom Fields Definition
$settings = $inventoryService->getInventorySettings();
$categories = $settings['categories'];
$units = $settings['units'];
$customFieldsDef = $settings['custom_fields_def'];
$defaultMinThreshold = $settings['default_min_threshold'];
$expiryWarningDays = $settings['expiry_warning_days'];
require_once __DIR__ . '/includes/inventory_fields.php';

// Search and Filter parameters
$searchQuery = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$activeTab = trim($_GET['tab'] ?? 'inventory'); // inventory or logs

// Fetch Inventory Items via InventoryService
$items = $inventoryService->searchAndFilterItems($searchQuery, $categoryFilter, $statusFilter);

// Calculate Stats
$totalItemsCount = count($items);
$lowStockCount = 0;
$outOfStockCount = 0;
$totalValuation = 0.0;

$expiringSoonCount = 0;
$expiryCutoff = date('Y-m-d', strtotime("+{$expiryWarningDays} days"));

foreach ($items as $item) {
    if (!empty($item['expiry_date']) && $item['expiry_date'] <= $expiryCutoff) {
        $expiringSoonCount++;
    }
    if ((int)$item['current_stock'] <= 0) {
        $outOfStockCount++;
    } elseif ((int)$item['current_stock'] <= (int)$item['min_threshold']) {
        $lowStockCount++;
    }
    $totalValuation += ((int)$item['current_stock'] * (float)$item['unit_price']);
}

// Pagination setup
$pagination = getPaginationParams(10, [5, 10, 25, 50, 100], $pdo);
$paginatedItems = array_slice($items, $pagination['offset'], $pagination['limit']);

// Fetch Inventory Logs via InventoryService
$inventoryLogs = $inventoryService->getInventoryLogs(100);

// Views: the stock list, what needs attention, and the reports
$invTab = (string) ($_GET['view'] ?? 'stock');
if (!in_array($invTab, ['stock', 'alerts', 'reports'], true)) {
    $invTab = 'stock';
}
$invAlerts = $inventoryService->getAlerts();
$alertCount = count($invAlerts['reorder']) + count($invAlerts['expiring']) + count($invAlerts['expired']);

$reportFrom = $_GET['from'] ?? date('Y-m-01');
$reportTo   = $_GET['to'] ?? date('Y-m-d');
if (!strtotime($reportFrom)) $reportFrom = date('Y-m-01');
if (!strtotime($reportTo)) $reportTo = date('Y-m-d');
$invReport = $invTab === 'reports' ? $inventoryService->getReport($reportFrom, $reportTo) : null;
$cur = nxCurrencySymbol();

$csrfToken = getCsrfToken();
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-on-surface">Pharmacy & Supplies Inventory</h1>
        <p class="nx-page-sub">Stock levels, batches and restocking for <?= htmlspecialchars(nxClinicName()) ?></p>
    </div>
    <div class="flex items-center gap-2">
        <button onclick="openModal('modal-audit-logs')" class="px-3.5 py-2 bg-surface-container border border-outline-variant/40 text-on-surface hover:bg-surface-container-high rounded-xl text-xs font-semibold transition flex items-center gap-1.5 shadow-xs">
            <span class="material-symbols-outlined text-base">history</span>
            <span>Movement History</span>
        </button>

        <?php if ($isAdminOrDev): ?>
            <button onclick="openModal('modal-add-item')" class="px-3.5 py-2 bg-primary text-white hover:bg-primary/90 rounded-xl text-xs font-semibold transition flex items-center gap-1.5 shadow-xs">
                <span class="material-symbols-outlined text-base">add_box</span>
                <span>Add Inventory Item</span>
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php
    $invStats = [
        ['label' => 'Items in the catalogue', 'value' => number_format($totalItemsCount), 'icon' => 'inventory_2', 'tone' => 'bg-blue-50 text-blue-700', 'note' => count($categories) . ' categories'],
        ['label' => 'Need reordering', 'url' => 'inventory.php?view=alerts', 'value' => number_format($lowStockCount + $outOfStockCount), 'icon' => 'warning', 'tone' => ($lowStockCount + $outOfStockCount) ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600', 'note' => $outOfStockCount ? $outOfStockCount . ' out of stock' : 'None out of stock', 'warn' => ($lowStockCount + $outOfStockCount) > 0],
        ['label' => 'Expiring within ' . $expiryWarningDays . ' days', 'url' => 'inventory.php?view=alerts', 'value' => number_format($expiringSoonCount), 'icon' => 'event_busy', 'tone' => $expiringSoonCount ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600', 'note' => $expiringSoonCount ? 'Use first or return' : 'Nothing close to expiry', 'warn' => $expiringSoonCount > 0],
        ['label' => 'Stock on hand', 'value' => nxCurrencySymbol() . number_format($totalValuation, 2), 'icon' => 'payments', 'tone' => 'bg-emerald-50 text-emerald-700', 'note' => 'At selling price'],
    ];
    foreach ($invStats as $st): ?>
        <?php $tag = !empty($st['url']) ? 'a' : 'div'; ?>
        <<?= $tag ?> <?= !empty($st['url']) ? 'href="' . $st['url'] . '"' : '' ?> class="block bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs <?= !empty($st['url']) ? 'hover:border-slate-300 transition' : '' ?>">
            <div class="flex items-start justify-between gap-2">
                <span class="text-[13px] text-slate-600"><?= htmlspecialchars($st['label']) ?></span>
                <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 <?= $st['tone'] ?>">
                    <span class="material-symbols-outlined text-[18px]"><?= $st['icon'] ?></span>
                </span>
            </div>
            <div class="text-2xl font-semibold text-slate-900 mt-2 tabular"><?= htmlspecialchars($st['value']) ?></div>
            <div class="text-[11px] mt-0.5 <?= !empty($st['warn']) ? 'text-amber-700 font-medium' : 'text-slate-500' ?>"><?= htmlspecialchars($st['note']) ?></div>
        </<?= $tag ?>>
    <?php endforeach; ?>
</div>

<nav class="mb-6 -mx-1 overflow-x-auto" aria-label="Inventory views">
    <ul class="flex gap-1 px-1 border-b border-slate-200 min-w-max">
        <?php foreach ([
            'stock'   => ['Stock', 'inventory_2', null],
            'alerts'  => ['Needs attention', 'warning', $alertCount],
            'reports' => ['Reports', 'monitoring', null],
        ] as $key => [$label, $icon, $badge]):
            $on = $invTab === $key; ?>
            <li>
                <a href="inventory.php?view=<?= $key ?>" <?= $on ? 'aria-current="page"' : '' ?>
                   class="relative inline-flex items-center gap-2 px-3 py-2.5 text-[13px] font-medium transition <?= $on ? 'text-blue-800' : 'text-slate-500 hover:text-slate-900' ?>">
                    <span class="material-symbols-outlined text-[18px]"><?= $icon ?></span><?= $label ?>
                    <?php if ($badge): ?>
                        <span class="ml-0.5 min-w-[20px] px-1.5 rounded-full bg-amber-100 text-amber-800 text-[11px] font-semibold text-center"><?= $badge ?></span>
                    <?php endif; ?>
                    <?php if ($on): ?><span class="absolute left-2 right-2 -bottom-px h-0.5 rounded-full bg-blue-700"></span><?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>

<?php if ($invTab === 'stock'): ?>
<!-- Filter Bar -->
<div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-4 mb-6 shadow-xs">
    <form method="GET" action="inventory.php" class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div class="relative md:col-span-2">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-lg">search</span>
            <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search by name, SKU, or batch number..." class="w-full bg-surface-container-low pl-9 pr-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
        </div>

        <div>
            <select name="category" onchange="this.form.submit()" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-medium">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex items-center gap-2">
            <select name="status" onchange="this.form.submit()" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-medium">
                <option value="">All Stock Status</option>
                <option value="In Stock" <?= $statusFilter === 'In Stock' ? 'selected' : '' ?>>In Stock</option>
                <option value="Low Stock" <?= $statusFilter === 'Low Stock' ? 'selected' : '' ?>>Low Stock</option>
                <option value="Out of Stock" <?= $statusFilter === 'Out of Stock' ? 'selected' : '' ?>>Out of Stock</option>
            </select>

            <?php if ($searchQuery !== '' || $categoryFilter !== '' || $statusFilter !== ''): ?>
                <a href="inventory.php" class="p-2 text-slate-500 hover:text-red-600 rounded-xl hover:bg-slate-100" title="Reset Filters">
                    <span class="material-symbols-outlined text-lg">filter_alt_off</span>
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Inventory Grid Table -->
<div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-5 shadow-xs">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="border-b border-outline-variant/30 text-outline uppercase text-[10px] font-bold tracking-wider">
                    <th class="py-2.5 px-3">Item Name & SKU</th>
                    <th class="py-2.5 px-3">Category</th>
                    <th class="py-2.5 px-3">Stock Level</th>
                    <th class="py-2.5 px-3">Prices (Cost / Sell)</th>
                    <th class="py-2.5 px-3">Batch & Expiry</th>
                    <th class="py-2.5 px-3">Status</th>
                    <th class="py-2.5 px-3 text-right">Actions / Restock</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/20">
                <?php if (empty($paginatedItems)): ?>
                    <tr>
                        <td colspan="7" class="py-8 text-center text-outline">
                            <span class="material-symbols-outlined text-4xl text-outline/50 block mb-1">inventory</span>
                            No matching inventory items found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($paginatedItems as $item): ?>
                        <?php
                            $cStock = (int)$item['current_stock'];
                            $mThresh = (int)$item['min_threshold'];
                            $isLow = $cStock <= $mThresh;
                            $customData = json_decode($item['custom_fields'] ?? '[]', true) ?: [];
                        ?>
                        <tr class="hover:bg-surface-container-low transition">
                            <td class="py-3.5 px-3 font-bold text-on-surface">
                                <div class="flex items-center gap-2">
                                    <div>
                                        <?= htmlspecialchars($item['name']) ?>
                                        <span class="block text-[10px] font-mono text-outline font-normal">
                                            SKU: <?= htmlspecialchars($item['sku']) ?> | VMS Tax: <span class="font-bold text-primary"><?= htmlspecialchars($item['vms_tax_code'] ?? 'A') ?></span>
                                        </span>
                                        <?php
                                        // The extra fields this clinic chose to record (Administration → Inventory)
                                        $shown = [];
                                        foreach ($customFieldsDef as $cf) {
                                            $v = trim((string) ($customData[$cf['name']] ?? ''));
                                            if ($v !== '') $shown[] = $cf['label'] . ': ' . $v;
                                        }
                                        if ($shown): ?>
                                            <span class="block text-[10px] text-outline font-normal mt-0.5"><?= htmlspecialchars(implode(' · ', array_slice($shown, 0, 3))) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($customData)): ?>
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                <?php foreach ($customData as $ckey => $cval): ?>
                                                    <?php if ($cval !== ''): ?>
                                                        <span class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 text-[9px] font-medium border border-slate-200">
                                                            <?= htmlspecialchars($ckey) ?>: <?= htmlspecialchars($cval) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 px-3 font-medium text-on-surface-variant">
                                <span class="px-2 py-0.5 rounded bg-surface-container text-primary text-[11px] font-semibold border border-primary/10">
                                    <?= htmlspecialchars($item['category']) ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-3 font-mono font-bold text-base <?= $isLow ? 'text-red-600' : 'text-on-surface' ?>">
                                <?= number_format($cStock) ?>
                                <span class="text-xs font-normal text-outline"><?= htmlspecialchars($item['unit']) ?></span>
                                <span class="block text-[10px] text-outline font-normal">Min Threshold: <?= number_format($mThresh) ?></span>
                            </td>
                            <td class="py-3.5 px-3 font-mono">
                                <span class="text-emerald-700 font-bold"><?= htmlspecialchars(nxCurrencySymbol()) ?><?= number_format((float)$item['unit_price'], 2) ?></span>
                                <span class="block text-[10px] text-outline">Cost: <?= htmlspecialchars(nxCurrencySymbol()) ?><?= number_format((float)$item['cost_price'], 2) ?></span>
                            </td>
                            <td class="py-3.5 px-3 font-mono text-xs">
                                <?php if (!empty($item['batch_number'])): ?>
                                    <span class="block font-semibold text-slate-800">Batch: <?= htmlspecialchars($item['batch_number']) ?></span>
                                <?php else: ?>
                                    <span class="text-outline italic">No batch</span>
                                <?php endif; ?>
                                <?php if (!empty($item['expiry_date'])): ?>
                                    <span class="block text-[10px] text-outline">Exp: <?= htmlspecialchars($item['expiry_date']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-3">
                                <?php if ($cStock <= 0): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-red-100 text-red-800 text-[10px] font-bold">
                                        <span class="material-symbols-outlined text-xs">error</span>
                                        Out of Stock
                                    </span>
                                <?php elseif ($isLow): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-800 text-[10px] font-bold">
                                        <span class="material-symbols-outlined text-xs">warning</span>
                                        Low Stock
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-bold">
                                        In Stock
                                    </span>
                                <?php endif; ?>
                                <span class="block text-[9px] text-outline mt-0.5">Restocked: <?= htmlspecialchars($item['last_restocked']) ?></span>
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <!-- Quick Restock Form -->
                                    <form action="actions/inventory_restock.php" method="POST" class="inline-flex items-center gap-1">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="item_id" value="<?= htmlspecialchars($item['id']) ?>">
                                        <input type="hidden" name="restock_type" value="quick">
                                        <input type="number" name="amount" value="50" min="1" class="w-14 bg-surface-container-low px-1.5 py-1 rounded-lg border border-outline-variant/40 text-xs font-mono font-bold text-center">
                                        <button type="submit" title="Quick Add Stock" class="px-2 py-1 bg-primary text-white rounded-lg text-[11px] font-semibold hover:bg-primary/90 transition shadow-xs flex items-center gap-0.5">
                                            +Add
                                        </button>
                                    </form>

                                    <!-- Detailed Restock Button -->
                                    <button onclick='openDetailedRestockModal(<?= json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Detailed Restock Entry" class="p-1.5 bg-surface-container hover:bg-surface-container-high text-on-surface rounded-lg text-xs font-semibold transition">
                                        <span class="material-symbols-outlined text-base">edit_note</span>
                                    </button>

                                    <?php if ($isAdminOrDev): ?>
                                        <!-- Edit Item Button -->
                                        <button onclick='openEditModal(<?= json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit Inventory Item" class="p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-semibold transition">
                                            <span class="material-symbols-outlined text-base">edit</span>
                                        </button>

                                        <!-- Delete Item Form -->
                                        <form action="actions/inventory_delete.php" method="POST" data-nx-confirm="Are you sure you want to delete this inventory record? Historical logs will be preserved." class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="item_id" value="<?= htmlspecialchars($item['id']) ?>">
                                            <button type="submit" title="Delete Inventory Item" class="p-1.5 bg-red-50 hover:bg-red-100 text-red-600 rounded-lg text-xs font-semibold transition">
                                                <span class="material-symbols-outlined text-base">delete</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls -->
    <?= renderPagination($totalItemsCount, $pagination['page'], $pagination['limit'], 'inventory.php', array_filter(['view' => 'stock', 'q' => $searchQuery, 'category' => $categoryFilter, 'status' => $statusFilter])) ?>
</div>
<?php endif; // stock view ?>

<?php if ($invTab === 'alerts'): ?>
<?php
$alertGroups = [
    ['key' => 'expired',  'title' => 'Expired',            'note' => 'Remove from the shelf and record the write-off.',           'tone' => 'red',   'items' => $invAlerts['expired']],
    ['key' => 'reorder',  'title' => 'Low or out of stock', 'note' => 'At or below the reorder level set for the item.',          'tone' => 'amber', 'items' => $invAlerts['reorder']],
    ['key' => 'expiring', 'title' => 'Expiring soon',       'note' => 'Within ' . $invAlerts['window_days'] . ' days. Use these first.', 'tone' => 'amber', 'items' => $invAlerts['expiring']],
];
$tones = [
    'red'   => ['bg-red-50 text-red-700 ring-red-200', 'bg-red-500'],
    'amber' => ['bg-amber-50 text-amber-800 ring-amber-200', 'bg-amber-500'],
];
?>
<?php if ($alertCount === 0): ?>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-2xs">
        <div class="nx-empty !py-12">
            <span class="material-symbols-outlined !text-emerald-500">task_alt</span>
            <p class="text-[15px] text-slate-700">Nothing needs attention.</p>
            <p>Every item is above its reorder level and nothing expires within <?= (int) $invAlerts['window_days'] ?> days.</p>
            <a href="admin.php?tab=inventory" class="text-[13px] text-blue-700 hover:underline mt-1">Change these levels</a>
        </div>
    </div>
<?php else: ?>
    <div class="space-y-6">
    <?php foreach ($alertGroups as $group): if (!$group['items']) continue;
        [$badge, $dot] = $tones[$group['tone']]; ?>
        <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden">
            <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-3">
                <div>
                    <h2 class="text-base font-semibold text-slate-900 flex items-center gap-2">
                        <span class="nx-dot <?= $dot ?>"></span><?= htmlspecialchars($group['title']) ?>
                        <span class="text-slate-400 font-normal">(<?= count($group['items']) ?>)</span>
                    </h2>
                    <p class="text-[13px] text-slate-500 mt-0.5"><?= htmlspecialchars($group['note']) ?></p>
                </div>
                <a href="inventory.php?view=stock&status=<?= $group['key'] === 'reorder' ? 'Low+Stock' : '' ?>" class="text-[13px] font-medium text-blue-700 hover:underline whitespace-nowrap">Open in stock list</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-y border-slate-200 bg-slate-50 text-[12px] font-semibold text-slate-500">
                            <th class="py-2.5 px-5">Item</th>
                            <th class="py-2.5 px-3">Category</th>
                            <th class="py-2.5 px-3">In stock</th>
                            <th class="py-2.5 px-3 hidden sm:table-cell">Reorder at</th>
                            <th class="py-2.5 px-3">Expiry</th>
                            <th class="py-2.5 px-5 text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($group['items'] as $item):
                            $stock = (int) $item['current_stock'];
                            $expiry = trim((string) ($item['expiry_date'] ?? ''));
                            $daysLeft = $expiry !== '' ? (int) floor((strtotime($expiry) - strtotime(date('Y-m-d'))) / 86400) : null; ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="py-3 px-5">
                                    <div class="text-[13px] font-semibold text-slate-900"><?= htmlspecialchars($item['name']) ?></div>
                                    <div class="text-[11px] font-mono text-slate-500"><?= htmlspecialchars($item['sku']) ?></div>
                                </td>
                                <td class="py-3 px-3 text-[13px] text-slate-600"><?= htmlspecialchars($item['category']) ?></td>
                                <td class="py-3 px-3">
                                    <span class="text-[13px] font-semibold tabular <?= $stock <= 0 ? 'text-red-700' : 'text-slate-900' ?>"><?= number_format($stock) ?></span>
                                    <span class="text-[12px] text-slate-500"><?= htmlspecialchars($item['unit']) ?></span>
                                    <?php if ($stock <= 0): ?><span class="block text-[11px] font-medium text-red-700">Out of stock</span><?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-[13px] text-slate-600 tabular hidden sm:table-cell"><?= number_format((int) $item['min_threshold']) ?></td>
                                <td class="py-3 px-3">
                                    <?php if ($expiry === ''): ?>
                                        <span class="text-[13px] text-slate-400">—</span>
                                    <?php else: ?>
                                        <span class="text-[13px] text-slate-700"><?= htmlspecialchars(nxDate($expiry)) ?></span>
                                        <span class="block text-[11px] <?= $daysLeft < 0 ? 'text-red-700 font-medium' : 'text-amber-700' ?>">
                                            <?= $daysLeft < 0 ? abs($daysLeft) . ' days ago' : 'in ' . $daysLeft . ' days' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-5 text-right whitespace-nowrap">
                                    <?php if ($isAdminOrDev): ?>
                                        <button type="button" onclick='openDetailedRestockModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES) ?>)' class="btn-secondary !py-1.5 !px-3 !text-[12px]">Restock</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php endif; // alerts view ?>

<?php if ($invTab === 'reports' && $invReport): ?>
<?php $r = $invReport; ?>
<form method="GET" action="inventory.php" class="mb-6 flex flex-wrap items-end gap-3">
    <input type="hidden" name="view" value="reports">
    <div>
        <label class="form-label" for="rep-from">From</label>
        <input id="rep-from" type="date" name="from" value="<?= htmlspecialchars($reportFrom) ?>" class="form-input !py-2">
    </div>
    <div>
        <label class="form-label" for="rep-to">To</label>
        <input id="rep-to" type="date" name="to" value="<?= htmlspecialchars($reportTo) ?>" class="form-input !py-2">
    </div>
    <button type="submit" class="btn-secondary">Update</button>
    <a href="actions/inventory_report_export.php?from=<?= urlencode($reportFrom) ?>&to=<?= urlencode($reportTo) ?>" class="btn-secondary ml-auto">
        <span class="material-symbols-outlined text-[18px]">download</span> Download CSV
    </a>
</form>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php foreach ([
        ['Stock on hand', $cur . number_format($r['totals']['retail_value'], 2), 'At selling price'],
        ['What it cost', $cur . number_format($r['totals']['cost_value'], 2), 'At purchase price'],
        ['Units received', number_format($r['movements']['in']), $cur . number_format($r['movements']['spend'], 2) . ' spent'],
        ['Units used', number_format($r['movements']['out']), 'Dispensed or written off'],
    ] as [$label, $value, $note]): ?>
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
            <div class="text-[13px] text-slate-600"><?= htmlspecialchars($label) ?></div>
            <div class="text-2xl font-semibold text-slate-900 mt-1 tabular"><?= htmlspecialchars($value) ?></div>
            <div class="text-[11px] text-slate-500 mt-0.5"><?= htmlspecialchars($note) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden">
        <div class="px-5 pt-5 pb-3">
            <h2 class="text-base font-semibold text-slate-900">Stock by category</h2>
            <p class="text-[13px] text-slate-500 mt-0.5">Where the value sits today, using your categories.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-y border-slate-200 bg-slate-50 text-[12px] font-semibold text-slate-500">
                        <th class="py-2.5 px-5">Category</th>
                        <th class="py-2.5 px-3 text-right">Items</th>
                        <th class="py-2.5 px-3 text-right">Units</th>
                        <th class="py-2.5 px-5 text-right">Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (!$r['by_category']): ?>
                        <tr><td colspan="4" class="px-5"><div class="nx-empty">No stock recorded yet.</div></td></tr>
                    <?php endif; ?>
                    <?php $maxVal = $r['by_category'] ? max(array_column($r['by_category'], 'retail_value')) : 0; ?>
                    <?php foreach ($r['by_category'] as $row): ?>
                        <tr class="hover:bg-slate-50 transition">
                            <td class="py-3 px-5">
                                <div class="text-[13px] text-slate-900"><?= htmlspecialchars($row['category']) ?></div>
                                <?php if ($row['low'] > 0): ?><div class="text-[11px] text-amber-700"><?= $row['low'] ?> need reordering</div><?php endif; ?>
                                <div class="mt-1.5 h-1.5 w-full max-w-[200px] rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full rounded-full bg-blue-600" style="width: <?= $maxVal > 0 ? round($row['retail_value'] / $maxVal * 100) : 0 ?>%"></div>
                                </div>
                            </td>
                            <td class="py-3 px-3 text-right text-[13px] text-slate-700 tabular"><?= number_format($row['items']) ?></td>
                            <td class="py-3 px-3 text-right text-[13px] text-slate-700 tabular"><?= number_format($row['units']) ?></td>
                            <td class="py-3 px-5 text-right text-[13px] font-semibold text-slate-900 tabular"><?= $cur . number_format($row['retail_value'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden">
        <div class="px-5 pt-5 pb-3">
            <h2 class="text-base font-semibold text-slate-900">Most movement</h2>
            <p class="text-[13px] text-slate-500 mt-0.5"><?= htmlspecialchars(nxDate($reportFrom)) ?> to <?= htmlspecialchars(nxDate($reportTo)) ?></p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-y border-slate-200 bg-slate-50 text-[12px] font-semibold text-slate-500">
                        <th class="py-2.5 px-5">Item</th>
                        <th class="py-2.5 px-3 text-right">Received</th>
                        <th class="py-2.5 px-5 text-right">Used</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (!$r['top_moving']): ?>
                        <tr><td colspan="3" class="px-5"><div class="nx-empty">Nothing moved in this period.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($r['top_moving'] as $row): ?>
                        <tr class="hover:bg-slate-50 transition">
                            <td class="py-3 px-5 text-[13px] text-slate-900"><?= htmlspecialchars($row['name']) ?></td>
                            <td class="py-3 px-3 text-right text-[13px] text-emerald-700 tabular">+<?= number_format($row['in']) ?></td>
                            <td class="py-3 px-5 text-right text-[13px] text-slate-700 tabular">-<?= number_format($row['out']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden xl:col-span-2">
        <div class="px-5 pt-5 pb-3">
            <h2 class="text-base font-semibold text-slate-900">Sitting unused</h2>
            <p class="text-[13px] text-slate-500 mt-0.5">In stock but not touched in this period — money on a shelf.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-y border-slate-200 bg-slate-50 text-[12px] font-semibold text-slate-500">
                        <th class="py-2.5 px-5">Item</th>
                        <th class="py-2.5 px-3">Category</th>
                        <th class="py-2.5 px-3 text-right">Units</th>
                        <th class="py-2.5 px-3">Expiry</th>
                        <th class="py-2.5 px-5 text-right">Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (!$r['never_moved']): ?>
                        <tr><td colspan="5" class="px-5"><div class="nx-empty">Everything in stock moved at least once.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($r['never_moved'] as $item): ?>
                        <tr class="hover:bg-slate-50 transition">
                            <td class="py-3 px-5">
                                <div class="text-[13px] text-slate-900"><?= htmlspecialchars($item['name']) ?></div>
                                <div class="text-[11px] font-mono text-slate-500"><?= htmlspecialchars($item['sku']) ?></div>
                            </td>
                            <td class="py-3 px-3 text-[13px] text-slate-600"><?= htmlspecialchars($item['category']) ?></td>
                            <td class="py-3 px-3 text-right text-[13px] text-slate-700 tabular"><?= number_format((int) $item['current_stock']) ?></td>
                            <td class="py-3 px-3 text-[13px] text-slate-600"><?= !empty($item['expiry_date']) ? htmlspecialchars(nxDate($item['expiry_date'])) : '—' ?></td>
                            <td class="py-3 px-5 text-right text-[13px] font-semibold text-slate-900 tabular"><?= $cur . number_format((int) $item['current_stock'] * (float) $item['unit_price'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php endif; // reports view ?>


<!-- MODAL 1: ADD NEW INVENTORY ITEM -->
<?php if ($isAdminOrDev): ?>
<div id="modal-add-item" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="p-5 border-b border-outline-variant/30 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-on-surface">Add New Inventory Item</h3>
                <p class="text-xs text-outline">Enter product details, pricing, stock levels, and custom fields</p>
            </div>
            <button onclick="closeModal('modal-add-item')" class="text-outline hover:text-on-surface p-1">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form action="actions/inventory_add.php" method="POST" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Item Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Paracetamol 500mg Tablets" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">SKU / Code *</label>
                    <input type="text" name="sku" required placeholder="e.g. MED-PARA-500" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Category</label>
                    <select name="category" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Unit Type</label>
                    <select name="unit" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
                        <?php foreach ($units as $u): ?>
                            <option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isAdminOrDev): ?>
                        <p class="text-[10px] text-outline mt-1">Set the list in <a href="admin.php?tab=inventory" class="underline hover:text-primary">Administration &rarr; Inventory</a></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">VMS Tax Label</label>
                    <select name="vms_tax_code" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
                        <option value="A">A - Standard VAT (15%)</option>
                        <option value="E">E - Exempt (0%)</option>
                        <option value="F">F - Zero Rated (0%)</option>
                        <option value="P">P - Levy / Concession (0.25%)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Initial Stock Quantity</label>
                    <input type="number" name="current_stock" value="100" min="0" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Low Stock Threshold</label>
                    <input type="number" name="min_threshold" value="<?= $defaultMinThreshold ?>" min="1" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Unit Cost Price ($)</label>
                    <input type="number" step="0.01" name="cost_price" value="0.00" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Unit Selling Price ($)</label>
                    <input type="number" step="0.01" name="unit_price" value="0.00" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Batch Number</label>
                    <input type="text" name="batch_number" placeholder="e.g. BATCH-2023-09" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Expiry Date</label>
                    <input type="date" name="expiry_date" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
            </div>
            <?= nxRenderInventoryFields($customFieldsDef, [], 'add') ?>

            <div class="flex items-center justify-end gap-2 border-t border-outline-variant/30 pt-4">
                <button type="button" onclick="closeModal('modal-add-item')" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl text-xs font-semibold text-on-surface">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary hover:bg-primary/90 text-white rounded-xl text-xs font-semibold">Create Item</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: EDIT INVENTORY ITEM -->
<div id="modal-edit-item" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="p-5 border-b border-outline-variant/30 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-on-surface">Edit Inventory Item</h3>
                <p class="text-xs text-outline">Modify details, reorder threshold, and pricing</p>
            </div>
            <button onclick="closeModal('modal-edit-item')" class="text-outline hover:text-on-surface p-1">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form action="actions/inventory_edit.php" method="POST" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="item_id" id="edit-item-id">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Item Name *</label>
                    <input type="text" name="name" id="edit-name" required class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">SKU / Code *</label>
                    <input type="text" name="sku" id="edit-sku" required class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Category</label>
                    <select name="category" id="edit-category" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Unit Type</label>
                    <select name="unit" id="edit-unit" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
                        <?php foreach ($units as $u): ?>
                            <option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">VMS Tax Label</label>
                    <select name="vms_tax_code" id="edit-vms-tax-code" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
                        <option value="A">A - Standard VAT (15%)</option>
                        <option value="E">E - Exempt (0%)</option>
                        <option value="F">F - Zero Rated (0%)</option>
                        <option value="P">P - Levy / Concession (0.25%)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Low Stock Threshold</label>
                    <input type="number" name="min_threshold" id="edit-min-threshold" min="1" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Cost Price ($)</label>
                    <input type="number" step="0.01" name="cost_price" id="edit-cost-price" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Selling Price ($)</label>
                    <input type="number" step="0.01" name="unit_price" id="edit-unit-price" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Batch Number</label>
                    <input type="text" name="batch_number" id="edit-batch-number" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Expiry Date</label>
                    <input type="date" name="expiry_date" id="edit-expiry-date" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
            </div>

            <?= nxRenderInventoryFields($customFieldsDef, [], 'edit') ?>

            <div class="flex items-center justify-end gap-2 border-t border-outline-variant/30 pt-4">
                <button type="button" onclick="closeModal('modal-edit-item')" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl text-xs font-semibold text-on-surface">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary hover:bg-primary/90 text-white rounded-xl text-xs font-semibold">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- MODAL 3: DETAILED RESTOCK ENTRY -->
<div id="modal-detailed-restock" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xl w-full max-w-lg">
        <div class="p-5 border-b border-outline-variant/30 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-on-surface">Detailed Restock Entry</h3>
                <p class="text-xs text-outline" id="restock-item-title">Log supplier, batch, unit cost, and restock quantity</p>
            </div>
            <button onclick="closeModal('modal-detailed-restock')" class="text-outline hover:text-on-surface p-1">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form action="actions/inventory_restock.php" method="POST" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="item_id" id="restock-item-id">
            <input type="hidden" name="restock_type" value="detailed">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Restock Quantity *</label>
                    <input type="number" name="amount" value="50" min="1" required class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono font-bold">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Unit Cost Price ($)</label>
                    <input type="number" step="0.01" name="unit_cost" id="restock-unit-cost" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-on-surface mb-1">Supplier Name</label>
                <input type="text" name="supplier" placeholder="e.g. Apex Medical Distributors Fiji" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Batch Number</label>
                    <input type="text" name="batch_number" id="restock-batch-number" placeholder="Optional" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-on-surface mb-1">Expiry Date</label>
                    <input type="date" name="expiry_date" id="restock-expiry-date" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-on-surface mb-1">Restock Reference / Notes</label>
                <textarea name="notes" rows="2" placeholder="e.g. Purchase order #PO-9912 received in good condition." class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-outline-variant/30 pt-4">
                <button type="button" onclick="closeModal('modal-detailed-restock')" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl text-xs font-semibold text-on-surface">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary hover:bg-primary/90 text-white rounded-xl text-xs font-semibold">Submit Restock Entry</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 4: STOCK MOVEMENT AUDIT LOGS -->
<div id="modal-audit-logs" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xl w-full max-w-4xl max-h-[85vh] flex flex-col">
        <div class="p-5 border-b border-outline-variant/30 flex items-center justify-between shrink-0">
            <div>
                <h3 class="text-base font-bold text-on-surface">Stock Movement & Restock Audit History</h3>
                <p class="text-xs text-outline">Complete audit trail of all inventory restocks, adjustments, and initial entries</p>
            </div>
            <button onclick="closeModal('modal-audit-logs')" class="text-outline hover:text-on-surface p-1">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-5 overflow-y-auto flex-1">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-outline-variant/30 text-outline uppercase text-[10px] font-bold tracking-wider">
                        <th class="py-2 px-2">Date & Time</th>
                        <th class="py-2 px-2">Item Name & SKU</th>
                        <th class="py-2 px-2">Type</th>
                        <th class="py-2 px-2 text-center">Prev &rarr; New</th>
                        <th class="py-2 px-2">Supplier & Cost</th>
                        <th class="py-2 px-2">Notes & Performed By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/20">
                    <?php if (empty($inventoryLogs)): ?>
                        <tr>
                            <td colspan="6" class="py-6 text-center text-outline">No stock movement logs recorded yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($inventoryLogs as $log): ?>
                            <tr class="hover:bg-surface-container-low transition">
                                <td class="py-2.5 px-2 font-mono text-[11px] text-outline whitespace-nowrap">
                                    <?= htmlspecialchars($log['created_at']) ?>
                                </td>
                                <td class="py-2.5 px-2 font-semibold text-on-surface">
                                    <?= htmlspecialchars($log['item_name'] ?? 'Deactivated Item') ?>
                                    <span class="block text-[10px] font-mono text-outline font-normal"><?= htmlspecialchars($log['item_sku'] ?? '') ?></span>
                                </td>
                                <td class="py-2.5 px-2">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase
                                        <?= str_contains($log['type'], 'restock') ? 'bg-emerald-100 text-emerald-800' : 'bg-blue-100 text-blue-800' ?>">
                                        <?= htmlspecialchars(str_replace('_', ' ', $log['type'])) ?>
                                    </span>
                                </td>
                                <td class="py-2.5 px-2 text-center font-mono font-bold">
                                    <span class="text-outline font-normal"><?= $log['previous_stock'] ?></span>
                                    <span class="text-emerald-600 font-bold mx-1">&rarr;</span>
                                    <span class="text-primary font-bold"><?= $log['new_stock'] ?></span>
                                    <span class="block text-[10px] text-emerald-700 font-normal">(+<?= $log['change_amount'] ?>)</span>
                                </td>
                                <td class="py-2.5 px-2 text-xs">
                                    <span class="font-medium text-slate-800"><?= htmlspecialchars($log['supplier'] ?: 'N/A') ?></span>
                                    <?php if ((float)$log['unit_cost'] > 0): ?>
                                        <span class="block text-[10px] font-mono text-emerald-700"><?= htmlspecialchars(nxCurrencySymbol()) ?><?= number_format((float)$log['unit_cost'], 2) ?>/unit</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-2 text-xs">
                                    <span class="text-on-surface-variant font-medium"><?= htmlspecialchars($log['notes'] ?: 'No notes') ?></span>
                                    <span class="block text-[10px] text-outline">By: <?= htmlspecialchars($log['created_by']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-outline-variant/30 text-right shrink-0">
            <button onclick="closeModal('modal-audit-logs')" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl text-xs font-semibold text-on-surface">Close Audit History</button>
        </div>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}

// A dropdown can only select a value it offers. An item saved before the
// clinic edited its categories or units would otherwise quietly come back as
// the first option, and be saved that way.
function selectValue(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    value = (value === null || value === undefined) ? '' : String(value);
    if (el.tagName === 'SELECT' && value !== '' && !Array.from(el.options).some(o => o.value === value)) {
        el.add(new Option(value + ' (no longer in the list)', value));
    }
    el.value = value;
}

function openEditModal(item) {
    document.getElementById('edit-item-id').value = item.id;
    document.getElementById('edit-name').value = item.name;
    document.getElementById('edit-sku').value = item.sku;
    selectValue('edit-category', item.category);
    selectValue('edit-unit', item.unit);
    selectValue('edit-vms-tax-code', item.vms_tax_code || 'A');
    document.getElementById('edit-min-threshold').value = item.min_threshold;
    document.getElementById('edit-cost-price').value = item.cost_price || 0.00;
    document.getElementById('edit-unit-price').value = item.unit_price || 0.00;
    document.getElementById('edit-batch-number').value = item.batch_number || '';
    document.getElementById('edit-expiry-date').value = item.expiry_date || '';

    // Extra fields: clear every one first, then fill in what this item has,
    // so values from the previously edited item never linger.
    document.querySelectorAll('#modal-edit-item [data-cf]').forEach(function (el) { el.value = ''; });
    try {
        const customObj = JSON.parse(item.custom_fields || '{}');
        for (const [k, v] of Object.entries(customObj)) {
            const input = document.querySelector('#modal-edit-item [data-cf="' + CSS.escape(k) + '"]');
            if (input) input.value = v;
        }
    } catch (e) {}

    openModal('modal-edit-item');
}

function openDetailedRestockModal(item) {
    document.getElementById('restock-item-id').value = item.id;
    document.getElementById('restock-item-title').textContent = `Restocking: ${item.name} (${item.sku})`;
    document.getElementById('restock-unit-cost').value = item.cost_price || '';
    document.getElementById('restock-batch-number').value = item.batch_number || '';
    document.getElementById('restock-expiry-date').value = item.expiry_date || '';

    openModal('modal-detailed-restock');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
