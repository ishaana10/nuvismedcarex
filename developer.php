<?php
require_once __DIR__ . '/includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Doctor';
if ($currentUserRole !== 'Developer') {
    setToast("Access Denied", "Developer Options are strictly restricted to system developers.", "error");
    header("Location: index.php");
    exit;
}

$pageTitle = "Developer Options - ClinicFlow";
$activePage = "developer";
include __DIR__ . '/includes/header.php';

$pdo = getDB();
$settingsRows = $pdo->query("SELECT * FROM clinic_settings")->fetchAll();
$settings = [];
foreach ($settingsRows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-on-surface flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-2xl">code</span>
            <span>Developer Workspace & System Diagnostics</span>
        </h1>
        <p class="text-xs text-outline font-medium">Independent developer controls: Git continuous deployment, error logs, database diagnostics, and environment configuration</p>
    </div>

    <div class="flex items-center gap-2">
        <span class="px-3 py-1 bg-amber-100 text-amber-900 border border-amber-300 rounded-xl text-xs font-bold flex items-center gap-1.5 shadow-2xs">
            <span class="material-symbols-outlined text-sm text-amber-700">shield</span>
            <span>Developer Level Access</span>
        </span>
    </div>
</div>

<div class="space-y-6">
    <!-- 1. System Updates (Git Updater) -->
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-outline-variant/20 pb-3">
            <h2 class="text-xs font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-base">update</span>
                <span>Continuous System Updates (1-Click Git Updater)</span>
            </h2>
            <div class="flex gap-2">
                <button type="button" onclick="switchGitSubTab('console')" id="btn-git-console" class="px-3 py-1 bg-primary text-white text-[11px] font-bold rounded-lg transition">Terminal Status</button>
                <button type="button" onclick="switchGitSubTab('history')" id="btn-git-history" class="px-3 py-1 bg-surface-container-high text-on-surface text-[11px] font-bold rounded-lg transition">Commit History</button>
            </div>
        </div>

        <!-- Terminal Console Tab -->
        <div id="git-tab-console" class="space-y-4 text-xs">
            <div>
                <label class="block font-bold text-slate-700 mb-1">Git Repository Terminal Status</label>
                <div id="git-status-console" class="font-mono text-xs bg-slate-900 text-emerald-400 p-4 rounded-xl border border-slate-800 h-40 overflow-y-auto whitespace-pre-wrap leading-relaxed shadow-inner">
                    Querying Git repository status...
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-2">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Executable Path</label>
                    <input type="text" id="git_path" value="<?= htmlspecialchars($settings['git_path'] ?? 'git') ?>" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-mono text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Repository Directory</label>
                    <input type="text" id="git_repo_dir" value="<?= htmlspecialchars($settings['git_repo_dir'] ?? __DIR__) ?>" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-mono text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Update Branch</label>
                    <select id="update_branch" class="w-full bg-surface-container-low px-3 py-2 rounded-xl border border-outline-variant/40 font-bold">
                        <option value="main">main</option>
                        <option value="master">master</option>
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-outline-variant/20">
                <button type="button" onclick="saveGitSettings()" class="px-4 py-2 bg-surface-container-high text-on-surface text-xs font-semibold rounded-xl hover:bg-surface-variant transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">settings</span>
                    <span>Save Git Settings</span>
                </button>

                <div class="flex gap-2">
                    <button type="button" onclick="refreshGitStatus()" class="px-4 py-2 bg-slate-200 text-slate-800 text-xs font-semibold rounded-xl hover:bg-slate-300 transition flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm">sync</span>
                        <span>Check Status</span>
                    </button>
                    <button type="button" onclick="triggerGitPull()" class="px-5 py-2 bg-emerald-600 text-white text-xs font-bold rounded-xl hover:bg-emerald-700 transition shadow-xs flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm">download</span>
                        <span>Pull Updates from Git</span>
                    </button>
                </div>
            </div>

            <!-- Initialize / Link Git Repo Form (if missing) -->
            <div id="git-init-card" class="mt-4 p-4 rounded-xl bg-amber-50 border border-amber-200 space-y-3">
                <p class="font-bold text-amber-900 text-xs flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm text-amber-700">link</span>
                    <span>Link Remote Git Repository</span>
                </p>
                <div class="flex gap-2">
                    <input type="text" id="git_remote_url" placeholder="https://github.com/username/repository.git" value="<?= htmlspecialchars($settings['git_remote_url'] ?? '') ?>" class="flex-1 bg-white px-3 py-2 rounded-xl border border-amber-300 text-xs font-mono">
                    <button type="button" onclick="initializeGitRepo()" class="px-4 py-2 bg-amber-800 text-white font-bold rounded-xl text-xs hover:bg-amber-900 transition">
                        Link & Sync
                    </button>
                </div>
            </div>
        </div>

        <!-- Commit History Tab -->
        <div id="git-tab-history" class="hidden space-y-3 text-xs">
            <div id="git-commit-list" class="space-y-2 max-h-60 overflow-y-auto">
                <p class="text-slate-500 italic">Loading commit logs...</p>
            </div>
        </div>
    </div>

    <!-- 2. Developer Error Logger Module -->
    <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant/30 p-6 shadow-xs space-y-5">
        <div class="flex items-center justify-between border-b border-outline-variant/20 pb-3">
            <h2 class="text-xs font-bold text-rose-700 uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-outlined text-base">bug_report</span>
                <span>Developer Error Logger & System Diagnostics</span>
            </h2>
            <div class="flex items-center gap-2">
                <button type="button" onclick="fetchDeveloperErrorLogs()" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold rounded-xl transition flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">refresh</span>
                    <span>Refresh Logs</span>
                </button>
                <button type="button" onclick="clearDeveloperErrorLogs()" class="px-3 py-1.5 bg-rose-100 hover:bg-rose-200 text-rose-800 text-xs font-bold rounded-xl transition flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">delete_sweep</span>
                    <span>Clear Logs</span>
                </button>
            </div>
        </div>

        <div>
            <div id="developer-error-console" class="font-mono text-xs bg-slate-950 text-slate-200 p-4 rounded-xl border border-slate-800 h-64 overflow-y-auto leading-relaxed space-y-2 shadow-inner">
                <p class="text-slate-500 italic">Initializing developer error logger...</p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    refreshGitStatus();
    fetchDeveloperErrorLogs();
});

