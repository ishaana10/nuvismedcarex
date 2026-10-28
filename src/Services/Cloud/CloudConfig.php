<?php
declare(strict_types=1);

namespace ClinicFlow\Services\Cloud;

/**
 * Where uploaded documents are kept, and the credentials for getting them
 * there. Ported from Nuvis StudioX.
 *
 *   config/config.cloud.php   (0600, git-ignored) everything, credentials and
 *                             refresh tokens included
 *   clinic_settings 'cloud_storage'  a non-secret copy (which drive, which
 *                             mode, which folder) so the choice is visible to
 *                             anything reading settings
 *
 * This is one setting for the installation, not per clinic: the drive chosen
 * here is the drive every upload goes to, wherever in the application it was
 * uploaded from.
 */
final class CloudConfig
{
    public const PROVIDERS = ['local', 'gdrive', 'onedrive', 'dropbox', 'webdav'];
    public const MODES = ['mirror', 'cloud_only'];
    private const SECRET = ['client_secret', 'app_secret', 'refresh_token', 'password'];

    private static ?array $cache = null;

    public static function file(): string
    {
        return dirname(__DIR__, 3) . '/config/config.cloud.php';
    }

    public static function defaults(): array
    {
        return [
            'provider'     => 'local',
            'mode'         => 'mirror',   // mirror = keep the local copy; cloud_only = remove it once the upload succeeds
            'folder'       => 'Nuvis Medcare X',
            'by_clinic'    => true,       // a sub-folder per clinic, so one drive can serve several
            'by_patient'   => false,      // a sub-folder per patient MRN
            'redirect_uri' => '',         // blank = worked out from this site's address
            'gdrive'   => ['client_id' => '', 'client_secret' => '', 'refresh_token' => '', 'account' => '', 'connected_at' => ''],
            'onedrive' => ['client_id' => '', 'client_secret' => '', 'tenant' => 'common', 'refresh_token' => '', 'account' => '', 'connected_at' => ''],
            'dropbox'  => ['app_key' => '', 'app_secret' => '', 'refresh_token' => '', 'account' => '', 'connected_at' => ''],
            'webdav'   => ['url' => '', 'username' => '', 'password' => '', 'account' => '', 'connected_at' => ''],
        ];
    }

    public static function get(bool $fresh = false): array
    {
        if (self::$cache !== null && !$fresh) {
            return self::$cache;
        }
        $cfg = self::defaults();
        $f = self::file();
        if (is_file($f)) {
            try {
                $saved = include $f;
            } catch (\Throwable $e) {
                error_log('[CloudConfig] reading ' . basename($f) . ': ' . $e->getMessage());
                $saved = [];
            }
            if (is_array($saved)) {
                foreach ($saved as $k => $v) {
                    if (!array_key_exists($k, $cfg)) {
                        continue;
                    }
                    $cfg[$k] = is_array($cfg[$k]) && is_array($v)
                        ? array_merge($cfg[$k], array_intersect_key($v, $cfg[$k]))
                        : $v;
                }
            }
        }
        $cfg['provider'] = in_array($cfg['provider'], self::PROVIDERS, true) ? $cfg['provider'] : 'local';
        $cfg['mode'] = in_array($cfg['mode'], self::MODES, true) ? $cfg['mode'] : 'mirror';
        return self::$cache = $cfg;
    }

    /** The settings for one provider, or [] when storage is local. */
    public static function section(?string $provider = null): array
    {
        $c = self::get();
        $p = $provider ?? $c['provider'];
        return $p !== 'local' && isset($c[$p]) && is_array($c[$p]) ? $c[$p] : [];
    }

    public static function isConnected(string $provider, ?array $c = null): bool
    {
        $s = ($c ?? self::get())[$provider] ?? [];
        return $provider === 'webdav'
            ? ($s['url'] ?? '') !== '' && ($s['username'] ?? '') !== '' && ($s['password'] ?? '') !== ''
            : (($s['refresh_token'] ?? '') !== '');
    }

