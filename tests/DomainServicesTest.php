<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\AuditService;
use ClinicFlow\Services\EncounterService;
use ClinicFlow\Services\InventoryService;
use ClinicFlow\Services\BillingService;
use ClinicFlow\Services\AppointmentService;
use ClinicFlow\Services\MigrationRunner;

class DomainServicesTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        require_once __DIR__ . '/../config/database.php';
        \executeAutoSchemaMigrations($this->pdo);
        $runner = new MigrationRunner($this->pdo);
        $runner->run();
    }

    public function testAuditService(): void {
        $audit = new AuditService($this->pdo);
        $id = $audit->log('TEST_ACTION', json_encode(['foo' => 'bar']));

        $this->assertNotEmpty($id);

        $stmt = $this->pdo->prepare("SELECT * FROM audit_logs WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('TEST_ACTION', $row['action']);
    }

    public function testInventoryService(): void {
        $inventory = new InventoryService($this->pdo);

        $item = $inventory->addItem([
            'name' => 'Amoxicillin 500mg',
            'category' => 'Antibiotics',
            'current_stock' => 50,
            'min_threshold' => 10,
            'unit' => 'Box',
            'unit_price' => 15.50
        ]);

        $this->assertNotEmpty($item['id']);

        $restocked = $inventory->restockItem($item['id'], 20, 'restock', 'Pharma Ltd', 10.00, 'Weekly delivery');
        $this->assertEquals(70, $restocked['new_stock']);
    }

    public function testBillingService(): void {
        $billing = new BillingService($this->pdo);

        $inv = $billing->createInvoice([
            'patient_name' => 'John Doe',
            'patient_mrn' => 'MRN-100',
            'amount' => 200.00
        ], [
            ['name' => 'General Consultation', 'unit_price' => 200.00, 'quantity' => 1]
        ]);

        $this->assertNotEmpty($inv['id']);
        $this->assertEquals('Pending', $inv['status']);

        $paid = $billing->recordPayment($inv['id'], 200.00, 'Cash');
        $this->assertEquals('Paid', $paid['status']);
    }

    public function testAppointmentService(): void {
        $app = new AppointmentService($this->pdo);

        // First insert dummy patient and doctor
        $this->pdo->exec("INSERT INTO patients (id, tenant_id, mrn, first_name, last_name, dob, age, gender, registration_date) VALUES ('p1', 'default-clinic', 'MRN1', 'John', 'Doe', '1990-01-01', 34, 'Male', '2025-01-01')");
        $this->pdo->exec("INSERT INTO doctors (id, tenant_id, name, specialty) VALUES ('d1', 'default-clinic', 'Dr. Smith', 'General')");

        $appointment = $app->createAppointment([
            'patient_id' => 'p1',
            'patient_name' => 'John Doe',
            'doctor_id' => 'd1',
            'doctor_name' => 'Dr. Smith',
            'appointment_date' => date('Y-m-d')
        ]);

        $this->assertNotEmpty($appointment['id']);
        $this->assertEquals('Scheduled', $appointment['status']);

        $app->updateStatus($appointment['id'], 'Completed');
        $updated = $app->getAppointmentById($appointment['id']);
        $this->assertEquals('Completed', $updated['status']);
    }

    public function testEncounterService(): void {
        $encounter = new EncounterService($this->pdo);

        $res = $encounter->saveEncounter('p1', [
            'blood_pressure' => '120/80',
            'heart_rate' => 75
        ], [
            'subjective' => 'Patient complains of headache',
            'objective' => 'Normal physical exam',
            'icd_code' => 'R51',
            'plan' => 'Rest and hydration'
        ], [], true);

        $this->assertTrue($res['finalized']);
        $this->assertNotEmpty($res['past_visit_id']);

        $encounters = $encounter->getEncountersByPatient('p1');
        $this->assertCount(1, $encounters);
    }
}
