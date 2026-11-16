<?php
declare(strict_types=1);

namespace Tests;

use ClinicFlow\Services\PatientAge;
use ClinicFlow\Services\PatientIdentifiers;
use PDO;
use PHPUnit\Framework\TestCase;

final class PatientIdentifiersTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE patient_identifiers (
            id VARCHAR(50) PRIMARY KEY, tenant_id VARCHAR(50) NOT NULL, patient_id VARCHAR(50) NOT NULL,
            id_type VARCHAR(60) NOT NULL, id_number VARCHAR(100) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    }

    public function testCleanDropsBlanksRepeatsAndUsesCustomLabels(): void
    {
        $list = PatientIdentifiers::clean(
            ['NHN #', 'Other', 'NHN #', 'Voter ID', ''],
            ['', 'Library card', '', '', ''],
            ['  N123 ', 'L-77', 'N123', '', 'X9']
        );
        $this->assertSame([
            ['type' => 'NHN #', 'number' => 'N123'],
            ['type' => 'Library card', 'number' => 'L-77'],
            ['type' => 'Other', 'number' => 'X9'],
        ], $list);
    }

    public function testSaveReplacesAndSearchFindsByAnyId(): void
    {
        PatientIdentifiers::save($this->pdo, 't1', 'p1', [
            ['type' => 'BRN #', 'number' => 'BRN-5521'],
            ['type' => 'Driving Licence', 'number' => 'DL884412'],
        ]);
        PatientIdentifiers::save($this->pdo, 't2', 'p2', [['type' => 'BRN #', 'number' => 'BRN-5521']]);

        $this->assertCount(2, PatientIdentifiers::forPatient($this->pdo, 't1', 'p1'));

        $clause = PatientIdentifiers::searchClause($this->pdo, 'p.id', 'p.tenant_id', 'q');
        $this->assertNotSame('', $clause);
        $this->pdo->exec('CREATE TABLE p (id TEXT, tenant_id TEXT)');
        $this->pdo->exec("INSERT INTO p VALUES ('p1','t1'),('p2','t2')");
        $st = $this->pdo->prepare("SELECT id FROM p WHERE tenant_id = 't1' AND {$clause}");
        $st->execute(['q' => '%884412%']);
        $this->assertSame(['p1'], $st->fetchAll(PDO::FETCH_COLUMN));
        // another clinic's patient with the same number is never returned
        $st->execute(['q' => '%5521%']);
        $this->assertSame(['p1'], $st->fetchAll(PDO::FETCH_COLUMN));

        PatientIdentifiers::save($this->pdo, 't1', 'p1', [['type' => 'Voter ID', 'number' => 'V1']]);
        $rows = PatientIdentifiers::forPatient($this->pdo, 't1', 'p1');
        $this->assertSame([['type' => 'Voter ID', 'number' => 'V1']], $rows);
    }

    public function testMissingTableIsHarmless(): void
    {
        $bare = new PDO('sqlite::memory:');
        $this->assertSame('', PatientIdentifiers::searchClause($bare, 'id', 'tenant_id', 'q'));
        $this->assertSame([], PatientIdentifiers::forPatient($bare, 't', 'p'));
    }

    public function testAgeUnderFifteenIsExact(): void
    {
        $this->assertTrue(PatientAge::isChild('2020-03-10', '2026-11-18'));
        $this->assertFalse(PatientAge::isChild('2011-11-18', '2026-11-18'), '15th birthday today');
        $this->assertTrue(PatientAge::isChild('2011-11-19', '2026-11-18'));
        $this->assertSame('6 yrs 8 mths', PatientAge::exact('2020-03-10', '2026-11-18'));
        $this->assertSame('1 yr', PatientAge::exact('2025-11-18', '2026-11-18'));
        $this->assertSame('3 mths 5 days', PatientAge::exact('2026-08-13', '2026-11-18'));
        $this->assertSame('9 days', PatientAge::exact('2026-11-09', '2026-11-18'));
        $this->assertSame('', PatientAge::exact('', '2026-11-18'));
    }
}
