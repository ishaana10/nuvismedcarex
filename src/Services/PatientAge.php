<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

/**
 * Exact age from a date of birth. The stored `age` column is whole years at
 * the time of the last save, so it goes stale and says nothing useful for a
 * baby; paediatric prescribing needs the real figure.
 */
final class PatientAge
{
    /** Children below this many whole years get their age printed on prescriptions. */
    public const CHILD_BELOW_YEARS = 15;

    public static function years(string $dob, ?string $today = null): ?int
    {
        $d = self::parse($dob);
        return $d ? (int) $d->diff(new \DateTimeImmutable($today ?? 'today'))->y : null;
    }

    public static function isChild(string $dob, ?string $today = null): bool
    {
        $y = self::years($dob, $today);
        return $y !== null && $y < self::CHILD_BELOW_YEARS;
    }

    /** "3 yrs 4 mths", "7 mths", "12 days" ; '' when the date is unusable. */
    public static function exact(string $dob, ?string $today = null): string
    {
        $d = self::parse($dob);
        if (!$d) return '';
        $t = new \DateTimeImmutable($today ?? 'today');
        if ($d > $t) return '';
        $i = $d->diff($t);
        if ($i->y >= 1) {
            $s = $i->y . ($i->y === 1 ? ' yr' : ' yrs');
            return $i->m > 0 ? $s . ' ' . $i->m . ($i->m === 1 ? ' mth' : ' mths') : $s;
        }
        if ($i->m >= 1) return $i->m . ($i->m === 1 ? ' mth' : ' mths') . ($i->d > 0 ? ' ' . $i->d . ($i->d === 1 ? ' day' : ' days') : '');
        return $i->d . ($i->d === 1 ? ' day' : ' days');
    }

    private static function parse(string $dob): ?\DateTimeImmutable
    {
        $dob = trim($dob);
        if ($dob === '' || str_starts_with($dob, '0000')) return null;
        try { return new \DateTimeImmutable($dob); } catch (\Throwable) { return null; }
    }
}
