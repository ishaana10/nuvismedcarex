<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The shared password rule used by every place a password is set. */
final class PasswordPolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../includes/security.php';
    }

    public function testAcceptsAStrongPassword(): void
    {
        $this->assertNull(nxPasswordProblem('Clinic#Door2026x'));
    }

    /** @dataProvider weak */
    public function testRejectsWeakPasswords(string $pw): void
    {
        $this->assertNotNull(nxPasswordProblem($pw));
    }

    public static function weak(): array
    {
        return [
            'too short'        => ['Ab1cdefg'],
            'no capital'       => ['abcdefghij1'],
            'no small letter'  => ['ABCDEFGHIJ1'],
            'no number'        => ['Abcdefghijk'],
            'common word'      => ['Password12345'],
            'too long'         => [str_repeat('Aa1', 80)],
        ];
    }
}
