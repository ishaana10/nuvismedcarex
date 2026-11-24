<?php
declare(strict_types=1);

/**
 * Sessions in Redis.
 *
 * - Locking: a page often fires several requests at once (dashboard widgets, notifications).
 *   Without a lock the last one to finish overwrites the others' changes, which can drop a
 *   flash message or a CSRF token. Like PHP's own file handler, a request holds the session
 *   from read to close; another request for the same session waits briefly. The lock expires
 *   on its own, so a crashed request can never lock a user out.
 * - validateId(): lets session.use_strict_mode refuse session ids the server never issued
 *   (protects against session fixation).
 * - updateTimestamp(): a request that only reads the session extends its life without
 *   rewriting it.
 */
class NuRedisSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private const LOCK_TTL_SECONDS = 20;
    private const LOCK_WAIT_SECONDS = 3.0;

    private \Redis $redis;
    private string $prefix;
    private int $ttl;
    private ?string $lockKey = null;
    private ?string $lockToken = null;

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
        $this->unlock();
        return true;
    }

    public function validateId(string $id): bool
    {
        try {
            return (bool) $this->redis->exists($this->prefix . $id);
        } catch (\Throwable $e) {
            error_log('[NuRedisSessionHandler validateId] ' . $e->getMessage());
            return false;
        }
    }

    public function read(string $id): string|false
    {
        try {
            $this->lock($id);
            $data = $this->redis->get($this->prefix . $id);
            return ($data === false || $data === null) ? '' : (string) $data;
        } catch (\Throwable $e) {
            error_log('[NuRedisSessionHandler read] ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            return (bool) $this->redis->setex($this->prefix . $id, $this->ttl, $data);
        } catch (\Throwable $e) {
            error_log('[NuRedisSessionHandler write] ' . $e->getMessage());
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            return (bool) $this->redis->expire($this->prefix . $id, $this->ttl);
        } catch (\Throwable $e) {
            error_log('[NuRedisSessionHandler updateTimestamp] ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->redis->del($this->prefix . $id);
            $this->unlock();
            return true;
        } catch (\Throwable $e) {
            error_log('[NuRedisSessionHandler destroy] ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0; // Redis expires keys by itself
    }

    private function lock(string $id): void
    {
        $this->unlock();
        $key = $this->prefix . 'lock:' . $id;
        $token = bin2hex(random_bytes(8));
        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        do {
            if ($this->redis->set($key, $token, ['nx', 'ex' => self::LOCK_TTL_SECONDS])) {
                $this->lockKey = $key;
                $this->lockToken = $token;
                return;
            }
            usleep(25000);
        } while (microtime(true) < $deadline);
        // Still busy after the wait: carry on without the lock rather than hang the page.
    }

    private function unlock(): void
    {
        if ($this->lockKey === null) {
            return;
        }
        try {
            // Only remove the lock if it is still ours (it may have expired and been retaken)
            $this->redis->eval(
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                [$this->lockKey, $this->lockToken],
                1
            );
        } catch (\Throwable $e) {
            error_log('[NuRedisSessionHandler unlock] ' . $e->getMessage());
        }
        $this->lockKey = null;
        $this->lockToken = null;
    }
}
