<?php
declare(strict_types=1);

use ClinicFlow\Services\AccessControl;
use PHPUnit\Framework\TestCase;

/** Patient charts are for doctors and administrators; the front desk never opens them. */
final class PatientChartAccessTest extends TestCase
{
    private function ac(): AccessControl
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE tenant_settings (tenant_id TEXT, setting_key TEXT, setting_value TEXT)');
        return new AccessControl($pdo, 't1');
    }

    public function testOnlyDoctorsAndAdministratorsOpenCharts(): void
    {
        $ac = $this->ac();
        $this->assertTrue($ac->can('Doctor', 'charts', AccessControl::VIEW));
        $this->assertTrue($ac->can('Administrator', 'charts', AccessControl::VIEW));
        foreach (['Receptionist', 'Nurse', 'Staff', 'Some Custom Role'] as $role) {
            $this->assertFalse($ac->can($role, 'charts', AccessControl::VIEW), $role);
        }
    }

    public function testReceptionistStillRegistersAndBooks(): void
    {
        $ac = $this->ac();
        $this->assertTrue($ac->can('Receptionist', 'patients', AccessControl::EDIT));
        $this->assertTrue($ac->can('Receptionist', 'appointments', AccessControl::EDIT));
    }

    public function testChartPageIsGuardedBeforeAnythingIsRead(): void
    {
        $src = file_get_contents(__DIR__ . '/../patient_detail.php');
        $guard = strpos($src, "requireAccess('charts', 'view')");
        $this->assertNotFalse($guard);
        $this->assertLessThan(strpos($src, 'SELECT * FROM patients'), $guard);
    }
}
