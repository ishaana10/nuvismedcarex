<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Shared\TenantContext;

/**
 * Reporting over insurance claims: a filtered claim listing plus the totals a
 * clinic needs when it chases an insurer — what was submitted, what came back
 * approved, what is still sitting with the provider and for how long.
 *
 * Every query is scoped to one clinic.
 */
final class InsuranceReportService
{
    /** Statuses that still owe the clinic an answer. */
    public const OPEN_STATUSES = ['Submitted', 'Pending'];

    private PDO $db;
    private string $tenantId;

    public function __construct(PDO $db, ?string $tenantId = null)
    {
        $this->db = $db;
        $this->tenantId = $tenantId ?? TenantContext::getTenantId();
    }

    /**
     * Provider names worth offering in the filter: every insurer this clinic
     * has claimed from, plus the ones recorded on patient records.
     */
    public function providers(): array
    {
        $names = [];
        // The clinic's configured insurers come first, so one that has not been
        // claimed from yet can still be picked.
        try {
            foreach ((new InsuranceProviders($this->db, $this->tenantId))->names() as $name) {
                $names[mb_strtolower($name)] = $name;
            }
        } catch (\Throwable $e) {
            error_log('[InsuranceReport] configured providers: ' . $e->getMessage());
        }
        foreach ([
            "SELECT DISTINCT provider_name FROM insurance_claims WHERE tenant_id = ? AND provider_name <> ''",
            "SELECT DISTINCT insurance_provider FROM patients WHERE tenant_id = ? AND insurance_provider IS NOT NULL AND insurance_provider <> ''",
        ] as $sql) {
            try {
                $st = $this->db->prepare($sql);
                $st->execute([$this->tenantId]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
                    $clean = InsuranceReportSettings::cleanProviderName((string) $name);
                    if ($clean !== '') {
                        $names[mb_strtolower($clean)] = $clean;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[InsuranceReport] providers: ' . $e->getMessage());
            }
        }
        $names = array_values($names);
        usort($names, 'strnatcasecmp');
        return $names;
    }

    /**
     * @param array{
     *   from?: string, to?: string, providers?: string[], statuses?: string[],
     *   date_basis?: string, search?: string
     * } $filters
     */
    public function report(array $filters): array
    {
        $from = self::cleanDate($filters['from'] ?? null) ?? date('Y-m-01');
        $to   = self::cleanDate($filters['to'] ?? null) ?? date('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $basis = ($filters['date_basis'] ?? 'submitted') === 'service' ? 'service' : 'submitted';
        $providers = array_values(array_filter(array_map(
            static fn($p) => InsuranceReportSettings::cleanProviderName((string) $p),
            (array) ($filters['providers'] ?? [])
        ), static fn($p) => $p !== ''));
        $statuses = array_values(array_filter(array_map(
            static fn($s) => trim((string) $s),
            (array) ($filters['statuses'] ?? [])
        ), static fn($s) => in_array($s, InsuranceReportSettings::STATUSES, true)));
        $search = trim((string) ($filters['search'] ?? ''));

        $dateColumn = $basis === 'service'
            ? "COALESCE(i.service_date, DATE(c.created_at))"
            : "DATE(c.created_at)";

        $where = ['c.tenant_id = :tid', "$dateColumn BETWEEN :from AND :to"];
        $params = ['tid' => $this->tenantId, 'from' => $from, 'to' => $to];

        if ($providers !== []) {
            $ors = [];
            foreach ($providers as $n => $name) {
                $ors[] = "LOWER(c.provider_name) = :prov$n";
                $params["prov$n"] = mb_strtolower($name);
            }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        }
        if ($statuses !== []) {
            $ors = [];
            foreach ($statuses as $n => $status) {
                $ors[] = "c.status = :st$n";
                $params["st$n"] = $status;
            }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        }
        if ($search !== '') {
            $where[] = '(c.policy_number LIKE :q OR c.provider_name LIKE :q OR i.invoice_number LIKE :q'
                . " OR p.first_name LIKE :q OR p.last_name LIKE :q OR p.mrn LIKE :q)";
            $params['q'] = '%' . $search . '%';
        }

        // Only ask for the signature columns when the database has them, so a
        // pending migration cannot empty the whole report.
        $signatureCols = InsuranceService::hasSignatureSupport($this->db)
            ? 'c.patient_signature, c.signed_by_name, c.signed_at,'
            : '';

        $sql = "SELECT c.id, c.invoice_id, c.patient_id, c.provider_name, c.policy_number,
                       c.claim_amount, c.status, c.notes, c.created_at, c.updated_at,
                       {$signatureCols}
                       i.invoice_number, i.service_date, i.amount AS invoice_total,
                       i.total_tax AS invoice_tax, i.insurance_covered, i.patient_owed,
                       i.status AS invoice_status,
                       p.first_name, p.last_name, p.mrn, p.dob, p.insurance_group_number
                  FROM insurance_claims c
                  LEFT JOIN invoices i ON i.id = c.invoice_id AND i.tenant_id = c.tenant_id
                  LEFT JOIN patients p ON p.id = c.patient_id AND p.tenant_id = c.tenant_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY $dateColumn DESC, c.created_at DESC";

        $rows = [];
        try {
            $st = $this->db->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[InsuranceReport] claims: ' . $e->getMessage());
        }

        $claims = array_map([$this, 'shapeRow'], $rows);

        return [
            'filters' => [
                'from' => $from, 'to' => $to, 'date_basis' => $basis,
                'providers' => $providers, 'statuses' => $statuses, 'search' => $search,
            ],
            'claims'      => $claims,
            'totals'      => $this->totals($claims),
            'by_provider' => $this->byProvider($claims),
            'by_status'   => $this->byStatus($claims),
            'aging'       => $this->aging($claims),
            'unclaimed'   => $this->unclaimedInvoices($from, $to),
        ];
    }

    /** One claim flattened into the fields the report columns draw on. */
    private function shapeRow(array $r): array
    {
        $name = trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''));
        $claimed = (float) ($r['claim_amount'] ?? 0);
        $covered = (float) ($r['insurance_covered'] ?? 0);
        $total = (float) ($r['invoice_total'] ?? 0);
        $status = (string) ($r['status'] ?? 'Submitted');
        $submitted = (string) ($r['created_at'] ?? '');
        $settled = in_array($status, self::OPEN_STATUSES, true) ? '' : (string) ($r['updated_at'] ?? '');

        $ageFrom = strtotime($submitted) ?: time();
        $ageTo = $settled !== '' ? (strtotime($settled) ?: time()) : time();
        $ageDays = (int) max(0, floor(($ageTo - $ageFrom) / 86400));

        return [
            'id'                => (string) $r['id'],
            'claim_ref'         => self::claimReference((string) $r['id'], $submitted),
            'claim_date'        => $submitted !== '' ? substr($submitted, 0, 10) : '',
            'settled_date'      => $settled !== '' ? substr($settled, 0, 10) : '',
            'status'            => $status,
            'is_open'           => in_array($status, self::OPEN_STATUSES, true),
            'patient_id'        => (string) ($r['patient_id'] ?? ''),
            'patient_name'      => $name !== '' ? $name : '(patient removed)',
            'patient_mrn'       => (string) ($r['mrn'] ?? ''),
            'patient_dob'       => (string) ($r['dob'] ?? ''),
            'policy_number'     => (string) ($r['policy_number'] ?? ''),
            'group_number'      => (string) ($r['insurance_group_number'] ?? ''),
            'provider'          => (string) ($r['provider_name'] ?? ''),
            'invoice_id'        => (string) ($r['invoice_id'] ?? ''),
            'invoice_number'    => (string) ($r['invoice_number'] ?? '(invoice removed)'),
            'invoice_status'    => (string) ($r['invoice_status'] ?? ''),
            'service_date'      => (string) ($r['service_date'] ?? ''),
            'invoice_total'     => $total,
            'invoice_tax'       => (float) ($r['invoice_tax'] ?? 0),
            'claim_amount'      => $claimed,
            'insurance_covered' => $covered,
            'patient_share'     => max(0.0, $total - $covered),
            'age_days'          => $ageDays,
            'signature'         => (string) ($r['patient_signature'] ?? ''),
            'signed_by'         => (string) ($r['signed_by_name'] ?? ''),
            'signed_by_name'    => trim((string) ($r['signed_by_name'] ?? '')) !== ''
                ? (string) $r['signed_by_name']
                : (($r['patient_signature'] ?? '') !== '' ? 'Signed' : ''),
            'signed_at'         => substr((string) ($r['signed_at'] ?? ''), 0, 10),
            'signed'            => ($r['patient_signature'] ?? '') !== ''
                ? (trim((string) ($r['signed_by_name'] ?? '')) !== '' ? (string) $r['signed_by_name'] : 'Signed')
                : 'Not signed',
            'notes'             => (string) ($r['notes'] ?? ''),
        ];
    }

    private function totals(array $claims): array
    {
        $t = [
            'count' => count($claims), 'claimed' => 0.0,
            'approved' => 0.0, 'approved_count' => 0,
            'rejected' => 0.0, 'rejected_count' => 0,
            'open' => 0.0, 'open_count' => 0,
            'invoice_total' => 0.0, 'patient_share' => 0.0,
            'oldest_open_days' => 0, 'approval_rate' => 0.0,
            'average_settle_days' => 0.0,
        ];
        $settleDays = [];
        foreach ($claims as $c) {
            $t['claimed'] += $c['claim_amount'];
            $t['invoice_total'] += $c['invoice_total'];
            $t['patient_share'] += $c['patient_share'];
            if ($c['is_open']) {
                $t['open'] += $c['claim_amount'];
                $t['open_count']++;
                $t['oldest_open_days'] = max($t['oldest_open_days'], $c['age_days']);
            } else {
                $settleDays[] = $c['age_days'];
            }
            if ($c['status'] === 'Approved' || $c['status'] === 'Paid') {
                $t['approved'] += $c['claim_amount'];
                $t['approved_count']++;
            } elseif ($c['status'] === 'Rejected') {
                $t['rejected'] += $c['claim_amount'];
                $t['rejected_count']++;
            }
        }
        $decided = $t['approved_count'] + $t['rejected_count'];
        $t['approval_rate'] = $decided > 0 ? round($t['approved_count'] / $decided * 100, 1) : 0.0;
        $t['average_settle_days'] = $settleDays !== [] ? round(array_sum($settleDays) / count($settleDays), 1) : 0.0;
        return $t;
    }

    private function byProvider(array $claims): array
    {
        $out = [];
        foreach ($claims as $c) {
            $key = $c['provider'] !== '' ? $c['provider'] : '(no provider recorded)';
            if (!isset($out[$key])) {
                $out[$key] = [
                    'provider' => $key, 'count' => 0, 'claimed' => 0.0,
                    'approved' => 0.0, 'approved_count' => 0,
                    'rejected' => 0.0, 'rejected_count' => 0,
                    'open' => 0.0, 'open_count' => 0, 'oldest_open_days' => 0,
                ];
            }
            $row = &$out[$key];
            $row['count']++;
            $row['claimed'] += $c['claim_amount'];
            if ($c['is_open']) {
                $row['open'] += $c['claim_amount'];
                $row['open_count']++;
                $row['oldest_open_days'] = max($row['oldest_open_days'], $c['age_days']);
            }
            if ($c['status'] === 'Approved' || $c['status'] === 'Paid') {
                $row['approved'] += $c['claim_amount'];
                $row['approved_count']++;
            } elseif ($c['status'] === 'Rejected') {
                $row['rejected'] += $c['claim_amount'];
                $row['rejected_count']++;
            }
            unset($row);
        }
        uasort($out, static fn($a, $b) => $b['claimed'] <=> $a['claimed']);
        return array_values($out);
    }

    private function byStatus(array $claims): array
    {
        $out = [];
        foreach (InsuranceReportSettings::STATUSES as $status) {
            $out[$status] = ['status' => $status, 'count' => 0, 'claimed' => 0.0];
        }
        foreach ($claims as $c) {
            $status = $c['status'];
            if (!isset($out[$status])) {
                $out[$status] = ['status' => $status, 'count' => 0, 'claimed' => 0.0];
            }
            $out[$status]['count']++;
            $out[$status]['claimed'] += $c['claim_amount'];
        }
        return array_values(array_filter($out, static fn($r) => $r['count'] > 0));
    }

    /** How long the still-open claims have been waiting. */
    private function aging(array $claims): array
    {
        $buckets = [
            ['label' => '0 – 30 days', 'min' => 0, 'max' => 30],
            ['label' => '31 – 60 days', 'min' => 31, 'max' => 60],
            ['label' => '61 – 90 days', 'min' => 61, 'max' => 90],
            ['label' => 'Over 90 days', 'min' => 91, 'max' => PHP_INT_MAX],
        ];
        $out = [];
        foreach ($buckets as $b) {
            $out[] = ['label' => $b['label'], 'count' => 0, 'claimed' => 0.0];
        }
        foreach ($claims as $c) {
            if (!$c['is_open']) {
                continue;
            }
            foreach ($buckets as $i => $b) {
                if ($c['age_days'] >= $b['min'] && $c['age_days'] <= $b['max']) {
                    $out[$i]['count']++;
                    $out[$i]['claimed'] += $c['claim_amount'];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Invoices in the period where insurance was meant to cover part of the
     * bill but no claim was ever submitted — money the clinic has not asked for.
     */
    private function unclaimedInvoices(string $from, string $to): array
    {
        $sql = "SELECT i.id, i.invoice_number, i.patient_name, i.patient_mrn, i.service_date,
                       i.amount, i.insurance_covered, p.insurance_provider
                  FROM invoices i
                  LEFT JOIN patients p ON p.id = i.patient_id AND p.tenant_id = i.tenant_id
                 WHERE i.tenant_id = :tid
                   AND i.insurance_covered > 0
                   AND COALESCE(i.service_date, DATE(i.created_at)) BETWEEN :from AND :to
                   AND NOT EXISTS (
                        SELECT 1 FROM insurance_claims c
                         WHERE c.invoice_id = i.id AND c.tenant_id = i.tenant_id
                   )
                 ORDER BY i.insurance_covered DESC";
        try {
            $st = $this->db->prepare($sql);
            $st->execute(['tid' => $this->tenantId, 'from' => $from, 'to' => $to]);
            return array_map(static fn(array $r) => [
                'invoice_id'        => (string) $r['id'],
                'invoice_number'    => (string) ($r['invoice_number'] ?? ''),
                'patient_name'      => (string) ($r['patient_name'] ?? ''),
                'patient_mrn'       => (string) ($r['patient_mrn'] ?? ''),
                'service_date'      => (string) ($r['service_date'] ?? ''),
                'amount'            => (float) ($r['amount'] ?? 0),
                'insurance_covered' => (float) ($r['insurance_covered'] ?? 0),
                'provider'          => (string) ($r['insurance_provider'] ?? ''),
            ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (\Throwable $e) {
            error_log('[InsuranceReport] unclaimed: ' . $e->getMessage());
            return [];
        }
    }

    /** Date presets offered on the report screen: key => [label, from, to] */
    public static function presets(): array
    {
        $today = date('Y-m-d');
        $month = (int) date('n');
        $quarter = (int) ceil($month / 3);
        return [
            'this_month'    => ['This month', date('Y-m-01'), $today],
            'last_month'    => ['Last month', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
            'this_quarter'  => ['This quarter', date(sprintf('Y-%02d-01', ($quarter - 1) * 3 + 1)), date('Y-m-t', strtotime(date(sprintf('Y-%02d-01', $quarter * 3))))],
            'this_year'     => ['This year', date('Y-01-01'), $today],
            'last_90'       => ['Last 90 days', date('Y-m-d', strtotime('-89 days')), $today],
        ];
    }

    /**
     * A short reference a clerk can quote on the phone. Claim ids are UUIDv7,
     * whose leading characters are a timestamp — every claim made the same week
     * shares them — so the reference is built from the month plus the random
     * tail of the id, which does distinguish one claim from another.
     */
    public static function claimReference(string $id, string $createdAt = ''): string
    {
        $hex = preg_replace('/[^A-Za-z0-9]/', '', $id) ?? '';
        $tail = strtoupper(substr($hex, -6)) ?: 'UNKNOWN';
        $ts = strtotime($createdAt);
        return ($ts ? date('ym', $ts) . '-' : '') . $tail;
    }

    public static function cleanDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    /**
     * Turn a report into header + rows for the given layout, ready for the
     * screen, a CSV or the printed page.
     *
     * @return array{headers: string[], keys: string[], types: string[], rows: array<int, array<int, string|float>>, groups: array}
     */
    public static function tabulate(array $report, array $layout): array
    {
        $keys = $layout['columns'] ?: InsuranceReportSettings::DEFAULT_COLUMNS;
        $headers = [];
        $types = [];
        foreach ($keys as $key) {
            [$label, $type] = InsuranceReportSettings::COLUMNS[$key] ?? [$key, 'text'];
            if ($key === 'policy_number' && ($layout['reference_label'] ?? '') !== '') {
                $label = (string) $layout['reference_label'];
            }
            $headers[] = $label;
            $types[] = $type;
        }

        $groupBy = $layout['group_by'] ?? 'none';
        $groups = [];
        foreach ($report['claims'] as $claim) {
            $cells = [];
            foreach ($keys as $i => $key) {
                $value = $claim[$key] ?? '';
                if ($key === 'claim_ref' && ($layout['claim_prefix'] ?? '') !== '') {
                    $value = $layout['claim_prefix'] . $value;
                }
                // A signature cell carries the drawing itself along with who
                // signed and when, so each renderer can show what it can: the
                // image on screen and on paper, words in a spreadsheet.
                if (($types[$i] ?? '') === 'signature') {
                    $value = [
                        'image' => (string) ($claim['signature'] ?? ''),
                        'by'    => (string) ($claim['signed_by'] ?? ''),
                        'at'    => (string) ($claim['signed_at'] ?? ''),
                    ];
                }
                $cells[] = $value;
            }
            $label = match ($groupBy) {
                'status'  => $claim['status'],
                'month'   => $claim['claim_date'] !== '' ? date('F Y', strtotime($claim['claim_date'])) : 'Undated',
                'patient' => $claim['patient_name'],
                default   => '',
            };
            $groups[$label] ??= ['label' => $label, 'rows' => [], 'claimed' => 0.0];
            $groups[$label]['rows'][] = $cells;
            $groups[$label]['claimed'] += $claim['claim_amount'];
        }

        $rows = [];
        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $rows[] = $row;
            }
        }

        return [
            'headers' => $headers,
            'keys' => $keys,
            'types' => $types,
            'rows' => $rows,
            'groups' => $groupBy === 'none' ? [] : array_values($groups),
        ];
    }
}
