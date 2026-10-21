<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\VMSService;

class FRCSAccreditationTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS clinic_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
            id VARCHAR(50) PRIMARY KEY,
            tenant_id VARCHAR(50) NOT NULL,
            invoice_number VARCHAR(50) NOT NULL,
            patient_id VARCHAR(50),
            patient_name VARCHAR(255) NOT NULL,
            patient_mrn VARCHAR(50) NOT NULL,
            service_date DATE NOT NULL,
            due_date DATE NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            insurance_covered DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            patient_owed DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            services TEXT,
            invoice_type VARCHAR(20) NOT NULL DEFAULT 'Normal',
            transaction_type VARCHAR(20) NOT NULL DEFAULT 'Sale',
            seller_tin VARCHAR(50) DEFAULT '502579006',
            business_location VARCHAR(255) DEFAULT 'Suva Central Clinic, 2 Woodstand Road, Suva',
            cashier VARCHAR(100) DEFAULT 'Admin',
            buyer_tin VARCHAR(50),
            buyer_cost_center VARCHAR(100),
            pos_number VARCHAR(50) DEFAULT 'CF-POS-V3/1.0',
            pos_time VARCHAR(50),
            ref_no VARCHAR(100),
            ref_time VARCHAR(50),
            is_fiscalized TINYINT(1) DEFAULT 0,
            sdc_invoice_no VARCHAR(100),
            sdc_time VARCHAR(50),
            invoice_counter VARCHAR(100),
            verification_url TEXT,
            digital_signature TEXT,
            total_tax DECIMAL(10,2) DEFAULT 0.00,
            payment_methods TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS invoice_items (
            id VARCHAR(50) PRIMARY KEY,
            tenant_id VARCHAR(50) NOT NULL,
            invoice_id VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            gtin VARCHAR(50),
            unit_price DECIMAL(10,2) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            total_price DECIMAL(10,2) NOT NULL,
            tax_label VARCHAR(10) NOT NULL DEFAULT 'A',
            tax_rate DECIMAL(5,2) NOT NULL DEFAULT 15.00,
            tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS vms_logs (
            id VARCHAR(50) PRIMARY KEY,
            tenant_id VARCHAR(50) NOT NULL DEFAULT 'default-clinic',
            invoice_id VARCHAR(50),
            event_type VARCHAR(50) NOT NULL,
            request_payload TEXT,
            response_payload TEXT,
            status_code INT DEFAULT 200,
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function testFijiVmsTaxCalculations(): void {
        $vms = new VMSService($this->pdo);

        // Standard VAT Rate A (15%)
        $calcA = $vms->calculateItemTax(115.00, 'A');
        $this->assertEquals(15.00, $calcA['tax_rate']);
        $this->assertEquals(15.00, round($calcA['tax_amount'], 2));

        // Exempt Rate E (0%)
        $calcE = $vms->calculateItemTax(100.00, 'E');
        $this->assertEquals(0.00, $calcE['tax_amount']);

        // Zero Rated F (0%)
        $calcF = $vms->calculateItemTax(200.00, 'F');
        $this->assertEquals(0.00, $calcF['tax_amount']);
    }

    public function testInvoiceFiscalizationAndSdcResponse(): void {
        $stmt = $this->pdo->prepare("INSERT INTO invoices (id, tenant_id, invoice_number, patient_name, patient_mrn, service_date, due_date, amount, status) VALUES ('inv-test-1', 'default-clinic', 'INV-001', 'Jane Doe', 'MRN-101', '2025-01-01', '2025-01-01', 115.00, 'Pending')");
        $stmt->execute();

        $stmtItem = $this->pdo->prepare("INSERT INTO invoice_items (id, tenant_id, invoice_id, name, unit_price, quantity, total_price, tax_label, tax_rate) VALUES ('item-1', 'default-clinic', 'inv-test-1', 'Medical Consultation', 115.00, 1, 115.00, 'A', 15.00)");
        $stmtItem->execute();

        $vms = new VMSService($this->pdo);
        $res = $vms->fiscalizeInvoice('inv-test-1');

        $this->assertEquals(1, $res['is_fiscalized']);
        $this->assertNotEmpty($res['sdcInvoiceNo']);
        $this->assertNotEmpty($res['verificationUrl']);
    }
}
