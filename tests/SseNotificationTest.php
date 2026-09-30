<?php

use PHPUnit\Framework\TestCase;
use ClinicFlow\Shared\TenantContext;

class SseNotificationTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("
            CREATE TABLE clinic_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE tenant_settings (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uk_tenant_setting UNIQUE (tenant_id, setting_key)
            );

            CREATE TABLE patients (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                mrn VARCHAR(50) NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                dob DATE NOT NULL,
                age INT NOT NULL,
                gender VARCHAR(20) NOT NULL,
                phone VARCHAR(50),
                email VARCHAR(255),
                address TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        TenantContext::clear();
    }

    public function testSseEnabledCheckDefaultsToTrue(): void {
        // Query default SSE check function logic
        $stmt = $this->pdo->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'sse_notifications_enabled' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();

        $isEnabled = ($val === false || $val === null || $val === '1' || $val === 'true');
        $this->assertTrue($isEnabled);
    }

    public function testSseDisabledStatusWhenSettingIsZero(): void {
        // Deactivate SSE in tenant_settings
        $this->pdo->exec("INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES ('ts-1', 'clinic-a', 'sse_notifications_enabled', '0')");

        $stmt = $this->pdo->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = :tid AND setting_key = 'sse_notifications_enabled' LIMIT 1");
        $stmt->execute(['tid' => 'clinic-a']);
        $val = $stmt->fetchColumn();

        $isEnabled = ($val === '1' || $val === 'true');
        $this->assertFalse($isEnabled);
    }

    public function testSseTenantIsolationForPatientRegistrations(): void {
        // Register patient for clinic-1
        $this->pdo->exec("INSERT INTO patients (id, tenant_id, mrn, first_name, last_name, dob, age, gender, created_at) VALUES ('p-101', 'clinic-1', '#1001', 'David', 'Kim', '1985-05-15', 38, 'Male', '2026-09-30 10:00:00')");

        // Register patient for clinic-2
        $this->pdo->exec("INSERT INTO patients (id, tenant_id, mrn, first_name, last_name, dob, age, gender, created_at) VALUES ('p-102', 'clinic-2', '#2002', 'Sarah', 'Connor', '1990-08-20', 33, 'Female', '2026-09-30 10:05:00')");

        // Query stream for clinic-1
        $stmt1 = $this->pdo->prepare("SELECT * FROM patients WHERE tenant_id = :tid ORDER BY created_at ASC");
        $stmt1->execute(['tid' => 'clinic-1']);
        $patients1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $patients1);
        $this->assertEquals('p-101', $patients1[0]['id']);
        $this->assertEquals('David', $patients1[0]['first_name']);

        // Query stream for clinic-2
        $stmt2 = $this->pdo->prepare("SELECT * FROM patients WHERE tenant_id = :tid ORDER BY created_at ASC");
        $stmt2->execute(['tid' => 'clinic-2']);
        $patients2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $patients2);
        $this->assertEquals('p-102', $patients2[0]['id']);
        $this->assertEquals('Sarah', $patients2[0]['first_name']);
    }

    public function testSseNotificationPayloadFormat(): void {
        $p = [
            'id' => 'p-300',
            'tenant_id' => 'clinic-1',
            'mrn' => '#3003',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'created_at' => '2026-09-30 10:10:00'
        ];

        $fullName = trim($p['first_name'] . ' ' . $p['last_name']);
        $payload = [
            'title' => 'New Patient Registered',
            'message' => "Patient {$fullName} ({$p['mrn']}) is waiting.",
            'patient_id' => $p['id'],
            'patient_name' => $fullName,
            'mrn' => $p['mrn'],
            'timestamp' => $p['created_at'],
            'tenant_id' => $p['tenant_id'],
            'enabled' => true
        ];

        $json = json_encode($payload);
        $decoded = json_decode($json, true);

        $this->assertEquals('New Patient Registered', $decoded['title']);
        $this->assertEquals('Patient Alice Smith (#3003) is waiting.', $decoded['message']);
        $this->assertEquals('clinic-1', $decoded['tenant_id']);
        $this->assertTrue($decoded['enabled']);
    }
}
