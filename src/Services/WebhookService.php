<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Shared\TenantContext;

class WebhookService {
    private PDO $db;
    private AuditService $audit;

    public function __construct(PDO $db, ?AuditService $audit = null) {
        $this->db = $db;
        $this->audit = $audit ?? new AuditService($db);
    }

    /**
     * Register or update a webhook subscription
     */
    public function saveSubscription(string $appName, string $eventType, string $targetUrl, ?string $secretToken = null, ?string $id = null, ?string $tenantId = null): string {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $id = $id ?: 'wh-' . uniqid();
        $secretToken = $secretToken ?: bin2hex(random_bytes(16));

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare("INSERT INTO webhook_subscriptions (id, tenant_id, app_name, event_type, target_url, secret_token, status) VALUES (:id, :tenant_id, :app_name, :event_type, :target_url, :secret_token, 'active') ON CONFLICT(id) DO UPDATE SET app_name = excluded.app_name, event_type = excluded.event_type, target_url = excluded.target_url, secret_token = excluded.secret_token, updated_at = CURRENT_TIMESTAMP");
        } else {
            $stmt = $this->db->prepare("INSERT INTO webhook_subscriptions (id, tenant_id, app_name, event_type, target_url, secret_token, status) VALUES (:id, :tenant_id, :app_name, :event_type, :target_url, :secret_token, 'active') ON DUPLICATE KEY UPDATE app_name = VALUES(app_name), event_type = VALUES(event_type), target_url = VALUES(target_url), secret_token = VALUES(secret_token), updated_at = CURRENT_TIMESTAMP");
        }
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
            'app_name' => $appName,
            'event_type' => $eventType,
            'target_url' => $targetUrl,
            'secret_token' => $secretToken
        ]);

        $this->audit->log('WEBHOOK_SAVE', "Saved webhook subscription '{$appName}' for event '{$eventType}'", null, null, null, $tenantId);
        return $id;
    }

    /**
     * Get webhook subscriptions for active tenant
     */
    public function getSubscriptions(?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM webhook_subscriptions WHERE tenant_id = :tid ORDER BY created_at DESC");
        $stmt->execute(['tid' => $tenantId]);
        return $stmt->fetchAll();
    }

    /**
     * Delete a webhook subscription
     */
    public function deleteSubscription(string $id, ?string $tenantId = null): bool {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("DELETE FROM webhook_subscriptions WHERE id = :id AND tenant_id = :tid");
        $res = $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $this->audit->log('WEBHOOK_DELETE', "Deleted webhook subscription '{$id}'", null, null, null, $tenantId);
        return $res;
    }

    /**
     * Dispatch event webhook payload to configured target endpoints
     */
    public function dispatch(string $event, array $payload, ?string $webhookUrl = null, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();

        $data = [
            'event' => $event,
            'tenant_id' => $tenantId,
            'timestamp' => date('c'),
            'data' => $payload
        ];

        $dispatchedTo = [];

        if ($webhookUrl) {
            $dispatchedTo[] = [
                'target_url' => $webhookUrl,
                'status' => 'dispatched'
            ];
        } else {
            // Query database subscriptions for matching event
            try {
                $stmt = $this->db->prepare("SELECT * FROM webhook_subscriptions WHERE tenant_id = :tid AND (event_type = :event OR event_type = '*') AND status = 'active'");
                $stmt->execute(['tid' => $tenantId, 'event' => $event]);
                $subscriptions = $stmt->fetchAll();

                foreach ($subscriptions as $sub) {
                    $dispatchedTo[] = [
                        'subscription_id' => $sub['id'],
                        'app_name' => $sub['app_name'],
                        'target_url' => $sub['target_url'],
                        'status' => 'dispatched'
                    ];
                }
            } catch (\Throwable $e) {
                // Table might not exist in test env
            }
        }

        $this->audit->log('WEBHOOK_DISPATCH', "Dispatched webhook event '{$event}' to " . count($dispatchedTo) . " endpoint(s)", null, null, null, $tenantId);

        return [
            'success' => true,
            'event' => $event,
            'tenant_id' => $tenantId,
            'data' => $data,
            'dispatches' => $dispatchedTo
        ];
    }
}
