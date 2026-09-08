<?php
/**
 * Action: Git Management API Handlers (AJAX)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/git_helper.php';

header('Content-Type: application/json');

// Session auth check
if (empty($_SESSION['authenticated'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit();
}

$userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Doctor';
if (!in_array($userRole, ['Administrator', 'Developer'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied: Administrator or Developer role required']);
    exit();
}

$pdo = getDB();
$jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? ($jsonInput['action'] ?? ($_POST['action'] ?? ''));

try {
    if ($action === 'git_status') {
        $cfg = getGitConfig($pdo);
        $gitPath = $cfg['git_path'];
        $gitRepoDir = $cfg['git_repo_dir'];
        $selectedBranch = $cfg['update_branch'];
        $gitRemoteUrl = $cfg['git_remote_url'];

        $isGitRepo = isGitRepoRobust($gitPath, $gitRepoDir);

        $status = '';
        $branch = '';
        $remoteBranches = [];

        if ($isGitRepo) {
            $status = runGitCmd($gitPath, $gitRepoDir, 'status');
            $branch = runGitCmd($gitPath, $gitRepoDir, 'rev-parse --abbrev-ref HEAD');
            $branchesOutput = runGitCmd($gitPath, $gitRepoDir, 'branch -a');

            if ($branchesOutput && stripos($branchesOutput, 'fatal:') === false) {
                $lines = explode("\n", $branchesOutput);
                foreach ($lines as $line) {
                    $line = trim($line, "* \t\r\n");
                    if (!$line) continue;
                    if (strpos($line, 'remotes/origin/HEAD') !== false) continue;
                    if (strpos($line, 'remotes/origin/') === 0) {
                        $b = substr($line, 15);
                    } elseif (strpos($line, 'origin/') === 0) {
                        $b = substr($line, 7);
                    } else {
                        $b = $line;
                    }
                    if ($b && !preg_match('/[\s:]/', $b) && !in_array($b, $remoteBranches)) {
                        $remoteBranches[] = $b;
                    }
                }
            }

            $remoteUrlCheck = runGitCmd($gitPath, $gitRepoDir, 'config --get remote.origin.url');
            if ($remoteUrlCheck && stripos($remoteUrlCheck, 'fatal:') === false) {
                $gitRemoteUrl = trim($remoteUrlCheck);
            }
        } else {
            $status = "fatal: not a git repository (or any of the parent directories): .git";
            $branch = "None";
        }

        if (empty($remoteBranches)) {
            $remoteBranches = [$selectedBranch ?: 'main'];
        }

        echo json_encode([
            'success'         => $isGitRepo && (stripos($status, 'fatal:') === false),
            'status'          => trim($status),
            'branch'          => trim($branch),
            'selected_branch' => $selectedBranch,
            'remote_branches' => array_values(array_unique($remoteBranches)),
            'git_path'        => $gitPath,
            'git_repo_dir'    => $gitRepoDir,
            'git_remote_url'  => $gitRemoteUrl
        ]);
        exit();
    }

    if ($action === 'save_git_settings') {
        $gitPath = trim((string)($jsonInput['git_path'] ?? $_POST['git_path'] ?? 'git'));
        $gitRepoDir = trim((string)($jsonInput['git_repo_dir'] ?? $_POST['git_repo_dir'] ?? ''));
        $updateBranch = trim((string)($jsonInput['update_branch'] ?? $_POST['update_branch'] ?? 'main'));

        if (!$gitPath) $gitPath = 'git';
        if (!$gitRepoDir) $gitRepoDir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
        if (!$updateBranch) $updateBranch = 'main';

        $isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
        $query = $isSqlite
            ? "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value"
            : "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $stmt = $pdo->prepare($query);

        $stmt->execute(['git_path', $gitPath]);
        $stmt->execute(['git_repo_dir', $gitRepoDir]);
        $stmt->execute(['update_branch', $updateBranch]);

        echo json_encode(['success' => true]);
        exit();
    }

    if ($action === 'git_init') {
        $repoUrl = trim((string)($jsonInput['repo_url'] ?? $_POST['repo_url'] ?? ''));
        $cfg = getGitConfig($pdo);
        $gitPath = $cfg['git_path'];
        $gitRepoDir = $cfg['git_repo_dir'];
        $branch = $cfg['update_branch'] ?: 'main';

        if (!$repoUrl) {
            echo json_encode(['success' => false, 'error' => 'Remote repository URL is required.']);
            exit();
        }

        // Initialize repository if needed
        $output = runGitCmd($gitPath, $gitRepoDir, 'init');
        runGitCmd($gitPath, $gitRepoDir, 'remote remove origin');
        runGitCmd($gitPath, $gitRepoDir, 'remote add origin ' . escapeshellarg($repoUrl));
        runGitCmd($gitPath, $gitRepoDir, 'fetch origin');

        // Save URL setting
        $stmt = $pdo->prepare("INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['git_remote_url', $repoUrl]);

        echo json_encode(['success' => true, 'output' => trim($output)]);
        exit();
    }

    if ($action === 'git_pull') {
        $cfg = getGitConfig($pdo);
        $gitPath = $cfg['git_path'];
        $gitRepoDir = $cfg['git_repo_dir'];
        $branch = trim((string)($jsonInput['branch'] ?? $_POST['branch'] ?? $cfg['update_branch'] ?: 'main'));

        if (!isGitRepoRobust($gitPath, $gitRepoDir)) {
            echo json_encode(['success' => false, 'error' => 'Directory is not a valid Git repository. Please initialize repository link first.']);
            exit();
        }

        runGitCmd($gitPath, $gitRepoDir, 'fetch origin');
        $pullOutput = runGitCmd($gitPath, $gitRepoDir, 'pull origin ' . escapeshellarg($branch));

        // Automatically run database schema update after git pull
        $migrationStats = executeAutoSchemaMigrations($pdo);
        if ($migrationStats['executed'] > 0) {
            $pullOutput .= "\n\n[Database Migration] Successfully applied " . $migrationStats['executed'] . " SQL schema update(s).";
        } else {
            $pullOutput .= "\n\n[Database Migration] Database schema is up to date.";
        }

        // Save selected branch if updated
        $stmt = $pdo->prepare("INSERT INTO clinic_settings (setting_key, setting_value) VALUES ('update_branch', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$branch]);

        echo json_encode(['success' => true, 'output' => trim($pullOutput)]);
        exit();
    }

    if ($action === 'git_log') {
        $cfg = getGitConfig($pdo);
        $gitPath = $cfg['git_path'];
        $gitRepoDir = $cfg['git_repo_dir'];

        if (!isGitRepoRobust($gitPath, $gitRepoDir)) {
            echo json_encode(['success' => false, 'error' => 'Not a git repository']);
            exit();
        }

        $logOutput = runGitCmd($gitPath, $gitRepoDir, 'log -n 15 --pretty=format:"%h|%an|%ar|%s"');
        $commits = [];
        if ($logOutput && stripos($logOutput, 'fatal:') === false) {
            $lines = explode("\n", trim($logOutput));
            foreach ($lines as $l) {
                $parts = explode('|', $l, 4);
                if (count($parts) === 4) {
                    $commits[] = [
                        'hash'    => $parts[0],
                        'author'  => $parts[1],
                        'date'    => $parts[2],
                        'message' => $parts[3]
                    ];
                }
            }
        }

        echo json_encode(['success' => true, 'commits' => $commits]);
        exit();
    }

    if ($action === 'get_error_logs') {
        if ($userRole !== 'Developer') {
            echo json_encode(['success' => false, 'error' => 'Developer role required for error logs access']);
            exit();
        }

        $logEntries = [];

        // 1. Fetch PHP Error Log if readable
        $errorLogFile = ini_get('error_log');
        if (!empty($errorLogFile) && file_exists($errorLogFile) && is_readable($errorLogFile)) {
            $raw = file_get_contents($errorLogFile);
            $lines = array_filter(explode("\n", trim($raw)));
            $lines = array_slice($lines, -50); // last 50 error entries
            foreach ($lines as $line) {
                $type = (stripos($line, 'error') !== false || stripos($line, 'exception') !== false || stripos($line, 'fatal') !== false) ? 'ERROR' : 'WARNING';
                $logEntries[] = [
                    'source'    => 'PHP_ERROR_LOG',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'type'      => $type,
                    'message'   => $line
                ];
            }
        }

        // 2. Fetch Audit Logs from Database
        try {
            $auditLogs = $pdo->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 30")->fetchAll();
            foreach ($auditLogs as $a) {
                $logEntries[] = [
                    'source'    => 'AUDIT_LOG',
                    'timestamp' => $a['created_at'] ?? date('Y-m-d H:i:s'),
                    'type'      => 'AUDIT',
                    'message'   => '[' . ($a['user_role'] ?? 'User') . ' ' . ($a['user_name'] ?? 'System') . '] ' . ($a['action'] ?? 'ACTION') . ': ' . ($a['details'] ?? '') . ' (IP: ' . ($a['ip_address'] ?? '127.0.0.1') . ')'
                ];
            }
        } catch (Throwable $e) {
            // Ignore if audit logs table is empty or missing
        }

        if (empty($logEntries)) {
            $logEntries[] = [
                'source'    => 'SYSTEM',
                'timestamp' => date('Y-m-d H:i:s'),
                'type'      => 'INFO',
                'message'   => 'No active errors or exception events detected. System operating normally.'
            ];
        }

        echo json_encode(['success' => true, 'logs' => $logEntries]);
        exit();
    }

    if ($action === 'clear_error_logs') {
        if ($userRole !== 'Developer') {
            echo json_encode(['success' => false, 'error' => 'Developer role required to clear logs']);
            exit();
        }

        $errorLogFile = ini_get('error_log');
        if (!empty($errorLogFile) && file_exists($errorLogFile) && is_writable($errorLogFile)) {
            @file_put_contents($errorLogFile, '');
        }

        echo json_encode(['success' => true, 'message' => 'Error log buffer cleared']);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action specified']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
