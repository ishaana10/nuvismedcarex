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
        $_SESSION['authenticated'] = true;
        $_SESSION['tenant_id'] = 'session-clinic';
        TenantContext::clear();
        $this->assertEquals('session-clinic', TenantContext::resolveTenantId());

        // An unauthenticated session value must NOT be trusted over the host.
        unset($_SESSION['authenticated']);
        TenantContext::clear();
        $this->assertEquals('clinic2', TenantContext::resolveTenantId());
    }

    public function testTenantHeaderIsIgnoredForSignedInSession(): void
    {
        $_SESSION['authenticated'] = true;
        $_SESSION['tenant_id'] = 'clinic-a';
        $_SERVER['HTTP_X_TENANT_ID'] = 'clinic-b';
        TenantContext::clear();
        $this->assertEquals('clinic-a', TenantContext::getTenantId());

        // Even an explicit override cannot switch away from the session tenant.
        TenantContext::setTenantId('clinic-b');
        $this->assertEquals('clinic-a', TenantContext::getTenantId());
    }

    public function testTenantHeaderIsIgnoredPreAuth(): void
    {
        $_SERVER['HTTP_X_TENANT_ID'] = 'clinic-b';
        $_SERVER['HTTP_HOST'] = '127.0.0.1:8000';
        TenantContext::clear();
        $this->assertEquals('default-clinic', TenantContext::resolveTenantId());
    }

    public function testAuthenticatedSessionWithoutTenantFailsClosed(): void
    {
        $_SESSION['authenticated'] = true;
        unset($_SESSION['tenant_id']);
        TenantContext::clear();
        $this->expectException(\RuntimeException::class);
        TenantContext::getTenantId();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_TENANT_ID'],
              $_SESSION['tenant_id'], $_SESSION['authenticated']);
        TenantContext::clear();
        parent::tearDown();
    }
}
