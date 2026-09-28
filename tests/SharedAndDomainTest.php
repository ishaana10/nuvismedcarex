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

        // Test IP address host resolution
        $_SERVER['HTTP_HOST'] = '127.0.0.1:8000';
        TenantContext::clear();
        $this->assertEquals('default-clinic', TenantContext::resolveTenantId());

        // Test session precedence over subdomain host
        $_SERVER['HTTP_HOST'] = 'clinic2.example.com';
        $_SESSION['tenant_id'] = 'session-clinic';
        TenantContext::clear();
        $this->assertEquals('session-clinic', TenantContext::resolveTenantId());

        unset($_SERVER['HTTP_HOST'], $_SESSION['tenant_id']);
        TenantContext::clear();
    }
}
