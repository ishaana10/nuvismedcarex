<?php
/**
 * Shared Structured Lab Order Creation and Edit Modals
 */

$labCategories = [
    'Biochemistry' => [
        'Uric Acid',
        'Fasting Blood Sugar',
        'Random Blood Sugar',
        'OGTT',
        'Lipid Profile',
        'Renal Function Test',
        'Liver Function Test',
        'Electrolytes',
        'Cardiac Markers (Troponin, CK-MB)',
        'HbA1c',
        'Thyroid Panel (TSH, FT3, FT4)',
        'Amylase / Lipase'
    ],
    'Serology' => [
        'HIV 1 & 2 Screen',
        'Hepatitis B (HBsAg)',
        'Hepatitis C (HCV Ab)',
        'Syphilis (VDRL / RPR)',
        'Dengue NS1 / IgG / IgM',
        'Rheumatoid Factor (RF)',
        'Widal / Typhoid Test'
    ],
    'Hematology' => [
        'Complete Blood Count (CBC)',
        'ESR',
        'Blood Grouping & Rh',
        'Peripheral Blood Smear',
        'Reticulocyte Count',
        'Coagulation Profile (PT/INR, aPTT)'
    ],
    'Microbiology' => [
        'Urine Routine & Microscopy',
        'Stool Routine & Microscopy',
        'Sputum AFB / GeneXpert',
        'Culture & Sensitivity (Urine, Blood, Wound)'
    ]
];
?>

<!-- Modal Add Lab Order (Multi-Category Checklist) -->
<div id="modal-add-lab-order" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/30 shadow-2xl max-w-3xl w-full overflow-hidden flex flex-col max-h-[90vh]">
        <div class="p-5 bg-slate-900 text-white flex items-center justify-between shrink-0">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-xl">science</span>
                <h3 class="font-bold text-sm">Order Lab Tests & Diagnostics</h3>
            </div>
            <button type="button" onclick="closeModal('modal-add-lab-order')" class="text-slate-400 hover:text-white">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/lab_order_save.php" method="POST" class="p-6 space-y-4 text-xs overflow-y-auto grow">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_lab_order">
            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patientId) ?>">
            <?php if (!empty($visitId)): ?>
                <input type="hidden" name="visit_id" value="<?= htmlspecialchars($visitId) ?>">
            <?php endif; ?>

            <div>
                <label class="block font-bold text-slate-800 text-xs mb-2 uppercase tracking-wider">
                    Select Test Checklist Items
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 max-h-72 overflow-y-auto p-3 bg-surface-container-low rounded-2xl border border-outline-variant/30">
                    <?php foreach ($labCategories as $category => $tests): ?>
                        <div class="bg-white p-3 rounded-xl border border-outline-variant/20 shadow-2xs space-y-2">
                            <div class="font-bold text-primary text-xs pb-1 border-b border-slate-100 flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">folder_open</span>
                                <span><?= htmlspecialchars($category) ?></span>
                            </div>
                            <div class="space-y-1.5">
                                <?php foreach ($tests as $tName): ?>
                                    <label class="flex items-center gap-2 text-[11px] font-medium text-slate-700 hover:text-primary cursor-pointer transition select-none">
                                        <input type="checkbox" name="checklist_tests[]" value="<?= htmlspecialchars($category . '|' . $tName) ?>" class="add-lab-chk w-4 h-4 rounded text-primary focus:ring-primary/30 border-slate-300">
                                        <span><?= htmlspecialchars($tName) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Custom / Additional Test Rows -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="font-bold text-slate-800 text-xs uppercase tracking-wider">Additional / Custom Tests</span>
                    <button type="button" onclick="addCustomLabRow('add-lab-custom-container')" class="px-2.5 py-1 bg-primary/10 text-primary hover:bg-primary/20 rounded-lg font-bold text-[11px] flex items-center gap-1 transition">
                        <span class="material-symbols-outlined text-xs">add</span> Add Custom Test
                    </button>
                </div>
                <div id="add-lab-custom-container" class="space-y-2"></div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Clinical Notes / Indications</label>
                <textarea name="clinical_notes" rows="2" placeholder="Clinical reasons for requesting lab tests..." class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-outline-variant/20 shrink-0">
                <button type="button" onclick="closeModal('modal-add-lab-order')" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl font-semibold text-on-surface">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 shadow-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">send</span>
                    <span>Submit Order</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal View & Edit Lab Request -->
