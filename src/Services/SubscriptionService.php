<?php

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;

/**
 * Subscription (SaaS) payment history, recorded per tenant. The tenants table
 * already carries the current plan, price and next renewal date; this service
 * adds the ledger of payments behind that.
 */
class SubscriptionService
{
    public const STATUSES = ['paid', 'pending', 'failed', 'refunded'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Payment ledger for one tenant, newest first. */
    public function getPayments(string $tenantId, int $limit = 200): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM subscription_payments WHERE tenant_id = ?
                 ORDER BY COALESCE(paid_at, created_at) DESC, created_at DESC
                 LIMIT " . max(1, min(1000, $limit))
            );
            $stmt->execute([$tenantId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Totals useful for a header/summary card. */
    public function getSummary(string $tenantId): array
    {
        $out = [
            'total_paid'   => 0.0,
            'payment_count' => 0,
            'last_payment' => null,
            'currency'     => 'FJD',
        ];
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS total_paid,
                        COUNT(*) AS payment_count,
                        MAX(COALESCE(paid_at, created_at)) AS last_payment,
                        MAX(currency) AS currency
                 FROM subscription_payments WHERE tenant_id = ?"
            );
            $stmt->execute([$tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $out['total_paid']    = (float) $row['total_paid'];
                $out['payment_count'] = (int) $row['payment_count'];
                $out['last_payment']  = $row['last_payment'] ?: null;
                $out['currency']      = $row['currency'] ?: 'FJD';
            }
        } catch (\Throwable $e) {
            // leave defaults
        }
        return $out;
    }

    public function recordPayment(string $tenantId, array $data): string
    {
        $id = Uuid::uuidv7();
        $status = in_array($data['status'] ?? 'paid', self::STATUSES, true) ? $data['status'] : 'paid';
        $paidAt = trim((string) ($data['paid_at'] ?? ''));
        if ($paidAt === '' && $status === 'paid') {
            $paidAt = date('Y-m-d H:i:s');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO subscription_payments
                (id, tenant_id, amount, currency, plan, billing_cycle, period_start, period_end,
                 status, method, reference, invoice_no, paid_at, notes, recorded_by, recorded_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            $tenantId,
            (float) ($data['amount'] ?? 0),
            trim((string) ($data['currency'] ?? 'FJD')),
            trim((string) ($data['plan'] ?? '')) ?: null,
            trim((string) ($data['billing_cycle'] ?? 'monthly')),
            $this->dateOrNull($data['period_start'] ?? ''),
            $this->dateOrNull($data['period_end'] ?? ''),
            $status,
            trim((string) ($data['method'] ?? '')) ?: null,
            trim((string) ($data['reference'] ?? '')) ?: null,
            trim((string) ($data['invoice_no'] ?? '')) ?: null,
            $paidAt !== '' ? $paidAt : null,
            trim((string) ($data['notes'] ?? '')) ?: null,
            $data['recorded_by'] ?? null,
            $data['recorded_by_name'] ?? null,
        ]);

        return $id;
    }

    private function dateOrNull($v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
