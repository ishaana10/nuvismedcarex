<?php
declare(strict_types=1);

namespace ClinicFlow\Tests;

use ClinicFlow\Services\DocumentTemplateSettings as D;
use PHPUnit\Framework\TestCase;

final class DocumentTemplateSettingsTest extends TestCase
{
    public function testCertificateDefaultsToClassicWithCaduceus(): void
    {
        $d = D::defaults('certificate');
        $this->assertSame('classic', $d['layout']);
        $this->assertTrue($d['show_caduceus']);
        $this->assertFileExists(__DIR__ . '/../' . D::CADUCEUS);
    }

    public function testPrescriptionHasNoCertificateOnlyFields(): void
    {
        $d = D::defaults('prescription');
        $this->assertArrayNotHasKey('layout', $d);
        $this->assertArrayNotHasKey('show_caduceus', $d);
    }

    public function testLayoutMustBeOneOfTheKnownTemplates(): void
    {
        $cur = D::defaults('certificate');
        $this->assertSame('form', D::sanitise('certificate', ['layout' => 'form'], $cur)['layout']);
        $this->assertSame('classic', D::sanitise('certificate', ['layout' => '<script>'], $cur)['layout']);
    }

    public function testUnticketedTogglesAreSwitchedOff(): void
    {
        $out = D::sanitise('certificate', ['layout' => 'form'], D::defaults('certificate'));
        $this->assertFalse($out['show_caduceus']);
        $out = D::sanitise('certificate', ['show_caduceus' => '1'], D::defaults('certificate'));
        $this->assertTrue($out['show_caduceus']);
    }

    public function testCategoriesAreTrimmedDedupedAndCapped(): void
    {
        $lines = ["  Cardiac  ", "Cardiac", "", str_repeat('x', 80)];
        for ($i = 0; $i < 30; $i++) $lines[] = "Item $i";
        $out = D::sanitise('certificate', ['problem_categories' => implode("\n", $lines)], D::defaults('certificate'));
        $cats = D::categories($out);
        $this->assertSame('Cardiac', $cats[0]);
        $this->assertCount(15, $cats);
        $this->assertSame(40, mb_strlen($cats[1]));
        $this->assertSame(count($cats), count(array_unique($cats)));
    }

    public function testEmptyCategoryListFallsBackToDefaults(): void
    {
        $out = D::sanitise('certificate', ['problem_categories' => " \n "], D::defaults('certificate'));
        $this->assertSame(D::DEFAULT_CATEGORIES, D::categories($out));
    }

    public function testFormTextIsLengthLimited(): void
    {
        $out = D::sanitise('certificate', ['doctor_line' => str_repeat('a', 500), 'consultation_in' => str_repeat('b', 900)], D::defaults('certificate'));
        $this->assertSame(160, mb_strlen($out['doctor_line']));
        $this->assertSame(300, mb_strlen($out['consultation_in']));
    }

    // ---- certificate numbering -------------------------------------------------

