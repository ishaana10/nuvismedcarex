let pickerApiLoaded = false;
let oauthToken = null;

function onPickerApiLoad() {
    pickerApiLoaded = true;
}

function logToUploadConsole(msg) {
    const card = document.getElementById('doc-upload-progress-card');
    const consoleBox = document.getElementById('doc-upload-console');
    if (card) card.classList.remove('hidden');
    if (consoleBox) {
        const time = new Date().toLocaleTimeString();
        consoleBox.innerText += (consoleBox.innerText ? '\n' : '') + `[${time}] ${msg}`;
        consoleBox.scrollTop = consoleBox.scrollHeight;
    }
}

function loadGsiSdk(callback) {
    logToUploadConsole("Checking Google Identity Services SDK...");

    if (typeof google !== 'undefined' && google.accounts && google.accounts.oauth2) {
        logToUploadConsole("✔ Google Identity Services SDK (google.accounts.oauth2) active.");
        callback();
        return;
    }

    const oldScript = document.getElementById('gsi-client-script');
    if (oldScript && (!window.google || !window.google.accounts || !window.google.accounts.oauth2)) {
        logToUploadConsole("Re-injecting Google Identity SDK script tag...");
        oldScript.remove();
    }

    const script = document.createElement('script');
    script.id = 'gsi-client-script';
    script.src = 'https://accounts.google.com/gsi/client';

    let callbackFired = false;
    const triggerCallback = () => {
        if (!callbackFired) {
            callbackFired = true;
            callback();
        }
    };

    script.onload = () => {
        logToUploadConsole("✔ Script tag load event fired.");
        if (typeof google !== 'undefined' && google.accounts && google.accounts.oauth2) {
            triggerCallback();
        }
    };

    script.onerror = () => {
        logToUploadConsole("❌ Error: Browser blocked script from accounts.google.com (AdBlocker/CSP)");
        const statusLabel = document.getElementById('gdrive-status-label');
        if (statusLabel) {
            statusLabel.innerText = 'Error: accounts.google.com blocked by browser extension/adblocker';
            statusLabel.className = 'font-bold text-red-600';
        }
    };

    logToUploadConsole("Injecting script: https://accounts.google.com/gsi/client");
    document.head.appendChild(script);

    let attempts = 0;
    const interval = setInterval(() => {
        attempts++;
        if (typeof google !== 'undefined' && google.accounts && google.accounts.oauth2) {
            clearInterval(interval);
            logToUploadConsole(`✔ window.google.accounts.oauth2 ready (${attempts * 100}ms)`);
            triggerCallback();
        } else if (attempts > 80) {
            clearInterval(interval);
            logToUploadConsole("❌ Timeout (8000ms): Could not initialize google.accounts.oauth2.");
            logToUploadConsole("Cause: Browser ad blocker (e.g. Brave Shields, uBlock, AdBlock) or network blocked accounts.google.com");
            logToUploadConsole("Alternative: You can paste a Google Drive file link below into 'Google Drive Document Link / URL' to link documents without browser SDK!");
            const statusLabel = document.getElementById('gdrive-status-label');
            if (statusLabel) {
                statusLabel.innerText = 'Error: accounts.google.com blocked by adblocker. Paste Google Drive link below or disable adblocker.';
                statusLabel.className = 'font-bold text-red-600';
            }
        }
    }, 100);
}