function fetchDeveloperErrorLogs() {
    const consoleBox = document.getElementById('developer-error-console');
    if (!consoleBox) return;

    fetch('actions/git_actions.php?action=get_error_logs')
    .then(r => r.json())
    .then(d => {
        if (d.success && d.logs && d.logs.length > 0) {
            consoleBox.innerHTML = d.logs.map(log => {
                let colorClass = 'text-slate-300';
                if (log.type === 'ERROR') colorClass = 'text-rose-400 font-bold';
                else if (log.type === 'WARNING') colorClass = 'text-amber-300';
                else if (log.type === 'AUDIT') colorClass = 'text-emerald-400';

                return `<div class="p-1 border-b border-slate-900 text-[11px]"><span class="text-slate-500">[${log.timestamp}]</span> <span class="px-1.5 py-0.5 rounded text-[9px] font-bold ${colorClass} bg-slate-900 mr-1">${log.type}</span> ${log.message}</div>`;
            }).join('');
        } else {
            consoleBox.innerHTML = '<p class="text-slate-400 italic">No log entries found.</p>';
        }
    })
    .catch(err => {
        consoleBox.innerHTML = '<p class="text-rose-400 font-bold">Error retrieving developer logs: ' + err + '</p>';
    });
}

function clearDeveloperErrorLogs() {
    if (!confirm('Are you sure you want to clear the developer error log buffer?')) return;

    fetch('actions/git_actions.php?action=clear_error_logs')
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('Developer log buffer cleared.');
            fetchDeveloperErrorLogs();
        } else {
            alert('Failed to clear logs: ' + (d.error || 'Unknown error'));
        }
    });
}

function refreshGitStatus() {
    const consoleBox = document.getElementById('git-status-console');
    if (!consoleBox) return;
    consoleBox.innerText = "Querying Git repository status...";

    fetch('actions/git_actions.php?action=git_status')
    .then(r => r.json())
    .then(d => {
        if (d.git_path && document.getElementById('git_path')) document.getElementById('git_path').value = d.git_path;
        if (d.git_repo_dir && document.getElementById('git_repo_dir')) document.getElementById('git_repo_dir').value = d.git_repo_dir;
        if (d.git_remote_url && document.getElementById('git_remote_url')) document.getElementById('git_remote_url').value = d.git_remote_url;

        if (d.remote_branches && d.remote_branches.length > 0) {
            const select = document.getElementById('update_branch');
            if (select) {
                select.innerHTML = '';
                d.remote_branches.forEach(b => {
                    const opt = document.createElement('option');
                    opt.value = b;
                    opt.textContent = b;
                    if (b === d.selected_branch) opt.selected = true;
                    select.appendChild(opt);
                });
            }
        }

        if (d.success) {
            consoleBox.innerHTML = `<span class="text-emerald-400 font-bold">✔ Git Repository Active</span>\nBranch: ${d.branch || 'main'}\n\n${d.status}`;
        } else {
            consoleBox.innerHTML = `<span class="text-amber-400 font-bold">⚠ Git Repository Not Linked or Unreachable</span>\n\n${d.status || d.error || 'Repository not initialized.'}`;
        }
    })
    .catch(err => {
        consoleBox.innerText = "Error querying Git status: " + err;
    });
}

