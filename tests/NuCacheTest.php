<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use NuCache;
use NuRedisSessionHandler;
use ClinicFlow\Services\CacheService;
use ClinicFlow\Shared\Container;

class NuCacheTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../core/Cache.php';
        require_once __DIR__ . '/../core/RedisSessionHandler.php';
    }

    public function testNuCacheSingletonAndFallback(): void
    {
        NuCache::resetInstance();
        $cache = NuCache::getInstance();
        $this->assertInstanceOf(NuCache::class, $cache);

        // When Redis is disabled in test environment, calls gracefully return default / false
        if (!$cache->isEnabled()) {
            $this->assertFalse($cache->isEnabled());
            $this->assertNull($cache->client());
            $this->assertFalse($cache->set('key', 'val'));
            $this->assertEquals('default', $cache->get('key', 'default'));
            $this->assertFalse($cache->delete('key'));
            $this->assertEquals(0, $cache->deleteByPattern('key*'));
            $this->assertFalse($cache->flush());

            // Remember callback should still run and return value
            $computed = $cache->remember('rem_key', fn() => 'computed_value');
            $this->assertEquals('computed_value', $computed);
        }
    }

    public function testCacheServiceWrapper(): void
    {
        NuCache::resetInstance();
        $service = new CacheService();
        $this->assertEquals($service->isEnabled(), NuCache::getInstance()->isEnabled());

        $value = $service->remember('svc_key', fn() => 42);
        $this->assertEquals(42, $value);

        // Test invalidation helpers do not throw exceptions
        $service->invalidateSystemSettings();
        $service->invalidateForm('f1');
        $service->invalidateUserMenu('doc-1');
        $service->invalidateMenus();
    }

    public function testContainerBinding(): void
    {
        $container = Container::getInstance();
        $cacheFromContainer = $container->get(NuCache::class);
        $this->assertInstanceOf(NuCache::class, $cacheFromContainer);

        $svcFromContainer = $container->get(CacheService::class);
        $this->assertInstanceOf(CacheService::class, $svcFromContainer);
    }

    public function testRedisSessionHandlerInterfaceWithMock(): void
    {
        if (class_exists('Redis')) {
            $mockRedis = $this->createMock(\Redis::class);
            $mockRedis->expects($this->once())
                ->method('get')
                ->with('nuvis:sess:test_sess_id')
                ->willReturn('serialized_data');

            $mockRedis->expects($this->once())
                ->method('setex')
                ->with('nuvis:sess:test_sess_id', 1800, 'session_payload')
                ->willReturn(true);

            $mockRedis->expects($this->once())
                ->method('del')
                ->with('nuvis:sess:test_sess_id')
                ->willReturn(1);

            $handler = new NuRedisSessionHandler($mockRedis, 'nuvis:sess:', 1800);

            $this->assertTrue($handler->open('/path', 'NAME'));
            $this->assertTrue($handler->close());
            $this->assertEquals('serialized_data', $handler->read('test_sess_id'));
            $this->assertTrue($handler->write('test_sess_id', 'session_payload'));
            $this->assertTrue($handler->destroy('test_sess_id'));
            $this->assertEquals(0, $handler->gc(1440));
        } else {
            $this->assertTrue(true, 'Redis class not available on CLI environment');
        }
    }
}