function authorizeGoogleDriveToken() {
    const statusLabel = document.getElementById('gdrive-status-label');

    logToUploadConsole("--- Initializing Google OAuth Authorization ---");

    const clientId = window.gdriveClientId;

    if (!clientId) {
        const msg = "Error: Google Drive Client ID missing. Configure in Cloud API Setup.";
        if (statusLabel) {
            statusLabel.innerText = msg;
            statusLabel.className = 'font-bold text-red-600';
        }
        logToUploadConsole("❌ " + msg);
        alert('Google Drive Client ID is not configured. Please enter your OAuth Client ID in Cloud API Setup.');
        openModal('modal-cloud-settings');
        return;
    }

    logToUploadConsole("Client ID: " + clientId.substring(0, 20) + "...");

    if (statusLabel) {
        statusLabel.innerText = 'Loading Google Identity SDK...';
        statusLabel.className = 'font-bold text-amber-600 animate-pulse';
    }

    loadGsiSdk(() => {
        if (typeof google === 'undefined' || !google.accounts || !google.accounts.oauth2) {
            const msg = "Error: Google Identity SDK not initialized in window.google";
            if (statusLabel) {
                statusLabel.innerText = msg;
                statusLabel.className = 'font-bold text-red-600';
            }
            logToUploadConsole("❌ " + msg);
            alert('Google Identity Services SDK could not be initialized.');
            return;
        }

        try {
            if (statusLabel) {
                statusLabel.innerText = 'Opening Google Login Popup... (Allow popups in browser address bar)';
                statusLabel.className = 'font-bold text-amber-700 animate-pulse';
            }

            logToUploadConsole("Initializing google.accounts.oauth2.initTokenClient...");

            const tokenClient = google.accounts.oauth2.initTokenClient({
                client_id: clientId,
                scope: 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly',
                error_callback: (err) => {
                    console.error('[GDrive OAuth Error]', err);
                    const errText = err.message || err.type || 'Popup closed or permission denied';
                    if (statusLabel) {
                        statusLabel.innerText = 'OAuth Popup Error: ' + errText;
                        statusLabel.className = 'font-bold text-red-600';
                    }
                    logToUploadConsole("❌ OAuth Popup Error: " + errText);
                    alert('Google Authorization Error: ' + errText);
                },
                callback: (response) => {
                    if (response.error !== undefined) {
                        console.error('[GDrive Auth Response Error]', response);
                        const desc = response.error_description ? ` (${response.error_description})` : '';
                        if (statusLabel) {
                            statusLabel.innerText = 'OAuth Auth Error: ' + response.error + desc;
                            statusLabel.className = 'font-bold text-red-600';
                        }
                        logToUploadConsole("❌ OAuth Response Error: " + response.error + desc);
                        alert('Google Authentication Error: ' + response.error + desc + '\n\nTip: Verify that ' + window.location.origin + ' is added as an Authorized JavaScript Origin in Google Cloud Console.');
                        return;
                    }
                    oauthToken = response.access_token;
                    const tokenInput = document.getElementById('gdrive_access_token');
                    if (tokenInput) {
                        tokenInput.value = oauthToken;
                    }
                    if (statusLabel) {
                        statusLabel.innerText = '✔ Authenticated & Connected to Google Drive API!';
                        statusLabel.className = 'font-bold text-emerald-700';
                    }
                    logToUploadConsole("✔ OAuth Access Token retrieved successfully! Direct Google Drive API upload ready.");
                },
            });

            logToUploadConsole("Requesting Access Token via tokenClient.requestAccessToken()...");
            tokenClient.requestAccessToken({ prompt: 'consent' });

            setTimeout(() => {
                if (!oauthToken && statusLabel && statusLabel.innerText.includes('Opening Google Login Popup')) {
                    statusLabel.innerText = 'Waiting for login completion... (If no popup appears, check browser popup blocker)';
                    statusLabel.className = 'font-bold text-amber-700';
                    logToUploadConsole("ℹ️ Popup prompt dispatched. If no window appeared, check address bar popup blocker.");
                }
            }, 2500);

        } catch (e) {
            console.error('[GDrive Auth Exception]', e);
            if (statusLabel) {
                statusLabel.innerText = 'Exception: ' + e.message;
                statusLabel.className = 'font-bold text-red-600';
            }
            logToUploadConsole("❌ OAuth Exception: " + e.message);
            alert('Google OAuth Exception: ' + e.message);
        }
    });
}

function launchGoogleDrivePicker() {
    const clientId = window.gdriveClientId;
    if (!clientId) {
        alert('Google Drive Client ID is not configured. Please enter your OAuth Client ID in Cloud API Setup.');
        openModal('modal-cloud-settings');
        return;
    }

    if (oauthToken) {
        createPicker();
    } else {
        const tokenClient = google.accounts.oauth2.initTokenClient({
            client_id: clientId,
            scope: 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly',
            callback: (response) => {
                if (response.error !== undefined) {
                    alert('Google Authentication Error: ' + response.error);
                    return;
                }
                oauthToken = response.access_token;
                const tokenInput = document.getElementById('gdrive_access_token');
                if (tokenInput) {
                    tokenInput.value = oauthToken;
                }
                gapi.load('picker', { callback: createPicker });
            },
        });
        tokenClient.requestAccessToken({ prompt: 'consent' });
    }
}

function createPicker() {
    if (pickerApiLoaded || typeof gapi !== 'undefined') {
        const view = new google.picker.View(google.picker.ViewId.DOCS);
        view.setMimeTypes('image/png,image/jpeg,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain');

        const pickerBuilder = new google.picker.PickerBuilder()
            .addView(view)
            .setOAuthToken(oauthToken)
            .setCallback(pickerCallback);

        if (window.gdriveApiKey) {
            pickerBuilder.setDeveloperKey(window.gdriveApiKey);
        }

        const picker = pickerBuilder.build();
        picker.setVisible(true);
    } else {
        gapi.load('picker', { callback: createPicker });
    }
}

function pickerCallback(data) {
    if (data.action === google.picker.Action.PICKED) {
        const doc = data.docs[0];
        const fileId = doc.id;
        const fileName = doc.name;
        const fileUrl = doc.url || `https://drive.google.com/file/d/${fileId}/view`;

        document.getElementById('gdrive_file_name').value = fileName;
        document.getElementById('gdrive_file_url').value = fileUrl;
        document.getElementById('gdrive_file_id').value = fileId;
    }
}

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

        let targetText = "Local Clinic Server Drive";
        if (provider === 'googledrive') targetText = "Google Drive Tenant Folder";
        else if (provider === 'onedrive') targetText = "Microsoft OneDrive";

        let logLines = [];
        logLines.push(`[${new Date().toLocaleTimeString()}] Target Storage Provider: ${targetText}`);
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
