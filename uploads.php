<?php
$pageTitle = "Document & File Management";
$activePage = "uploads";

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/pagination.php';
include __DIR__ . '/includes/header.php';

$pdo = getDB();
$container = \ClinicFlow\Shared\Container::getInstance();
$uploadService = $container->get(\ClinicFlow\Services\FileUploadService::class);

// Fetch cloud drive settings & storage quota
$cloudSettings = $uploadService->getCloudSettings();
$storageUsage = $uploadService->getStorageUsage();

// Filter parameters
$search = trim($_GET['search'] ?? '');
$providerFilter = trim($_GET['provider'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$patientFilter = trim($_GET['patient_id'] ?? '');

// Retrieve file records
$filesList = $uploadService->listFiles($patientFilter ?: null, $providerFilter ?: null, $categoryFilter ?: null, $search ?: null);

// The drive this installation copies documents to (Developer -> Document storage)
$cloudCfg = \ClinicFlow\Services\Cloud\CloudConfig::get();
$cloudLabels = \ClinicFlow\Services\Cloud\Cloud::labels() + [
    'server' => 'This server',
    // Files picked from someone's own drive are links, not copies
    'onedrive' => 'OneDrive (linked)',
    'googledrive' => 'Google Drive (linked)',
];
$defaultDrive = (string) ($cloudCfg['provider'] ?? 'local');
$defaultDriveLabel = $defaultDrive === 'local' ? 'this server' : ($cloudLabels[$defaultDrive] ?? $defaultDrive);

/**
 * Label, icon and colour classes for a storage provider. The classes are
 * written out in full rather than built from a colour name, so they survive a
 * compiled stylesheet.
 */
function nxDriveMeta(string $providerId, array $labels): array
{
    [$label, $icon, $tile, $pill] = match ($providerId) {
        'onedrive' => ['OneDrive (linked)', 'cloud', 'bg-sky-100 text-sky-700', 'bg-sky-100 text-sky-800 border-sky-200'],
        'googledrive' => ['Google Drive (linked)', 'add_to_drive', 'bg-amber-100 text-amber-700', 'bg-amber-100 text-amber-800 border-amber-200'],
        'gdrive' => ['Google Drive', 'add_to_drive', 'bg-amber-100 text-amber-700', 'bg-amber-100 text-amber-800 border-amber-200'],
        'onedrive_app' => ['OneDrive', 'cloud', 'bg-sky-100 text-sky-700', 'bg-sky-100 text-sky-800 border-sky-200'],
        'dropbox' => ['Dropbox', 'inventory_2', 'bg-indigo-100 text-indigo-700', 'bg-indigo-100 text-indigo-800 border-indigo-200'],
        // Short enough for a table pill; the long name belongs in the settings
        'webdav' => ['WebDAV', 'dns', 'bg-violet-100 text-violet-700', 'bg-violet-100 text-violet-800 border-violet-200'],
        'server' => ['This server', 'description', 'bg-emerald-100 text-emerald-700', 'bg-emerald-100 text-emerald-800 border-emerald-200'],
        default => [$labels[$providerId] ?? ucfirst($providerId), 'cloud', 'bg-slate-100 text-slate-700', 'bg-slate-100 text-slate-800 border-slate-200'],
    };
    return ['label' => $label, 'icon' => $icon, 'tile' => $tile, 'pill' => $pill];
}

// Stats calculations
$totalFiles = count($filesList);
$totalSize = 0;
$byProvider = [];
$syncedCount = 0;
$waitingCount = 0;
$failedCount = 0;

foreach ($filesList as $f) {
    $where = (string) ($f['storage_provider'] ?? 'server');
    $byProvider[$where] = ($byProvider[$where] ?? 0) + 1;
    $state = (string) ($f['remote_status'] ?? 'none');
    if ($state === 'synced') {
        $syncedCount++;
    } elseif ($state === 'failed') {
        $failedCount++;
    } elseif ($where === 'server' && $defaultDrive !== 'local') {
        $waitingCount++;
    }
    $totalSize += (int)$f['file_size'];
}
arsort($byProvider);

// Format bytes
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    if ($bytes > 0) return $bytes . ' B';
    return 'N/A';
}

// Patients of this clinic only, for the upload picker
$patientsStmt = $pdo->prepare("SELECT id, first_name, last_name, mrn FROM patients WHERE tenant_id = ? ORDER BY first_name ASC, last_name ASC");
$patientsStmt->execute([\ClinicFlow\Shared\TenantContext::getTenantId()]);
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
        <p class="text-xs text-outline mt-1 font-medium">Upload and manage clinical documents. New uploads anywhere in the application are kept on <?= htmlspecialchars($defaultDriveLabel) ?>.</p>
    </div>

    <div class="flex items-center gap-3">
        <?php if (nxCan('developer', 'edit')): ?>
            <a href="developer.php?dev_tab=cloud" class="px-4 py-2.5 bg-surface-container border border-outline-variant/40 text-on-surface font-semibold text-xs rounded-xl hover:bg-surface-container-high transition flex items-center gap-2">
                <span class="material-symbols-outlined text-base">settings_suggest</span>
                <span>Storage settings</span>
            </a>
        <?php endif; ?>

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

    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 shadow-2xs flex flex-col justify-between gap-2">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold shrink-0">
                <span class="material-symbols-outlined text-2xl">dns</span>
            </div>
            <div class="min-w-0">
                <div class="text-xs text-outline font-semibold">Server Allocation Plan</div>
                <div class="text-sm font-bold text-on-surface flex items-center gap-1">
                    <span><?= htmlspecialchars($storageUsage['formatted_used']) ?></span>
                    <span class="text-slate-400 font-normal text-xs">/ <?= htmlspecialchars($storageUsage['formatted_limit']) ?></span>
                </div>
                <div class="text-[10px] text-emerald-700 font-medium truncate"><?= htmlspecialchars($storageUsage['formatted_remaining']) ?> free space remaining</div>
            </div>
        </div>
        <!-- Storage Usage Progress Bar -->
        <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden mt-1">
            <div class="h-full rounded-full transition-all duration-500 <?= $storageUsage['percentage'] > 85 ? 'bg-red-500' : ($storageUsage['percentage'] > 60 ? 'bg-amber-500' : 'bg-primary') ?>" style="width: <?= max(2, $storageUsage['percentage']) ?>%"></div>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 shadow-2xs flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-sky-100 text-sky-700 flex items-center justify-center font-bold">
            <span class="material-symbols-outlined text-2xl">cloud_done</span>
        </div>
        <div class="min-w-0">
            <div class="text-xs text-outline font-semibold">Copied to the drive</div>
            <div class="text-xl font-bold text-on-surface"><?= number_format($syncedCount) ?></div>
            <div class="text-[11px] text-sky-700 font-medium truncate">
                <?= $defaultDrive === 'local' ? 'No drive is in use' : htmlspecialchars($defaultDriveLabel) ?>
            </div>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-4 rounded-2xl border border-outline-variant/30 shadow-2xs flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl flex items-center justify-center font-bold <?= $failedCount > 0 ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-600' ?>">
            <span class="material-symbols-outlined text-2xl"><?= $failedCount > 0 ? 'cloud_off' : 'cloud_queue' ?></span>
        </div>
        <div class="min-w-0">
            <div class="text-xs text-outline font-semibold">Not on the drive yet</div>
            <div class="text-xl font-bold text-on-surface"><?= number_format($failedCount + $waitingCount) ?></div>
            <div class="text-[11px] font-medium truncate <?= $failedCount > 0 ? 'text-rose-700' : 'text-slate-500' ?>">
                <?php if ($failedCount > 0): ?>
                    <?= number_format($failedCount) ?> refused &middot; a developer can retry
                <?php elseif ($waitingCount > 0): ?>
                    Waiting to be copied
                <?php else: ?>
                    Everything is copied
                <?php endif; ?>
            </div>
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
                <option value="">All storage locations</option>
                <?php foreach (array_keys($byProvider) as $pid): ?>
                    <option value="<?= htmlspecialchars($pid) ?>" <?= $providerFilter === $pid ? 'selected' : '' ?>>
                        <?= htmlspecialchars(nxDriveMeta($pid, $cloudLabels)['label']) ?> (<?= number_format($byProvider[$pid]) ?>)
                    </option>
                <?php endforeach; ?>
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
                                    <?php $drive = nxDriveMeta((string) ($file['storage_provider'] ?? 'server'), $cloudLabels); ?>
                                    <div class="w-8 h-8 rounded-lg <?= $drive['tile'] ?> flex items-center justify-center shrink-0 font-bold">
                                        <span class="material-symbols-outlined text-lg"><?= $drive['icon'] ?></span>
                                    </div>
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
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border <?= $drive['pill'] ?> inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xs"><?= $drive['icon'] ?></span> <?= htmlspecialchars($drive['label']) ?>
                                </span>
                                <?php if (($file['remote_status'] ?? '') === 'failed'): ?>
                                    <span class="mt-1 block text-[10px] font-bold text-rose-700" title="<?= htmlspecialchars((string) ($file['remote_error'] ?? '')) ?>">
                                        Not copied to the drive
                                    </span>
                                <?php elseif (($file['storage_provider'] ?? 'server') === 'server' && $defaultDrive !== 'local'): ?>
                                    <span class="mt-1 block text-[10px] font-semibold text-slate-500">Waiting to be copied</span>
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
                                    <button type="button" onclick="openDocumentViewer('<?= htmlspecialchars($file['id']) ?>', '<?= htmlspecialchars(addslashes($file['original_name'])) ?>')" class="px-2.5 py-1 bg-primary text-white text-xs font-bold rounded-lg hover:bg-primary/90 flex items-center gap-1 shadow-xs">
                                        <span class="material-symbols-outlined text-sm">visibility</span> View Document
                                    </button>

                                    <form action="actions/delete_uploaded_file.php" method="POST" data-nx-confirm="Are you sure you want to delete this document?" class="inline">
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

        <form id="doc-upload-form" action="actions/upload_file.php" method="POST" enctype="multipart/form-data" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

            <?php
            $nxCloudCfg = \ClinicFlow\Services\Cloud\CloudConfig::get();
            $nxCloudLabels = \ClinicFlow\Services\Cloud\Cloud::labels();
            $nxCloudOn = \ClinicFlow\Services\Cloud\FileStore::cloudActive($nxCloudCfg);
            $nxDriveName = $nxCloudLabels[$nxCloudCfg['provider']] ?? 'this server';
            $nxDriveFolder = implode('/', \ClinicFlow\Services\Cloud\FileStore::remoteSegments($nxCloudCfg));
            ?>
            <input type="hidden" name="storage_provider" value="server">

            <!-- Where this will be stored, which is set once in Developer -->
            <div class="p-3.5 rounded-2xl border text-xs flex items-start justify-between gap-3 <?= $nxCloudOn ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : 'bg-slate-50 border-slate-200 text-slate-700' ?>">
                <div class="flex items-start gap-2 min-w-0">
                    <span class="material-symbols-outlined text-xl <?= $nxCloudOn ? 'text-emerald-700' : 'text-slate-500' ?>">
                        <?= $nxCloudOn ? 'cloud_done' : 'dns' ?>
                    </span>
                    <div class="min-w-0">
                        <span class="font-bold block">
                            <?= $nxCloudOn ? 'Stored on ' . htmlspecialchars($nxDriveName) : 'Stored on this server' ?>
                        </span>
                        <span class="text-[11px] block mt-0.5">
                            <?php if ($nxCloudOn): ?>
                                Into <code class="font-mono"><?= htmlspecialchars($nxDriveFolder) ?></code><?php
                                if ($nxCloudCfg['mode'] === 'mirror'): ?>, with a copy kept here<?php endif; ?>.
                            <?php elseif ($nxCloudCfg['provider'] !== 'local'): ?>
                                <?= htmlspecialchars($nxDriveName) ?> is chosen but not connected, so documents stay here for now.
                            <?php else: ?>
                                No cloud drive is set up for this installation.
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <?php if (nxCan('developer', 'full')): ?>
                    <a href="developer.php?dev_tab=cloud" class="text-[11px] font-semibold underline shrink-0">Change</a>
                <?php endif; ?>
            </div>

            <!-- Common Details -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1" for="up-patient">Associated patient (optional)</label>
                    <select id="up-patient" name="patient_id" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-semibold text-on-surface">
                        <option value="">-- No patient / general document --</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?= htmlspecialchars($p['id']) ?>" <?= $patientFilter === $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name'] . ' (' . $p['mrn'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1" for="up-category">Document category</label>
                    <select id="up-category" name="category" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-semibold text-on-surface">
                        <option value="General">General</option>
                        <option value="Lab Results">Lab results</option>
                        <option value="X-Ray / Imaging">X-ray / imaging</option>
                        <option value="Prescription">Prescription</option>
                        <option value="Insurance Claim">Insurance claim</option>
                        <option value="Medical Certificate">Medical certificate</option>
                        <option value="Consent Form">Consent form</option>
                    </select>
                </div>
            </div>

            <!-- The file. One chooser: three fields all named "file" meant PHP
                 kept only the last one, so a chosen file was silently dropped. -->
            <div class="space-y-2">
                <label class="block font-bold text-slate-700 mb-1" for="up-file">Choose a file</label>
                <input id="up-file" type="file" name="file" required class="w-full text-xs text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-white hover:file:bg-primary/90">
                <p class="text-[10px] text-slate-500">PDF, images (JPG, PNG), DOCX, XLSX, TXT, CSV or ZIP, up to 25 MB.</p>
            </div>

            <!-- Linking a file that already lives in someone's own drive is a
                 different thing from uploading one, so it is kept apart. -->
            <details class="rounded-2xl border border-outline-variant/30 bg-surface-container-low/50 p-3">
                <summary class="text-[11px] font-semibold text-slate-700 cursor-pointer">Or link a file that is already in OneDrive or Google Drive</summary>
                <p class="text-[10px] text-slate-500 mt-1 mb-3">
                    This records a link to a file in your own drive. Nothing is copied, and the link only works for people who can open it.
                </p>
                <div class="space-y-3">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1" for="up-od-url">OneDrive sharing link</label>
                            <input id="up-od-url" type="url" name="onedrive_file_url" placeholder="https://1drv.ms/b/s!..." class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1" for="up-od-name">Document name</label>
                            <input id="up-od-name" type="text" name="onedrive_file_name" placeholder="MRI scan, 12 Oct 2026.pdf" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1" for="gdrive_file_url">Google Drive link</label>
                            <input id="gdrive_file_url" type="url" name="gdrive_file_url" placeholder="https://drive.google.com/file/d/..." class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1" for="gdrive_file_name">Document name</label>
                            <input id="gdrive_file_name" type="text" name="gdrive_file_name" placeholder="Blood panel, Oct 2026.pdf" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-medium text-on-surface">
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" id="btn-gdrive-picker" onclick="launchGoogleDrivePicker()" class="px-3 py-1.5 bg-surface-container border border-outline-variant/40 text-on-surface font-semibold text-xs rounded-xl hover:bg-surface-container-high transition flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base">add_to_drive</span>
                            <span>Pick from Google Drive</span>
                        </button>
                        <span id="gdrive-status-label" class="text-[10px] text-slate-500"></span>
                    </div>
                    <input type="hidden" id="gdrive_file_id" name="gdrive_file_id">
                    <input type="hidden" id="gdrive_access_token" name="gdrive_access_token">
                </div>
            </details>

            <script>
            // Linking instead of uploading: the file chooser is no longer required
            (function () {
                const form = document.getElementById('doc-upload-form');
                if (!form) return;
                const file = document.getElementById('up-file');
                const links = ['up-od-url', 'gdrive_file_url'].map(id => document.getElementById(id)).filter(Boolean);
                const sync = () => { file.required = !links.some(i => i.value.trim() !== ''); };
                links.forEach(i => i.addEventListener('input', sync));
                sync();
            })();
            </script>

            <!-- Interactive Upload Progress & Real-Time Console Indicator -->
            <div id="doc-upload-progress-card" class="p-3.5 bg-slate-900 text-white rounded-2xl space-y-2 hidden">
                <div class="flex items-center justify-between text-xs">
                    <span id="doc-upload-status-text" class="font-bold flex items-center gap-1.5 text-amber-300">
                        <span class="material-symbols-outlined text-base animate-spin">sync</span>
                        <span>Uploading file...</span>
                    </span>
                    <span id="doc-upload-percentage" class="font-mono font-bold text-emerald-400">0%</span>
                </div>
                <div class="w-full bg-slate-800 h-2 rounded-full overflow-hidden">
                    <div id="doc-upload-bar" class="h-full bg-emerald-500 rounded-full transition-all duration-200" style="width: 0%"></div>
                </div>
                <div id="doc-upload-console" class="font-mono text-[11px] text-slate-300 bg-slate-950 p-2 rounded-lg border border-slate-800 h-16 overflow-y-auto leading-relaxed">
                    [System Console] Upload initiated...
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-outline-variant/20">
                <button type="button" onclick="closeModal('modal-upload-document')" class="px-4 py-2 bg-surface-container hover:bg-surface-container-high rounded-xl font-semibold text-on-surface">Cancel</button>
                <button type="submit" id="btn-doc-submit" class="px-5 py-2 bg-primary text-white font-bold rounded-xl hover:bg-primary/90 shadow-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">cloud_upload</span>
                    <span>Submit & Save Document</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Cloud API Settings -->
<!-- Google API Loader and GIS Client SDKs -->
<script src="https://accounts.google.com/gsi/client" id="gsi-client-script"></script>
<script src="https://apis.google.com/js/api.js" id="gapi-client-script"></script>

<script>
window.gdriveClientId = <?= json_encode($cloudSettings['cloud_googledrive_client_id'] ?? '') ?>;
window.gdriveApiKey = <?= json_encode($cloudSettings['cloud_googledrive_api_key'] ?? '') ?>;
</script>
<script src="assets/js/uploads.js?v=<?= urlencode(AppVersion::get('commit')) ?>"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