<div id="modal-edit-lab-order" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/30 shadow-2xl max-w-3xl w-full overflow-hidden flex flex-col max-h-[90vh]">
        <div class="p-5 bg-slate-900 text-white flex items-center justify-between shrink-0">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-xl">science</span>
                <h3 class="font-bold text-sm">View & Edit Lab Request / Attach Results</h3>
            </div>
            <button type="button" onclick="closeModal('modal-edit-lab-order')" class="text-slate-400 hover:text-white">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/lab_order_save.php" method="POST" class="p-6 space-y-4 text-xs overflow-y-auto grow">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="edit_lab_order">
            <input type="hidden" name="order_id" id="edit-lab-order-id">
            <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patientId) ?>">
            <?php if (!empty($visitId)): ?>
                <input type="hidden" name="visit_id" value="<?= htmlspecialchars($visitId) ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Order Status</label>
                    <select name="status" id="edit-lab-status" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-bold text-primary">
                        <option value="Ordered">Ordered (Pending)</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="flex items-center gap-2 pt-5">
                    <input type="checkbox" id="edit-lab-is-abnormal" name="is_abnormal" value="1" class="w-4 h-4 text-red-600 rounded focus:ring-red-500">
                    <label for="edit-lab-is-abnormal" class="font-bold text-rose-700 text-xs">Mark as Abnormal / High Concern Result</label>
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-800 text-xs mb-2 uppercase tracking-wider">
                    Test Checklist Items
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 max-h-64 overflow-y-auto p-3 bg-surface-container-low rounded-2xl border border-outline-variant/30">
                    <?php foreach ($labCategories as $category => $tests): ?>
                        <div class="bg-white p-3 rounded-xl border border-outline-variant/20 shadow-2xs space-y-2">
                            <div class="font-bold text-primary text-xs pb-1 border-b border-slate-100 flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">folder_open</span>
                                <span><?= htmlspecialchars($category) ?></span>
                            </div>
                            <div class="space-y-1.5">
                                <?php foreach ($tests as $tName): ?>
                                    <label class="flex items-center gap-2 text-[11px] font-medium text-slate-700 hover:text-primary cursor-pointer transition select-none">
                                        <input type="checkbox" name="checklist_tests[]" value="<?= htmlspecialchars($category . '|' . $tName) ?>" data-test-name="<?= htmlspecialchars($tName) ?>" class="edit-lab-chk w-4 h-4 rounded text-primary focus:ring-primary/30 border-slate-300">
                                        <span><?= htmlspecialchars($tName) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Custom / Additional Test Rows for Edit -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <span class="font-bold text-slate-800 text-xs uppercase tracking-wider">Additional / Custom Tests</span>
                    <button type="button" onclick="addCustomLabRow('edit-lab-custom-container')" class="px-2.5 py-1 bg-primary/10 text-primary hover:bg-primary/20 rounded-lg font-bold text-[11px] flex items-center gap-1 transition">
                        <span class="material-symbols-outlined text-xs">add</span> Add Custom Test
                    </button>
                </div>
                <div id="edit-lab-custom-container" class="space-y-2"></div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Clinical Notes / Indications</label>
                <textarea name="clinical_notes" id="edit-lab-notes" rows="2" placeholder="Clinical reasons for requesting lab tests..." class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary"></textarea>
            </div>

            <div class="p-3 bg-amber-50 border border-amber-200 rounded-2xl space-y-2">
                <label class="block font-bold text-amber-900 text-xs">Laboratory Results & Findings Summary</label>
                <textarea name="results" id="edit-lab-results" rows="3" placeholder="Enter test results, numerical values, findings, or radiologist report..." class="w-full bg-white p-2.5 rounded-xl border border-amber-300 font-mono text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500"></textarea>
                <p class="text-[10px] text-amber-800">Updating results will allow doctors and clinical staff to view findings directly in patient chart.</p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-outline-variant/20 shrink-0">
                <button type="button" onclick="closeModal('modal-edit-lab-order')" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl font-semibold text-on-surface">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 shadow-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span>Save Changes & Results</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let customLabRowIndex = 0;

