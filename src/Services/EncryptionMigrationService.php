<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use ClinicFlow\Utils\Encryption;
use PDO;
use Throwable;

/**
 * Moves encrypted clinical data from the old, publicly known default key to the
 * operator's own app_key.
 *
 * Safe by design:
 *  - Refuses to run until a real key is configured (otherwise it would just re-lock
 *    the data with the public key).
 *  - Each value is decrypted with the old key, re-encrypted with the new one, and the
 *    result is decrypted again and compared before it is written. The UPDATE only
 *    applies if the row still holds the value we read (no lost update).
 *  - Idempotent and resumable: rows already on the new key are skipped, so it can be
 *    stopped and run again at any time. A value that no key can open is never touched.
 *  - Works across all clinics, because the key is global to the installation.
 */
final class EncryptionMigrationService
{
    /** table => encrypted columns (every place the app writes Encryption::encrypt()). */
    public const ENCRYPTED_COLUMNS = [
        'soap_notes'  => ['subjective', 'objective', 'plan'],
        'past_visits' => ['soap_notes', 'vitals'],
    ];

    private const SCAN_CHUNK = 500;

    public function __construct(private PDO $pdo)
    {
    }

    /** Where the installation stands right now. Cheap enough to show on a page. */
    public function status(): array
    {
        $current = Encryption::configuredKeyMaterial();
        $usingDefault = Encryption::isUsingDefaultKey();
        $totals = ['plaintext' => 0, 'current_key' => 0, 'legacy_key' => 0, 'unreadable' => 0, 'empty' => 0];
        $byColumn = [];

        foreach ($this->targets() as [$table, $col]) {
            $c = ['plaintext' => 0, 'current_key' => 0, 'legacy_key' => 0, 'unreadable' => 0, 'empty' => 0];
            $this->eachValue($table, $col, function (string $id, ?string $value) use (&$c, $current, $usingDefault) {
                $c[$this->classify($value, $current, $usingDefault)]++;
                return true;
            });
            $byColumn["$table.$col"] = $c;
            foreach ($c as $k => $n) {
                $totals[$k] += $n;
            }
        }

        return [
            'key_configured'    => !$usingDefault,
            'legacy_fallback'   => Encryption::legacyFallbackAllowed(),
            'totals'            => $totals,
            'columns'           => $byColumn,
            'needs_migration'   => $totals['legacy_key'],
            'ready_to_migrate'  => !$usingDefault,
        ];
    }

    /**
     * Re-encrypt up to $maxSeconds worth of rows.
     *
     * @param bool $encryptPlaintext also encrypt values that were stored unencrypted
     * @return array{done:bool, migrated:int, skipped_unreadable:int, remaining:int, error?:string}
     */
    public function migrate(int $maxSeconds = 20, bool $encryptPlaintext = false, bool $dryRun = false): array
    {
        $current = Encryption::configuredKeyMaterial();
        if (Encryption::isUsingDefaultKey() || $current === null) {
            return ['done' => false, 'migrated' => 0, 'skipped_unreadable' => 0, 'remaining' => 0,
                'error' => 'No app_key is configured. Set one first (the default key is public), then run this again.'];
        }

        $deadline = microtime(true) + max(1, $maxSeconds);
        $migrated = 0;
        $unreadable = 0;
        $timedOut = false;

        foreach ($this->targets() as [$table, $col]) {
            $this->eachValue($table, $col, function (string $id, ?string $value) use (
                $table, $col, $current, $encryptPlaintext, $dryRun, $deadline, &$migrated, &$unreadable, &$timedOut
            ) {
                if (microtime(true) > $deadline) {
                    $timedOut = true;
                    return false; // stop scanning; the caller runs us again
                }
                $kind = $this->classify($value, $current, false);
                if ($kind === 'unreadable') {
                    $unreadable++;
                    return true;
                }
                if ($kind === 'legacy_key') {
                    $plain = Encryption::decryptStrict($value, Encryption::LEGACY_DEFAULT_KEY);
                } elseif ($kind === 'plaintext' && $encryptPlaintext) {
                    $plain = $value;
                } else {
                    return true; // already on the new key, empty, or left as stored
                }
                if ($plain === null) {
                    return true;
                }

                $new = Encryption::encryptWith($plain, $current);
                if (Encryption::decryptStrict($new, $current) !== $plain) {
                    return true; // never write something we cannot read back
                }
                if (!$dryRun && !$this->swap($table, $col, $id, $value, $new)) {
                    return true; // changed underneath us; the next run will pick it up
                }
                $migrated++;
                return true;
            });
            if ($timedOut) {
                break;
            }
        }

        $remaining = $dryRun ? max(0, $this->status()['needs_migration'] - $migrated) : $this->status()['needs_migration'];
        return [
            'done'               => !$timedOut && $remaining === 0,
            'migrated'           => $migrated,
            'skipped_unreadable' => $unreadable,
            'remaining'          => $remaining,
            'dry_run'            => $dryRun,
        ];
    }

    // ----------------------------------------------------------------- internals

    /** @return array<int, array{0:string,1:string}> */
    private function targets(): array
    {
        $out = [];
        foreach (self::ENCRYPTED_COLUMNS as $table => $cols) {
            if (!$this->tableExists($table)) {
                continue;
            }
            foreach ($cols as $col) {
                $out[] = [$table, $col];
            }
        }
        return $out;
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return 'empty'|'plaintext'|'current_key'|'legacy_key'|'unreadable' */
    private function classify(?string $value, ?string $current, bool $usingDefault): string
    {
        if ($value === null || $value === '') {
            return 'empty';
        }
        if (!Encryption::isEncrypted($value)) {
            return 'plaintext';
        }
        if (!$usingDefault && $current !== null && Encryption::decryptStrict($value, $current) !== null) {
            return 'current_key';
        }
        if (Encryption::decryptStrict($value, Encryption::LEGACY_DEFAULT_KEY) !== null) {
            // With no real key configured the legacy key IS the current key.
            return $usingDefault ? 'current_key' : 'legacy_key';
        }
        return 'unreadable';
    }

    /**
     * Visit every row's value in id order, in chunks. The callback returns false to stop.
     * Table/column names come from the constant above, never from input.
     */
    private function eachValue(string $table, string $col, callable $fn): void
    {
        $last = '';
        while (true) {
            $stmt = $this->pdo->prepare("SELECT id, {$col} AS v FROM {$table} WHERE id > ? ORDER BY id LIMIT " . self::SCAN_CHUNK);
            $stmt->execute([$last]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                return;
            }
            foreach ($rows as $row) {
                $last = (string) $row['id'];
                if ($fn($last, $row['v'] === null ? null : (string) $row['v']) === false) {
                    return;
                }
            }
            if (count($rows) < self::SCAN_CHUNK) {
                return;
            }
        }
    }

    /** Replace the value only if the row still holds what we read. */
    private function swap(string $table, string $col, string $id, string $old, string $new): bool
    {
        $stmt = $this->pdo->prepare("UPDATE {$table} SET {$col} = ? WHERE id = ? AND {$col} = ?");
        $stmt->execute([$new, $id, $old]);
        return $stmt->rowCount() === 1;
    }
}
