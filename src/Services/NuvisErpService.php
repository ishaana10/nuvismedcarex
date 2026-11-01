<?php

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;
use ClinicFlow\Services\Cloud\CloudHttp;

/**
 * Integration with NuvisERPX, the billing system of record for tenant
 * subscriptions. Invoices are issued in NuvisERPX and mirrored here so clinics
 * can view them; when a payment is reconciled in NuvisERPX an inbound webhook
 * (or a manual sync) flips the local invoice to "paid", records the payment in
 * the subscription ledger, and reactivates the tenant's subscription.
 *
 * Connection settings are install-wide and live in the shared clinic_settings
 * table (one ERP per deployment), managed by developers.
 */
class NuvisErpService
{
    public const STATUSES = ['draft', 'issued', 'sent', 'paid', 'partially_paid', 'overdue', 'void'];
    private const PAID_STATUSES = ['paid'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // --- Connection config (install-wide) -----------------------------------

    public function config(): array
    {
        $g = fn(string $k, string $d = '') => (string) ($this->globalSetting($k) ?? $d);
        return [
            'enabled'        => $this->globalSetting('erp_enabled') === '1',
            'base_url'       => $g('erp_base_url'),
            'api_key'        => $g('erp_api_key'),
            'webhook_secret' => $g('erp_webhook_secret'),
            'last_sync'      => $g('erp_last_sync'),
        ];
    }

    public function saveConfig(array $in): void
    {
        $this->putGlobal('erp_enabled', !empty($in['enabled']) ? '1' : '0');
        $this->putGlobal('erp_base_url', rtrim(trim((string) ($in['base_url'] ?? '')), '/'));
        $this->putGlobal('erp_api_key', trim((string) ($in['api_key'] ?? '')));
        $secret = trim((string) ($in['webhook_secret'] ?? ''));
        if ($secret === '' && $this->globalSetting('erp_webhook_secret') === null) {
            $secret = bin2hex(random_bytes(24)); // generate one on first save
        }
        if ($secret !== '') {
            $this->putGlobal('erp_webhook_secret', $secret);
        }
    }

    public function isConfigured(): bool
    {
        $c = $this->config();
        return $c['base_url'] !== '' && $c['api_key'] !== '';
    }

    public function webhookUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'your-domain';
        return $scheme . '://' . $host . '/api/erp_webhook.php';
    }

    // --- Invoices -----------------------------------------------------------

