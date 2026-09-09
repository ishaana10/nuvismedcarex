<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\MigrationRunner;

class ApiTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        require_once __DIR__ . '/../config/database.php';
        \executeAutoSchemaMigrations($this->pdo);
        $runner = new MigrationRunner($this->pdo);
        $runner->run();
    }

    public function testDatabaseInitialization(): void {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM tenants");
        $this->assertGreaterThanOrEqual(1, (int)$stmt->fetchColumn());
    }
}
