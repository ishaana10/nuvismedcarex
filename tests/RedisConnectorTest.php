<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../core/RedisConnector.php';

/** The parts of Redis set-up that need no running server. */
final class RedisConnectorTest extends TestCase
{
    /** @dataProvider modes */
    public function testModeReadsTrueFalseAndAuto($in, string $expected): void
    {
        $this->assertSame($expected, NuRedisConnector::mode(['enabled' => $in]));
    }

    public static function modes(): array
    {
        return [[true, 'on'], [false, 'off'], ['auto', 'auto'], ['', 'auto'], ['false', 'off'], ['0', 'off'], ['true', 'on'], [null, 'auto']];
    }

    public function testMissingSettingMeansAuto(): void
    {
        $this->assertSame('auto', NuRedisConnector::mode([]));
    }

    public function testNothingIsTiedToOneHostingAccount(): void
    {
        $src = '';
        foreach (['core/RedisConnector.php', 'core/Cache.php', 'actions/redis_actions.php', 'config/database.php', 'config/config.example.php'] as $f) {
            $src .= file_get_contents(dirname(__DIR__) . '/' . $f);
        }
        $this->assertStringNotContainsString('ictfjcom', $src);
    }

    public function testStalePathIsKeptButHomeSocketIsAlsoChecked(): void
    {
        putenv('HOME=' . sys_get_temp_dir());
        $c = NuRedisConnector::socketCandidates(['path' => '/home/old/.redis/redis.sock']);
        $this->assertSame('/home/old/.redis/redis.sock', $c[0]);
        $this->assertContains(rtrim(sys_get_temp_dir(), '/') . '/.redis/redis.sock', $c);
    }

    public function testOffAndMissingExtensionGiveAReason(): void
    {
        $this->assertNull(NuRedisConnector::connect(['enabled' => false]));
        $this->assertStringContainsString('switched off', NuRedisConnector::$lastReason);
    }

    /** @dataProvider configShapes */
    public function testTurningRedisOffOrOnTouchesOnlyThatSetting(string $php): void
    {
        foreach ([false, 'auto'] as $mode) {
            $out = NuRedisConnector::setModeInConfigText($php, $mode);
            $cfg = eval('?>' . $out);
            $this->assertSame($mode, $cfg['redis']['enabled']);
            $this->assertSame('secret', $cfg['db_pass'], 'other settings are untouched');
        }
    }

    public static function configShapes(): array
    {
        return [
            'hand written'   => ["<?php\nreturn [\n 'db_pass' => 'secret',\n 'redis' => [\n  'enabled' => true,\n  'path' => '/x',\n ],\n];\n"],
            'var_export'     => ["<?php\nreturn array (\n  'db_pass' => 'secret',\n  'redis' => \n  array (\n    'enabled' => false,\n    'path' => '/x',\n  ),\n);\n"],
            'no redis block' => ["<?php\nreturn [\n 'db_pass' => 'secret',\n];\n"],
            'no enabled key' => ["<?php\nreturn [\n 'db_pass' => 'secret',\n 'redis' => [\n  'path' => '',\n ],\n];\n"],
        ];
    }

    public function testAColdStartIsRateLimitedSoAMissingRedisNeverSlowsTheSite(): void
    {
        $home = sys_get_temp_dir() . '/nuvis_nohome_' . bin2hex(random_bytes(3));
        mkdir($home);
        $old = getenv('HOME');
        putenv('HOME=' . $home);
        @unlink(sys_get_temp_dir() . '/nuvis_redis_' . md5(dirname(__DIR__) . '/core') . '.retry');
        $cfg = ['enabled' => 'auto', 'path' => '', 'port' => 1, 'timeout' => 0.2];
        NuRedisConnector::connect($cfg);                       // may wait once for the daemon
        $t = microtime(true);
        $this->assertNull(NuRedisConnector::connect($cfg));    // within the next minute: instant
        $this->assertLessThan(0.5, microtime(true) - $t);
        putenv($old === false ? 'HOME' : 'HOME=' . $old);
        @unlink($home . '/.redis.on');
        @rmdir($home);
    }
}
