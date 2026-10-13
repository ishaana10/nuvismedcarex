<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

require_once __DIR__ . '/../../core/Cache.php';

/**
 * CacheService PSR-4 wrapper around NuCache
 */
class CacheService
{
    private \NuCache $cache;

    public function __construct(?\NuCache $cache = null)
    {
        $this->cache = $cache ?? \NuCache::getInstance();
    }

    public function isEnabled(): bool
    {
        return $this->cache->isEnabled();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($key, $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->cache->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    public function deleteByPattern(string $pattern): int
    {
        return $this->cache->deleteByPattern($pattern);
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        return $this->cache->remember($key, $callback, $ttl);
    }

    public function flush(): bool
    {
        return $this->cache->flush();
    }

    public function client(): ?\Redis
    {
        return $this->cache->client();
    }

    public function invalidateSystemSettings(): void
    {
        $this->cache->invalidateSystemSettings();
    }

    public function invalidateForm(string $formId): void
    {
        $this->cache->invalidateForm($formId);
    }

    public function invalidateUserMenu(string|int $userId): void
    {
        $this->cache->invalidateUserMenu($userId);
    }

    public function invalidateMenus(): void
    {
        $this->cache->invalidateMenus();
    }
}
