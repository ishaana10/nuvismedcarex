/*! Documents page: provider tabs and upload progress. Nothing here loads from another website. */
function toggleUploadTab(provider) {
    const serverTab = document.getElementById('tab-section-server');
    const onedriveTab = document.getElementById('tab-section-onedrive');
    const gdriveTab = document.getElementById('tab-section-googledrive');

    const btnServer = document.getElementById('provider-btn-server');
    const btnOnedrive = document.getElementById('provider-btn-onedrive');
    const btnGdrive = document.getElementById('provider-btn-googledrive');

    if (serverTab) serverTab.classList.add('hidden');
    if (onedriveTab) onedriveTab.classList.add('hidden');
    if (gdriveTab) gdriveTab.classList.add('hidden');

    if (btnServer) btnServer.className = "p-3 rounded-xl border-2 border-slate-200 bg-surface-container-low cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition hover:border-emerald-500";
    if (btnOnedrive) btnOnedrive.className = "p-3 rounded-xl border-2 border-slate-200 bg-surface-container-low cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition hover:border-sky-500";
    if (btnGdrive) btnGdrive.className = "p-3 rounded-xl border-2 border-slate-200 bg-surface-container-low cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition hover:border-amber-500";

    if (provider === 'server') {
        if (serverTab) serverTab.classList.remove('hidden');
        if (btnServer) btnServer.className = "p-3 rounded-xl border-2 border-emerald-500 bg-emerald-50/50 cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition";
    } else if (provider === 'onedrive') {
        if (onedriveTab) onedriveTab.classList.remove('hidden');
        if (btnOnedrive) btnOnedrive.className = "p-3 rounded-xl border-2 border-sky-500 bg-sky-50/50 cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition";
    } else if (provider === 'googledrive') {
        if (gdriveTab) gdriveTab.classList.remove('hidden');
        if (btnGdrive) btnGdrive.className = "p-3 rounded-xl border-2 border-amber-500 bg-amber-50/50 cursor-pointer flex flex-col items-center justify-center gap-1 text-center transition";
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const uploadForm = document.getElementById('doc-upload-form');
    if (!uploadForm) return;

    uploadForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const progressCard = document.getElementById('doc-upload-progress-card');
        const progressBar = document.getElementById('doc-upload-bar');
        const progressPercentage = document.getElementById('doc-upload-percentage');
        const statusText = document.getElementById('doc-upload-status-text');
        const consoleBox = document.getElementById('doc-upload-console');
        const submitBtn = document.getElementById('btn-doc-submit');

        if (progressCard) progressCard.classList.remove('hidden');
        if (submitBtn) submitBtn.disabled = true;

        const formData = new FormData(uploadForm);
        const provider = formData.get('storage_provider') || 'server';

        const targetText = "secure storage";

        let logLines = [];
        logLines.push(`[${new Date().toLocaleTimeString()}] Preparing file upload stream...`);
        if (consoleBox) consoleBox.innerText = logLines.join('\n');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', uploadForm.action, true);
        // Ask for the answer rather than a redirect the XHR would follow,
        // which used to swallow the message before the page reloaded.
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.onprogress = function(evt) {
            if (evt.lengthComputable) {
                const percent = Math.round((evt.loaded / evt.total) * 100);
                if (progressBar) progressBar.style.width = percent + '%';
                if (progressPercentage) progressPercentage.innerText = percent + '%';

                if (percent < 100) {
                    if (statusText) statusText.innerHTML = `<span class="material-symbols-outlined text-base animate-spin">sync</span> Uploading to ${targetText}... (${percent}%)`;
                } else {
                    if (statusText) statusText.innerHTML = `<span class="material-symbols-outlined text-base animate-spin">cloud_sync</span> Processing file & syncing metadata...`;
                    logLines.push(`[${new Date().toLocaleTimeString()}] 100% transferred. Registering document in database...`);
                    if (consoleBox) consoleBox.innerText = logLines.join('\n');
                }
            }
        };

        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 400) {
                let answer = null;
                try { answer = JSON.parse(xhr.responseText); } catch (e) {}
                const line = answer && answer.message ? answer.message : 'Upload complete.';
                logLines.push(`[${new Date().toLocaleTimeString()}] ${answer && answer.success === false ? '✖' : '✔'} ${line}`);
                if (consoleBox) consoleBox.innerText = logLines.join('\n');
                if (progressBar) progressBar.style.width = '100%';
                if (progressPercentage) progressPercentage.innerText = '100%';
                // The message itself is shown by the reload below
                if (!answer || answer.success !== false) {
                    setTimeout(() => { window.location.reload(); }, 900);
                } else if (submitBtn) {
                    submitBtn.disabled = false;
                    if (statusText) statusText.innerHTML = `<span class="material-symbols-outlined text-base text-rose-400">error</span> ${line}`;
                }
            } else {
                logLines.push(`[${new Date().toLocaleTimeString()}] ❌ Upload failed with HTTP ${xhr.status}`);
                if (consoleBox) consoleBox.innerText = logLines.join('\n');
                if (statusText) statusText.innerHTML = `<span class="material-symbols-outlined text-base text-rose-400">error</span> Upload Error (${xhr.status})`;
                if (submitBtn) submitBtn.disabled = false;
            }
        };

        xhr.onerror = function() {
            logLines.push(`[${new Date().toLocaleTimeString()}] ❌ Network error occurred during upload.`);
            if (consoleBox) consoleBox.innerText = logLines.join('\n');
            if (submitBtn) submitBtn.disabled = false;
        };

        xhr.send(formData);
    });
});
