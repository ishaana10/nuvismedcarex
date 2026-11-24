<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use ClinicFlow\Services\TaxService;
use PDO;

class TaxServiceTest extends TestCase
{
    private PDO $pdo;
    private TaxService $tax;

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
                tenant_id TEXT DEFAULT 'default-clinic',
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
                cashier TEXT DEFAULT 'Admin',
                ref_no TEXT,
                total_tax REAL DEFAULT 0.00,
                payment_methods TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE invoice_items (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL DEFAULT 'default-clinic',
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
        ");

        $this->tax = new TaxService($this->pdo);
    }

    public function testTaxCalculation(): void
    {
        // 100 FJD inclusive of 15% VAT
        $calc = $this->tax->calculateItemTax(100.00, 'A');
        $this->assertEquals(15.00, $calc['tax_rate']);
        $this->assertEquals(13.0435, round($calc['tax_amount'], 4));
        $this->assertEquals(86.9565, round($calc['net_amount'], 4));

        // Exempt 0%
        $calcExempt = $this->tax->calculateItemTax(100.00, 'E');
        $this->assertEquals(0.00, $calcExempt['tax_amount']);
    }

    public function testInvoiceCancellation(): void
    {
        // Create initial invoice
        $stmt = $this->pdo->prepare("
            INSERT INTO invoices (id, invoice_number, patient_name, patient_mrn, service_date, due_date, amount, status, patient_owed, invoice_type, transaction_type)
            VALUES ('inv-orig-1', 'INV-ORIG-1', 'John Smith', 'MRN-200', '2023-10-24', '2023-11-24', 150.00, 'Paid', 0.00, 'Normal', 'Sale')
        ");
        $stmt->execute();

        $stmtItem = $this->pdo->prepare("
            INSERT INTO invoice_items (id, invoice_id, name, unit_price, quantity, total_price, tax_label, tax_rate, tax_amount)
            VALUES ('item-orig-1', 'inv-orig-1', 'Blood Test', 150.00, 1.0, 150.00, 'A', 15.00, 19.57)
        ");
        $stmtItem->execute();

        // Cancel
        $cancelInvId = $this->tax->cancelInvoice('inv-orig-1', 'Admin');

        $stmtCancel = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmtCancel->execute([$cancelInvId]);
        $cancelInv = $stmtCancel->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('Refund', $cancelInv['transaction_type']);
        $this->assertSame('INV-ORIG-1', $cancelInv['ref_no']); // points back at the original

        // Original status
        $stmtOrigCheck = $this->pdo->prepare("SELECT status FROM invoices WHERE id = 'inv-orig-1'");
        $stmtOrigCheck->execute();
        $this->assertEquals('Cancelled', $stmtOrigCheck->fetchColumn());
    }

    public function testDailySummary(): void
    {
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare("
            INSERT INTO invoices (id, invoice_number, patient_name, patient_mrn, service_date, due_date, amount, status, invoice_type, transaction_type, total_tax, created_at, payment_methods)
            VALUES ('inv-rep-1', 'INV-REP-1', 'Alice', 'MRN-300', ?, ?, 100.00, 'Paid', 'Normal', 'Sale', 13.04, ?, '[{\"type\":\"Cash\",\"amount\":100.00}]')
        ");
        $stmt->execute([$today, $today, $today . ' 10:00:00']);

        $report = $this->tax->getDailySummary($today);

        $this->assertEquals(1, $report['total_invoices']);
        $this->assertEquals(100.00, $report['total_sales']);
        $this->assertEquals(13.04, $report['total_vat']);
        $this->assertEquals(100.00, $report['by_payment']['Cash']);
    }

    public function testTaxCalculationSpecialAndZeroRates(): void
    {
        // Zero-rated rate 'F' (0%)
        $calcF = $this->tax->calculateItemTax(200.00, 'F');
        $this->assertEquals(0.00, $calcF['tax_rate']);
        $this->assertEquals(0.00, $calcF['tax_amount']);
        $this->assertEquals(200.00, $calcF['net_amount']);

        // Special rate 'P' (0.25%)
        $calcP = $this->tax->calculateItemTax(1000.00, 'P');
        $this->assertEquals(0.25, $calcP['tax_rate']);
        $this->assertEquals(2.4938, round($calcP['tax_amount'], 4));

        // Custom rate loaded from settings
        $stmt = $this->pdo->prepare("INSERT INTO clinic_settings (setting_key, setting_value) VALUES ('tax_rate_a', '12.50')");
        $stmt->execute();

        $customService = new TaxService($this->pdo);
        $calcCustom = $customService->calculateItemTax(100.00, 'A');
        $this->assertEquals(12.50, $calcCustom['tax_rate']);
    }
}
