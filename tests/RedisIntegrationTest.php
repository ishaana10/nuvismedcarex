<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../core/RedisConnector.php';
require_once __DIR__ . '/../core/Cache.php';
require_once __DIR__ . '/../core/RedisSessionHandler.php';

/**
 * Runs against a real throw-away Redis (started on a temporary socket) so the behaviour that
 * matters in production is exercised: finding the socket after a move, sessions, per-clinic keys.
 * Skipped where redis-server or the PHP extension is missing.
 */
final class RedisIntegrationTest extends TestCase
{
    private static $proc;
    private static string $home = '';
    private static string $sock = '';
    private static ?string $oldHome = null;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('redis') || trim((string) @shell_exec('command -v redis-server')) === '') {
            return;
        }
        self::$home = sys_get_temp_dir() . '/nuvis_rt_' . bin2hex(random_bytes(4));
        mkdir(self::$home . '/.redis', 0777, true);
        self::$sock = self::$home . '/.redis/redis.sock';
        self::$proc = proc_open(
            ['redis-server', '--port', '0', '--unixsocket', self::$sock, '--save', '', '--appendonly', 'no'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($i = 0; $i < 50 && !file_exists(self::$sock); $i++) {
            usleep(100000);
        }
        self::$oldHome = getenv('HOME') === false ? null : getenv('HOME');
        putenv('HOME=' . self::$home);
    }

    public static function tearDownAfterClass(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // before the test Redis goes away
        }
        if (self::$oldHome !== null) {
            putenv('HOME=' . self::$oldHome);
        }
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
        if (self::$home !== '') {
            @unlink(self::$sock);
            @rmdir(self::$home . '/.redis');
            @unlink(self::$home . '/.redis.on');
            @rmdir(self::$home);
        }
    }

    protected function setUp(): void
    {
        if (!is_resource(self::$proc) || !file_exists(self::$sock)) {
            $this->markTestSkipped('redis-server / phpredis not available');
        }
        NuCache::resetInstance();
    }

    private function cache(array $over = []): NuCache
    {
        $GLOBALS['nuConfig'] = ['redis' => array_merge(['enabled' => 'auto', 'path' => '', 'prefix' => 'nuvis:test' . getmypid() . ':'], $over)];
        NuCache::resetInstance();
        return NuCache::getInstance();
    }

    public function testFindsTheSocketEvenWhenConfigPointsAtAnOldAccount(): void
    {
        $c = $this->cache(['enabled' => true, 'path' => '/home/some-old-account/.redis/redis.sock']);
        $this->assertTrue($c->isEnabled(), $c->reason());
        $this->assertSame('unix', NuRedisConnector::$lastVia);
        $this->assertSame(self::$sock, NuRedisConnector::$lastSocket);
    }

    public function testAutoModeConnectsWithBlankPath(): void
    {
        $this->assertTrue($this->cache()->isEnabled());
    }

    public function testOffModeNeverConnectsAndSaysWhy(): void
    {
        $c = $this->cache(['enabled' => false]);
        $this->assertFalse($c->isEnabled());
        $this->assertStringContainsString('switched off', $c->reason());
    }

    public function testEachAppAndEachClinicGetsItsOwnKeys(): void
    {
        $c = $this->cache();
        $this->assertStringContainsString('medcare', $c->prefix());
        $c->setForTenant('menu', ['a' => 1], 60, 'clinic-A');
        $this->assertSame(['a' => 1], $c->getForTenant('menu', null, 'clinic-A'));
        $this->assertNull($c->getForTenant('menu', null, 'clinic-B'), 'another clinic must not see it');
        $c->setForTenant('menu', ['b' => 2], 60, 'clinic-B');
        $c->forgetTenant('clinic-A');
        $this->assertNull($c->getForTenant('menu', null, 'clinic-A'));
        $this->assertSame(['b' => 2], $c->getForTenant('menu', null, 'clinic-B'));
        $c->flush();
    }

    public function testFlushKeepsSessionsButClearsCache(): void
    {
        $c = $this->cache();
        $c->set('some:cache', 'x', 60);
        $h = new NuRedisSessionHandler($c->client(), $c->prefix() . 'sess:', 60);
        $h->write('abc123', 'user|s:1:"1";');
        $c->flush();
        $this->assertNull($c->get('some:cache'));
        $this->assertSame('user|s:1:"1";', $h->read('abc123'), 'people must stay signed in');
        $h->close();
        $c->flush(true);
        $this->assertFalse($h->validateId('abc123'));
    }

    public function testClearingManyKeysWorksAcrossScanBatches(): void
    {
        $c = $this->cache();
        for ($i = 0; $i < 650; $i++) {
            $c->set("bulk:$i", $i, 60);
        }
        $this->assertSame(650, $c->deleteByPattern('bulk:*'));
        $this->assertCount(0, array_filter($c->listKeys(2000), fn($k) => str_contains($k, 'bulk:')));
    }

    public function testRateLimitCounterCountsAndExpires(): void
    {
        $c = $this->cache();
        $b = 'unit-' . bin2hex(random_bytes(3));
        $this->assertSame(1, $c->hit($b, 1));
        $this->assertSame(2, $c->hit($b, 1));
        usleep(1200000);
        $this->assertSame(1, $c->hit($b, 1), 'the window resets');
    }

    public function testSessionHandlerRejectsUnknownIdsAndLocksUntilClosed(): void
    {
        $c = $this->cache();
        $prefix = $c->prefix() . 'sess:';
        $h1 = new NuRedisSessionHandler($c->client(), $prefix, 60);
        $this->assertFalse($h1->validateId('never-issued'), 'strict mode needs unknown ids refused');
        $h1->write('sid1', 'a');
        $this->assertTrue($h1->validateId('sid1'));

        $h1->read('sid1');
        $this->assertSame(1, (int) $c->client()->exists($prefix . 'lock:sid1'), 'held while the request runs');
        $h1->close();
        $this->assertSame(0, (int) $c->client()->exists($prefix . 'lock:sid1'), 'released at the end');

        $t = microtime(true);
        $h2 = new NuRedisSessionHandler($c->client(), $prefix, 60);
        $h2->read('sid1');
        $this->assertLessThan(0.5, microtime(true) - $t, 'the next request is not delayed');
        $h2->close();
        $h2->destroy('sid1');
        $this->assertFalse($h2->validateId('sid1'));
    }

    public function testSessionLifetimeIsExtendedWithoutRewriting(): void
    {
        $c = $this->cache();
        $prefix = $c->prefix() . 'sess:';
        $h = new NuRedisSessionHandler($c->client(), $prefix, 100);
        $h->write('sid2', 'data');
        $c->client()->expire($prefix . 'sid2', 5);
        $h->updateTimestamp('sid2', 'data');
        $this->assertGreaterThan(50, $c->client()->ttl($prefix . 'sid2'));
        $h->destroy('sid2');
    }

    public function testThrottleUsesRedisWhenAvailable(): void
    {
        $this->cache();
        require_once __DIR__ . '/../includes/security.php';
        $b = 'nxthrottle-' . bin2hex(random_bytes(3));
        $this->assertTrue(nxThrottle($b, 2, 30));
        $this->assertTrue(nxThrottle($b, 2, 30));
        $this->assertFalse(nxThrottle($b, 2, 30), 'third call in the window is refused');
    }
}
