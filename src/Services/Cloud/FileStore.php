<?php
declare(strict_types=1);

namespace ClinicFlow\Services\Cloud;

use PDO;

/**
 * One place that saves, serves and removes uploaded documents, wherever in the
 * application they came from. Ported from Nuvis StudioX.
 *
 * The local disk is always written first; when a drive is configured the file
 * is then copied to it. A failed copy never loses the upload — the file stays
 * on this server and is marked "failed" so it can be retried.
 *
 *   uploaded_files.storage_provider  server | gdrive | onedrive | dropbox | webdav
 *   uploaded_files.remote_status     none | synced | failed
 */
final class FileStore
{
    private static ?bool $schemaOk = null;

    /** Columns this feature adds to uploaded_files. */
    private const COLUMNS = [
        'remote_status' => ['sqlite' => "TEXT DEFAULT 'none'",   'mysql' => "VARCHAR(10) NOT NULL DEFAULT 'none'"],
        'remote_error'  => ['sqlite' => 'TEXT',                  'mysql' => 'VARCHAR(500) NULL'],
        'remote_folder' => ['sqlite' => 'TEXT',                  'mysql' => 'VARCHAR(500) NULL'],
        'file_sha256'   => ['sqlite' => 'TEXT',                  'mysql' => 'CHAR(64) NULL'],
        'synced_at'     => ['sqlite' => 'TIMESTAMP',             'mysql' => 'TIMESTAMP NULL'],
    ];

    /** Whether the database has the columns this needs. */
    public static function isReady(PDO $db): bool
    {
        if (self::$schemaOk !== null) {
            return self::$schemaOk;
        }
        try {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $rows = $driver === 'sqlite'
                ? $db->query("PRAGMA table_info(uploaded_files)")->fetchAll(PDO::FETCH_ASSOC)
                : $db->query("SHOW COLUMNS FROM uploaded_files")->fetchAll(PDO::FETCH_ASSOC);
            $have = array_map(static fn($r) => strtolower((string) ($r['name'] ?? $r['Field'] ?? '')), $rows);
            foreach (array_keys(self::COLUMNS) as $needed) {
                if (!in_array($needed, $have, true)) {
                    return self::$schemaOk = false;
                }
            }
            return self::$schemaOk = true;
        } catch (\Throwable $e) {
            error_log('[FileStore] schema check: ' . $e->getMessage());
            return self::$schemaOk = false;
        }
    }

    private static function seg(string $s): string
    {
        return trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', '_', $s) ?? '', " .\t");
    }

