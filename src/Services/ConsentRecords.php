<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;

/**
 * Consents a patient has actually given.
 *
 * Each record keeps a copy of the form as it read at the moment of signing,
 * so what the patient agreed to can always be shown back exactly, however the
 * template changes afterwards.
 */
final class ConsentRecords
{
    public const RELATIONSHIPS = ['Patient', 'Parent', 'Guardian', 'Spouse', 'Next of kin', 'Power of attorney'];

    private PDO $db;
    private string $tenantId;
    private AuditService $audit;

    public function __construct(PDO $db, ?string $tenantId = null, ?AuditService $audit = null)
    {
        $this->db = $db;
        $this->tenantId = $tenantId ?? \ClinicFlow\Shared\TenantContext::getTenantId();
        $this->audit = $audit ?? new AuditService($db);
    }

    /** Consents signed during one encounter. */
    public function forVisit(string $patientId, string $visitId): array
    {
        return $this->query("patient_id = :pid AND visit_id = :vid", ['pid' => $patientId, 'vid' => $visitId]);
    }

    /** Everything this patient has ever signed, newest first. */
    public function forPatient(string $patientId, int $limit = 100): array
    {
        return $this->query("patient_id = :pid", ['pid' => $patientId], $limit);
    }

    public function find(string $id): ?array
    {
        $rows = $this->query("id = :id", ['id' => $id], 1);
        return $rows[0] ?? null;
    }

