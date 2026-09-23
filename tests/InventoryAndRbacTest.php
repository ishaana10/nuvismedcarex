<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\RbacService;
use ClinicFlow\Repositories\AuditLogRepository;
use ClinicFlow\Services\InventoryBatchService;
use ClinicFlow\Services\MigrationRunner;

class InventoryAndRbacTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        require_once __DIR__ . '/../config/database.php';
        \executeAutoSchemaMigrations($this->pdo);
        $runner = new MigrationRunner($this->pdo);
        $runner->run();

        // Seed expiring item
        $expiringDate = date('Y-m-d', strtotime('+10 days'));
        $this->pdo->exec("
            INSERT INTO inventory (id, tenant_id, name, sku, category, current_stock, min_threshold, unit, status, last_restocked, expiry_date, is_active)
            VALUES ('inv-exp-1', 'default-clinic', 'Test Antibiotic', 'SKU-EXP-1', 'Pharma', 5, 20, 'Box', 'Low Stock', '2025-01-01', '{$expiringDate}', 1)
        ");
    }

    public function testRbacService(): void {
        $rbac = new RbacService($this->pdo);

        $this->assertTrue($rbac->hasPermission('Developer', 'view_patients'));
        $this->assertFalse($rbac->hasPermission('Nurse', 'delete_patient'));
        $this->assertTrue($rbac->isFeatureEnabled('vms_fiscalization'));
    }

    public function testInventoryBatchService(): void {
        $batchService = new InventoryBatchService($this->pdo);

        $expiring = $batchService->getExpiringItems(30);
        $this->assertCount(1, $expiring);
        $this->assertEquals('inv-exp-1', $expiring[0]['id']);

        $lowStock = $batchService->getLowStockItems();
        $this->assertCount(1, $lowStock);
    }

    public function testAuditLogRepository(): void {
        $this->pdo->exec("
            INSERT INTO audit_logs (id, tenant_id, user_id, user_name, user_role, action, details)
            VALUES ('a-1', 'default-clinic', 'u-1', 'Admin', 'Developer', 'LOGIN', 'User login')
        ");

        $repo = new AuditLogRepository($this->pdo);
        $logs = $repo->findLogs('LOGIN');

        $this->assertNotEmpty($logs);
        $this->assertEquals('LOGIN', $logs[0]['action']);
    }
}
