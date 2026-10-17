<?php require_once __DIR__ . '/../config/version.php'; ?>
</main>
</div> <!-- closing flex-1 -->
<footer class="bg-surface-container-lowest border-t border-outline-variant/30 px-6 py-3 text-center text-xs text-outline">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span>&copy; <?= date('Y') ?> Nuvis Medcare X by Nuvis Technologies. All rights reserved.</span>
            <span class="px-2 py-0.5 rounded-full bg-primary/10 text-primary font-mono text-[10px] font-bold border border-primary/20" title="Released: <?= htmlspecialchars(AppVersion::get('released_at')) ?>">
                <?= htmlspecialchars(AppVersion::get('full')) ?>
            </span>
        </div>
        <span>A2 Hosting PHP 8.1+ Ready</span>
    </div>
</footer>
<!-- Global In-App Document Viewer Modal -->
<div id="modal-document-viewer" class="fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-md flex items-center justify-center hidden p-2 sm:p-4">
    <div class="bg-slate-900 rounded-3xl border border-slate-700/60 shadow-2xl w-full max-w-5xl h-[88vh] flex flex-col overflow-hidden">
        <div class="p-4 bg-slate-800 text-white flex items-center justify-between border-b border-slate-700 shrink-0">
            <div class="flex items-center gap-2 min-w-0">
                <span class="material-symbols-outlined text-primary text-xl">visibility</span>
                <h3 id="doc-viewer-title" class="font-bold text-sm truncate">Document Preview</h3>
            </div>
            <div class="flex items-center gap-2">
                <a id="doc-viewer-external" href="#" target="_blank" class="px-2.5 py-1 bg-slate-700 hover:bg-slate-600 text-slate-200 text-xs font-semibold rounded-lg flex items-center gap-1 transition" title="Open in New Window">
                    <span class="material-symbols-outlined text-sm">open_in_new</span> External
                </a>
                <button type="button" onclick="closeModal('modal-document-viewer')" class="text-slate-400 hover:text-white p-1">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
        </div>
        <div class="flex-1 bg-slate-950 relative overflow-hidden">
            <iframe id="doc-viewer-frame" class="w-full h-full border-0 bg-slate-950" src="about:blank"></iframe>
        </div>
    </div>
</div>

<script>
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('hidden');
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('hidden');
}
function openDocumentViewer(fileId, title) {
    const frame = document.getElementById('doc-viewer-frame');
    const titleEl = document.getElementById('doc-viewer-title');
    const externalLink = document.getElementById('doc-viewer-external');

    if (titleEl) titleEl.textContent = title || 'Document Preview';
    const viewUrl = 'actions/view_file.php?id=' + encodeURIComponent(fileId);
    if (frame) frame.src = viewUrl;
    if (externalLink) externalLink.href = viewUrl;

    openModal('modal-document-viewer');
}
</script>
</body>
</html>
