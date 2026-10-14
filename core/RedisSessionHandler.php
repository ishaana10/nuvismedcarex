<?php
declare(strict_types=1);

class NuRedisSessionHandler implements SessionHandlerInterface
{
    private \Redis $redis;
    private string $prefix;
    private int $ttl;

    public function __construct(\Redis $redis, string $prefix = 'nuvis:sess:', int $ttl = 1800)
    {
        $this->redis  = $redis;
        $this->prefix = $prefix;
        $this->ttl    = $ttl > 0 ? $ttl : 1800;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $data = $this->redis->get($this->prefix . $id);
            return ($data === false || $data === null) ? '' : (string)$data;
        } catch (\Throwable $e) {
            error_log('[NuRedisSessionHandler read] ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            return (bool)$this->redis->setex($this->prefix . $id, $this->ttl, $data);
        } catch (\Throwable $e) {
            error_log('[NuRedisSessionHandler write] ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            return (bool)$this->redis->del($this->prefix . $id);
        } catch (\Throwable $e) {
            error_log('[NuRedisSessionHandler destroy] ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }
}
