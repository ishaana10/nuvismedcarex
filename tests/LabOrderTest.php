<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ClinicFlow\Services\LabOrderService;
use ClinicFlow\Shared\TenantContext;

class LabOrderTest extends TestCase {
    private PDO $pdo;
    private LabOrderService $service;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Run schema migrations
        $this->pdo->exec("
            CREATE TABLE tenants (
                id VARCHAR(50) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                code VARCHAR(50) UNIQUE NOT NULL
            );

            CREATE TABLE clinic_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT
            );

            CREATE TABLE tenant_settings (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT,
                CONSTRAINT uk_tenant_setting UNIQUE (tenant_id, setting_key)
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

            CREATE TABLE lab_orders (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                patient_id VARCHAR(50) NOT NULL,
                order_type VARCHAR(50) NOT NULL DEFAULT 'Lab',
                test_name VARCHAR(255) NOT NULL,
                category VARCHAR(100) DEFAULT 'General',
                status VARCHAR(50) NOT NULL DEFAULT 'Ordered',
                is_abnormal INTEGER DEFAULT 0,
                results TEXT,
                result_file_id VARCHAR(64),
                result_file_path VARCHAR(512),
                ordered_by VARCHAR(255) DEFAULT 'Doctor',
                clinical_notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE lab_order_items (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                lab_order_id VARCHAR(50) NOT NULL,
                test_name VARCHAR(255) NOT NULL,
                category VARCHAR(100) DEFAULT 'General',
                instructions TEXT,
                status VARCHAR(50) NOT NULL DEFAULT 'Ordered',
                is_abnormal INTEGER DEFAULT 0,
                results TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->service = new LabOrderService($this->pdo);
        TenantContext::setTenantId('tenant-alpha');
    }

    public function testCreateMultiItemLabOrder(): void {
        $items = [
            ['test_name' => 'Complete Blood Count (CBC)', 'category' => 'Hematology', 'instructions' => 'Fasting not required'],
            ['test_name' => 'Fasting Blood Sugar (FBS)', 'category' => 'Biochemistry', 'instructions' => 'Fasting 8-10 hours']
        ];

        $order = $this->service->createMultiItemOrder('pat-100', $items, 'Lab', 'Routine health checkup');

        $this->assertNotNull($order);
        $this->assertEquals('pat-100', $order['patient_id']);
        $this->assertEquals('tenant-alpha', $order['tenant_id']);
        $this->assertEquals('Routine health checkup', $order['clinical_notes']);
        $this->assertCount(2, $order['items']);
        $this->assertEquals('Complete Blood Count (CBC)', $order['items'][0]['test_name']);
        $this->assertEquals('Fasting Blood Sugar (FBS)', $order['items'][1]['test_name']);
    }

    public function testUpdateLabOrderAndItems(): void {
        $initialItems = [
            ['test_name' => 'Routine Urinalysis', 'category' => 'Urinalysis', 'instructions' => 'Midstream catch']
        ];
        $order = $this->service->createMultiItemOrder('pat-100', $initialItems, 'Lab');

        $updatedItems = [
            ['test_name' => 'Routine Urinalysis', 'category' => 'Urinalysis', 'instructions' => 'Midstream catch', 'status' => 'Completed', 'results' => 'Clear yellow, normal', 'is_abnormal' => false],
            ['test_name' => 'Urine Culture', 'category' => 'Microbiology', 'instructions' => 'Sterile container', 'status' => 'Ordered', 'results' => null, 'is_abnormal' => false]
        ];

        $updatedOrder = $this->service->updateOrder($order['id'], $updatedItems, 'Follow-up UTI evaluation');

        $this->assertNotNull($updatedOrder);
        $this->assertEquals('Follow-up UTI evaluation', $updatedOrder['clinical_notes']);
        $this->assertCount(2, $updatedOrder['items']);
        $this->assertEquals('Completed', $updatedOrder['items'][0]['status']);
        $this->assertEquals('Clear yellow, normal', $updatedOrder['items'][0]['results']);
    }

    public function testAttachResultsToMultiItemOrder(): void {
        $items = [
            ['test_name' => 'Lipid Profile', 'category' => 'Biochemistry', 'instructions' => 'Fasting 12h']
        ];
        $order = $this->service->createMultiItemOrder('pat-100', $items, 'Lab');

        $completedOrder = $this->service->attachResults($order['id'], 'Cholesterol: 240 mg/dL (High), Triglycerides: 180 mg/dL', true);

        $this->assertEquals('Completed', $completedOrder['status']);
        $this->assertEquals(1, $completedOrder['is_abnormal']);
        $this->assertStringContainsString('240 mg/dL', $completedOrder['results']);
        $this->assertEquals('Completed', $completedOrder['items'][0]['status']);
    }

    public function testDeveloperLabCatalogManagement(): void {
        $customCatalog = [
            [
                'category' => 'Specialized Diagnostics',
                'tests' => ['Genetic Panel A', 'Thyroid Profile (T3/T4/TSH)']
            ]
        ];

        $saved = $this->service->saveLabCatalog($customCatalog, 'tenant-alpha');
        $this->assertTrue($saved);

        $retrievedCatalog = $this->service->getLabCatalog('tenant-alpha');
        $this->assertCount(1, $retrievedCatalog);
        $this->assertEquals('Specialized Diagnostics', $retrievedCatalog[0]['category']);
        $this->assertEquals(['Genetic Panel A', 'Thyroid Profile (T3/T4/TSH)'], $retrievedCatalog[0]['tests']);
    }

    public function testMultiTenantSeparation(): void {
        TenantContext::setTenantId('tenant-alpha');
        $orderAlpha = $this->service->createMultiItemOrder('pat-100', [
            ['test_name' => 'Alpha Specific Test', 'category' => 'General']
        ]);

        TenantContext::setTenantId('tenant-beta');
        $orderBeta = $this->service->getOrdersByPatient('pat-100', 'tenant-beta');
        $this->assertEmpty($orderBeta);

        $this->assertNull($this->service->getOrderWithItems($orderAlpha['id'], 'tenant-beta'));

        TenantContext::setTenantId('tenant-alpha');
        $ordersAlphaRetrieved = $this->service->getOrdersByPatient('pat-100', 'tenant-alpha');
        $this->assertCount(1, $ordersAlphaRetrieved);
        $this->assertEquals($orderAlpha['id'], $ordersAlphaRetrieved[0]['id']);
    }
}