function addCustomLabRow(containerId, testName = '', category = 'General', instructions = '') {
    const container = document.getElementById(containerId);
    if (!container) return;
    const div = document.createElement('div');
    div.className = 'p-2 bg-surface-container-low rounded-xl border border-outline-variant/30 grid grid-cols-1 md:grid-cols-12 gap-2 items-center';
    div.innerHTML = `
        <div class="md:col-span-5">
            <input type="text" name="items[${customLabRowIndex}][test_name]" value="${escapeHtml(testName)}" required placeholder="Custom Test Name *" class="w-full bg-white px-3 py-1 rounded-lg border border-outline-variant/40 text-xs">
        </div>
        <div class="md:col-span-4">
            <select name="items[${customLabRowIndex}][category]" class="w-full bg-white px-2 py-1 rounded-lg border border-outline-variant/40 text-xs">
                <option value="General" ${category === 'General' ? 'selected' : ''}>General / Other</option>
                <option value="Biochemistry" ${category === 'Biochemistry' ? 'selected' : ''}>Biochemistry</option>
                <option value="Serology" ${category === 'Serology' ? 'selected' : ''}>Serology</option>
                <option value="Hematology" ${category === 'Hematology' ? 'selected' : ''}>Hematology</option>
                <option value="Microbiology" ${category === 'Microbiology' ? 'selected' : ''}>Microbiology</option>
                <option value="Imaging" ${category === 'Imaging' ? 'selected' : ''}>Imaging / Radiology</option>
            </select>
        </div>
        <div class="md:col-span-3 flex items-center gap-1">
            <input type="text" name="items[${customLabRowIndex}][instructions]" value="${escapeHtml(instructions)}" placeholder="Instructions" class="w-full bg-white px-2 py-1 rounded-lg border border-outline-variant/40 text-xs">
            <button type="button" onclick="this.closest('div.grid').remove()" class="text-rose-600 hover:text-rose-800 p-1">
                <span class="material-symbols-outlined text-base">delete</span>
            </button>
        </div>
    `;
    container.appendChild(div);
    customLabRowIndex++;
}

function openEditLabModal(order) {
    document.getElementById('edit-lab-order-id').value = order.id || '';
    document.getElementById('edit-lab-status').value = order.status || 'Ordered';
    document.getElementById('edit-lab-notes').value = order.clinical_notes || '';
    document.getElementById('edit-lab-results').value = order.results || '';
    document.getElementById('edit-lab-is-abnormal').checked = Boolean(order.is_abnormal && order.is_abnormal != '0');

    // Reset all edit checkboxes
    const checkBoxes = document.querySelectorAll('.edit-lab-chk');
    checkBoxes.forEach(cb => cb.checked = false);

    // Clear custom items container
    const customContainer = document.getElementById('edit-lab-custom-container');
    if (customContainer) customContainer.innerHTML = '';

    const orderItems = (order.items && order.items.length > 0) ? order.items : [{ test_name: order.test_name, category: order.category, instructions: '' }];

    orderItems.forEach(item => {
        let matched = false;
        checkBoxes.forEach(cb => {
            const testName = cb.getAttribute('data-test-name') || '';
            const valName = cb.value.split('|')[1] || '';
            if (testName && (testName.toLowerCase() === item.test_name.toLowerCase() || valName.toLowerCase() === item.test_name.toLowerCase())) {
                cb.checked = true;
                matched = true;
            }
        });

        if (!matched && item.test_name) {
            addCustomLabRow('edit-lab-custom-container', item.test_name, item.category || 'General', item.instructions || '');
        }
    });

    openModal('modal-edit-lab-order');
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>
