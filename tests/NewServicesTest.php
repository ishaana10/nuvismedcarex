<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\ClinicalAlertService;
use ClinicFlow\Services\LabOrderService;
use ClinicFlow\Services\TenantService;
use ClinicFlow\Services\MigrationRunner;

class NewServicesTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        require_once __DIR__ . '/../config/database.php';
        \executeAutoSchemaMigrations($this->pdo);
        $runner = new MigrationRunner($this->pdo);
        $runner->run();

        // Seed patient and tenant
        $this->pdo->exec("INSERT INTO patients (id, tenant_id, mrn, first_name, last_name, dob, age, gender, known_allergies, registration_date) VALUES ('pat-100', 'default-clinic', 'MRN-100', 'John', 'Doe', '1990-01-01', 34, 'Male', 'Penicillin, Peanuts', '2025-01-01')");
    }

    public function testClinicalAlertService(): void {
        $alertService = new ClinicalAlertService($this->pdo);

        $alerts = $alertService->checkMedicationAllergies('pat-100', 'Amoxicillin 500mg');
        $this->assertNotEmpty($alerts);
        $this->assertEquals('ALLERGY_WARNING', $alerts[0]['type']);

        $noAlerts = $alertService->checkMedicationAllergies('pat-100', 'Paracetamol 500mg');
        $this->assertEmpty($noAlerts);
    }

    public function testLabOrderService(): void {
        $labService = new LabOrderService($this->pdo);

        $order = $labService->createOrder('pat-100', 'Full Blood Count', 'Lab', 'Hematology');
        $this->assertNotEmpty($order['id']);
        $this->assertEquals('Ordered', $order['status']);

        $updated = $labService->attachResults($order['id'], 'WBC 11.5 (High)', true);
        $this->assertEquals('Completed', $updated['status']);
        $this->assertEquals(1, (int)$updated['is_abnormal']);
    }

    public function testTenantService(): void {
        $tenantService = new TenantService($this->pdo);

        $tenants = $tenantService->getAllTenants();
        $this->assertNotEmpty($tenants);

        $switched = $tenantService->switchTenant('default-clinic');
        $this->assertTrue($switched);
    }
}
