<?php

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;
use ClinicFlow\Shared\Container;

/**
 * Application, database and tenant health monitoring for the developer console.
 * Gathers metrics, evaluates them against configurable thresholds to produce
 * incidents, and emails developers when something needs attention.
 *
 * Monitoring settings are install-wide (clinic_settings).
 */
class MonitoringService
{
    public const DEFAULTS = [
        'monitoring_enabled' => '0',
        'monitor_recipients' => '',
        'th_errors_24h'      => '50',
        'th_errors_1h'       => '15',
        'th_storage_pct'     => '90',
        'th_db_conn'         => '100',
        'monitor_cooldown_min' => '60',
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // --- Settings -----------------------------------------------------------

    public function settings(): array
    {
        $out = self::DEFAULTS;
        foreach (array_keys(self::DEFAULTS) as $k) {
            $v = $this->globalSetting($k);
            if ($v !== null) $out[$k] = $v;
        }
        $out['monitor_cron_token'] = $this->globalSetting('monitor_cron_token') ?? '';
        $out['monitor_last_check'] = $this->globalSetting('monitor_last_check') ?? '';
        if ($out['monitor_cron_token'] === '') {
            $out['monitor_cron_token'] = bin2hex(random_bytes(16));
            $this->putGlobal('monitor_cron_token', $out['monitor_cron_token']);
        }
        return $out;
    }

    public function saveSettings(array $in): void
    {
        $this->putGlobal('monitoring_enabled', !empty($in['monitoring_enabled']) ? '1' : '0');
        $recips = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) ($in['monitor_recipients'] ?? ''))));
        $recips = array_filter($recips, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        $this->putGlobal('monitor_recipients', implode(', ', $recips));
        foreach (['th_errors_24h', 'th_errors_1h', 'th_storage_pct', 'th_db_conn', 'monitor_cooldown_min'] as $k) {
            if (isset($in[$k])) $this->putGlobal($k, (string) max(0, (int) $in[$k]));
        }
    }

    public function cronUrl(string $token): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'your-domain';
        return $scheme . '://' . $host . '/api/monitoring_cron.php?token=' . $token;
    }

    // --- Metrics ------------------------------------------------------------

    public function appMetrics(): array
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
        $root = dirname(__DIR__, 2);
        $diskFree = @disk_free_space($root);
        $diskTotal = @disk_total_space($root);
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        return [
            'php_version'   => PHP_VERSION,
            'memory_used'   => memory_get_usage(true),
            'memory_peak'   => memory_get_peak_usage(true),
            'memory_limit'  => ini_get('memory_limit'),
            'load'          => is_array($load) ? $load : null,
            'disk_free'     => $diskFree ?: null,
            'disk_total'    => $diskTotal ?: null,
            'disk_pct'      => ($diskFree && $diskTotal) ? round(100 - ($diskFree / $diskTotal * 100), 1) : null,
            'opcache_hit'   => is_array($opcache) && isset($opcache['opcache_statistics']['opcache_hit_rate']) ? round((float) $opcache['opcache_statistics']['opcache_hit_rate'], 1) : null,
            'server_time'   => date('Y-m-d H:i:s'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? php_sapi_name(),
        ];
    }

    /** Error/issue counts over time windows, by level, plus recent + top. */
    public function errorStats(): array
    {
        $out = [
            'count_1h' => 0, 'count_24h' => 0, 'count_7d' => 0,
            'by_level' => [], 'by_type' => [], 'recent' => [], 'top' => [],
        ];
        try {
            $win = fn(string $expr) => (int) $this->one("SELECT COUNT(*) FROM system_error_logs WHERE created_at >= $expr");
            $out['count_1h'] = $win("DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $out['count_24h'] = $win("DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $out['count_7d'] = $win("DATE_SUB(NOW(), INTERVAL 7 DAY)");

            $out['by_level'] = $this->rows("SELECT level, COUNT(*) n FROM system_error_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY level ORDER BY n DESC");
            $out['by_type'] = $this->rows("SELECT type, COUNT(*) n FROM system_error_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY type ORDER BY n DESC");
            $out['recent'] = $this->rows("SELECT type, level, message, file_line, tenant_id, created_at FROM system_error_logs ORDER BY created_at DESC LIMIT 12");
            $out['top'] = $this->rows("SELECT LEFT(message,120) msg, COUNT(*) n, MAX(created_at) last_seen FROM system_error_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY LEFT(message,120) ORDER BY n DESC LIMIT 8");
        } catch (\Throwable $e) {}
        return $out;
    }

    public function dbHealth(): array
    {
        $h = ['connected' => false, 'version' => null, 'uptime_days' => null, 'size_mb' => null,
              'table_count' => null, 'threads_connected' => null, 'threads_max' => null,
              'slow_queries' => null, 'top_tables' => [], 'driver' => null];
        try {
            $h['driver'] = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $h['connected'] = ((int) $this->one("SELECT 1")) === 1;
            if ($h['driver'] !== 'mysql') { return $h; }

            $h['version'] = (string) $this->one("SELECT VERSION()");
            $uptime = (int) $this->statusVar('Uptime');
            $h['uptime_days'] = $uptime ? round($uptime / 86400, 1) : null;
            $h['threads_connected'] = (int) $this->statusVar('Threads_connected');
            $h['slow_queries'] = (int) $this->statusVar('Slow_queries');
            $h['threads_max'] = (int) $this->one("SELECT @@max_connections");
            $h['size_mb'] = round((float) $this->one("SELECT COALESCE(SUM(data_length + index_length),0)/1048576 FROM information_schema.tables WHERE table_schema = DATABASE()"), 1);
            $h['table_count'] = (int) $this->one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
            $h['top_tables'] = $this->rows("SELECT table_name AS name, table_rows AS rows, ROUND((data_length + index_length)/1048576,2) AS size_mb FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC LIMIT 8");
        } catch (\Throwable $e) {
            $h['error'] = $e->getMessage();
        }
        return $h;
    }

    public function cacheHealth(): array
    {
        $out = ['enabled' => false, 'connected' => false];
        try {
            if (class_exists('\\NuCache')) {
                $c = \NuCache::getInstance();
                $out['enabled'] = $c->isEnabled();
                if ($out['enabled']) {
                    $probe = 'nx_mon_' . bin2hex(random_bytes(3));
                    $c->set($probe, '1', 10);
                    $out['connected'] = $c->get($probe) === '1';
                    $c->delete($probe);
                }
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /** Per-tenant health snapshot. */
    public function tenantHealth(): array
    {
        $rows = [];
        try {
            $tenants = $this->rows("SELECT id, name, status, subscription_status, storage_limit_mb, plan FROM tenants ORDER BY name ASC");
            foreach ($tenants as $t) {
                $tid = $t['id'];
                $r = [
                    'id' => $tid,
                    'name' => $t['name'],
                    'subscription_status' => $t['subscription_status'] ?? 'active',
                    'plan' => $t['plan'] ?? 'standard',
                    'patients' => (int) $this->one("SELECT COUNT(*) FROM patients WHERE tenant_id = ?", [$tid]),
                    'appts_today' => (int) $this->one("SELECT COUNT(*) FROM appointments WHERE tenant_id = ? AND appointment_date = CURDATE()", [$tid]),
                    'errors_24h' => (int) $this->one("SELECT COUNT(*) FROM system_error_logs WHERE tenant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)", [$tid]),
                    'last_activity' => $this->one("SELECT MAX(created_at) FROM activities WHERE tenant_id = ?", [$tid]),
                    'storage_limit_mb' => (int) ($t['storage_limit_mb'] ?? 500),
                ];
                // storage used (uploaded_files size, if available)
                $usedBytes = (int) $this->one("SELECT COALESCE(SUM(file_size),0) FROM uploaded_files WHERE tenant_id = ?", [$tid]);
                $r['storage_used_mb'] = round($usedBytes / 1048576, 1);
                $r['storage_pct'] = $r['storage_limit_mb'] > 0 ? round($r['storage_used_mb'] / $r['storage_limit_mb'] * 100, 1) : 0;
                $r['outstanding_invoices'] = (int) $this->one("SELECT COUNT(*) FROM tenant_invoices WHERE tenant_id = ? AND status NOT IN ('paid','void')", [$tid]);

                // derive a status
                $status = 'healthy';
                if ($r['subscription_status'] === 'suspended' || $r['errors_24h'] >= 25 || $r['storage_pct'] >= 95) $status = 'critical';
                elseif ($r['subscription_status'] === 'past_due' || $r['errors_24h'] >= 10 || $r['storage_pct'] >= 85 || $r['outstanding_invoices'] > 0) $status = 'warning';
                $r['health'] = $status;
                $rows[] = $r;
            }
        } catch (\Throwable $e) {}
        return $rows;
    }

    // --- Health checks → incidents ------------------------------------------

    /** Evaluate thresholds and return a list of current incidents. */
    public function runHealthChecks(): array
    {
        $s = $this->settings();
        $incidents = [];

        $db = $this->dbHealth();
        if (!$db['connected']) {
            $incidents[] = $this->inc('db_down', 'critical', 'system', null, 'Database unreachable', 'The application could not run a query against the database.');
        } else {
            if ($db['driver'] === 'mysql' && $db['threads_connected'] !== null && (int) $s['th_db_conn'] > 0 && $db['threads_connected'] >= (int) $s['th_db_conn']) {
                $incidents[] = $this->inc('db_conn', 'warning', 'system', null, 'High database connections', "{$db['threads_connected']} active connections (threshold {$s['th_db_conn']}).");
            }
        }

        $cache = $this->cacheHealth();
        if ($cache['enabled'] && !$cache['connected']) {
            $incidents[] = $this->inc('cache_down', 'warning', 'system', null, 'Cache/Redis unavailable', 'Redis is enabled but a read/write probe failed.');
        }

        $errs = $this->errorStats();
        if ((int) $s['th_errors_24h'] > 0 && $errs['count_24h'] >= (int) $s['th_errors_24h']) {
            $incidents[] = $this->inc('err_24h', 'warning', 'system', null, 'Elevated error rate (24h)', "{$errs['count_24h']} errors in the last 24h (threshold {$s['th_errors_24h']}).");
        }
        if ((int) $s['th_errors_1h'] > 0 && $errs['count_1h'] >= (int) $s['th_errors_1h']) {
            $incidents[] = $this->inc('err_1h', 'critical', 'system', null, 'Error spike (last hour)', "{$errs['count_1h']} errors in the last hour (threshold {$s['th_errors_1h']}).");
        }

        $app = $this->appMetrics();
        if ($app['disk_pct'] !== null && $app['disk_pct'] >= 90) {
            $incidents[] = $this->inc('disk', $app['disk_pct'] >= 95 ? 'critical' : 'warning', 'system', null, 'Low disk space', "Disk is {$app['disk_pct']}% full.");
        }

        foreach ($this->tenantHealth() as $t) {
            if ((int) $s['th_storage_pct'] > 0 && $t['storage_pct'] >= (int) $s['th_storage_pct']) {
                $incidents[] = $this->inc('storage_' . $t['id'], 'warning', 'tenant', $t['id'], "Storage near limit: {$t['name']}", "{$t['storage_used_mb']} MB of {$t['storage_limit_mb']} MB ({$t['storage_pct']}%).");
            }
            if ($t['subscription_status'] === 'suspended') {
                $incidents[] = $this->inc('sub_' . $t['id'], 'critical', 'tenant', $t['id'], "Subscription suspended: {$t['name']}", 'This clinic\'s subscription is suspended.');
            } elseif ($t['subscription_status'] === 'past_due') {
                $incidents[] = $this->inc('sub_' . $t['id'], 'warning', 'tenant', $t['id'], "Subscription past due: {$t['name']}", ($t['outstanding_invoices'] ? "{$t['outstanding_invoices']} outstanding invoice(s)." : 'Payment overdue.'));
            }
        }

        return $incidents;
    }

    /**
     * Run checks, persist incidents, and email developers if appropriate.
     * Returns [incidentCount, notified(bool), message].
     */
    public function runAndNotify(bool $force = false): array
    {
        $incidents = $this->runHealthChecks();
        $this->persistIncidents($incidents);
        $this->putGlobal('monitor_last_check', date('Y-m-d H:i:s'));

        $s = $this->settings();
        if (!$force && $s['monitoring_enabled'] !== '1') {
            return [count($incidents), false, 'Monitoring notifications are disabled'];
        }
        if (empty($incidents)) {
            $this->putGlobal('monitor_last_sig', '');
            return [0, false, 'All clear'];
        }

        $recipients = array_filter(array_map('trim', explode(',', $s['monitor_recipients'])));
        if (empty($recipients)) {
            return [count($incidents), false, 'No recipient emails configured'];
        }

        // Dedup: only re-notify when the incident set changes, or after cooldown.
        $sig = md5(implode('|', array_map(fn($i) => $i['key'] . ':' . $i['severity'], $incidents)));
        $lastSig = $this->globalSetting('monitor_last_sig') ?? '';
        $lastNotify = strtotime((string) ($this->globalSetting('monitor_last_notify') ?? '')) ?: 0;
        $cooldown = max(0, (int) $s['monitor_cooldown_min']) * 60;
        if (!$force && $sig === $lastSig && (time() - $lastNotify) < $cooldown) {
            return [count($incidents), false, 'No change since last notification (within cooldown)'];
        }

        $sent = $this->sendAlertEmail($recipients, $incidents);
        if ($sent) {
            $this->putGlobal('monitor_last_sig', $sig);
            $this->putGlobal('monitor_last_notify', date('Y-m-d H:i:s'));
            $this->markNotified($incidents);
        }
        return [count($incidents), $sent, $sent ? 'Alert emailed to ' . count($recipients) . ' recipient(s)' : 'Email could not be sent (check SMTP settings)'];
    }

    private function sendAlertEmail(array $recipients, array $incidents): bool
    {
        try {
            $email = Container::getInstance()->get(EmailService::class);
        } catch (\Throwable $e) {
            return false;
        }
        $smtp = $email->getSmtpSettings(null);
        $crit = array_filter($incidents, fn($i) => $i['severity'] === 'critical');
        $subject = '[Nuvis Monitoring] ' . (count($crit) ? count($crit) . ' critical, ' : '') . count($incidents) . ' issue(s) detected';
        $host = $_SERVER['HTTP_HOST'] ?? 'Nuvis Medcare X';

        $rows = '';
        foreach ($incidents as $i) {
            $color = $i['severity'] === 'critical' ? '#b91c1c' : '#b45309';
            $rows .= '<tr>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #eee;"><span style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . $color . ';color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;">' . htmlspecialchars($i['severity']) . '</span></td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #eee;font-weight:600;color:#111;">' . htmlspecialchars($i['title']) . '</td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #eee;color:#555;">' . htmlspecialchars($i['detail']) . '</td>'
                . '</tr>';
        }
        $body = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:0 auto;">'
            . '<h2 style="color:#164487;">Nuvis Medcare X — System Alert</h2>'
            . '<p style="color:#444;">The monitoring dashboard detected the following issue(s) on <strong>' . htmlspecialchars($host) . '</strong> at ' . date('d M Y H:i') . ':</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:13px;"><thead><tr style="text-align:left;background:#f8fafc;"><th style="padding:8px 10px;">Severity</th><th style="padding:8px 10px;">Issue</th><th style="padding:8px 10px;">Detail</th></tr></thead><tbody>'
            . $rows
            . '</tbody></table>'
            . '<p style="color:#888;font-size:12px;margin-top:16px;">Open the Developer → Monitoring dashboard for full details. You are receiving this because you are listed as a monitoring recipient.</p>'
            . '</div>';

        $ok = false;
        foreach ($recipients as $to) {
            try {
                $res = $email->sendSmtpEmail($to, $subject, $body, $smtp);
                if (is_array($res) ? !empty($res['success']) : (bool) $res) $ok = true;
            } catch (\Throwable $e) {}
        }
        return $ok;
    }

    public function openIncidents(int $limit = 50): array
    {
        try {
            return $this->rows("SELECT * FROM monitoring_incidents WHERE status = 'open' ORDER BY FIELD(severity,'critical','warning'), created_at DESC LIMIT " . max(1, min(200, $limit)));
        } catch (\Throwable $e) {
            return [];
        }
    }

    // --- internals ----------------------------------------------------------

    private function inc(string $key, string $sev, string $scope, ?string $tid, string $title, string $detail): array
    {
        return ['key' => $key, 'severity' => $sev, 'scope' => $scope, 'tenant_id' => $tid, 'title' => $title, 'detail' => $detail];
    }

    /** Upsert open incidents; auto-resolve ones no longer present. */
    private function persistIncidents(array $incidents): void
    {
        try {
            $activeKeys = array_map(fn($i) => $i['key'], $incidents);
            // resolve stale
            $open = $this->rows("SELECT id, incident_key FROM monitoring_incidents WHERE status = 'open'");
            foreach ($open as $o) {
                if (!in_array($o['incident_key'], $activeKeys, true)) {
                    $this->db->prepare("UPDATE monitoring_incidents SET status='resolved', resolved_at=NOW() WHERE id=?")->execute([$o['id']]);
                }
            }
            foreach ($incidents as $i) {
                $exists = $this->one("SELECT id FROM monitoring_incidents WHERE incident_key = ? AND status='open' LIMIT 1", [$i['key']]);
                if (!$exists) {
                    $this->db->prepare("INSERT INTO monitoring_incidents (id, incident_key, severity, scope, tenant_id, title, detail, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'open')")
                        ->execute([Uuid::uuidv7(), $i['key'], $i['severity'], $i['scope'], $i['tenant_id'], $i['title'], $i['detail']]);
                }
            }
        } catch (\Throwable $e) {}
    }

    private function markNotified(array $incidents): void
    {
        try {
            foreach ($incidents as $i) {
                $this->db->prepare("UPDATE monitoring_incidents SET notified=1, notified_at=NOW() WHERE incident_key=? AND status='open'")->execute([$i['key']]);
            }
        } catch (\Throwable $e) {}
    }

    private function statusVar(string $name): ?string
    {
        try {
            $s = $this->db->query("SHOW GLOBAL STATUS LIKE " . $this->db->quote($name));
            $row = $s ? $s->fetch(PDO::FETCH_ASSOC) : null;
            return $row['Value'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function one(string $sql, array $params = [])
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    private function rows(string $sql, array $params = []): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function globalSetting(string $key): ?string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = ? LIMIT 1");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false ? null : (string) $v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function putGlobal(string $key, string $value): void
    {
        try {
            $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $sql = $driver === 'sqlite'
                ? "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value"
                : "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
            $this->db->prepare($sql)->execute([$key, $value]);
        } catch (\Throwable $e) {}
    }
}
