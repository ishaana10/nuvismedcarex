<?php
declare(strict_types=1);

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/security.php';

final class SecurityHardeningTest extends TestCase
{
    public function testOtpDigestIsNotThePlainCodeAndFitsTheColumn(): void
    {
        $d = nxOtpDigest('123456');
        $this->assertNotSame('123456', $d);
        $this->assertSame(10, strlen($d), 'must fit password_resets.otp VARCHAR(10)');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $d);
    }

    public function testOtpDigestIsStableAndDistinctPerCode(): void
    {
        $this->assertSame(nxOtpDigest('654321'), nxOtpDigest('654321'));
        $this->assertNotSame(nxOtpDigest('654321'), nxOtpDigest('654322'));
    }

    public function testInvoiceCancelAndTaxRateEntryPointsAreDeveloperOnly(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../includes/access.php');
        foreach (['actions/cancel_invoice.php', 'actions/tax_save_settings.php'] as $script) {
            $this->assertMatchesRegularExpression(
                "#'" . preg_quote($script, '#') . "'\s*=>\s*\['developer', 'full'\]#",
                $src,
                "$script must require the developer module (Developer-only this release)"
            );
        }
        // Creating an ordinary invoice stays a normal billing permission.
        $this->assertMatchesRegularExpression("#'actions/create_invoice.php'\s*=>\s*\['billing', 'edit'\]#", $src);
    }

    public function testDeveloperControlEndpointsRequireCsrfForMutations(): void
    {
        foreach (['git_actions', 'redis_actions', 'integration_actions'] as $f) {
            $this->assertStringContainsString(
                'nxRequireCsrfForMutations(',
                (string) file_get_contents(__DIR__ . "/../actions/$f.php"),
                "$f.php must guard mutating actions"
            );
        }
    }
}