    private function query(string $where, array $params, int $limit = 200): array
    {
        if (!ConsentForms::isAvailable($this->db)) {
            return [];
        }
        try {
            $st = $this->db->prepare(
                "SELECT * FROM consent_records WHERE tenant_id = :tid AND {$where} ORDER BY created_at DESC LIMIT {$limit}"
            );
            $st->execute($params + ['tid' => $this->tenantId]);
            return array_map(static function (array $row): array {
                $row['snapshot'] = json_decode((string) ($row['snapshot'] ?? '{}'), true) ?: [];
                $row['answers'] = json_decode((string) ($row['answers'] ?? '{}'), true) ?: [];
                $row['is_withdrawn'] = !empty($row['withdrawn_at']);
                return $row;
            }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (\Throwable $e) {
            error_log('[ConsentRecords] query: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Record a signed consent.
     *
     * @param array $form   the template, as returned by ConsentForms
     * @param array $input  the submitted answers and signatures
     * @throws \InvalidArgumentException when something required is missing
     */
    public function record(array $form, string $patientId, ?string $visitId, array $input): string
    {
        if (!ConsentForms::isAvailable($this->db)) {
            throw new \RuntimeException('This database has not been set up for consent forms yet. A developer can apply the pending database changes from Developer → Database.');
        }

        [$answers, $missing] = self::collectAnswers($form, $input);
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                count($missing) === 1
                    ? 'This still needs an answer: ' . $missing[0]
                    : 'These still need an answer: ' . implode('; ', array_slice($missing, 0, 4))
                      . (count($missing) > 4 ? ' and ' . (count($missing) - 4) . ' more' : '')
            );
        }

        $signature = InsuranceService::cleanSignature((string) ($input['patient_signature'] ?? ''));
        if ($signature === null) {
            throw new \InvalidArgumentException('The form has to be signed before it can be saved.');
        }

        $signedBy = mb_substr(trim((string) ($input['signed_by_name'] ?? '')), 0, 160);
        if ($signedBy === '') {
            throw new \InvalidArgumentException('Write the name of the person signing.');
        }
        $relationship = (string) ($input['signed_relationship'] ?? 'Patient');
        if (!in_array($relationship, self::RELATIONSHIPS, true)) {
            $relationship = 'Patient';
        }

        $witnessName = mb_substr(trim((string) ($input['witness_name'] ?? '')), 0, 160);
        $witnessSignature = InsuranceService::cleanSignature((string) ($input['witness_signature'] ?? ''));
        if (!empty($form['require_witness']) && ($witnessName === '' || $witnessSignature === null)) {
            throw new \InvalidArgumentException('This form needs a witness to sign as well.');
        }

        $id = 'cr-' . Uuid::uuidv7();
        $snapshot = [
            'name' => $form['name'],
            'purpose' => $form['purpose'] ?? '',
            'intro' => $form['intro'] ?? '',
            'declaration' => $form['declaration'] ?? '',
            'fields' => $form['fields'] ?? [],
            'require_witness' => !empty($form['require_witness']),
        ];

        $this->db->prepare("
            INSERT INTO consent_records
                (id, tenant_id, form_id, form_name, form_version, patient_id, visit_id, snapshot, answers,
                 patient_signature, signed_by_name, signed_relationship, witness_name, witness_signature,
                 signed_at, recorded_by)
            VALUES (:id, :tid, :fid, :fname, :fver, :pid, :vid, :snap, :ans, :sig, :by, :rel, :wname, :wsig, CURRENT_TIMESTAMP, :rec)
        ")->execute([
            'id' => $id,
            'tid' => $this->tenantId,
            'fid' => $form['id'] ?: null,
            'fname' => $form['name'],
            'fver' => (int) ($form['version'] ?? 1),
            'pid' => $patientId,
            'vid' => $visitId !== '' ? $visitId : null,
            'snap' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ans' => json_encode($answers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'sig' => $signature,
            'by' => $signedBy,
            'rel' => $relationship,
            'wname' => $witnessName !== '' ? $witnessName : null,
            'wsig' => $witnessSignature,
            'rec' => $_SESSION['user_name'] ?? null,
        ]);

        $this->audit->log(
            'CONSENT_SIGNED',
            sprintf('%s signed by %s (%s) for patient %s', $form['name'], $signedBy, $relationship, $patientId),
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_name'] ?? null,
            null,
            $this->tenantId
        );

        return $id;
    }

    /**
     * Collect the answers and report what is still missing.
     *
     * @return array{0: array<string, mixed>, 1: string[]}
     */
    public static function collectAnswers(array $form, array $input): array
    {
        $given = (array) ($input['answers'] ?? []);
        $answers = [];
        $missing = [];

        foreach ((array) ($form['fields'] ?? []) as $field) {
            $type = (string) ($field['type'] ?? '');
            $key = (string) ($field['key'] ?? '');
            if ($key === '' || !isset(ConsentForms::FIELD_TYPES[$type])) {
                continue;
            }
            if (ConsentForms::FIELD_TYPES[$type][3]) {
                continue; // headings and paragraphs are not answered
            }

            $raw = $given[$key] ?? null;
            $label = (string) ($field['label'] ?? $key);

            switch ($type) {
                case 'acknowledge':
                case 'checkbox':
                    $value = !empty($raw);
                    if ($type === 'acknowledge' && !$value) {
                        $missing[] = $label;
                    }
                    break;
                case 'radio':
                case 'select':
                    $value = (string) ($raw ?? '');
                    if ($value !== '' && !in_array($value, (array) ($field['options'] ?? []), true)) {
                        $value = '';
                    }
                    if ($value === '' && !empty($field['required'])) {
                        $missing[] = $label;
                    }
                    break;
                case 'date':
                    $value = trim((string) ($raw ?? ''));
                    $value = $value !== '' && strtotime($value) ? date('Y-m-d', (int) strtotime($value)) : '';
                    if ($value === '' && !empty($field['required'])) {
                        $missing[] = $label;
                    }
                    break;
                default:
                    $value = mb_substr(trim((string) ($raw ?? '')), 0, $type === 'textarea' ? 4000 : 300);
                    if ($value === '' && !empty($field['required'])) {
                        $missing[] = $label;
                    }
            }
            $answers[$key] = $value;
        }

        return [$answers, $missing];
    }

    /**
     * Withdraw a consent. The record stays — that someone consented and later
     * withdrew is itself part of the history — and is marked instead.
     */
    public function withdraw(string $id, string $reason): bool
    {
        $record = $this->find($id);
        if (!$record || !empty($record['withdrawn_at'])) {
            return false;
        }
        $this->db->prepare("
            UPDATE consent_records SET withdrawn_at = CURRENT_TIMESTAMP, withdrawn_reason = :reason
             WHERE id = :id AND tenant_id = :tid
        ")->execute([
            'reason' => mb_substr(trim($reason), 0, 400) ?: null,
            'id' => $id,
            'tid' => $this->tenantId,
        ]);

        $this->audit->log(
            'CONSENT_WITHDRAWN',
            sprintf('%s withdrawn for patient %s', $record['form_name'], $record['patient_id']),
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_name'] ?? null,
            null,
            $this->tenantId
        );
        return true;
    }

    /** Which of the clinic's active forms this visit has not yet covered. */
    public function outstandingForVisit(array $activeForms, string $patientId, string $visitId): array
    {
        $signed = [];
        foreach ($this->forVisit($patientId, $visitId) as $record) {
            if (empty($record['withdrawn_at'])) {
                $signed[(string) $record['form_id']] = true;
            }
        }
        return array_values(array_filter($activeForms, static fn($f) => !isset($signed[(string) $f['id']])));
    }
}