    public function listInvoices(?string $tenantId = null, int $limit = 300): array
    {
        try {
            $sql = "SELECT i.*, t.name AS tenant_name FROM tenant_invoices i
                    LEFT JOIN tenants t ON t.id = i.tenant_id";
            $params = [];
            if ($tenantId !== null) {
                $sql .= " WHERE i.tenant_id = ?";
                $params[] = $tenantId;
            }
            $sql .= " ORDER BY COALESCE(i.issue_date, i.created_at) DESC, i.created_at DESC LIMIT " . max(1, min(1000, $limit));
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function invoiceSummary(string $tenantId): array
    {
        $out = ['outstanding' => 0.0, 'paid' => 0.0, 'open_count' => 0, 'currency' => 'FJD'];
        try {
            $stmt = $this->db->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN status NOT IN ('paid','void') THEN amount - paid_amount ELSE 0 END),0) AS outstanding,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END),0) AS paid,
                    SUM(CASE WHEN status NOT IN ('paid','void') THEN 1 ELSE 0 END) AS open_count,
                    MAX(currency) AS currency
                 FROM tenant_invoices WHERE tenant_id = ?"
            );
            $stmt->execute([$tenantId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $out['outstanding'] = (float) $r['outstanding'];
                $out['paid'] = (float) $r['paid'];
                $out['open_count'] = (int) $r['open_count'];
                $out['currency'] = $r['currency'] ?: 'FJD';
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /**
     * Insert or update an invoice, keyed on erp_invoice_id when present.
     * Returns the local invoice id.
     */
    public function upsertInvoice(string $tenantId, array $data): string
    {
        $erpId = trim((string) ($data['erp_invoice_id'] ?? ''));
        $status = in_array($data['status'] ?? 'issued', self::STATUSES, true) ? $data['status'] : 'issued';

        $existing = null;
        if ($erpId !== '') {
            $s = $this->db->prepare("SELECT id FROM tenant_invoices WHERE erp_invoice_id = ? LIMIT 1");
            $s->execute([$erpId]);
            $existing = $s->fetchColumn() ?: null;
        }

        $fields = [
            'tenant_id'      => $tenantId,
            'erp_invoice_id' => $erpId ?: null,
            'invoice_number' => trim((string) ($data['invoice_number'] ?? '')) ?: null,
            'amount'         => (float) ($data['amount'] ?? 0),
            'paid_amount'    => (float) ($data['paid_amount'] ?? 0),
            'currency'       => trim((string) ($data['currency'] ?? 'FJD')),
            'status'         => $status,
            'description'    => trim((string) ($data['description'] ?? '')) ?: null,
            'period_start'   => $this->d($data['period_start'] ?? ''),
            'period_end'     => $this->d($data['period_end'] ?? ''),
            'issue_date'     => $this->d($data['issue_date'] ?? ''),
            'due_date'       => $this->d($data['due_date'] ?? ''),
            'pdf_url'        => trim((string) ($data['pdf_url'] ?? '')) ?: null,
            'erp_reference'  => trim((string) ($data['erp_reference'] ?? '')) ?: null,
            'synced_at'      => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $sets = [];
            foreach ($fields as $k => $v) { $sets[] = "`{$k}` = ?"; }
            $sets[] = "updated_at = CURRENT_TIMESTAMP";
            $stmt = $this->db->prepare("UPDATE tenant_invoices SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute(array_merge(array_values($fields), [$existing]));
            return (string) $existing;
        }

        $id = Uuid::uuidv7();
        $cols = array_keys($fields);
        $place = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $this->db->prepare("INSERT INTO tenant_invoices (id, " . implode(', ', $cols) . ") VALUES (?, {$place})");
        $stmt->execute(array_merge([$id], array_values($fields)));
        return $id;
    }

    /**
     * Mark an invoice paid/reconciled: update the invoice, write a matching row
     * to the subscription payment ledger, and reactivate the subscription.
     * $ref may be a local invoice id or an erp_invoice_id.
     */
    public function applyReconciliation(string $ref, array $data = []): bool
    {
        $inv = $this->findInvoice($ref);
        if (!$inv) {
            $this->log('in', 'reconcile', null, $ref, 'error', 'Invoice not found for reconciliation');
            return false;
        }
        $status = in_array($data['status'] ?? 'paid', self::STATUSES, true) ? $data['status'] : 'paid';
        $paidAmount = isset($data['paid_amount']) ? (float) $data['paid_amount'] : (float) $inv['amount'];
        $reconciledAt = trim((string) ($data['reconciled_at'] ?? '')) ?: date('Y-m-d H:i:s');

        $this->db->prepare(
            "UPDATE tenant_invoices SET status = ?, paid_amount = ?, reconciled_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        )->execute([$status, $paidAmount, $reconciledAt, $inv['id']]);

        if (in_array($status, self::PAID_STATUSES, true)) {
            // Record in the subscription ledger (idempotent on erp reference).
            try {
                $reference = 'ERP:' . ($inv['erp_invoice_id'] ?: $inv['invoice_number'] ?: $inv['id']);
                $dup = $this->db->prepare("SELECT COUNT(*) FROM subscription_payments WHERE tenant_id = ? AND reference = ?");
                $dup->execute([$inv['tenant_id'], $reference]);
                if ((int) $dup->fetchColumn() === 0) {
                    $sub = new SubscriptionService($this->db);
                    $sub->recordPayment($inv['tenant_id'], [
                        'amount'       => $paidAmount,
                        'currency'     => $inv['currency'],
                        'billing_cycle' => '',
                        'period_start' => $inv['period_start'],
                        'period_end'   => $inv['period_end'],
                        'status'       => 'paid',
                        'method'       => $data['method'] ?? 'NuvisERPX',
                        'reference'    => $reference,
                        'invoice_no'   => $inv['invoice_number'],
                        'paid_at'      => $reconciledAt,
                        'notes'        => 'Reconciled in NuvisERPX',
                        'recorded_by_name' => 'NuvisERPX',
                    ]);
                }
            } catch (\Throwable $e) {}

            // Reactivate the tenant's subscription.
            try {
                $this->db->prepare("UPDATE tenants SET subscription_status = 'active' WHERE id = ?")->execute([$inv['tenant_id']]);
            } catch (\Throwable $e) {}
        }

        $this->log('in', 'reconcile', $inv['tenant_id'], $inv['erp_invoice_id'], 'ok', "Invoice {$inv['invoice_number']} → {$status}");
        return true;
    }

    // --- Inbound webhook ----------------------------------------------------

    /**
     * Verify and process a webhook from NuvisERPX.
     * Returns [bool ok, string message, int httpCode].
     */
    public function handleWebhook(string $rawBody, string $signature): array
    {
        $cfg = $this->config();
        if (($cfg['webhook_secret'] ?? '') === '') {
            return [false, 'ERP webhook secret is not configured', 503];
        }
        if (!$this->verifySignature($rawBody, $signature, $cfg['webhook_secret'])) {
            $this->log('in', 'webhook', null, null, 'error', 'Invalid signature');
            return [false, 'Invalid signature', 401];
        }
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return [false, 'Malformed payload', 400];
        }

        $event = (string) ($payload['event'] ?? '');
        $inv = $payload['invoice'] ?? $payload['data'] ?? $payload;
        $tenantId = $this->resolveTenant($inv);

        try {
            if (in_array($event, ['invoice.created', 'invoice.updated', 'invoice.issued', 'invoice.sent', ''], true)) {
                if (!$tenantId) {
                    return [false, 'Unknown tenant / client', 422];
                }
                $this->upsertInvoice($tenantId, $this->mapInvoice($inv));
                $this->log('in', $event ?: 'invoice.sync', $tenantId, (string) ($inv['id'] ?? ''), 'ok', 'Invoice upserted', $rawBody);
                return [true, 'Invoice synced', 200];
            }
            if (in_array($event, ['invoice.paid', 'payment.reconciled', 'invoice.reconciled'], true)) {
                $ref = (string) ($inv['id'] ?? $inv['erp_invoice_id'] ?? $inv['invoice_number'] ?? '');
                if ($tenantId && $ref !== '') {
                    // Make sure the invoice exists locally, then reconcile.
                    $this->upsertInvoice($tenantId, $this->mapInvoice($inv));
                }
                $ok = $this->applyReconciliation($ref, [
                    'status'       => 'paid',
                    'paid_amount'  => $inv['paid_amount'] ?? $inv['amount'] ?? null,
                    'reconciled_at' => $inv['reconciled_at'] ?? $inv['paid_at'] ?? null,
                    'method'       => $inv['method'] ?? 'NuvisERPX',
                ]);
                $this->log('in', $event, $tenantId, $ref, $ok ? 'ok' : 'error', $ok ? 'Reconciled' : 'Invoice not found', $rawBody);
                return $ok ? [true, 'Reconciled', 200] : [false, 'Invoice not found', 404];
            }
            if ($event === 'invoice.void') {
                $ref = (string) ($inv['id'] ?? $inv['invoice_number'] ?? '');
                $found = $this->findInvoice($ref);
                if ($found) {
                    $this->db->prepare("UPDATE tenant_invoices SET status = 'void', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$found['id']]);
                }
                return [true, 'Voided', 200];
            }
        } catch (\Throwable $e) {
            $this->log('in', $event, $tenantId, null, 'error', $e->getMessage(), $rawBody);
            return [false, 'Processing error', 500];
        }

        return [true, 'Ignored event: ' . $event, 200];
    }

    public function verifySignature(string $rawBody, string $signature, string $secret): bool
    {
        $signature = trim($signature);
        if (stripos($signature, 'sha256=') === 0) {
            $signature = substr($signature, 7);
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return $signature !== '' && hash_equals($expected, $signature);
    }

    // --- Outbound sync (pull from ERP) --------------------------------------

    /**
     * Pull invoices from NuvisERPX for one tenant (or all mapped tenants).
     * Best-effort: returns [count, error]. Never throws.
     */
    public function syncFromErp(?string $tenantId = null): array
    {
        $cfg = $this->config();
        if (!$this->isConfigured()) {
            return [0, 'NuvisERPX connection is not configured'];
        }

        // Which tenants to pull, with their ERP client id.
        try {
            if ($tenantId) {
                $s = $this->db->prepare("SELECT id, nuvis_erp_client_id FROM tenants WHERE id = ?");
                $s->execute([$tenantId]);
            } else {
                $s = $this->db->query("SELECT id, nuvis_erp_client_id FROM tenants WHERE nuvis_erp_client_id IS NOT NULL AND nuvis_erp_client_id <> ''");
            }
            $tenants = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [0, 'Could not read tenants'];
        }

        $count = 0;
        $lastError = '';
        foreach ($tenants as $t) {
            $clientId = (string) ($t['nuvis_erp_client_id'] ?? '');
            if ($clientId === '') { continue; }
            $url = $cfg['base_url'] . '/api/v1/invoices?client_id=' . rawurlencode($clientId);
            $res = CloudHttp::request('GET', $url, [
                'Authorization: Bearer ' . $cfg['api_key'],
                'Accept: application/json',
            ], null, ['timeout' => 30]);

            if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
                $lastError = CloudHttp::reason($res);
                $this->log('out', 'sync', $t['id'], null, 'error', $lastError);
                continue;
            }
            $data = CloudHttp::json($res);
            $invoices = $data['invoices'] ?? $data['data'] ?? [];
            foreach ($invoices as $inv) {
                try {
                    $id = $this->upsertInvoice($t['id'], $this->mapInvoice($inv));
                    if (in_array(($inv['status'] ?? ''), self::PAID_STATUSES, true)) {
                        $this->applyReconciliation($id, [
                            'status' => 'paid',
                            'paid_amount' => $inv['paid_amount'] ?? $inv['amount'] ?? null,
                            'reconciled_at' => $inv['reconciled_at'] ?? $inv['paid_at'] ?? null,
                        ]);
                    }
                    $count++;
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                }
            }
            $this->log('out', 'sync', $t['id'], null, 'ok', "Pulled " . count($invoices) . " invoice(s)");
        }

        $this->putGlobal('erp_last_sync', date('Y-m-d H:i:s'));
        return [$count, $lastError];
    }

    public function recentLogs(int $limit = 50): array
    {
        try {
            $stmt = $this->db->query("SELECT * FROM erp_sync_logs ORDER BY created_at DESC LIMIT " . max(1, min(200, $limit)));
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // --- Helpers ------------------------------------------------------------

    /** Normalise an ERP invoice payload into our column names. */
    private function mapInvoice(array $inv): array
    {
        return [
            'erp_invoice_id' => $inv['id'] ?? $inv['erp_invoice_id'] ?? '',
            'invoice_number' => $inv['number'] ?? $inv['invoice_number'] ?? '',
            'amount'         => $inv['total'] ?? $inv['amount'] ?? 0,
            'paid_amount'    => $inv['paid_amount'] ?? 0,
            'currency'       => $inv['currency'] ?? 'FJD',
            'status'         => $inv['status'] ?? 'issued',
            'description'    => $inv['description'] ?? $inv['memo'] ?? '',
            'period_start'   => $inv['period_start'] ?? '',
            'period_end'     => $inv['period_end'] ?? '',
            'issue_date'     => $inv['issue_date'] ?? $inv['date'] ?? '',
            'due_date'       => $inv['due_date'] ?? '',
            'pdf_url'        => $inv['pdf_url'] ?? $inv['url'] ?? '',
            'erp_reference'  => $inv['reference'] ?? '',
        ];
    }

    private function resolveTenant(array $inv): ?string
    {
        // Direct tenant id, or map via nuvis_erp_client_id.
        $tid = (string) ($inv['tenant_id'] ?? '');
        if ($tid !== '') {
            $s = $this->db->prepare("SELECT id FROM tenants WHERE id = ? LIMIT 1");
            $s->execute([$tid]);
            if ($s->fetchColumn()) return $tid;
        }
        $client = (string) ($inv['client_id'] ?? $inv['erp_client_id'] ?? '');
        if ($client !== '') {
            $s = $this->db->prepare("SELECT id FROM tenants WHERE nuvis_erp_client_id = ? LIMIT 1");
            $s->execute([$client]);
            $id = $s->fetchColumn();
            if ($id) return (string) $id;
        }
        return null;
    }

    private function findInvoice(string $ref): ?array
    {
        try {
            $s = $this->db->prepare("SELECT * FROM tenant_invoices WHERE id = ? OR erp_invoice_id = ? OR invoice_number = ? LIMIT 1");
            $s->execute([$ref, $ref, $ref]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function log(string $dir, string $event, ?string $tenantId, ?string $erpId, string $status, string $message, ?string $payload = null): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO erp_sync_logs (id, direction, event, tenant_id, erp_invoice_id, status, message, payload)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([Uuid::uuidv7(), $dir, $event, $tenantId, $erpId, $status, mb_substr($message, 0, 500), $payload ? mb_substr($payload, 0, 60000) : null]);
        } catch (\Throwable $e) {}
    }

    private function d($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        // Accept full datetimes but store date part for *_date columns.
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function globalSetting(string $key): ?string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = ? LIMIT 1");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false ? null : (string) $v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function putGlobal(string $key, string $value): void
    {
        try {
            $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $sql = $driver === 'sqlite'
                ? "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value"
                : "INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
            $this->db->prepare($sql)->execute([$key, $value]);
        } catch (\Throwable $e) {}
    }
}
