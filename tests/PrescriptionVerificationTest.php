<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\PrescriptionVerificationService;
use ClinicFlow\Services\MigrationRunner;

class PrescriptionVerificationTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../includes/security.php';

        \executeAutoSchemaMigrations($this->pdo);
        $runner = new MigrationRunner($this->pdo);
        $runner->run();

        // Seed tenant
        $this->pdo->exec("INSERT INTO tenants (id, name, code, status) VALUES ('tenant-test', 'Test Clinic', 'TC01', 'active')");

        // Seed patient
        $this->pdo->exec("INSERT INTO patients (id, tenant_id, mrn, first_name, last_name, dob, age, gender, known_allergies, registration_date) VALUES ('pat-test-1', 'tenant-test', 'MRN-100', 'John', 'Doe', '1985-05-12', 39, 'Male', 'Penicillin', '2025-01-01')");

        // Seed prescription
        $this->pdo->exec("INSERT INTO prescriptions (id, tenant_id, patient_id, visit_id, medication_name, dosage, frequency, duration, instructions) VALUES ('rx-1', 'tenant-test', 'pat-test-1', 'visit-100', 'Amoxicillin 500mg', '500mg', '3x daily', '7 days', 'Take with food')");

        // Seed doctor
        $this->pdo->exec("INSERT INTO doctors (id, tenant_id, name, specialty, role, prc_number, ptr_number) VALUES ('doc-test-1', 'tenant-test', 'Dr. Sarah Jenkins', 'Internal Medicine', 'Doctor', 'PRC-001', 'PTR-002')");
    }

    public function testPrescriptionVerificationsTableExists(): void {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='prescription_verifications'");
        $this->assertEquals('prescription_verifications', $stmt->fetchColumn());
    }

    public function testTokenCreationAndIdempotency(): void {
        $service = new PrescriptionVerificationService();
        $token1 = $service->getOrCreateToken($this->pdo, 'tenant-test', 'pat-test-1', 'visit-100');

        $this->assertNotEmpty($token1);
        $this->assertStringStartsWith('RXV-', $token1);

        // Call again -> should return exact same token
        $token2 = $service->getOrCreateToken($this->pdo, 'tenant-test', 'pat-test-1', 'visit-100');
        $this->assertEquals($token1, $token2);
    }

    public function testVerificationUrlGeneration(): void {
        $service = new PrescriptionVerificationService();
        $token = 'RXV-TESTTOKEN1234';
        $url = $service->getVerificationUrl($token);

        $this->assertStringContainsString('/verify_prescription.php?token=RXV-TESTTOKEN1234', $url);
    }

    public function testVerificationDetailsResolution(): void {
        $service = new PrescriptionVerificationService();
        $token = $service->getOrCreateToken($this->pdo, 'tenant-test', 'pat-test-1', 'visit-100');

        $details = $service->getVerificationDetails($this->pdo, $token);

        $this->assertNotNull($details);
        $this->assertTrue($details['valid']);
        $this->assertEquals($token, $details['token']);
        $this->assertEquals('John Doe', $details['patient']['name']);
        $this->assertEquals('MRN-100', $details['patient']['mrn']);
        $this->assertEquals('Penicillin', $details['patient']['allergies']);

        $this->assertCount(1, $details['prescriptions']);
        $this->assertEquals('Amoxicillin 500mg', $details['prescriptions'][0]['medication_name']);
        $this->assertEquals('3x daily', $details['prescriptions'][0]['frequency']);
    }

    public function testInvalidTokenResolution(): void {
        $service = new PrescriptionVerificationService();

        $detailsNull = $service->getVerificationDetails($this->pdo, '');
        $this->assertNull($detailsNull);

        $detailsInvalid = $service->getVerificationDetails($this->pdo, 'RXV-NONEXISTENTTOKEN');
        $this->assertNull($detailsInvalid);
    }
}
