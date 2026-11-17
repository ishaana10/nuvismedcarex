<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The top panel and dashboard greeting must show the person who is signed in,
 * never the "acting clinician" (which, for front-desk staff, is just the first doctor).
 */
final class SignedInIdentityTest extends TestCase
{
    public function testHeaderShowsSignedInUserNotActingClinician(): void
    {
        $h = file_get_contents(__DIR__ . '/../includes/header.php');
        $this->assertStringContainsString('$nxMe', $h);
        $this->assertStringNotContainsString("htmlspecialchars(\$currentDoctor['name'] ?? \$nxUserName)", $h);
        $this->assertStringNotContainsString("nxInitials(\$currentDoctor['name']", $h);
        $this->assertStringContainsString('$nxIsClinical && count($doctors) > 1', $h, 'front desk gets no acting-clinician picker');
    }

    public function testDashboardGreetsTheSignedInUser(): void
    {
        $i = file_get_contents(__DIR__ . '/../index.php');
        $this->assertStringContainsString('$displayName = $nxUserName', $i);
        $this->assertStringNotContainsString("\$displayName = \$currentDoctor['name']", $i);
    }
}
