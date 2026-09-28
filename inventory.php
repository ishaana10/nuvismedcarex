<?php
$pageTitle = "Pharmacy & Supplies Inventory - NuvisMedcareX";
$activePage = "inventory";
include __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/security.php';

$userRole = $_SESSION['user_role'] ?? 'Staff';
$isAdminOrDev = in_array($userRole, ['Administrator', 'Developer']);

$inventoryService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\InventoryService::class);

// Fetch Clinic Inventory Settings & Custom Fields Definition
$settings = $inventoryService->getInventorySettings();
$categories = $settings['categories'];
$customFieldsDef = $settings['custom_fields_def'];

// Search and Filter parameters
$searchQuery = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$activeTab = trim($_GET['tab'] ?? 'inventory'); // inventory or logs

// Fetch Inventory Items via InventoryService
$items = $inventoryService->searchAndFilterItems($searchQuery, $categoryFilter, $statusFilter);

// Calculate Stats
$totalItems = count($items);
$lowStockCount = 0;
$outOfStockCount = 0;
$totalValuation = 0.0;

foreach ($items as $item) {
    if ((int)$item['current_stock'] <= 0) {
        $outOfStockCount++;
    } elseif ((int)$item['current_stock'] <= (int)$item['min_threshold']) {
        $lowStockCount++;
    }
    $totalValuation += ((int)$item['current_stock'] * (float)$item['unit_price']);
}

// Fetch Inventory Logs via InventoryService
$inventoryLogs = $inventoryService->getInventoryLogs(100);

$csrfToken = getCsrfToken();
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-on-surface">Pharmacy & Supplies Inventory</h1>
        <p class="text-xs text-outline font-medium">Manage medical inventory, track stock levels, restock history, and customize developer fields</p>
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

<!-- Stats Header -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 shadow-xs flex items-center justify-between">
        <div>
            <div class="text-[11px] font-bold tracking-wider text-outline uppercase">Total Catalog Items</div>
            <div class="text-2xl font-bold text-on-surface mt-1"><?= number_format($totalItems) ?></div>
        </div>
        <div class="p-3 bg-blue-50 text-blue-600 rounded-xl">
            <span class="material-symbols-outlined text-2xl">inventory_2</span>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 shadow-xs flex items-center justify-between">
        <div>
            <div class="text-[11px] font-bold tracking-wider text-outline uppercase">Low / Out of Stock</div>
            <div class="text-2xl font-bold <?= $lowStockCount + $outOfStockCount > 0 ? 'text-red-600' : 'text-emerald-600' ?> mt-1">
                <?= number_format($lowStockCount + $outOfStockCount) ?>
            </div>
        </div>
        <div class="p-3 bg-red-50 text-red-600 rounded-xl">
            <span class="material-symbols-outlined text-2xl">warning</span>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 shadow-xs flex items-center justify-between">
        <div>
            <div class="text-[11px] font-bold tracking-wider text-outline uppercase">Estimated Valuation</div>
            <div class="text-2xl font-bold text-emerald-700 mt-1">$<?= number_format($totalValuation, 2) ?></div>
        </div>
        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-xl">
            <span class="material-symbols-outlined text-2xl">payments</span>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 shadow-xs flex items-center justify-between">
        <div>
            <div class="text-[11px] font-bold tracking-wider text-outline uppercase">Custom Categories</div>
            <div class="text-2xl font-bold text-indigo-600 mt-1"><?= count($categories) ?></div>
        </div>
        <div class="p-3 bg-indigo-50 text-indigo-600 rounded-xl">
            <span class="material-symbols-outlined text-2xl">category</span>
        </div>
    </div>
</div>

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
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="7" class="py-8 text-center text-outline">
                            <span class="material-symbols-outlined text-4xl text-outline/50 block mb-1">inventory</span>
                            No matching inventory items found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
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
                                <span class="text-emerald-700 font-bold">$<?= number_format((float)$item['unit_price'], 2) ?></span>
                                <span class="block text-[10px] text-outline">Cost: $<?= number_format((float)$item['cost_price'], 2) ?></span>
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
                                        <form action="actions/inventory_delete.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this inventory record? Historical logs will be preserved.');" class="inline">
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
</div>

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
                    <input type="text" name="unit" value="Boxes" placeholder="e.g. Boxes, Bottles, Vials" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
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
                    <input type="number" name="min_threshold" value="<?= (int)($settingsMap['inventory_default_min_threshold'] ?? 10) ?>" min="1" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none font-mono">
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

            <!-- Dynamic Custom Fields Section -->
            <?php if (!empty($customFieldsDef)): ?>
                <div class="border-t border-outline-variant/30 pt-3">
                    <h4 class="text-xs font-bold text-outline uppercase tracking-wider mb-2">Developer Dynamic Custom Fields</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <?php foreach ($customFieldsDef as $cf): ?>
                            <div>
                                <label class="block text-[11px] font-semibold text-on-surface mb-1"><?= htmlspecialchars($cf['label'] ?? $cf['name']) ?></label>
                                <input type="<?= htmlspecialchars($cf['type'] ?? 'text') ?>" name="custom_fields[<?= htmlspecialchars($cf['name']) ?>]" placeholder="<?= htmlspecialchars($cf['placeholder'] ?? '') ?>" class="w-full bg-surface-container-low px-3 py-1.5 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

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
                    <input type="text" name="unit" id="edit-unit" class="w-full bg-surface-container-low px-3 py-2 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
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

            <?php if (!empty($customFieldsDef)): ?>
                <div class="border-t border-outline-variant/30 pt-3">
                    <h4 class="text-xs font-bold text-outline uppercase tracking-wider mb-2">Developer Dynamic Custom Fields</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <?php foreach ($customFieldsDef as $cf): ?>
                            <div>
                                <label class="block text-[11px] font-semibold text-on-surface mb-1"><?= htmlspecialchars($cf['label'] ?? $cf['name']) ?></label>
                                <input type="<?= htmlspecialchars($cf['type'] ?? 'text') ?>" name="custom_fields[<?= htmlspecialchars($cf['name']) ?>]" id="edit-cf-<?= htmlspecialchars($cf['name']) ?>" class="w-full bg-surface-container-low px-3 py-1.5 rounded-xl text-xs border border-outline-variant/40 focus:border-primary focus:bg-white focus:outline-none">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

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
                                        <span class="block text-[10px] font-mono text-emerald-700">$<?= number_format((float)$log['unit_cost'], 2) ?>/unit</span>
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

function openEditModal(item) {
    document.getElementById('edit-item-id').value = item.id;
    document.getElementById('edit-name').value = item.name;
    document.getElementById('edit-sku').value = item.sku;
    document.getElementById('edit-category').value = item.category;
    document.getElementById('edit-unit').value = item.unit;
    document.getElementById('edit-vms-tax-code').value = item.vms_tax_code || 'A';
    document.getElementById('edit-min-threshold').value = item.min_threshold;
    document.getElementById('edit-cost-price').value = item.cost_price || 0.00;
    document.getElementById('edit-unit-price').value = item.unit_price || 0.00;
    document.getElementById('edit-batch-number').value = item.batch_number || '';
    document.getElementById('edit-expiry-date').value = item.expiry_date || '';

    // Custom fields prefill
    try {
        const customObj = JSON.parse(item.custom_fields || '{}');
        for (const [k, v] of Object.entries(customObj)) {
            const input = document.getElementById('edit-cf-' + k);
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