function saveGitSettings() {
    const gitPath = document.getElementById('git_path').value;
    const gitRepoDir = document.getElementById('git_repo_dir').value;
    const updateBranch = document.getElementById('update_branch').value;

    fetch('actions/git_actions.php?action=save_git_settings', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({git_path: gitPath, git_repo_dir: gitRepoDir, update_branch: updateBranch})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('Git settings saved successfully.');
            refreshGitStatus();
        } else {
            alert('Failed to save Git settings: ' + (d.error || 'Unknown error'));
        }
    });
}

function triggerGitPull() {
    const branch = document.getElementById('update_branch').value || 'main';
    const consoleBox = document.getElementById('git-status-console');
    if (!confirm(`Are you sure you want to pull updates from branch '${branch}'? Local files will be updated.`)) return;

    consoleBox.innerText = `Executing 'git pull origin ${branch}'... Please wait...`;

    fetch('actions/git_actions.php?action=git_pull', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({branch: branch})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('Git pull completed successfully!');
            consoleBox.innerHTML = `<span class="text-emerald-400 font-bold">✔ Git Pull Successful</span>\n\n${d.output}`;
            loadGitHistory();
        } else {
            alert('Git pull failed: ' + (d.error || 'Unknown error'));
            consoleBox.innerHTML = `<span class="text-red-400 font-bold">❌ Git Pull Error</span>\n\n${d.error || 'Pull failed'}`;
        }
    });
}

function initializeGitRepo() {
    const repoUrl = document.getElementById('git_remote_url').value;
    if (!repoUrl) {
        alert('Please enter a remote Git URL (e.g. https://github.com/username/repository.git)');
        return;
    }

    if (!confirm('This will initialize a Git repository in your directory and link it to remote origin. Proceed?')) return;

    fetch('actions/git_actions.php?action=git_init', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({repo_url: repoUrl})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('Git repository linked successfully!');
            refreshGitStatus();
        } else {
            alert('Git init failed: ' + (d.error || 'Unknown error'));
        }
    });
}

function switchGitSubTab(tab) {
    if (tab === 'console') {
        document.getElementById('git-tab-console').classList.remove('hidden');
        document.getElementById('git-tab-history').classList.add('hidden');
        document.getElementById('btn-git-console').className = 'px-3 py-1 bg-primary text-white text-[11px] font-bold rounded-lg transition';
        document.getElementById('btn-git-history').className = 'px-3 py-1 bg-surface-container-high text-on-surface text-[11px] font-bold rounded-lg transition';
    } else {
        document.getElementById('git-tab-console').classList.add('hidden');
        document.getElementById('git-tab-history').classList.remove('hidden');
        document.getElementById('btn-git-console').className = 'px-3 py-1 bg-surface-container-high text-on-surface text-[11px] font-bold rounded-lg transition';
        document.getElementById('btn-git-history').className = 'px-3 py-1 bg-primary text-white text-[11px] font-bold rounded-lg transition';
        loadGitHistory();
    }
}

function loadGitHistory() {
    const list = document.getElementById('git-commit-list');
    list.innerHTML = '<p class="text-slate-500 italic">Fetching commit history...</p>';

    fetch('actions/git_actions.php?action=git_log')
    .then(r => r.json())
    .then(d => {
        if (d.success && d.commits && d.commits.length > 0) {
            list.innerHTML = d.commits.map(c => `
                <div class="p-3 rounded-xl bg-surface-container-low border border-outline-variant/30 text-xs">
                    <div class="flex items-center justify-between mb-1">
                        <span class="font-mono font-bold text-primary">${c.hash}</span>
                        <span class="text-[10px] text-outline">${c.date}</span>
                    </div>
                    <p class="font-bold text-on-surface">${c.message}</p>
                    <p class="text-[10px] text-slate-500 mt-0.5">Author: ${c.author}</p>
                </div>
            `).join('');
        } else {
            list.innerHTML = `<p class="text-slate-500 italic p-3">${d.error || 'No commits found.'}</p>`;
        }
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
