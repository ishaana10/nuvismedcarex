<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\EncounterService;
use ClinicFlow\Services\BillingService;
use ClinicFlow\Services\VMSService;
use ClinicFlow\Services\MigrationRunner;

class IntegrationWorkflowTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        require_once __DIR__ . '/../config/database.php';
        \executeAutoSchemaMigrations($this->pdo);
        $runner = new MigrationRunner($this->pdo);
        $runner->run();

        // Seed doctor and patient
        $this->pdo->exec("INSERT INTO doctors (id, tenant_id, name, specialty) VALUES ('doc-1', 'default-clinic', 'Dr. Jenkins', 'GP')");
        $this->pdo->exec("INSERT INTO patients (id, tenant_id, mrn, first_name, last_name, dob, age, gender, registration_date) VALUES ('pat-1', 'default-clinic', 'MRN-555', 'Alice', 'Smith', '1992-05-10', 31, 'Female', '2025-01-01')");
    }

    public function testClinicalVisitToBillingFlow(): void {
        $encounter = new EncounterService($this->pdo);
        $billing = new BillingService($this->pdo);
        $vms = new VMSService($this->pdo);

        // 1. Clinical Visit
        $visit = $encounter->saveEncounter('pat-1', [
            'blood_pressure' => '120/80',
            'heart_rate' => 72
        ], [
            'subjective' => 'Routine checkup',
            'objective' => 'Healthy',
            'icd_code' => 'Z00.00',
            'plan' => 'Annual review complete'
        ], [], true);

        $this->assertTrue($visit['finalized']);

        // 2. Billing Invoice
        $invoice = $billing->createInvoice([
            'patient_id' => 'pat-1',
            'patient_name' => 'Alice Smith',
            'patient_mrn' => 'MRN-555',
            'amount' => 150.00
        ], [
            ['name' => 'General Consultation', 'unit_price' => 150.00, 'quantity' => 1, 'tax_label' => 'A', 'tax_rate' => 15.0]
        ]);

        $this->assertNotEmpty($invoice['id']);

        // 3. VMS Fiscalization Calculation
        $itemTax = $vms->calculateItemTax(150.00, 'A');
        $this->assertEquals(15.00, $itemTax['tax_rate']);
        $this->assertGreaterThan(0.0, $itemTax['tax_amount']);

        // 4. Record Payment
        $paid = $billing->recordPayment($invoice['id'], 150.00, 'Cash');
        $this->assertEquals('Paid', $paid['status']);
    }
}
