<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../core/AppVersion.php';

class AppVersionTest extends TestCase
{
    protected function setUp(): void
    {
        \AppVersion::reset();
    }

    public function testLoadVersionData(): void
    {
        $data = \AppVersion::load();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('build', $data);
        $this->assertArrayHasKey('commit', $data);
        $this->assertArrayHasKey('full', $data);
        $this->assertStringStartsWith('v', $data['full']);
    }

    public function testGetVersionFields(): void
    {
        $version = \AppVersion::get('version');
        $this->assertNotEmpty($version);

        $full = \AppVersion::get('full');
        $this->assertStringContainsString($version, $full);

        $nonExistent = \AppVersion::get('invalid_key');
        $this->assertEquals('', $nonExistent);
    }
}
