<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use ClinicFlow\Services\VMSService;
use PDO;

class VMSServiceTest extends TestCase
{
    private PDO $pdo;
    private VMSService $vmsService;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Setup in-memory schema
        $this->pdo->exec("
            CREATE TABLE clinic_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT
            );

            CREATE TABLE invoices (
                id TEXT PRIMARY KEY,
                invoice_number TEXT UNIQUE NOT NULL,
                patient_id TEXT,
                patient_name TEXT NOT NULL,
                patient_mrn TEXT NOT NULL,
                service_date TEXT NOT NULL,
                due_date TEXT NOT NULL,
                amount REAL NOT NULL,
                status TEXT NOT NULL DEFAULT 'Pending',
                insurance_covered REAL NOT NULL DEFAULT 0.00,
                patient_owed REAL NOT NULL DEFAULT 0.00,
                services TEXT,
                invoice_type TEXT NOT NULL DEFAULT 'Normal',
                transaction_type TEXT NOT NULL DEFAULT 'Sale',
                seller_tin TEXT DEFAULT '502579006',
                business_location TEXT DEFAULT 'Suva Central Clinic, 2 Woodstand Road, Suva',
                cashier TEXT DEFAULT 'Admin',
                buyer_tin TEXT,
                buyer_cost_center TEXT,
                pos_number TEXT DEFAULT 'CF-POS-V3/1.0',
                pos_time TEXT,
                ref_no TEXT,
                ref_time TEXT,
                is_fiscalized INTEGER DEFAULT 0,
                sdc_invoice_no TEXT,
                sdc_time TEXT,
                invoice_counter TEXT,
                verification_url TEXT,
                digital_signature TEXT,
                total_tax REAL DEFAULT 0.00,
                payment_methods TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE invoice_items (
                id TEXT PRIMARY KEY,
                invoice_id TEXT NOT NULL,
                name TEXT NOT NULL,
                gtin TEXT,
                unit_price REAL NOT NULL,
                quantity REAL NOT NULL DEFAULT 1.00,
                total_price REAL NOT NULL,
                tax_label TEXT NOT NULL DEFAULT 'A',
                tax_rate REAL NOT NULL DEFAULT 15.00,
                tax_amount REAL NOT NULL DEFAULT 0.00,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE vms_logs (
                id TEXT PRIMARY KEY,
                invoice_id TEXT,
                event_type TEXT NOT NULL,
                request_payload TEXT,
                response_payload TEXT,
                status_code INTEGER DEFAULT 200,
                error_message TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->vmsService = new VMSService($this->pdo);
    }

    public function testTaxCalculation(): void
    {
        // 100 FJD inclusive of 15% VAT
        $calc = $this->vmsService->calculateItemTax(100.00, 'A');
        $this->assertEquals(15.00, $calc['tax_rate']);
        $this->assertEquals(13.0435, round($calc['tax_amount'], 4));
        $this->assertEquals(86.9565, round($calc['net_amount'], 4));

        // Exempt 0%
        $calcExempt = $this->vmsService->calculateItemTax(100.00, 'E');
        $this->assertEquals(0.00, $calcExempt['tax_amount']);
    }

    public function testFiscalizationWorkflow(): void
    {
        // Insert sample invoice and item
        $stmt = $this->pdo->prepare("
            INSERT INTO invoices (id, invoice_number, patient_name, patient_mrn, service_date, due_date, amount, status, patient_owed)
            VALUES ('inv-test-1', 'INV-2023-100', 'Jane Doe', 'MRN-100', '2023-10-24', '2023-11-24', 230.00, 'Pending', 230.00)
        ");
        $stmt->execute();

        $stmtItem = $this->pdo->prepare("
            INSERT INTO invoice_items (id, invoice_id, name, gtin, unit_price, quantity, total_price, tax_label, tax_rate, tax_amount)
            VALUES ('item-test-1', 'inv-test-1', 'General Practice Consultation', '10009812', 230.00, 1.0, 230.00, 'A', 15.00, 30.00)
        ");
        $stmtItem->execute();

        $result = $this->vmsService->fiscalizeInvoice('inv-test-1');

        $this->assertEquals('SUCCESS', $result['status']);
        $this->assertNotEmpty($result['sdcInvoiceNo']);
        $this->assertNotEmpty($result['verificationUrl']);
        $this->assertEquals(1, $result['is_fiscalized']);

        // Check DB update
        $stmtCheck = $this->pdo->prepare("SELECT is_fiscalized, sdc_invoice_no FROM invoices WHERE id = 'inv-test-1'");
        $stmtCheck->execute();
        $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(1, (int)$row['is_fiscalized']);
        $this->assertNotEmpty($row['sdc_invoice_no']);
    }

    public function testInvoiceCancellation(): void
    {
        // Create initial invoice
        $stmt = $this->pdo->prepare("
            INSERT INTO invoices (id, invoice_number, patient_name, patient_mrn, service_date, due_date, amount, status, patient_owed, invoice_type, transaction_type, seller_tin)
            VALUES ('inv-orig-1', 'INV-ORIG-1', 'John Smith', 'MRN-200', '2023-10-24', '2023-11-24', 150.00, 'Paid', 0.00, 'Normal', 'Sale', '502579006')
        ");
        $stmt->execute();

        $stmtItem = $this->pdo->prepare("
            INSERT INTO invoice_items (id, invoice_id, name, unit_price, quantity, total_price, tax_label, tax_rate, tax_amount)
            VALUES ('item-orig-1', 'inv-orig-1', 'Blood Test', 150.00, 1.0, 150.00, 'A', 15.00, 19.57)
        ");
        $stmtItem->execute();

        $this->vmsService->fiscalizeInvoice('inv-orig-1');

        // Cancel
        $cancelInvId = $this->vmsService->cancelInvoice('inv-orig-1', 'Admin');

        $stmtCancel = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmtCancel->execute([$cancelInvId]);
        $cancelInv = $stmtCancel->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('Refund', $cancelInv['transaction_type']);
        $this->assertEquals('502579006', $cancelInv['buyer_tin']); // Buyer TIN = Seller TIN on cancel
        $this->assertEquals(1, $cancelInv['is_fiscalized']);

        // Original status
        $stmtOrigCheck = $this->pdo->prepare("SELECT status FROM invoices WHERE id = 'inv-orig-1'");
        $stmtOrigCheck->execute();
        $this->assertEquals('Cancelled', $stmtOrigCheck->fetchColumn());
    }

    public function testDailyFiscalReport(): void
    {
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare("
            INSERT INTO invoices (id, invoice_number, patient_name, patient_mrn, service_date, due_date, amount, status, is_fiscalized, invoice_type, transaction_type, total_tax, created_at, payment_methods)
            VALUES ('inv-rep-1', 'INV-REP-1', 'Alice', 'MRN-300', ?, ?, 100.00, 'Paid', 1, 'Normal', 'Sale', 13.04, ?, '[{\"type\":\"Cash\",\"amount\":100.00}]')
        ");
        $stmt->execute([$today, $today, $today . ' 10:00:00']);

        $report = $this->vmsService->getDailyFiscalReport($today);

        $this->assertEquals(1, $report['total_invoices']);
        $this->assertEquals(100.00, $report['total_sales']);
        $this->assertEquals(13.04, $report['total_vat']);
        $this->assertEquals(100.00, $report['by_payment']['Cash']);
    }

    public function testTaxCalculationSpecialAndZeroRates(): void
    {
        // Zero-rated rate 'F' (0%)
        $calcF = $this->vmsService->calculateItemTax(200.00, 'F');
        $this->assertEquals(0.00, $calcF['tax_rate']);
        $this->assertEquals(0.00, $calcF['tax_amount']);
        $this->assertEquals(200.00, $calcF['net_amount']);

        // Special rate 'P' (0.25%)
        $calcP = $this->vmsService->calculateItemTax(1000.00, 'P');
        $this->assertEquals(0.25, $calcP['tax_rate']);
        $this->assertEquals(2.4938, round($calcP['tax_amount'], 4));

        // Custom rate loaded from settings
        $stmt = $this->pdo->prepare("INSERT INTO clinic_settings (setting_key, setting_value) VALUES ('vms_tax_rate_a', '12.50')");
        $stmt->execute();

        $customService = new VMSService($this->pdo);
        $calcCustom = $customService->calculateItemTax(100.00, 'A');
        $this->assertEquals(12.50, $calcCustom['tax_rate']);
    }

    public function testBuildSDCRequestWithAllInvoiceTypesAndBuyerDetails(): void
    {
        $invoiceData = [
            'invoice_type' => 'Advance',
            'transaction_type' => 'Sale',
            'buyer_tin' => '999888777',
            'buyer_cost_center' => 'CC-DEPT-1',
            'ref_no' => 'INV-2023-001',
            'ref_time' => '2023-10-20 12:00:00',
            'cashier' => 'Dr. Smith',
            'pos_number' => 'POS-SUVA-01',
            'payment_methods' => [['type' => 'Card', 'amount' => 500.00]]
        ];

        $items = [
            [
                'name' => 'Advance Consultation Fee',
                'gtin' => '88001122',
                'quantity' => 1,
                'unit_price' => 500.00,
                'tax_label' => 'A'
            ]
        ];

        $sdcReq = $this->vmsService->buildSDCRequest($invoiceData, $items);

        $this->assertEquals('Advance', $sdcReq['invoiceType']);
        $this->assertEquals('Sale', $sdcReq['transactionType']);
        $this->assertEquals('999888777', $sdcReq['buyer']['tin']);
        $this->assertEquals('CC-DEPT-1', $sdcReq['buyer']['costCenter']);
        $this->assertEquals('INV-2023-001', $sdcReq['referencedDocument']['number']);
        $this->assertEquals('Dr. Smith', $sdcReq['cashier']);
        $this->assertEquals('POS-SUVA-01', $sdcReq['posNumber']);
    }

    public function testVMSLogsAuditTrail(): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO invoices (id, invoice_number, patient_name, patient_mrn, service_date, due_date, amount, status, patient_owed)
            VALUES ('inv-log-test', 'INV-LOG-1', 'Log Test User', 'MRN-LOG', '2023-10-25', '2023-11-25', 100.00, 'Pending', 100.00)
        ");
        $stmt->execute();

        $stmtItem = $this->pdo->prepare("
            INSERT INTO invoice_items (id, invoice_id, name, unit_price, quantity, total_price, tax_label, tax_rate, tax_amount)
            VALUES ('item-log-test', 'inv-log-test', 'Service', 100.00, 1.0, 100.00, 'A', 15.00, 13.04)
        ");
        $stmtItem->execute();

        $this->vmsService->fiscalizeInvoice('inv-log-test');

        $stmtLogs = $this->pdo->query("SELECT event_type FROM vms_logs WHERE invoice_id = 'inv-log-test'");
        $eventTypes = $stmtLogs->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('FISCALIZATION_REQ', $eventTypes);
        $this->assertContains('FISCALIZATION_RESP', $eventTypes);
    }
}
