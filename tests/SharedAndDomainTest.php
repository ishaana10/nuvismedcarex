<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use ClinicFlow\Domain\ValueObjects\Money;
use ClinicFlow\Domain\ValueObjects\MRN;
use ClinicFlow\Domain\ValueObjects\TIN;
use ClinicFlow\Shared\TenantContext;

class SharedAndDomainTest extends TestCase {
    public function testMoneyValueObject(): void {
        $m1 = Money::fromFloat(100.50, 'FJD');
        $m2 = Money::fromFloat(49.50, 'FJD');

        $sum = $m1->add($m2);
        $this->assertEquals(150.00, $sum->getAmountFloat());
        $this->assertEquals(15000, $sum->getAmountCents());
        $this->assertEquals('FJD 150.00', $sum->format());

        $diff = $m1->subtract($m2);
        $this->assertEquals(51.00, $diff->getAmountFloat());
    }

    public function testMrnAndTinValueObjects(): void {
        $mrn = new MRN('MRN-9988');
        $this->assertEquals('MRN-9988', (string)$mrn);

        $tin = new TIN('502579006');
        $this->assertEquals('502579006', (string)$tin);
    }

    public function testTenantContext(): void {
        TenantContext::clear();
        TenantContext::setTenantId('clinic-suva-01');

        $this->assertEquals('clinic-suva-01', TenantContext::getTenantId());

        TenantContext::clear();
        $this->assertEquals('default-clinic', TenantContext::getTenantId());
    }
}
