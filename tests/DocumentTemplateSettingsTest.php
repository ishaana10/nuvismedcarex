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
}
