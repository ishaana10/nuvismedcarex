<?php
declare(strict_types=1);

use ClinicFlow\Services\PatientIntake;
use PHPUnit\Framework\TestCase;

final class PatientIntakeTest extends TestCase
{
    private function today(): DateTimeImmutable { return new DateTimeImmutable('2026-11-24'); }

    public function testDobRules(): void
    {
        $t = $this->today();
        $this->assertNull(PatientIntake::dobProblem('1990-05-01', $t));
        $this->assertNull(PatientIntake::dobProblem('2026-11-24', $t));
        $this->assertNotNull(PatientIntake::dobProblem('2030-01-01', $t));
        $this->assertNotNull(PatientIntake::dobProblem('1850-01-01', $t));
        $this->assertNotNull(PatientIntake::dobProblem('2020-02-31', $t));
        $this->assertNotNull(PatientIntake::dobProblem('', $t));
    }

    public function testPhoneRules(): void
    {
        $this->assertNull(PatientIntake::phoneProblem('+679 998 1234'));
        $this->assertNull(PatientIntake::phoneProblem('(679) 998-1234'));
        $this->assertNotNull(PatientIntake::phoneProblem('abc'));
        $this->assertNotNull(PatientIntake::phoneProblem('123'));
        $this->assertNotNull(PatientIntake::phoneProblem(''));
        $this->assertNull(PatientIntake::phoneProblem('', false));
    }

    public function testEmail(): void
    {
        $this->assertNull(PatientIntake::emailProblem(''));
        $this->assertNull(PatientIntake::emailProblem('a@b.fj'));
        $this->assertNotNull(PatientIntake::emailProblem('nope'));
    }

    public function testDuplicateDetection(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE patients (id TEXT, tenant_id TEXT, mrn TEXT, first_name TEXT, last_name TEXT, dob TEXT)');
        $pdo->exec("INSERT INTO patients VALUES ('p1','t1','#10001','Mereani','Tuilagi','1990-05-01')");
        $this->assertSame('#10001', PatientIntake::findDuplicate($pdo, 't1', ' mereani', 'TUILAGI ', '1990-05-01')['mrn']);
        $this->assertNull(PatientIntake::findDuplicate($pdo, 't2', 'Mereani', 'Tuilagi', '1990-05-01'));
        $this->assertNull(PatientIntake::findDuplicate($pdo, 't1', 'Mereani', 'Tuilagi', '1991-05-01'));
        $this->assertNull(PatientIntake::findDuplicate($pdo, 't1', 'Mereani', 'Tuilagi', '1990-05-01', 'p1'));
    }
}
