<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\FinancialReportService;
use ClinicFlow\Services\InsuranceService;
use ClinicFlow\Services\MigrationRunner;

class BillingAndReportingTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        require_once __DIR__ . '/../config/database.php';
        \executeAutoSchemaMigrations($this->pdo);
        $runner = new MigrationRunner($this->pdo);
        $runner->run();

        // Seed sample invoice
        $this->pdo->exec("
            INSERT INTO invoices (id, tenant_id, invoice_number, patient_id, patient_name, patient_mrn, service_date, due_date, amount, status, insurance_covered, patient_owed, total_tax, is_fiscalized)
            VALUES ('inv-101', 'default-clinic', 'INV-101', 'pat-1', 'John Doe', 'MRN-1', '2025-01-15', '2025-02-15', 200.00, 'Paid', 50.00, 150.00, 22.50, 1)
        ");
        $this->pdo->exec("INSERT INTO patients (id, tenant_id, mrn, first_name, last_name, dob, age, gender, registration_date) VALUES ('pat-1', 'default-clinic', 'MRN-1', 'John', 'Doe', '1990-01-01', 34, 'Male', '2025-01-01')");
    }

    public function testFinancialReportService(): void {
        $reportService = new FinancialReportService($this->pdo);
        $summary = $reportService->getDailySummary('2025-01-15');

        $this->assertEquals(1, $summary['total_invoices']);
        $this->assertEquals(200.00, $summary['gross_revenue']);
        $this->assertEquals(50.00, $summary['insurance_covered']);
        $this->assertEquals(1, $summary['fiscalized_count']);
    }

    public function testInsuranceService(): void {
        $insuranceService = new InsuranceService($this->pdo);

        $claim = $insuranceService->createClaim('inv-101', 'pat-1', 'Fiji Care Insurance', 'FC-9988', 50.00, 'Initial submission');
        $this->assertNotEmpty($claim['id']);
        $this->assertEquals('Submitted', $claim['status']);

        $updated = $insuranceService->updateClaimStatus($claim['id'], 'Approved', 'Claim processed');
        $this->assertEquals('Approved', $updated['status']);
    }
}
