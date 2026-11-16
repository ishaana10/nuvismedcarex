<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use Throwable;

/**
 * The identity documents a patient can have on file: social welfare ID, NHN,
 * BRN, voter ID, driving licence, and so on. A patient may have any number of
 * them, and any of them finds the patient in search.
 *
 * Stored one per row in patient_identifiers (see the 2026_11_18 migration).
 * Every method tolerates the table not existing yet, so a clinic whose
 * migrations have not run keeps working without the feature.
 */
final class PatientIdentifiers
{
    public const OTHER = 'Other';
    public const MAX_PER_PATIENT = 10;

    /** Offered in the picker. "Other" lets the clinic type its own label. */
    public const TYPES = [
        'Social Welfare ID',
        'NHN #',
        'BRN #',
        'Voter ID',
        'Driving Licence',
        'Passport',
        'FNPF No.',
        'TIN',
        self::OTHER,
    ];

    /**
     * Turn the posted parallel arrays into a clean list of
     * ['type' => label, 'number' => value]. Blank rows and repeats are dropped.
     *
     * @param array<int,mixed> $types
     * @param array<int,mixed> $others custom label for rows whose type is "Other"
     * @param array<int,mixed> $numbers
     * @return list<array{type:string,number:string}>
     */
    public static function clean(array $types, array $others, array $numbers): array
    {
        $out = [];
        $seen = [];
        foreach ($numbers as $i => $raw) {
            $number = self::tidy((string) $raw, 60);
            if ($number === '') continue;
            $type = self::tidy((string) ($types[$i] ?? ''), 60);
            if ($type === self::OTHER || $type === '') {
                $type = self::tidy((string) ($others[$i] ?? ''), 60) ?: self::OTHER;
            }
            $key = mb_strtolower($type . '|' . $number);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = ['type' => $type, 'number' => $number];
            if (count($out) >= self::MAX_PER_PATIENT) break;
        }
        return $out;
    }

    /** @return list<array{type:string,number:string}> */
    public static function fromPost(array $post): array
    {
        return self::clean(
            (array) ($post['id_type'] ?? []),
            (array) ($post['id_other'] ?? []),
            (array) ($post['id_number'] ?? [])
        );
    }

    /** @return list<array{type:string,number:string}> */
    public static function forPatient(PDO $db, string $tenantId, string $patientId): array
    {
        if (!self::available($db)) return [];
        try {
            $st = $db->prepare(
                'SELECT id_type, id_number FROM patient_identifiers
                  WHERE tenant_id = ? AND patient_id = ? ORDER BY created_at, id_number'
            );
            $st->execute([$tenantId, $patientId]);
            return array_map(
                static fn(array $r): array => ['type' => (string) $r['id_type'], 'number' => (string) $r['id_number']],
                $st->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable $e) {
            error_log('[patient ids] read: ' . $e->getMessage());
            return [];
        }
    }

    /** Replace this patient's identifiers with the given list. */
    public static function save(PDO $db, string $tenantId, string $patientId, array $list): bool
    {
        if (!self::available($db)) return $list === [];
        try {
            $own = !$db->inTransaction();
            if ($own) $db->beginTransaction();
            $db->prepare('DELETE FROM patient_identifiers WHERE tenant_id = ? AND patient_id = ?')
               ->execute([$tenantId, $patientId]);
            $ins = $db->prepare(
                'INSERT INTO patient_identifiers (id, tenant_id, patient_id, id_type, id_number) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($list as $row) {
                $ins->execute([\ClinicFlow\Utils\Uuid::uuidv7(), $tenantId, $patientId, $row['type'], $row['number']]);
            }
            if ($own) $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[patient ids] save: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * SQL fragment true when one of the patient's IDs contains the bound
     * parameter. Returns '' when the table is not there, so callers can skip it.
     *
     * @param string $patientIdCol e.g. "id" or "p.id"
     * @param string $tenantIdCol  e.g. "tenant_id" or "p.tenant_id"
     */
    public static function searchClause(PDO $db, string $patientIdCol, string $tenantIdCol, string $param): string
    {
        if (!self::available($db)) return '';
        return "EXISTS (SELECT 1 FROM patient_identifiers pid WHERE pid.patient_id = {$patientIdCol}"
             . " AND pid.tenant_id = {$tenantIdCol} AND pid.id_number LIKE :{$param})";
    }

    public static function available(PDO $db): bool
    {
        static $known = null;
        $known ??= new \WeakMap();
        if (isset($known[$db])) return true;
        try {
            $db->query('SELECT 1 FROM patient_identifiers LIMIT 1');
            $known[$db] = true;
            return true;
        } catch (Throwable $e) {
            return false; // not cached: the migration may run later in this request
        }
    }

    private static function tidy(string $v, int $max): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', strip_tags($v)) ?? '');
        return mb_substr($v, 0, $max);
    }
}
