<?php require_once __DIR__ . '/../config/version.php'; ?>
</main>
<footer class="nx-footer px-4 sm:px-8 py-4 text-xs text-slate-500 border-t border-slate-200/70">
    <div class="max-w-[1680px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-2">
        <span>&copy; <?= date('Y') ?> Nuvis Medcare X by Nuvis Technologies</span>
        <span class="flex items-center gap-3">
            <button type="button" data-nx-help-open class="hover:text-slate-800">Keyboard shortcuts</button>
            <span class="font-mono text-[11px] text-slate-400" title="Released: <?= htmlspecialchars(AppVersion::get('released_at')) ?>"><?= htmlspecialchars(AppVersion::get('full')) ?></span>
        </span>
    </div>
</footer>
</div><!-- /content column -->
</div><!-- /app flex -->

<!-- In-app document viewer -->
<div id="modal-document-viewer" class="fixed inset-0 z-[80] bg-slate-950/70 backdrop-blur-sm flex items-center justify-center hidden p-2 sm:p-4" data-nx-modal>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-pop w-full max-w-5xl h-[88vh] flex flex-col overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="doc-viewer-title">
        <div class="px-4 py-3 flex items-center justify-between border-b border-slate-200 shrink-0 gap-3">
            <div class="flex items-center gap-2 min-w-0">
                <span class="material-symbols-outlined text-blue-700">description</span>
                <h3 id="doc-viewer-title" class="font-semibold text-sm text-slate-900 truncate">Document preview</h3>
            </div>
            <div class="flex items-center gap-1">
                <a id="doc-viewer-external" href="#" target="_blank" rel="noopener" class="btn-secondary !py-1.5 !px-3">
                    <span class="material-symbols-outlined text-[18px]">open_in_new</span> Open in new tab
                </a>
                <button type="button" onclick="closeModal('modal-document-viewer')" class="nx-icon-btn" aria-label="Close preview">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        </div>
        <div class="flex-1 bg-slate-100 relative overflow-hidden">
            <iframe id="doc-viewer-frame" class="w-full h-full border-0" src="about:blank" title="Document preview"></iframe>
        </div>
    </div>
</div>

<script>
function openModal(id) {
    if (window.NX && NX.openModal) return NX.openModal(id);
    var el = document.getElementById(id);
    if (el) el.classList.remove('hidden');
}
function closeModal(id) {
    if (window.NX && NX.closeModal) return NX.closeModal(id);
    var el = document.getElementById(id);
    if (el) el.classList.add('hidden');
    if (id === 'modal-document-viewer') { var f = document.getElementById('doc-viewer-frame'); if (f) f.src = 'about:blank'; }
}
function openDocumentViewer(fileId, title) {
    var frame = document.getElementById('doc-viewer-frame');
    var titleEl = document.getElementById('doc-viewer-title');
    var externalLink = document.getElementById('doc-viewer-external');
    var viewUrl = 'actions/view_file.php?id=' + encodeURIComponent(fileId);
    if (titleEl) titleEl.textContent = title || 'Document preview';
    if (frame) frame.src = viewUrl;
    if (externalLink) externalLink.href = viewUrl;
    openModal('modal-document-viewer');
}
</script>
</body>
</html>
