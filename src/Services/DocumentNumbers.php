<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use PDOException;

/**
 * Issues patient numbers (MRN), invoice numbers and the like.
 *
 * Numbers used to be random (rand(10000, 99999)). With a unique constraint per clinic that
 * fails sooner than expected: a clinic with 300 patients had a 39% chance that some
 * registration had already crashed on a clash, and invoice numbers (only 9,999 a year) clashed
 * within days. Now each number is the next one after the highest the clinic already has, so
 * it never repeats, and two people saving at the same moment cannot both get the same number
 * (the loser retries with the following one).
 */
final class DocumentNumbers
{
    /** Tables and columns this may read; they are fixed names, never user input. */
    private const ALLOWED = [
        'patients' => ['mrn'],
        'invoices' => ['invoice_number'],
    ];

    /**
     * The next number for this clinic: <prefix><digits>, one higher than the highest existing.
     * Numbers keep growing past their padding width (0999 -> 1000 -> 10000).
     */
    public static function next(PDO $pdo, string $tenantId, string $table, string $column, string $prefix, int $width, int $first = 1): string
    {
        if (!isset(self::ALLOWED[$table]) || !in_array($column, self::ALLOWED[$table], true)) {
            throw new \InvalidArgumentException('Unsupported number sequence.');
        }
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
        // Longest first, then highest: gives the numerically highest even when widths differ
        $st = $pdo->prepare(
            "SELECT $column FROM $table WHERE tenant_id = ? AND $column LIKE ? ESCAPE '\\' ORDER BY LENGTH($column) DESC, $column DESC LIMIT 1"
        );
        $st->execute([$tenantId, $like]);
        $top = $st->fetchColumn();
        $n = $first;
        if ($top !== false && preg_match('/(\d+)$/', (string) $top, $m)) {
            $n = max($first, ((int) $m[1]) + 1);
        }
        return $prefix . str_pad((string) $n, $width, '0', STR_PAD_LEFT);
    }

    /**
     * Run $insert with a fresh number, retrying with the next one if somebody else took it.
     * @param callable(string): mixed $insert performs the INSERT using the given number
     * @return array{0:string,1:mixed} the number used and what $insert returned
     */
    public static function issue(PDO $pdo, string $tenantId, string $table, string $column, string $prefix, int $width, callable $insert, int $first = 1, int $tries = 8): array
    {
        $last = null;
        for ($i = 0; $i < $tries; $i++) {
            $number = self::next($pdo, $tenantId, $table, $column, $prefix, $width, $first);
            try {
                return [$number, $insert($number)];
            } catch (PDOException $e) {
                if (!self::isDuplicate($e)) {
                    throw $e;
                }
                $last = $e;
                usleep(random_int(2000, 25000)); // let the other request finish before we look again
            }
        }
        throw $last ?? new \RuntimeException('Could not issue a number.');
    }

    private static function isDuplicate(PDOException $e): bool
    {
        $state = (string) $e->getCode();
        return $state === '23000' || $state === '23505'
            || stripos($e->getMessage(), 'duplicate') !== false
            || stripos($e->getMessage(), 'UNIQUE constraint') !== false;
    }
}
