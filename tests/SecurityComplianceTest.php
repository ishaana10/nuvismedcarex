<?php
declare(strict_types=1);

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\MfaService;
use ClinicFlow\Services\RbacService;
use ClinicFlow\Services\AuditService;
use ClinicFlow\Http\SecurityHeadersMiddleware;
use ClinicFlow\Shared\TenantContext;

class SecurityComplianceTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("
            CREATE TABLE doctors (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE,
                password_hash VARCHAR(255),
                role VARCHAR(50) DEFAULT 'Doctor',
                mfa_secret VARCHAR(255) DEFAULT NULL,
                mfa_enabled INTEGER DEFAULT 0
            );

            CREATE TABLE audit_logs (
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
            );

            CREATE TABLE clinic_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT
            );

            CREATE TABLE tenants (
                id VARCHAR(50) PRIMARY KEY,
                feature_flags TEXT
            );
        ");

        TenantContext::setTenantId('tenant-security-test');
    }

    public function testMfaServiceGenerationAndVerification(): void {
        $mfa = new MfaService();
        $secret = $mfa->generateSecret();

        $this->assertEquals(16, strlen($secret));

        $uri = $mfa->getProvisioningUri('drjenkins', $secret);
        $this->assertStringContainsString('otpauth://totp/', $uri);
        $this->assertStringContainsString('drjenkins', $uri);
        $this->assertStringContainsString($secret, $uri);

        // Test developer / test bypass code with testing env active
        putenv('APP_ENV=testing');
        $this->assertTrue($mfa->verifyCode($secret, '000000'));

        // Test invalid code format
        $this->assertFalse($mfa->verifyCode($secret, 'invalid'));
        $this->assertFalse($mfa->verifyCode($secret, '12345'));
    }

    public function testRbacRolesAndPermissions(): void {
        $rbac = new RbacService($this->pdo);

        // System Admin / Developer
        $this->assertTrue($rbac->hasPermission('System Admin', 'manage_users'));
        $this->assertTrue($rbac->hasPermission('Developer', 'manage_users'));

        // Clinic Admin
        $this->assertTrue($rbac->hasPermission('Clinic Admin', 'view_audit_logs'));

        // Doctor / Physician
        $this->assertTrue($rbac->hasPermission('Doctor/Physician', 'create_encounter'));
        $this->assertTrue($rbac->hasPermission('Doctor', 'create_encounter'));
        $this->assertFalse($rbac->hasPermission('Doctor', 'manage_users'));

        // Nurse
        $this->assertTrue($rbac->hasPermission('Nurse', 'record_vitals'));
        $this->assertFalse($rbac->hasPermission('Nurse', 'manage_users'));

        // Receptionist
        $this->assertTrue($rbac->hasPermission('Receptionist', 'manage_appointments'));
        $this->assertFalse($rbac->hasPermission('Receptionist', 'create_encounter'));

        // Patient
        $this->assertTrue($rbac->hasPermission('Patient', 'view_own_profile'));
        $this->assertFalse($rbac->hasPermission('Patient', 'manage_users'));
        $this->assertFalse($rbac->hasPermission('Patient', 'create_encounter'));
    }

    public function testAuditServicePatientReadLogging(): void {
        $auditService = new AuditService($this->pdo);

        $logId = $auditService->logPatientRead('pat-999', 'Patient chart viewed in clinical audit test', 'usr-1', 'tenant-security-test');
        $this->assertNotNull($logId);

        $stmt = $this->pdo->prepare("SELECT * FROM audit_logs WHERE id = ?");
        $stmt->execute([$logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($row);
        $this->assertEquals('READ_PATIENT_RECORD', $row['action']);
        $this->assertEquals('pat-999', $row['patient_id']);
        $this->assertEquals('tenant-security-test', $row['tenant_id']);
    }

    public function testSecurityHeadersMiddlewareCallable(): void {
        // Confirm SecurityHeadersMiddleware applyHeaders is callable without errors
        SecurityHeadersMiddleware::applyHeaders();
        $this->assertTrue(class_exists(SecurityHeadersMiddleware::class));
    }
}
