<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use ClinicFlow\Services\MigrationRunner;
use PDO;

class MigrationRunnerTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        require_once __DIR__ . '/../config/database.php';
        \executeAutoSchemaMigrations($this->pdo);
    }

    public function testMigrationExecution(): void {
        $runner = new MigrationRunner($this->pdo);
        $executed = $runner->run();

        $this->assertNotEmpty($executed);

        // Check if tenants table exists
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM tenants WHERE id = 'default-clinic'");
        $this->assertEquals(1, (int)$stmt->fetchColumn());
    }
}
