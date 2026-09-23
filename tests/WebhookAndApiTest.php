<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\WebhookService;
use ClinicFlow\Services\MigrationRunner;

class WebhookAndApiTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        require_once __DIR__ . '/../config/database.php';
        \executeAutoSchemaMigrations($this->pdo);
        $runner = new MigrationRunner($this->pdo);
        $runner->run();
    }

    public function testWebhookService(): void {
        $webhook = new WebhookService($this->pdo);
        $res = $webhook->dispatch('APPOINTMENT_BOOKED', ['appointment_id' => 'apt-123']);

        $this->assertTrue($res['success']);
        $this->assertEquals('APPOINTMENT_BOOKED', $res['event']);
        $this->assertEquals('apt-123', $res['data']['data']['appointment_id']);
    }
}
