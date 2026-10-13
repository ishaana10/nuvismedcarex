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
        $this->ttl    = $ttl;
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
        $data = $this->redis->get($this->prefix . $id);
        return $data === false ? '' : $data;
    }

    public function write(string $id, string $data): bool
    {
        return $this->redis->setex($this->prefix . $id, $this->ttl, $data);
    }

    public function destroy(string $id): bool
    {
        return (bool)$this->redis->del($this->prefix . $id);
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis handles expiry automatically via key TTL
        return 0;
    }
}