    private function svc(array $cfgChanges = []): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE tenant_settings (id TEXT PRIMARY KEY, tenant_id TEXT, setting_key TEXT, setting_value TEXT, updated_at TEXT, UNIQUE (tenant_id, setting_key))");
        $pdo->exec("CREATE TABLE medical_certificates (id TEXT PRIMARY KEY, tenant_id TEXT, certificate_number TEXT, UNIQUE (tenant_id, certificate_number))");
        $svc = new D($pdo, 'clinicA');
        if ($cfgChanges) {
            $svc->save('certificate', D::sanitise('certificate', $cfgChanges, $svc->load('certificate')));
        }
        return [$svc, $pdo];
    }

    private function issue(D $svc, \PDO $pdo, string $tenant, string $date): string
    {
        $i = 0;
        return $svc->issueCertificateNumber(new \DateTimeImmutable($date), function (string $n) use ($pdo, $tenant, &$i) {
            $pdo->prepare("INSERT INTO medical_certificates (id, tenant_id, certificate_number) VALUES (?, ?, ?)")->execute([uniqid('c', true), $tenant, $n]);
        });
    }

    public function testDefaultNumberKeepsTheExistingShape(): void
    {
        [$svc, $pdo] = $this->svc();
        $this->assertSame('MC-20261117-0001', $this->issue($svc, $pdo, 'clinicA', '2026-11-17 09:00'));
        $this->assertSame('MC-20261117-0002', $this->issue($svc, $pdo, 'clinicA', '2026-11-17 10:00'));
    }

    public function testCountCarriesOnAfterNumbersAlreadyIssued(): void
    {
        [$svc, $pdo] = $this->svc();
        $pdo->exec("INSERT INTO medical_certificates VALUES ('old', 'clinicA', 'MC-20261117-1281')");
        $this->assertSame('MC-20261117-1282', $this->issue($svc, $pdo, 'clinicA', '2026-11-17 11:00'));
    }

    public function testCountRestartsEachDayByDefault(): void
    {
        [$svc, $pdo] = $this->svc();
        $this->issue($svc, $pdo, 'clinicA', '2026-11-17 09:00');
        $this->issue($svc, $pdo, 'clinicA', '2026-11-17 09:30');
        $this->assertSame('MC-20261118-0001', $this->issue($svc, $pdo, 'clinicA', '2026-11-18 08:00'));
    }

    public function testClinicLettersDateAndDigitsAreCustomisable(): void
    {
        [$svc, $pdo] = $this->svc(['number_prefix' => ' mt-mc ', 'number_date' => 'Ym', 'number_separator' => '/', 'number_digits' => '6', 'number_reset' => 'monthly', 'layout' => 'form']);
        $this->assertSame('MTMC', $svc->load('certificate')['number_prefix']);
        $this->assertSame('MTMC/202611/000001', $this->issue($svc, $pdo, 'clinicA', '2026-11-17'));
    }

    public function testDifferentClinicsGetDifferentNumbers(): void
    {
        [$a, $pdo] = $this->svc(['number_prefix' => 'ALPHA']);
        $b = new D($pdo, 'clinicB');
        $b->save('certificate', D::sanitise('certificate', ['number_prefix' => 'BRAVO'], $b->load('certificate')));
        $na = $this->issue($a, $pdo, 'clinicA', '2026-11-17');
        $nb = $this->issue($b, $pdo, 'clinicB', '2026-11-17');
        $this->assertSame('ALPHA-20261117-0001', $na);
        $this->assertSame('BRAVO-20261117-0001', $nb);
        $this->assertNotSame($na, $nb);
    }

    public function testRestartCannotBeMoreFrequentThanTheDateInTheNumber(): void
    {
        $this->assertSame('never', D::coherentReset('none', 'daily'));
        $this->assertSame('yearly', D::coherentReset('Y', 'daily'));
        $this->assertSame('monthly', D::coherentReset('Ym', 'daily'));
        $this->assertSame('daily', D::coherentReset('Ymd', 'daily'));
        $this->assertSame('yearly', D::coherentReset('Ymd', 'yearly'));
        [$svc, $pdo] = $this->svc(['number_date' => 'none', 'number_reset' => 'daily', 'number_prefix' => 'X']);
        $this->assertSame('never', $svc->load('certificate')['number_reset']);
        $this->assertSame('X-0001', $this->issue($svc, $pdo, 'clinicA', '2026-11-17'));
        $this->assertSame('X-0002', $this->issue($svc, $pdo, 'clinicA', '2026-11-18'));
    }

    public function testNextCountCanBeSetToContinueFromAPaperPad(): void
    {
        [$svc, $pdo] = $this->svc();
        $svc->setCertificateCounter('20261117', 5032 - 1);
        $this->assertSame('MC-20261117-5032', $this->issue($svc, $pdo, 'clinicA', '2026-11-17'));
        $this->assertSame('MC-20261117-5033', $svc->previewNextNumber(new \DateTimeImmutable('2026-11-17')));
    }

    public function testATakenNumberIsSkippedNotReused(): void
    {
        [$svc, $pdo] = $this->svc();
        $first = true;
        $n = $svc->issueCertificateNumber(new \DateTimeImmutable('2026-11-17'), function (string $num) use (&$first, $pdo) {
            if ($first) { // someone else took it between the check and the insert
                $first = false;
                $pdo->exec("INSERT INTO medical_certificates VALUES ('race', 'clinicA', '$num')");
            }
            $pdo->prepare("INSERT INTO medical_certificates (id, tenant_id, certificate_number) VALUES (?, ?, ?)")->execute([uniqid('c', true), 'clinicA', $num]);
        });
        $this->assertSame('MC-20261117-0002', $n);
    }
}