    /**
     * The folder a file uploaded now should land in on the drive.
     *
     * @param array|null $patient a patient row, when the file belongs to one
     */
    public static function remoteSegments(?array $cfg = null, ?array $patient = null, ?string $clinicName = null): array
    {
        $cfg = $cfg ?? CloudConfig::get();
        $segs = array_values(array_filter(explode('/', CloudConfig::cleanFolder((string) $cfg['folder']))));

        if (!empty($cfg['by_clinic'])) {
            $name = $clinicName ?? (function_exists('nxClinicName') ? \nxClinicName() : '');
            $name = self::seg((string) $name);
            if ($name !== '') {
                $segs[] = $name;
            }
        }
        if (!empty($cfg['by_patient']) && $patient) {
            $label = trim(($patient['mrn'] ?? '') . ' ' . trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '')));
            $label = self::seg($label);
            if ($label !== '') {
                $segs[] = mb_substr($label, 0, 80);
            }
        }
        $segs[] = date('Y');
        $segs[] = date('m');
        return array_values(array_filter($segs, static fn($s) => $s !== ''));
    }

    /** True when uploads should be copied to a connected drive. */
    public static function cloudActive(?array $cfg = null): bool
    {
        $cfg = $cfg ?? CloudConfig::get();
        return $cfg['provider'] !== 'local' && CloudConfig::isConnected($cfg['provider'], $cfg);
    }

    /** The absolute path of a stored file, coping with the stored relative paths. */
    public static function localPath(array $f, string $basePath): ?string
    {
        $p = (string) ($f['file_path'] ?? '');
        if ($p === '') {
            return null;
        }
        $candidates = [$p, rtrim($basePath, '/') . '/' . ltrim($p, '/'), dirname($basePath) . '/' . ltrim($p, '/')];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Copy one stored file to the configured drive. Safe to call again for a
     * file that failed before.
     *
     * @return array{status:string, provider:string, error:string, url:string}
     */
    public static function push(PDO $db, string $fileId, string $basePath, ?array $cfg = null): array
    {
        $cfg = $cfg ?? CloudConfig::get();
        $none = ['status' => 'none', 'provider' => 'server', 'error' => '', 'url' => ''];

        if (!self::isReady($db)) {
            return $none;
        }
        $provider = Cloud::provider(null, $cfg);
        if (!$provider || !CloudConfig::isConnected($cfg['provider'], $cfg)) {
            return $none;
        }

        $st = $db->prepare("SELECT * FROM uploaded_files WHERE id = ? LIMIT 1");
        $st->execute([$fileId]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) {
            return ['status' => 'failed', 'provider' => 'server', 'error' => 'That file is no longer on record.', 'url' => ''];
        }

        $path = self::localPath($f, $basePath);
        if ($path === null) {
            self::mark($db, $fileId, 'failed', 'The local copy is missing.');
            return ['status' => 'failed', 'provider' => (string) ($f['storage_provider'] ?? 'server'), 'error' => 'The local copy is missing.', 'url' => ''];
        }

        // Where the file belongs on the drive: the patient's folder when it has one
        $patient = null;
        if (!empty($f['patient_id'])) {
            try {
                $ps = $db->prepare("SELECT mrn, first_name, last_name FROM patients WHERE id = ? LIMIT 1");
                $ps->execute([$f['patient_id']]);
                $patient = $ps->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable $e) {
                // the folder simply loses the patient segment
            }
        }

        try {
            $segments = self::remoteSegments($cfg, $patient);
            $result = $provider->upload(
                $path,
                $segments,
                (string) ($f['original_name'] ?: $f['file_name']),
                (string) ($f['file_type'] ?: 'application/octet-stream')
            );

            $fields = [
                'storage_provider' => $provider->id(),
                'external_id' => $result['id'],
                'external_url' => $result['url'] ?: null,
                'remote_status' => 'synced',
                'remote_error' => null,
                'remote_folder' => implode('/', $segments),
            ];
            if (($cfg['mode'] ?? 'mirror') === 'cloud_only') {
                @unlink($path);
                $fields['file_path'] = null;
            }
            $sets = implode(', ', array_map(static fn($k) => "$k = :$k", array_keys($fields)));
            $db->prepare("UPDATE uploaded_files SET {$sets}, synced_at = CURRENT_TIMESTAMP WHERE id = :id")
               ->execute($fields + ['id' => $fileId]);

            return ['status' => 'synced', 'provider' => $provider->id(), 'error' => '', 'url' => (string) $result['url']];
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 480);
            self::mark($db, $fileId, 'failed', $message);
            error_log('[FileStore] push ' . $fileId . ': ' . $message);
            return ['status' => 'failed', 'provider' => 'server', 'error' => $message, 'url' => ''];
        }
    }

    private static function mark(PDO $db, string $fileId, string $status, ?string $error): void
    {
        try {
            $db->prepare("UPDATE uploaded_files SET remote_status = ?, remote_error = ? WHERE id = ?")
               ->execute([$status, $error, $fileId]);
        } catch (\Throwable $e) {
            error_log('[FileStore] mark: ' . $e->getMessage());
        }
    }

    /**
     * Copy files that have not reached the drive — either because they failed
     * or because they were uploaded before a drive was configured.
     *
     * @return array{synced:int, failed:int, remaining:int, last_error:string}
     */
    public static function sweep(PDO $db, string $basePath, string $which = 'failed', int $limit = 25): array
    {
        $cfg = CloudConfig::get();
        if (!self::isReady($db) || !self::cloudActive($cfg)) {
            return ['synced' => 0, 'failed' => 0, 'remaining' => 0, 'last_error' => 'No drive is connected.'];
        }
        $where = $which === 'failed'
            ? "remote_status = 'failed'"
            : "(remote_status IS NULL OR remote_status = 'none')";
        $where .= " AND file_path IS NOT NULL AND file_path <> ''";

        $rows = $db->query("SELECT id FROM uploaded_files WHERE {$where} ORDER BY created_at ASC LIMIT " . max(1, min(200, $limit)))
                   ->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $synced = 0;
        $failed = 0;
        $lastError = '';
        foreach ($rows as $id) {
            $r = self::push($db, (string) $id, $basePath, $cfg);
            if ($r['status'] === 'synced') {
                $synced++;
            } else {
                $failed++;
                $lastError = $r['error'] ?: $lastError;
            }
        }
        $remaining = (int) ($db->query("SELECT COUNT(*) FROM uploaded_files WHERE {$where}")->fetchColumn() ?: 0);
        return ['synced' => $synced, 'failed' => $failed, 'remaining' => $remaining, 'last_error' => $lastError];
    }

    /** How many files are in each state. */
    public static function counts(PDO $db): array
    {
        $out = ['synced' => 0, 'failed' => 0, 'none' => 0];
        if (!self::isReady($db)) {
            return $out;
        }
        try {
            $rows = $db->query("SELECT COALESCE(remote_status, 'none') AS s, COUNT(*) AS c FROM uploaded_files GROUP BY COALESCE(remote_status, 'none')")
                       ->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $out[(string) $r['s']] = (int) $r['c'];
            }
        } catch (\Throwable $e) {
            error_log('[FileStore] counts: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Send a stored file to the browser, from disk when it is still here and
     * from the drive when it is not.
     */
    public static function stream(array $f, string $basePath, bool $download = false): void
    {
        $name = (string) ($f['original_name'] ?: $f['file_name']);
        $mime = (string) ($f['file_type'] ?: 'application/octet-stream');
        // Only what a browser can safely render; everything else downloads
        $inlineOk = in_array(strtolower($mime), ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
        $disposition = ($download || !$inlineOk) ? 'attachment' : 'inline';

        $sendHeaders = static function () use ($mime, $disposition, $name): void {
            header('Content-Type: ' . ($disposition === 'inline' ? $mime : 'application/octet-stream'));
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
            header('Content-Disposition: ' . $disposition . '; filename="'
                . str_replace(['"', "\r", "\n", '\\'], '_', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
            header('Cache-Control: private, max-age=300');
        };

        $local = self::localPath($f, $basePath);
        if ($local !== null) {
            $sendHeaders();
            header('Content-Length: ' . filesize($local));
            readfile($local);
            return;
        }

        $providerId = (string) ($f['storage_provider'] ?? 'server');
        if ($providerId === 'server' || empty($f['external_id'])) {
            http_response_code(404);
            echo 'That file is no longer on this server.';
            return;
        }
        $provider = Cloud::provider($providerId);
        if (!$provider) {
            http_response_code(502);
            echo 'That file is stored on a drive this installation is no longer set up for.';
            return;
        }
        try {
            $started = false;
            $provider->download((string) $f['external_id'], function ($chunk) use (&$started, $sendHeaders) {
                if (!$started) {
                    $sendHeaders();
                    $started = true;
                }
                echo $chunk;
                if (ob_get_level()) {
                    @ob_flush();
                }
                flush();
            });
            if (!$started) {
                $sendHeaders();
            }
        } catch (\Throwable $e) {
            error_log('[FileStore] download: ' . $e->getMessage());
            if (!headers_sent()) {
                http_response_code(502);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo 'The file could not be fetched from ' . $provider->label() . ': ' . $e->getMessage();
        }
    }

    /**
     * Remove both copies. A failure on the drive is reported but never stops
     * the record going.
     */
    public static function remove(array $f, string $basePath): string
    {
        $warning = '';
        $providerId = (string) ($f['storage_provider'] ?? 'server');
        if ($providerId !== 'server' && !empty($f['external_id'])) {
            try {
                $provider = Cloud::provider($providerId);
                if ($provider) {
                    $provider->delete((string) $f['external_id']);
                }
            } catch (\Throwable $e) {
                $warning = $e->getMessage();
                error_log('[FileStore] remote delete: ' . $warning);
            }
        }
        $local = self::localPath($f, $basePath);
        if ($local !== null) {
            @unlink($local);
        }
        return $warning;
    }
}
