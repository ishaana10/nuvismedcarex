<?php
$pageTitle = "Document & File Management";
$activePage = "uploads";

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/pagination.php';
include __DIR__ . '/includes/header.php';

$pdo = getDB();
$container = \ClinicFlow\Shared\Container::getInstance();
$uploadService = $container->get(\ClinicFlow\Services\FileUploadService::class);

// Fetch cloud drive settings
$cloudSettings = $uploadService->getCloudSettings();

// Filter parameters
$search = trim($_GET['search'] ?? '');
$providerFilter = trim($_GET['provider'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$patientFilter = trim($_GET['patient_id'] ?? '');

// Retrieve file records
$filesList = $uploadService->listFiles($patientFilter ?: null, $providerFilter ?: null, $categoryFilter ?: null, $search ?: null);

// Stats calculations
$totalFiles = count($filesList);
$serverCount = 0;
$onedriveCount = 0;
$gdriveCount = 0;
$totalSize = 0;

foreach ($filesList as $f) {
    if ($f['storage_provider'] === 'server') $serverCount++;
    elseif ($f['storage_provider'] === 'onedrive') $onedriveCount++;
    elseif ($f['storage_provider'] === 'googledrive') $gdriveCount++;
    $totalSize += (int)$f['file_size'];
}

// Format bytes
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    if ($bytes > 0) return $bytes . ' B';
    return 'N/A';
}

// Fetch patients list for upload dropdown
$patientsStmt = $pdo->query("SELECT id, first_name, last_name, mrn FROM patients ORDER BY first_name ASC, last_name ASC");
$patients = $patientsStmt->fetchAll(PDO::FETCH_ASSOC);

// Pagination
$pagination = getPaginationParams(10, [10, 25, 50], $pdo);
$paginatedFiles = array_slice($filesList, $pagination['offset'], $pagination['limit']);
?>

<!-- Document Management Header -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-on-surface flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-3xl">cloud_upload</span>
            <span>Document & File Management Hub</span>
        </h1>
        <p class="text-xs text-outline mt-1 font-medium">Upload, organize, and manage clinical documents across Local Server, Microsoft OneDrive, and Google Drive.</p>
    </div>

    <div class="flex items-center gap-3">
        <button type="button" onclick="openModal('modal-cloud-settings')" class="px-4 py-2.5 bg-slate-800 text-white font-semibold text-xs rounded-xl hover:bg-slate-900 transition shadow-xs flex items-center gap-2">
            <span class="material-symbols-outlined text-base">settings_suggest</span>
            <span>Cloud API Setup</span>
        </button>

        <button type="button" onclick="openModal('modal-upload-document')" class="px-5 py-2.5 bg-primary text-white font-bold text-xs rounded-xl hover:bg-primary/90 transition shadow-md shadow-primary/20 flex items-center gap-2">
            <span class="material-symbols-outlined text-base">add</span>
            <span>Upload Document</span>
        </button>
    </div>
</div>

<!-- Storage Statistics Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 shadow-2xs flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center font-bold">
            <span class="material-symbols-outlined text-2xl">folder</span>
        </div>
        <div>
            <div class="text-xs text-outline font-semibold">Total Documents</div>
            <div class="text-xl font-bold text-on-surface"><?= number_format($totalFiles) ?></div>
            <div class="text-[11px] text-slate-500 font-medium"><?= formatFileSize($totalSize) ?> total size</div>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 shadow-2xs flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold">
            <span class="material-symbols-outlined text-2xl">dns</span>
        </div>
        <div>
            <div class="text-xs text-outline font-semibold">Local Server Storage</div>
            <div class="text-xl font-bold text-on-surface"><?= number_format($serverCount) ?></div>
            <div class="text-[11px] text-emerald-700 font-medium">On-Premise Isolated</div>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 shadow-2xs flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-sky-100 text-sky-700 flex items-center justify-center font-bold">
            <span class="material-symbols-outlined text-2xl">cloud_sync</span>
        </div>
        <div>
            <div class="text-xs text-outline font-semibold">Microsoft OneDrive</div>
            <div class="text-xl font-bold text-on-surface"><?= number_format($onedriveCount) ?></div>
            <div class="text-[11px] text-sky-700 font-medium">Integrated Cloud</div>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 shadow-2xs flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center font-bold">
            <span class="material-symbols-outlined text-2xl">add_to_drive</span>
        </div>
        <div>
            <div class="text-xs text-outline font-semibold">Google Drive</div>
            <div class="text-xl font-bold text-on-surface"><?= number_format($gdriveCount) ?></div>
            <div class="text-[11px] text-amber-700 font-medium">Integrated Cloud</div>
        </div>
    </div>
</div>

<!-- Filters and Search Bar -->
<div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 shadow-xs mb-6">
    <form method="GET" action="uploads.php" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-center text-xs">
        <div class="md:col-span-4 relative">
            <span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-lg">search</span>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search document name, category, or MRN..." class="w-full bg-surface-container-low pl-9 pr-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface focus:outline-none focus:border-primary">
        </div>

        <div class="md:col-span-3">
            <select name="provider" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-semibold text-on-surface">
                <option value="">All Storage Providers</option>
                <option value="server" <?= $providerFilter === 'server' ? 'selected' : '' ?>>Local Server</option>
                <option value="onedrive" <?= $providerFilter === 'onedrive' ? 'selected' : '' ?>>Microsoft OneDrive</option>
                <option value="googledrive" <?= $providerFilter === 'googledrive' ? 'selected' : '' ?>>Google Drive</option>
            </select>
        </div>

        <div class="md:col-span-3">
            <select name="category" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-semibold text-on-surface">
                <option value="">All Document Categories</option>
                <option value="General" <?= $categoryFilter === 'General' ? 'selected' : '' ?>>General</option>
                <option value="Lab Results" <?= $categoryFilter === 'Lab Results' ? 'selected' : '' ?>>Lab Results</option>
                <option value="X-Ray / Imaging" <?= $categoryFilter === 'X-Ray / Imaging' ? 'selected' : '' ?>>X-Ray / Imaging</option>
                <option value="Prescription" <?= $categoryFilter === 'Prescription' ? 'selected' : '' ?>>Prescription</option>
                <option value="Insurance Claim" <?= $categoryFilter === 'Insurance Claim' ? 'selected' : '' ?>>Insurance Claim</option>
                <option value="Medical Certificate" <?= $categoryFilter === 'Medical Certificate' ? 'selected' : '' ?>>Medical Certificate</option>
                <option value="Consent Form" <?= $categoryFilter === 'Consent Form' ? 'selected' : '' ?>>Consent Form</option>
            </select>
        </div>

        <div class="md:col-span-2 flex items-center gap-2">
            <button type="submit" class="w-full py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 transition shadow-xs flex items-center justify-center gap-1">
                <span class="material-symbols-outlined text-base">filter_alt</span> Filter
            </button>
            <?php if ($search || $providerFilter || $categoryFilter || $patientFilter): ?>
                <a href="uploads.php" class="p-2 bg-slate-200 text-slate-700 rounded-xl hover:bg-slate-300 transition" title="Clear Filters">
                    <span class="material-symbols-outlined text-base">filter_alt_off</span>
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Documents List Table -->
<div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 shadow-xs overflow-hidden mb-6">
    <div class="p-4 border-b border-outline-variant/20 flex items-center justify-between">
        <h2 class="font-bold text-sm text-on-surface flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-lg">description</span>
            <span>Uploaded Documents (<?= count($filesList) ?>)</span>
        </h2>
    </div>

    <?php if (empty($filesList)): ?>
        <div class="p-12 text-center text-outline text-xs">
            <span class="material-symbols-outlined text-4xl text-slate-300 mb-2">cloud_off</span>
            <p class="font-medium text-slate-500">No documents found matching your filter criteria.</p>
            <button type="button" onclick="openModal('modal-upload-document')" class="mt-3 px-4 py-2 bg-primary text-white font-bold rounded-xl text-xs inline-flex items-center gap-1 shadow-xs">
                <span class="material-symbols-outlined text-sm">add</span> Upload First Document
            </button>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-surface-container-low text-outline font-bold border-b border-outline-variant/20 uppercase text-[10px]">
                    <tr>
                        <th class="p-3.5">Document / File Name</th>
                        <th class="p-3.5">Patient / Link</th>
                        <th class="p-3.5">Storage Provider</th>
                        <th class="p-3.5">Category</th>
                        <th class="p-3.5">Size / Type</th>
                        <th class="p-3.5">Uploaded By & Date</th>
                        <th class="p-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/20">
                    <?php foreach ($paginatedFiles as $file): ?>
                        <tr class="hover:bg-surface-container-low/50 transition">
                            <td class="p-3.5 font-bold text-on-surface">
                                <div class="flex items-center gap-2.5">
                                    <?php if ($file['storage_provider'] === 'onedrive'): ?>
                                        <div class="w-8 h-8 rounded-lg bg-sky-100 text-sky-700 flex items-center justify-center shrink-0 font-bold">
                                            <span class="material-symbols-outlined text-lg">cloud</span>
                                        </div>
                                    <?php elseif ($file['storage_provider'] === 'googledrive'): ?>
                                        <div class="w-8 h-8 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center shrink-0 font-bold">
                                            <span class="material-symbols-outlined text-lg">add_to_drive</span>
                                        </div>
                                    <?php else: ?>
                                        <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 font-bold">
                                            <span class="material-symbols-outlined text-lg">description</span>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <span class="block truncate max-w-xs text-slate-900 font-bold"><?= htmlspecialchars($file['original_name']) ?></span>
                                        <span class="text-[10px] text-slate-500 font-mono">ID: <?= htmlspecialchars($file['id']) ?></span>
                                    </div>
                                </div>
                            </td>

                            <td class="p-3.5 font-medium text-on-surface">
                                <?php if (!empty($file['patient_name'])): ?>
                                    <a href="patient_detail.php?id=<?= urlencode($file['patient_id']) ?>" class="font-bold text-primary hover:underline">
                                        <?= htmlspecialchars($file['patient_name']) ?>
                                    </a>
                                    <span class="text-[10px] block font-mono text-slate-500"><?= htmlspecialchars($file['mrn']) ?></span>
                                <?php else: ?>
                                    <span class="text-slate-400 italic">General / Unassigned</span>
                                <?php endif; ?>
                            </td>

                            <td class="p-3.5">
                                <?php if ($file['storage_provider'] === 'onedrive'): ?>
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-sky-100 text-sky-800 border border-sky-200 inline-flex items-center gap-1">
                                        <span class="material-symbols-outlined text-xs">cloud_queue</span> OneDrive
                                    </span>
                                <?php elseif ($file['storage_provider'] === 'googledrive'): ?>
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200 inline-flex items-center gap-1">
                                        <span class="material-symbols-outlined text-xs">add_to_drive</span> Google Drive
                                    </span>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 inline-flex items-center gap-1">
                                        <span class="material-symbols-outlined text-xs">dns</span> Local Server
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td class="p-3.5">
                                <span class="px-2.5 py-0.5 rounded bg-surface-container-high text-on-surface font-semibold text-[11px]">
                                    <?= htmlspecialchars($file['category']) ?>
                                </span>
                            </td>

                            <td class="p-3.5 text-slate-600 font-medium">
                                <?= formatFileSize($file['file_size']) ?>
                            </td>

                            <td class="p-3.5 text-slate-600 font-medium">
                                <span class="block text-slate-800 font-bold"><?= htmlspecialchars($file['uploaded_by'] ?: 'System User') ?></span>
                                <span class="text-[10px] text-slate-500"><?= date('M d, Y g:i A', strtotime($file['created_at'])) ?></span>
                            </td>

                            <td class="p-3.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <?php if ($file['storage_provider'] === 'server'): ?>
                                        <a href="<?= htmlspecialchars($file['file_path']) ?>" target="_blank" class="px-2.5 py-1 bg-primary text-white text-xs font-bold rounded-lg hover:bg-primary/90 flex items-center gap-1 shadow-xs">
                                            <span class="material-symbols-outlined text-sm">visibility</span> View
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= htmlspecialchars($file['external_url']) ?>" target="_blank" class="px-2.5 py-1 bg-blue-600 text-white text-xs font-bold rounded-lg hover:bg-blue-700 flex items-center gap-1 shadow-xs">
                                            <span class="material-symbols-outlined text-sm">open_in_new</span> Open Link
                                        </a>
                                    <?php endif; ?>

                                    <form action="actions/delete_uploaded_file.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this document?');" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                        <input type="hidden" name="file_id" value="<?= htmlspecialchars($file['id']) ?>">
                                        <button type="submit" class="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Delete Document">
                                            <span class="material-symbols-outlined text-base">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($totalFiles, $pagination['page'], $pagination['limit'], 'uploads.php', array_filter(['search' => $search, 'provider' => $providerFilter, 'category' => $categoryFilter, 'patient_id' => $patientFilter]), [10, 25, 50]) ?>
    <?php endif; ?>
</div>

<!-- Modal: Upload Document (Server, OneDrive, Google Drive) -->
<div id="modal-upload-document" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/30 shadow-2xl max-w-xl w-full overflow-hidden">
        <div class="p-5 bg-slate-900 text-white flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-xl">cloud_upload</span>
                <h3 class="font-bold text-sm">Upload or Link Document</h3>
            </div>
            <button type="button" onclick="closeModal('modal-upload-document')" class="text-slate-400 hover:text-white">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/upload_file.php" method="POST" enctype="multipart/form-data" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

            <!-- Storage Destination Selector -->
            <div>
                <label class="block font-bold text-slate-800 mb-1.5">Storage Provider / Destination <span class="text-rose-500">*</span></label>
                <div class="grid grid-cols-3 gap-3">
                    <label class="p-3 rounded-xl border-2 border-primary/20 bg-primary/5 cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition hover:border-primary" id="provider-btn-server">
                        <input type="radio" name="storage_provider" value="server" checked onclick="toggleUploadTab('server')" class="sr-only">
                        <span class="material-symbols-outlined text-2xl text-emerald-600">dns</span>
                        <span class="font-bold text-slate-800">Local Server</span>
                    </label>

                    <label class="p-3 rounded-xl border-2 border-slate-200 bg-surface-container-low cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition hover:border-sky-500" id="provider-btn-onedrive">
                        <input type="radio" name="storage_provider" value="onedrive" onclick="toggleUploadTab('onedrive')" class="sr-only">
                        <span class="material-symbols-outlined text-2xl text-sky-600">cloud</span>
                        <span class="font-bold text-slate-800">OneDrive</span>
                    </label>

                    <label class="p-3 rounded-xl border-2 border-slate-200 bg-surface-container-low cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition hover:border-amber-500" id="provider-btn-googledrive">
                        <input type="radio" name="storage_provider" value="googledrive" onclick="toggleUploadTab('googledrive')" class="sr-only">
                        <span class="material-symbols-outlined text-2xl text-amber-600">add_to_drive</span>
                        <span class="font-bold text-slate-800">Google Drive</span>
                    </label>
                </div>
            </div>

            <!-- Common Details -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Associated Patient (Optional)</label>
                    <select name="patient_id" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-semibold text-on-surface">
                        <option value="">-- No Patient / General Document --</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?= htmlspecialchars($p['id']) ?>" <?= $patientFilter === $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name'] . ' (' . $p['mrn'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">Document Category</label>
                    <select name="category" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-semibold text-on-surface">
                        <option value="General">General</option>
                        <option value="Lab Results">Lab Results</option>
                        <option value="X-Ray / Imaging">X-Ray / Imaging</option>
                        <option value="Prescription">Prescription</option>
                        <option value="Insurance Claim">Insurance Claim</option>
                        <option value="Medical Certificate">Medical Certificate</option>
                        <option value="Consent Form">Consent Form</option>
                    </select>
                </div>
            </div>

            <!-- Section 1: Server File Upload -->
            <div id="tab-section-server" class="space-y-2">
                <label class="block font-bold text-slate-700 mb-1">Choose File to Upload</label>
                <input type="file" name="file" class="w-full text-xs text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-white hover:file:bg-primary/90">
                <p class="text-[10px] text-slate-500">Supported formats: PDF, Images (JPG, PNG), DOCX, XLSX, TXT, CSV, ZIP. Max file size: 25MB.</p>
            </div>

            <!-- Section 2: OneDrive Link -->
            <div id="tab-section-onedrive" class="space-y-3 hidden">
                <div class="p-3 bg-sky-50 border border-sky-200 rounded-2xl text-[11px] text-sky-900">
                    <strong class="font-bold">Microsoft OneDrive Integration:</strong> Paste your OneDrive shared file URL and document title below.
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Document Title / File Name *</label>
                    <input type="text" name="onedrive_file_name" placeholder="e.g. MRI_Brain_Scan_Patient_2025.pdf" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">OneDrive Shared File Link / URL *</label>
                    <input type="url" name="onedrive_file_url" placeholder="https://1drv.ms/b/s!..." class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">External OneDrive Item ID (Optional)</label>
                    <input type="text" name="onedrive_file_id" placeholder="e.g. 01ABCDEFGH12345" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-mono text-xs">
                </div>
            </div>

            <!-- Section 3: Google Drive Link -->
            <div id="tab-section-googledrive" class="space-y-3 hidden">
                <div class="p-3 bg-amber-50 border border-amber-200 rounded-2xl text-[11px] text-amber-900">
                    <strong class="font-bold">Google Drive Integration:</strong> Paste your Google Drive document sharing link and file title below.
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Document Title / File Name *</label>
                    <input type="text" name="gdrive_file_name" placeholder="e.g. Blood_Lab_Panel_Oct2025.pdf" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Google Drive Sharing Link / URL *</label>
                    <input type="url" name="gdrive_file_url" placeholder="https://drive.google.com/file/d/..." class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Google Drive File ID (Optional)</label>
                    <input type="text" name="gdrive_file_id" placeholder="e.g. 1a2b3c4d5e6f7g8h9i0j" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-mono text-xs">
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-outline-variant/20">
                <button type="button" onclick="closeModal('modal-upload-document')" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl font-semibold text-on-surface">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 shadow-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">cloud_upload</span>
                    <span>Submit & Save Document</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Cloud API Settings -->
<div id="modal-cloud-settings" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/30 shadow-2xl max-w-lg w-full overflow-hidden">
        <div class="p-5 bg-slate-900 text-white flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-xl">settings_suggest</span>
                <h3 class="font-bold text-sm">Cloud Storage Credentials Setup</h3>
            </div>
            <button type="button" onclick="closeModal('modal-cloud-settings')" class="text-slate-400 hover:text-white">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="actions/cloud_storage_settings.php" method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

            <!-- OneDrive Credentials -->
            <div class="space-y-2 p-3.5 bg-sky-50/50 rounded-2xl border border-sky-200">
                <h4 class="font-bold text-sky-900 flex items-center gap-1">
                    <span class="material-symbols-outlined text-base text-sky-700">cloud</span>
                    <span>Microsoft OneDrive (Azure Graph API) Credentials</span>
                </h4>
                <div>
                    <label class="block font-semibold text-slate-700 mb-0.5">Azure Client Application ID</label>
                    <input type="text" name="cloud_onedrive_client_id" value="<?= htmlspecialchars($cloudSettings['cloud_onedrive_client_id'] ?? '') ?>" placeholder="e.g. 00000000-0000-0000-0000-000000000000" class="w-full bg-white px-3 py-1.5 rounded-lg border border-sky-300 font-mono text-xs">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 mb-0.5">Azure Client Secret</label>
                    <input type="password" name="cloud_onedrive_client_secret" value="<?= htmlspecialchars($cloudSettings['cloud_onedrive_client_secret'] ?? '') ?>" placeholder="Client Secret Key" class="w-full bg-white px-3 py-1.5 rounded-lg border border-sky-300 font-mono text-xs">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 mb-0.5">Azure Tenant ID</label>
                    <input type="text" name="cloud_onedrive_tenant_id" value="<?= htmlspecialchars($cloudSettings['cloud_onedrive_tenant_id'] ?? '') ?>" placeholder="common or tenant-id" class="w-full bg-white px-3 py-1.5 rounded-lg border border-sky-300 font-mono text-xs">
                </div>
            </div>

            <!-- Google Drive Credentials -->
            <div class="space-y-2 p-3.5 bg-amber-50/50 rounded-2xl border border-amber-200">
                <h4 class="font-bold text-amber-900 flex items-center gap-1">
                    <span class="material-symbols-outlined text-base text-amber-700">add_to_drive</span>
                    <span>Google Drive API Credentials</span>
                </h4>
                <div>
                    <label class="block font-semibold text-slate-700 mb-0.5">Google Cloud Client ID</label>
                    <input type="text" name="cloud_googledrive_client_id" value="<?= htmlspecialchars($cloudSettings['cloud_googledrive_client_id'] ?? '') ?>" placeholder="e.g. xxxxx.apps.googleusercontent.com" class="w-full bg-white px-3 py-1.5 rounded-lg border border-amber-300 font-mono text-xs">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 mb-0.5">Google API Key / Service Account Key</label>
                    <input type="password" name="cloud_googledrive_api_key" value="<?= htmlspecialchars($cloudSettings['cloud_googledrive_api_key'] ?? '') ?>" placeholder="API Key" class="w-full bg-white px-3 py-1.5 rounded-lg border border-amber-300 font-mono text-xs">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 mb-0.5">Shared Folder ID (Optional)</label>
                    <input type="text" name="cloud_googledrive_folder_id" value="<?= htmlspecialchars($cloudSettings['cloud_googledrive_folder_id'] ?? '') ?>" placeholder="Target Google Drive Folder ID" class="w-full bg-white px-3 py-1.5 rounded-lg border border-amber-300 font-mono text-xs">
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-outline-variant/20">
                <button type="button" onclick="closeModal('modal-cloud-settings')" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl font-semibold text-on-surface">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-slate-900 text-white font-bold rounded-xl hover:bg-slate-800 shadow-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span>Save API Settings</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleUploadTab(provider) {
    const serverTab = document.getElementById('tab-section-server');
    const onedriveTab = document.getElementById('tab-section-onedrive');
    const gdriveTab = document.getElementById('tab-section-googledrive');

    const btnServer = document.getElementById('provider-btn-server');
    const btnOnedrive = document.getElementById('provider-btn-onedrive');
    const btnGdrive = document.getElementById('provider-btn-googledrive');

    serverTab.classList.add('hidden');
    onedriveTab.classList.add('hidden');
    gdriveTab.classList.add('hidden');

    btnServer.className = "p-3 rounded-xl border-2 border-slate-200 bg-surface-container-low cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition hover:border-emerald-500";
    btnOnedrive.className = "p-3 rounded-xl border-2 border-slate-200 bg-surface-container-low cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition hover:border-sky-500";
    btnGdrive.className = "p-3 rounded-xl border-2 border-slate-200 bg-surface-container-low cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition hover:border-amber-500";

    if (provider === 'server') {
        serverTab.classList.remove('hidden');
        btnServer.className = "p-3 rounded-xl border-2 border-emerald-500 bg-emerald-50/50 cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition";
    } else if (provider === 'onedrive') {
        onedriveTab.classList.remove('hidden');
        btnOnedrive.className = "p-3 rounded-xl border-2 border-sky-500 bg-sky-50/50 cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition";
    } else if (provider === 'googledrive') {
        gdriveTab.classList.remove('hidden');
        btnGdrive.className = "p-3 rounded-xl border-2 border-amber-500 bg-amber-50/50 cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition";
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
