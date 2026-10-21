<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\AuditService;
use ClinicFlow\Shared\TenantContext;

class AuditAndErrorLogTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id VARCHAR(50) PRIMARY KEY,
            tenant_id VARCHAR(50) NOT NULL,
            user_id VARCHAR(50),
            user_name VARCHAR(100),
            user_role VARCHAR(50),
            patient_id VARCHAR(50) DEFAULT NULL,
            action VARCHAR(100),
            details TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS system_error_logs (
            id VARCHAR(64) PRIMARY KEY,
            tenant_id VARCHAR(64) NOT NULL DEFAULT 'default-clinic',
            type VARCHAR(20) NOT NULL DEFAULT 'PHP',
            level VARCHAR(20) NOT NULL DEFAULT 'ERROR',
            message TEXT NOT NULL,
            file_line VARCHAR(500) NULL,
            stack_trace TEXT NULL,
            user_id VARCHAR(64) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function testAuditLogTenantIsolationAndPatientRead(): void {
        $auditService = new AuditService($this->pdo);

        $logId1 = $auditService->log('USER_LOGIN', 'User logged in', 'usr-1', 'Dr. Smith', 'Doctor', 'clinic-a');
        $logId2 = $auditService->logPatientRead('pat-99', 'Accessed chart', 'usr-2', 'clinic-b');

        $stmtA = $this->pdo->prepare("SELECT * FROM audit_logs WHERE tenant_id = ?");
        $stmtA->execute(['clinic-a']);
        $logsA = $stmtA->fetchAll();

        $this->assertCount(1, $logsA);
        $this->assertEquals('USER_LOGIN', $logsA[0]['action']);
        $this->assertEquals('clinic-a', $logsA[0]['tenant_id']);

        $stmtB = $this->pdo->prepare("SELECT * FROM audit_logs WHERE tenant_id = ?");
        $stmtB->execute(['clinic-b']);
        $logsB = $stmtB->fetchAll();

        $this->assertCount(1, $logsB);
        $this->assertEquals('READ_PATIENT_RECORD', $logsB[0]['action']);
        $this->assertEquals('pat-99', $logsB[0]['patient_id']);
    }

    public function testSystemErrorLogPersistence(): void {
        $stmt = $this->pdo->prepare("INSERT INTO system_error_logs (id, tenant_id, type, level, message, file_line, stack_trace, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['err-1', 'default-clinic', 'JS', 'ERROR', 'Uncaught TypeError: Cannot read property of undefined', 'app.js:42', 'Error: Stack trace', date('Y-m-d H:i:s')]);

        $query = $this->pdo->query("SELECT * FROM system_error_logs WHERE type = 'JS'");
        $rows = $query->fetchAll();

        $this->assertCount(1, $rows);
        $this->assertEquals('JS', $rows[0]['type']);
        $this->assertStringContainsString('TypeError', $rows[0]['message']);
    }
}
