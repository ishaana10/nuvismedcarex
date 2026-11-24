<?php
declare(strict_types=1);

use ClinicFlow\Services\DocumentNumbers;
use PHPUnit\Framework\TestCase;

final class DocumentNumbersTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE patients (id TEXT, tenant_id TEXT, mrn TEXT, UNIQUE(tenant_id, mrn))');
        $this->pdo->exec('CREATE TABLE invoices (id TEXT, tenant_id TEXT, invoice_number TEXT, UNIQUE(tenant_id, invoice_number))');
    }

    private function add(string $table, string $col, string $tenant, string $value): void
    {
        $this->pdo->prepare("INSERT INTO $table (id, tenant_id, $col) VALUES (?, ?, ?)")->execute([uniqid(), $tenant, $value]);
    }

    public function testFirstNumberStartsWhereTheCallerSays(): void
    {
        $this->assertSame('#10001', DocumentNumbers::next($this->pdo, 't1', 'patients', 'mrn', '#', 5, 10001));
        $this->assertSame('INV-2026-0001', DocumentNumbers::next($this->pdo, 't1', 'invoices', 'invoice_number', 'INV-2026-', 4));
    }

    public function testContinuesAfterTheHighestExistingNumberEvenIfOldOnesWereRandom(): void
    {
        foreach (['#12345', '#98765', '#20000'] as $m) {
            $this->add('patients', 'mrn', 't1', $m);
        }
        $this->assertSame('#98766', DocumentNumbers::next($this->pdo, 't1', 'patients', 'mrn', '#', 5, 10001));
    }

    public function testClinicsKeepSeparateSequences(): void
    {
        $this->add('patients', 'mrn', 't1', '#10500');
        $this->assertSame('#10001', DocumentNumbers::next($this->pdo, 't2', 'patients', 'mrn', '#', 5, 10001));
    }

    public function testNumbersKeepGrowingPastTheirWidth(): void
    {
        $this->add('invoices', 'invoice_number', 't1', 'INV-2026-9999');
        $this->assertSame('INV-2026-10000', DocumentNumbers::next($this->pdo, 't1', 'invoices', 'invoice_number', 'INV-2026-', 4));
        $this->add('invoices', 'invoice_number', 't1', 'INV-2026-10000');
        $this->assertSame('INV-2026-10001', DocumentNumbers::next($this->pdo, 't1', 'invoices', 'invoice_number', 'INV-2026-', 4));
    }

    public function testAnotherYearOrKindOfInvoiceDoesNotInterfere(): void
    {
        $this->add('invoices', 'invoice_number', 't1', 'INV-2025-0500');
        $this->add('invoices', 'invoice_number', 't1', 'INV-CAN-77777');
        $this->assertSame('INV-2026-0001', DocumentNumbers::next($this->pdo, 't1', 'invoices', 'invoice_number', 'INV-2026-', 4));
    }

    public function testManyPatientsNeverClash(): void
    {
        $seen = [];
        for ($i = 0; $i < 400; $i++) {
            [$mrn] = DocumentNumbers::issue($this->pdo, 't1', 'patients', 'mrn', '#', 5, function (string $n): void {
                $this->add('patients', 'mrn', 't1', $n);
            }, 10001);
            $this->assertArrayNotHasKey($mrn, $seen);
            $seen[$mrn] = true;
        }
        $this->assertCount(400, $seen);
    }

    public function testSomeoneElseTakingTheNumberFirstMeansAnAutomaticRetry(): void
    {
        $calls = 0;
        [$n] = DocumentNumbers::issue($this->pdo, 't1', 'invoices', 'invoice_number', 'INV-2026-', 4, function (string $n) use (&$calls): void {
            $calls++;
            if ($calls === 1) {
                $this->add('invoices', 'invoice_number', 't1', $n); // the other request wins the race
            }
            $this->add('invoices', 'invoice_number', 't1', $n);      // ours: duplicate the first time
        });
        $this->assertSame(2, $calls);
        $this->assertSame('INV-2026-0002', $n);
    }

    public function testOtherDatabaseErrorsAreNotHidden(): void
    {
        $this->expectException(PDOException::class);
        DocumentNumbers::issue($this->pdo, 't1', 'invoices', 'invoice_number', 'INV-2026-', 4, function (): void {
            $this->pdo->exec('INSERT INTO no_such_table VALUES (1)');
        });
    }

    public function testOnlyKnownColumnsCanBeUsed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DocumentNumbers::next($this->pdo, 't1', 'users', 'password', 'x', 4);
    }

    public function testNothingStillIssuesRandomNumbers(): void
    {
        foreach (['actions/patient_add.php', 'src/Services/BillingService.php', 'actions/vms_create_invoice.php', 'includes/encounter_invoice.php'] as $f) {
            $this->assertDoesNotMatchRegularExpression('/rand\(\s*\d+\s*,\s*\d+\s*\)/', (string) file_get_contents(dirname(__DIR__) . '/' . $f), "$f issues random numbers");
        }
    }
}
