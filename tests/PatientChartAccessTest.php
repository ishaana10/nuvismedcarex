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

    public function testFrontDeskCancelsButOnlyDoctorsAndAdminsDelete(): void
    {
        $ac = $this->ac();
        $this->assertTrue($ac->can('Receptionist', 'appointments', AccessControl::EDIT), 'receptionist may cancel');
        $this->assertFalse($ac->can('Receptionist', 'appointments', AccessControl::FULL), 'receptionist may not delete');
        $this->assertTrue($ac->can('Doctor', 'appointments', AccessControl::FULL));
        $this->assertTrue($ac->can('Administrator', 'appointments', AccessControl::FULL));
        $this->assertFalse($ac->can('Nurse', 'appointments', AccessControl::FULL));
    }

    public function testAppointmentPageOffersCancelAndPatientSearch(): void
    {
        $src = file_get_contents(__DIR__ . '/../appointment.php');
        $this->assertStringContainsString('actions/appointment_cancel.php', $src);
        $this->assertStringContainsString('$nxCanDeleteAppt', $src);
        $this->assertStringContainsString('actions/patient_lookup.php', $src);
        $this->assertStringNotContainsString('<select name="patient_id"', $src, 'patient list must be searchable, not a giant dropdown');
        $map = file_get_contents(__DIR__ . '/../includes/access.php');
        $this->assertMatchesRegularExpression("#appointment_cancel\\.php'\\s*=>\\s*\\['appointments', 'edit'\\]#", $map);
        $this->assertMatchesRegularExpression("#appointment_delete\\.php'\\s*=>\\s*\\['appointments', 'full'\\]#", $map);
    }

    public function testLabResultFormDoesNotRevealWhereFilesAreStored(): void
    {
        $src = file_get_contents(__DIR__ . '/../includes/lab_order_modal.php');
        $this->assertStringNotContainsString('Where it will be kept', $src);
        $this->assertStringNotContainsString('Google Drive', $src);
        $this->assertStringNotContainsString('Cloud::labels', $src);
    }
}
