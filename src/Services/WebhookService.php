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
     * Dispatch event webhook payload
     */
    public function dispatch(string $event, array $payload, ?string $webhookUrl = null, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();

        $data = [
            'event' => $event,
            'tenant_id' => $tenantId,
            'timestamp' => date('c'),
            'data' => $payload
        ];

        $this->audit->log('WEBHOOK_DISPATCH', "Dispatched webhook event '{$event}'", null, null, null, $tenantId);

        return [
            'success' => true,
            'event' => $event,
            'data' => $data
        ];
    }
}
