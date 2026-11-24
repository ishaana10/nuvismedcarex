<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;

/**
 * Checks on patient details when a chart is created or edited. A wrong birth date changes the
 * age used for paediatric doses, and a second chart for the same person splits their allergies
 * and history, so these are refused or flagged before anything is saved.
 */
final class PatientIntake
{
    public const MAX_AGE_YEARS = 120;

    /** @return string|null what is wrong with the date of birth, or null when it is fine */
    public static function dobProblem(string $dob, ?\DateTimeImmutable $today = null): ?string
    {
        $today ??= new \DateTimeImmutable('today');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        if (!$d || $d->format('Y-m-d') !== $dob) {
            return 'Enter the date of birth as a real date.';
        }
        if ($d > $today) {
            return 'The date of birth cannot be in the future.';
        }
        if ($d < $today->modify('-' . self::MAX_AGE_YEARS . ' years')) {
            return 'That date of birth makes the patient over ' . self::MAX_AGE_YEARS . ' years old. Please check it.';
        }
        return null;
    }

    /** Digits, spaces and + - ( ) . only, with 6 to 15 digits. */
    public static function phoneProblem(string $phone, bool $required = true): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return $required ? 'A phone number is required.' : null;
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (!preg_match('/^[0-9+()\-.\s]+$/', $phone) || strlen($digits) < 6 || strlen($digits) > 15) {
            return 'Enter a valid phone number, for example +679 998 1234.';
        }
        return null;
    }

    public static function emailProblem(string $email): ?string
    {
        $email = trim($email);
        return ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL)) ? null : 'Enter a valid email address or leave it blank.';
    }

    /** First problem found across the common fields, or null. */
    public static function firstProblem(array $post): ?string
    {
        return self::dobProblem(trim((string) ($post['dob'] ?? '')))
            ?? self::phoneProblem((string) ($post['phone'] ?? ''))
            ?? self::phoneProblem((string) ($post['emergency_contact_phone'] ?? ''), false)
            ?? self::emailProblem((string) ($post['email'] ?? ''));
    }

    /**
     * A chart in this clinic with the same name and date of birth (the usual sign of a
     * double registration).
     * @return array{id:string,mrn:string,name:string}|null
     */
    public static function findDuplicate(PDO $pdo, string $tenantId, string $first, string $last, string $dob, ?string $exceptId = null): ?array
    {
        $st = $pdo->prepare(
            "SELECT id, mrn, first_name, last_name FROM patients
             WHERE tenant_id = ? AND dob = ? AND LOWER(TRIM(first_name)) = ? AND LOWER(TRIM(last_name)) = ?" .
            ($exceptId !== null ? ' AND id <> ?' : '') . ' LIMIT 1'
        );
        $params = [$tenantId, $dob, mb_strtolower(trim($first)), mb_strtolower(trim($last))];
        if ($exceptId !== null) {
            $params[] = $exceptId;
        }
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id' => (string) $r['id'], 'mrn' => (string) $r['mrn'], 'name' => trim($r['first_name'] . ' ' . $r['last_name'])] : null;
    }
}
