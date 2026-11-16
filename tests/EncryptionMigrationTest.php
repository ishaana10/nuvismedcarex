<?php
declare(strict_types=1);

namespace ClinicFlow\Tests;

use ClinicFlow\Services\EncryptionMigrationService;
use ClinicFlow\Utils\Encryption;
use PDO;
use PHPUnit\Framework\TestCase;

final class EncryptionMigrationTest extends TestCase
{
    private PDO $pdo;
    private string $newKey;

    protected function setUp(): void
    {
        putenv('APP_KEY'); // start from "no key configured"
        $this->newKey = bin2hex(random_bytes(32));
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE soap_notes (id TEXT PRIMARY KEY, subjective TEXT, objective TEXT, plan TEXT)');
        $this->pdo->exec('CREATE TABLE past_visits (id TEXT PRIMARY KEY, soap_notes TEXT, vitals TEXT)');
    }

    protected function tearDown(): void
    {
        putenv('APP_KEY');
    }

    private function legacy(string $plain): string
    {
        return Encryption::encryptWith($plain, Encryption::LEGACY_DEFAULT_KEY);
    }

    private function seed(): void
    {
        $ins = $this->pdo->prepare('INSERT INTO soap_notes VALUES (?,?,?,?)');
        $ins->execute(['s1', $this->legacy('headache'), $this->legacy('afebrile'), $this->legacy('rest')]);
        $ins->execute(['s2', Encryption::encryptWith('already new', $this->newKey), 'typed in plain', null]);
        $ins->execute(['s3', Encryption::encryptWith('someone elses key', 'a-completely-different-key-123'), '', $this->legacy('x')]);
        $this->pdo->prepare('INSERT INTO past_visits VALUES (?,?,?)')->execute(['v1', $this->legacy('{"a":1}'), $this->legacy('{"bp":"120/80"}')]);
    }

    public function testRefusesToRunWithoutARealKey(): void
    {
        $this->seed();
        $r = (new EncryptionMigrationService($this->pdo))->migrate();
        $this->assertSame(0, $r['migrated']);
        $this->assertArrayHasKey('error', $r);
        // nothing was re-locked with the public key: still readable with it
        $this->assertSame('headache', Encryption::decryptStrict(
            $this->pdo->query("SELECT subjective FROM soap_notes WHERE id='s1'")->fetchColumn(),
            Encryption::LEGACY_DEFAULT_KEY
        ));
    }

    public function testStatusClassifiesEveryValue(): void
    {
        $this->seed();
        putenv('APP_KEY=' . $this->newKey);
        $s = (new EncryptionMigrationService($this->pdo))->status();
        $this->assertTrue($s['key_configured']);
        $this->assertSame(6, $s['totals']['legacy_key']);   // s1 x3, s3.plan, v1 x2 => 3+1+2
        $this->assertSame(1, $s['totals']['current_key']);  // s2.subjective
        $this->assertSame(1, $s['totals']['plaintext']);    // s2.objective
        $this->assertSame(1, $s['totals']['unreadable']);   // s3.subjective (third-party key)
        $this->assertSame(6, $s['needs_migration']);
    }

    public function testMigratesLegacyRowsAndLeavesEverythingElseAlone(): void
    {
        $this->seed();
        putenv('APP_KEY=' . $this->newKey);
        $before = $this->pdo->query("SELECT subjective FROM soap_notes WHERE id='s3'")->fetchColumn();
        $svc = new EncryptionMigrationService($this->pdo);

        $r = $svc->migrate();
        $this->assertTrue($r['done']);
        $this->assertSame(6, $r['migrated']);
        $this->assertSame(1, $r['skipped_unreadable']);
        $this->assertSame(0, $r['remaining']);

        // Now only the new key opens them, and the public key no longer does.
        $v = $this->pdo->query("SELECT subjective FROM soap_notes WHERE id='s1'")->fetchColumn();
        $this->assertSame('headache', Encryption::decryptStrict($v, $this->newKey));
        $this->assertNull(Encryption::decryptStrict($v, Encryption::LEGACY_DEFAULT_KEY));
        $this->assertSame('{"bp":"120/80"}', Encryption::decryptStrict(
            $this->pdo->query("SELECT vitals FROM past_visits WHERE id='v1'")->fetchColumn(), $this->newKey));

        // Untouched: the third-party value, the typed-in plaintext, the already-migrated one.
        $this->assertSame($before, $this->pdo->query("SELECT subjective FROM soap_notes WHERE id='s3'")->fetchColumn());
        $this->assertSame('typed in plain', $this->pdo->query("SELECT objective FROM soap_notes WHERE id='s2'")->fetchColumn());
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->seed();
        putenv('APP_KEY=' . $this->newKey);
        $svc = new EncryptionMigrationService($this->pdo);
        $svc->migrate();
        $snapshot = $this->pdo->query('SELECT * FROM soap_notes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $r = $svc->migrate();
        $this->assertSame(0, $r['migrated']);
        $this->assertSame($snapshot, $this->pdo->query('SELECT * FROM soap_notes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testDryRunWritesNothing(): void
    {
        $this->seed();
        putenv('APP_KEY=' . $this->newKey);
        $snapshot = $this->pdo->query('SELECT * FROM soap_notes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $r = (new EncryptionMigrationService($this->pdo))->migrate(20, false, true);
        $this->assertSame(6, $r['migrated']);
        $this->assertTrue($r['dry_run']);
        $this->assertSame($snapshot, $this->pdo->query('SELECT * FROM soap_notes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testCanOptionallyEncryptPlaintext(): void
    {
        $this->seed();
        putenv('APP_KEY=' . $this->newKey);
        (new EncryptionMigrationService($this->pdo))->migrate(20, true);
        $v = $this->pdo->query("SELECT objective FROM soap_notes WHERE id='s2'")->fetchColumn();
        $this->assertTrue(Encryption::isEncrypted($v));
        $this->assertSame('typed in plain', Encryption::decryptStrict($v, $this->newKey));
    }

    public function testAppStillReadsOldRowsAfterKeyIsSetBeforeMigrating(): void
    {
        $old = $this->legacy('penicillin allergy');
        putenv('APP_KEY=' . $this->newKey);
        $this->assertSame('penicillin allergy', Encryption::decrypt($old));
        $this->assertNotSame($old, Encryption::encrypt('new note'));
        $this->assertNull(Encryption::decryptStrict(Encryption::encrypt('new note'), Encryption::LEGACY_DEFAULT_KEY));
    }

    public function testStrictDecryptReportsFailureInsteadOfReturningGarbage(): void
    {
        $ct = Encryption::encryptWith('secret', 'key-one-key-one-key-one');
        $this->assertNull(Encryption::decryptStrict($ct, 'key-two-key-two-key-two'));
        $this->assertNull(Encryption::decryptStrict('not encrypted', 'whatever-whatever-1'));
        $this->assertSame('secret', Encryption::decryptStrict($ct, 'key-one-key-one-key-one'));
    }
}