    /** Where the provider sends the browser back after sign-in. */
    public static function redirectUri(?array $c = null): string
    {
        $c = $c ?? self::get();
        if (!empty($c['redirect_uri'])) {
            return (string) $c['redirect_uri'];
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/actions/x.php'))), '/');
        if (substr($dir, -8) !== '/actions') {
            $dir .= '/actions';
        }
        return ($https ? 'https://' : 'http://') . $host . $dir . '/cloud_oauth.php';
    }

    /** The settings as the browser may see them: secrets become *_set flags. */
    public static function publicView(?array $c = null): array
    {
        $c = $c ?? self::get();
        foreach (['gdrive', 'onedrive', 'dropbox', 'webdav'] as $p) {
            $connected = self::isConnected($p, $c);
            foreach (self::SECRET as $k) {
                if (array_key_exists($k, $c[$p])) {
                    $c[$p][$k . '_set'] = $c[$p][$k] !== '';
                    unset($c[$p][$k]);
                }
            }
            $c[$p]['connected'] = $connected;
        }
        $c['effective_redirect_uri'] = self::redirectUri($c);
        return $c;
    }

    private static function cleanSegment(string $s): string
    {
        return trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', '_', $s) ?? '', " .\t");
    }

    public static function cleanFolder(string $folder): string
    {
        $parts = array_filter(
            array_map([self::class, 'cleanSegment'], preg_split('#[\\\\/]+#', $folder) ?: []),
            static fn($p) => $p !== '' && $p !== '..'
        );
        return mb_substr(implode('/', $parts), 0, 150);
    }

    /**
     * @param array $in same shape as get(); a blank secret means "keep the one on file"
     * @throws \InvalidArgumentException
     */
    public static function save(array $in): array
    {
        $cur = self::get(true);
        $new = $cur;

        if (isset($in['provider'])) {
            if (!in_array($in['provider'], self::PROVIDERS, true)) {
                throw new \InvalidArgumentException('Unknown storage provider.');
            }
            $new['provider'] = $in['provider'];
        }
        if (isset($in['mode'])) {
            if (!in_array($in['mode'], self::MODES, true)) {
                throw new \InvalidArgumentException('Unknown storage mode.');
            }
            $new['mode'] = $in['mode'];
        }
        if (isset($in['folder'])) {
            $new['folder'] = self::cleanFolder((string) $in['folder']) ?: 'Nuvis Medcare X';
        }
        foreach (['by_clinic', 'by_patient'] as $flag) {
            if (array_key_exists($flag, $in)) {
                $new[$flag] = filter_var($in[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (isset($in['redirect_uri'])) {
            $r = trim((string) $in['redirect_uri']);
            if ($r !== '' && !preg_match('#^https?://[^\s]+$#i', $r)) {
                throw new \InvalidArgumentException('The redirect address must be a full http(s) URL.');
            }
            $new['redirect_uri'] = $r;
        }

        foreach (['gdrive', 'onedrive', 'dropbox', 'webdav'] as $p) {
            if (!isset($in[$p]) || !is_array($in[$p])) {
                continue;
            }
            foreach ($cur[$p] as $k => $old) {
                if (!array_key_exists($k, $in[$p])) {
                    continue;
                }
                if (in_array($k, ['account', 'connected_at'], true)) {
                    continue; // set by the connect flow only
                }
                $v = trim((string) $in[$p][$k]);
                if (in_array($k, self::SECRET, true)) {
                    if ($v === '' || $v === '********') {
                        continue; // keep what is on file
                    }
                    if ($k === 'refresh_token') {
                        continue; // only ever arrives through the sign-in flow
                    }
                }
                if ($k === 'tenant') {
                    $v = preg_match('/^[A-Za-z0-9.\-]{1,100}$/', $v) ? $v : 'common';
                }
                if ($k === 'url') {
                    if ($v !== '' && !preg_match('#^https?://[^\s]+$#i', $v)) {
                        throw new \InvalidArgumentException('The WebDAV address must be a full http(s) URL.');
                    }
                    $v = rtrim($v, '/');
                }
                // New credentials mean the old authorisation no longer applies
                if (in_array($k, ['client_id', 'app_key'], true) && $v !== $old && $old !== '') {
                    $new[$p]['refresh_token'] = '';
                    $new[$p]['account'] = '';
                    $new[$p]['connected_at'] = '';
                }
                $new[$p][$k] = $v;
            }
        }

        self::write($new);
        return $new;
    }

    /** Called by the connect and disconnect flow. */
    public static function setConnection(string $provider, array $values): void
    {
        $c = self::get(true);
        if (!isset($c[$provider])) {
            return;
        }
        $c[$provider] = array_merge($c[$provider], array_intersect_key($values, $c[$provider]));
        self::write($c);
    }

    private static function write(array $c): void
    {
        $f = self::file();
        $php = "<?php\n// Written by Nuvis Medcare X (Developer → Document storage).\n"
            . "// Contains credentials and refresh tokens: do not commit or share.\nreturn "
            . var_export($c, true) . ";\n";
        if (@file_put_contents($f, $php, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write ' . basename($f) . '. Make the config folder writable by the web server.');
        }
        @chmod($f, 0600);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($f, true);
        }
        self::$cache = null;

        // A non-secret copy in the database, so the choice is visible to
        // anything that reads settings. The file stays the source of truth.
        try {
            $pdo = \getDB();
            $public = [
                'provider' => $c['provider'],
                'mode' => $c['mode'],
                'folder' => $c['folder'],
                'by_clinic' => $c['by_clinic'],
                'by_patient' => $c['by_patient'],
            ];
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $sql = $driver === 'sqlite'
                ? "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value"
                : "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
            $pdo->prepare($sql)->execute(['cloud_storage', json_encode($public)]);
        } catch (\Throwable $e) {
            error_log('[CloudConfig] settings copy: ' . $e->getMessage());
        }
    }

    /** Whether the credentials file can be written where it needs to live. */
    public static function isWritable(): bool
    {
        $f = self::file();
        return is_file($f) ? is_writable($f) : is_writable(dirname($f));
    }
}
