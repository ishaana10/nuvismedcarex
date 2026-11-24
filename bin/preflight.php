<?php
/**
 * Production preflight for NuvisMedcareX.
 *
 *   php bin/preflight.php https://your-clinic.example.com
 *
 * Probes the LIVE site over HTTP (the only way to prove the .htaccess rules work on
 * the real web server) and, when DB env vars are set, checks the database too.
 * Exit code 0 = no failures, 1 = at least one FAIL. Read-only: it never writes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$base = rtrim($argv[1] ?? '', '/');
$fail = 0; $warn = 0;
function out(string $lvl, string $msg): void {
    global $fail, $warn;
    if ($lvl === 'FAIL') $fail++;
    if ($lvl === 'WARN') $warn++;
    echo str_pad("[$lvl]", 7) . $msg . "\n";
}
function http(string $url, array $headers = []): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0,
        'header' => implode("\r\n", $headers)], 'ssl' => ['verify_peer' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0; $hdrs = $http_response_header ?? [];
    if ($hdrs && preg_match('#HTTP/\S+ (\d+)#', $hdrs[0], $m)) $code = (int)$m[1];
    return [$code, (string)$body, $hdrs];
}

if ($base !== '') {
    [$c0] = http($base . '/login.php');
    if ($c0 === 0) { fwrite(STDERR, "Cannot reach $base - nothing was checked.\n"); exit(2); }
    if (strpos($base, 'https://') !== 0) out('FAIL', 'Site is not served over HTTPS');
    // Paths that must never be reachable from the web.
    foreach (['/database/seed.php', '/database/schema.sql', '/install.php', '/config/config.php',
              '/config/config.local.php', '/config/installed.lock', '/composer.json', '/composer.lock',
              '/src/Shared/TenantContext.php', '/includes/access.php', '/core/Cache.php', '/tests/',
              '/vendor/autoload.php', '/.git/config', '/.env', '/uploads/', '/logs/'] as $path) {
        [$code, $body] = http($base . $path);
        $leaks = $code === 200 && ($body !== '' && stripos($body, '<?php') === false || stripos($body, '<?php') !== false);
        if ($path === '/install.php') {
            $ok = in_array($code, [403, 404], true);
        } else {
            $ok = in_array($code, [301, 302, 401, 403, 404], true) || ($code === 200 && trim($body) === '');
        }
        $ok ? out('PASS', "$path -> $code") : out('FAIL', "$path reachable (HTTP $code)" . ($leaks ? ' and returns content' : ''));
    }
    // Tenant header must not select a tenant.
    [$c1, $b1] = http($base . '/login.php');
    [$c2, $b2] = http($base . '/login.php', ['X-Tenant-ID: preflight-other-clinic']);
    ($c1 === $c2 && md5(preg_replace('/(value|content|nonce)="[^"]*"/i', '', $b1)) === md5(preg_replace('/(value|content|nonce)="[^"]*"/i', '', $b2))) ? out('PASS', 'X-Tenant-ID header has no effect on login page')
                                           : out('WARN', 'Login page differs when X-Tenant-ID is sent - verify manually');
    [, , $h] = http($base . '/login.php');
    $hs = strtolower(implode("\n", $h));
    foreach (['x-content-type-options' => 'nosniff', 'strict-transport-security' => 'HSTS', 'content-security-policy' => 'CSP', 'x-frame-options' => 'X-Frame-Options'] as $k => $n) {
        strpos($hs, $k) !== false ? out('PASS', "$n header present") : out($k === 'x-content-type-options' ? 'FAIL' : 'WARN', "$n header missing");
    }
    if (preg_match_all('/^set-cookie:\s*([^\n]+)/mi', $hs, $m)) {
        foreach ($m[1] as $ck) {
            $s = strtolower($ck);
            if (strpos($s, 'httponly') === false) out('FAIL', 'Cookie without HttpOnly: ' . strtok($ck, '='));
            if (strpos($s, 'secure') === false)   out('FAIL', 'Cookie without Secure: ' . strtok($ck, '='));
            if (strpos($s, 'samesite') === false) out('WARN', 'Cookie without SameSite: ' . strtok($ck, '='));
        }
    }
    if (stripos($hs, 'access-control-allow-origin: *') !== false) out('FAIL', 'Wildcard CORS is enabled');
    if (stripos($hs, 'x-powered-by') !== false) out('WARN', 'X-Powered-By leaks PHP version (expose_php=Off)');
} else {
    out('WARN', 'No base URL given: HTTP exposure checks skipped (pass https://your-site)');
}

// Filesystem / config checks (run on the server).
$root = dirname(__DIR__);
is_file($root . '/config/installed.lock') ? out('PASS', 'config/installed.lock present (installer disabled)')
                                           : out('WARN', 'config/installed.lock missing');
is_file($root . '/uploads/.htaccess') ? out('PASS', 'uploads/.htaccess present') : out('FAIL', 'uploads/.htaccess missing');
foreach (['display_errors' => ['0', ''], 'expose_php' => ['0', '']] as $k => $good) {
    in_array((string)ini_get($k), $good, true) ? out('PASS', "php $k off") : out('WARN', "php $k is on (CLI ini; check the web SAPI too)");
}

// Encryption key: clinical notes/vitals are encrypted with it. If it is unset the app silently
// falls back to a key that is published in the source repo, which makes the encryption meaningless.
try {
    require_once $root . '/config/database.php';
    $cfgArr = function_exists('getAppConfig') ? getAppConfig() : [];
    $appKey = (string)(getenv('APP_KEY') ?: ($cfgArr['app_key'] ?? ''));
    if ($appKey === '' || $appKey === 'clinicflow_default_secret_encryption_key_2025!') {
        out('FAIL', 'No APP_KEY / app_key configured: clinical data is encrypted with the PUBLIC default key (see config.example.php before changing it)');
    } elseif (strlen($appKey) < 32) {
        out('WARN', 'app_key is shorter than 32 characters');
    } else {
        out('PASS', 'Encryption app_key is configured');
    }
    // Is any clinical data still on the old public key?
    if (getenv('DB_HOST') && getenv('DB_NAME') && $appKey !== '') {
        require_once $root . '/includes/autoloader.php';
        $encPdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT') ?: '3306', getenv('DB_NAME')),
            getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $es = (new \ClinicFlow\Services\EncryptionMigrationService($encPdo))->status();
        $es['totals']['legacy_key'] > 0
            ? out('FAIL', $es['totals']['legacy_key'] . ' value(s) are still encrypted with the old PUBLIC key: run Developer > Encryption (or php bin/reencrypt.php --run)')
            : out('PASS', 'No clinical data is on the old public key');
        if ($es['totals']['unreadable'] > 0) out('WARN', $es['totals']['unreadable'] . ' encrypted value(s) cannot be opened by any known key (wrong/lost key?)');
        $es['legacy_fallback'] ? out('WARN', "Old-key fallback is still on: after migrating, set 'allow_legacy_key' => false") : out('PASS', 'Old public key is disabled');
    }
} catch (Throwable $e) {
    out('WARN', 'Could not check app_key: ' . $e->getMessage());
}

// Database checks (only if env is provided).
if (getenv('DB_HOST') && getenv('DB_NAME')) {
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT') ?: '3306', getenv('DB_NAME')),
            getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $mode = (string)$pdo->query('SELECT @@sql_mode')->fetchColumn();
        stripos($mode, 'STRICT') !== false ? out('PASS', 'SQL strict mode on') : out('WARN', 'SQL strict mode off: missing tenant_id would be silently accepted');
        // Default / demo credentials (accounts live in `doctors`).
        $weak = ['password', 'ClinicPass123', 'admin', 'admin123', '123456', 'changeme', 'password123'];
        $n = 0;
        foreach ($pdo->query("SELECT email, role, password_hash FROM doctors WHERE is_active = 1 AND password_hash IS NOT NULL AND password_hash <> ''") as $u) {
            foreach ($weak as $w) {
                if (password_verify($w, $u['password_hash'])) { out('FAIL', "Active account {$u['email']} ({$u['role']}) uses a default/weak password"); $n++; break; }
            }
        }
        if (!$n) out('PASS', 'No default/weak passwords on active accounts');
        // tenant_id must be NOT NULL/no silent default on clinical tables.
        foreach (['patients', 'prescriptions', 'vitals', 'invoices', 'invoice_items', 'soap_notes'] as $t) {
            $q = $pdo->prepare("SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'tenant_id'");
            $q->execute([$t]); $c = $q->fetch(PDO::FETCH_ASSOC);
            if (!$c) { out('FAIL', "$t has no tenant_id column"); continue; }
            ($c['IS_NULLABLE'] === 'YES') ? out('WARN', "$t.tenant_id is nullable") : out('PASS', "$t.tenant_id NOT NULL");
        }
        // Rows with no clinic are visible to every clinic in places, so none may exist.
        $orphTotal = 0;
        foreach (['doctors', 'patients', 'appointments', 'prescriptions', 'vitals', 'soap_notes', 'invoices', 'invoice_items', 'inventory', 'medical_certificates', 'lab_orders', 'uploaded_files', 'audit_logs'] as $t) {
            try {
                $c = (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE tenant_id IS NULL OR tenant_id = ''")->fetchColumn();
                if ($c) { out('FAIL', "$c row(s) in $t have no clinic"); $orphTotal += $c; }
            } catch (Throwable $e) { /* table not installed */ }
        }
        if (!$orphTotal) out('PASS', 'No rows without a clinic');
        $mfa = (int)$pdo->query("SELECT COUNT(*) FROM doctors WHERE is_active = 1 AND role IN ('Administrator','Developer') AND (mfa_enabled IS NULL OR mfa_enabled = 0)")->fetchColumn();
        $mfa ? out('WARN', "$mfa admin/developer account(s) without MFA") : out('PASS', 'All admin/developer accounts have MFA');
    } catch (Throwable $e) {
        out('WARN', 'DB checks skipped/failed: ' . $e->getMessage());
    }
} else {
    out('WARN', 'DB_HOST/DB_NAME not set: database checks skipped');
}

echo "\n" . ($fail ? "RESULT: NOT READY ($fail fail, $warn warn)" : "RESULT: no blocking failures ($warn warn)") . "\n";
exit($fail ? 1 : 0);
