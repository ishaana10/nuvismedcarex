<?php
declare(strict_types=1);

/**
 * NuCache - Simple Redis-backed cache with graceful fallback
 * Uses native phpredis extension (already available on host)
 */
class NuCache
{
    private static ?self $instance = null;
    private ?\Redis $redis = null;
    private bool $enabled = false;
    private string $prefix = 'nuvis:';
    private int $defaultTtl = 300;

    private function __construct()
    {
        global $nuConfig;
        $cfg = $nuConfig['redis'] ?? [];
        if (empty($cfg) && function_exists('getAppConfig')) {
            $appConfig = getAppConfig();
            $cfg = $appConfig['redis'] ?? [];
        }

        require_once __DIR__ . '/RedisConnector.php';
        $this->prefix     = (string) ($cfg['prefix'] ?? 'nuvis:');
        // Several Nuvis apps may share one Redis: give this app its own corner so one can never
        // read, or clear, another's keys.
        if (!str_contains($this->prefix, 'medcare')) {
            $this->prefix .= 'medcare:';
        }
        $this->defaultTtl = (int) ($cfg['default_ttl'] ?? 300);

        $this->redis = \NuRedisConnector::connect($cfg);
        $this->enabled = $this->redis !== null;
        if (!$this->enabled) {
            // One quiet line in the log; the Developer panel shows the same reason in full.
            error_log('[NuCache] Redis off: ' . \NuRedisConnector::$lastReason);
        }
    }

    /** The key prefix this app uses (it includes the app name). */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /** Plain-English reason Redis is on or off right now. */
    public function reason(): string
    {
        return \NuRedisConnector::$lastReason;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->redis !== null;
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->isEnabled()) {
            return $default;
        }

        try {
            $value = $this->redis->get($this->key($key));
            if ($value === false) {
                return $default;
            }
            $decoded = @unserialize($value, ['allowed_classes' => false]);
            return $decoded === false && $value !== serialize(false)
                ? $value
                : $decoded;
        } catch (\Throwable $e) {
            error_log('[NuCache get] ' . $e->getMessage());
            return $default;
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $ttl = $ttl ?? $this->defaultTtl;
            $payload = serialize($value);
            if ($ttl > 0) {
                return $this->redis->setex($this->key($key), $ttl, $payload);
            }
            return $this->redis->set($this->key($key), $payload);
        } catch (\Throwable $e) {
            error_log('[NuCache set] ' . $e->getMessage());
            return false;
        }
    }

    public function delete(string $key): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        try {
            return (bool)$this->redis->del($this->key($key));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Delete multiple keys by pattern (use carefully) */
    public function deleteByPattern(string $pattern): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }
        try {
            return $this->deleteMatching($this->prefix . $pattern);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * Clear the cache. Sessions are kept unless $includeSessions is true, because clearing them
     * signs every user out.
     */
    public function flush(bool $includeSessions = false): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        try {
            $this->deleteMatching($this->prefix . '*', $includeSessions ? [] : [$this->prefix . 'sess:']);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Delete keys matching a pattern with SCAN, which never blocks Redis the way KEYS does.
     * @param string[] $keepPrefixes keys starting with any of these are left alone
     */
    private function deleteMatching(string $pattern, array $keepPrefixes = []): int
    {
        $deleted = 0;
        $it = null;
        do {
            $batch = $this->redis->scan($it, $pattern, 200);
            if (!is_array($batch)) {
                break;
            }
            $batch = array_values(array_filter($batch, static function (string $k) use ($keepPrefixes): bool {
                foreach ($keepPrefixes as $keep) {
                    if (str_starts_with($k, $keep)) {
                        return false;
                    }
                }
                return true;
            }));
            if ($batch) {
                $deleted += (int) $this->redis->unlink(...$batch);
            }
        } while ($it > 0);
        return $deleted;
    }

    /** Keys under our prefix (for diagnostics), newest scan order, capped. */
    public function listKeys(int $limit = 200): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        $out = [];
        $it = null;
        try {
            do {
                $batch = $this->redis->scan($it, $this->prefix . '*', 200);
                if (is_array($batch)) {
                    foreach ($batch as $k) {
                        $out[] = $k;
                        if (count($out) >= $limit) {
                            return $out;
                        }
                    }
                }
            } while ($it > 0);
        } catch (\Throwable $e) {
            error_log('[NuCache listKeys] ' . $e->getMessage());
        }
        return $out;
    }

    // ─── Per-clinic keys ───────────────────────────────────────────────────────
    // One Redis serves every clinic, so anything cached for a clinic must carry its id.
    // Use these instead of building keys by hand: a key without the clinic could be read by another.

    public function tenantKey(string $key, ?string $tenantId = null): string
    {
        $tenantId ??= class_exists('\\ClinicFlow\\Shared\\TenantContext') ? \ClinicFlow\Shared\TenantContext::getTenantId() : '';
        if ($tenantId === '' || $tenantId === null) {
            throw new \LogicException('A clinic-specific cache key needs a clinic.');
        }
        return 't:' . $tenantId . ':' . $key;
    }

    public function getForTenant(string $key, mixed $default = null, ?string $tenantId = null): mixed
    {
        return $this->get($this->tenantKey($key, $tenantId), $default);
    }

    public function setForTenant(string $key, mixed $value, ?int $ttl = null, ?string $tenantId = null): bool
    {
        return $this->set($this->tenantKey($key, $tenantId), $value, $ttl);
    }

    public function forgetForTenant(string $key, ?string $tenantId = null): bool
    {
        return $this->delete($this->tenantKey($key, $tenantId));
    }

    /** Drop everything cached for one clinic (not its sessions). */
    public function forgetTenant(?string $tenantId = null): int
    {
        return $this->deleteByPattern($this->tenantKey('*', $tenantId));
    }

    /**
     * Fixed-window counter used for rate limiting. Returns the count including this call, or
     * null when Redis is unavailable (callers then fall back to the database).
     */
    public function hit(string $bucket, int $seconds): ?int
    {
        if (!$this->isEnabled()) {
            return null;
        }
        try {
            $k = $this->key('rl:' . $bucket);
            $n = (int) $this->redis->incr($k);
            if ($n === 1) {
                $this->redis->expire($k, $seconds);
            }
            return $n;
        } catch (\Throwable $e) {
            error_log('[NuCache hit] ' . $e->getMessage());
            return null;
        }
    }

    /** Expose native Redis for advanced use */
    public function client(): ?\Redis
    {
        return $this->isEnabled() ? $this->redis : null;
    }

    // ─── Invalidation Helpers ──────────────────────────────────────────────────

    public function invalidateForm(string $formId): void
    {
        $this->delete("form:meta:{$formId}");
        $this->deleteByPattern("menu:*");
    }

    public function invalidateSystemSettings(): void
    {
        $this->deleteByPattern("sys:*");
    }

    public function invalidateUserMenu(string|int $userId): void
    {
        $this->deleteByPattern("menu:user:{$userId}:*");
    }

    public function invalidateMenus(): void
    {
        $this->deleteByPattern("menu:*");
    }
}
