<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;
use ClinicFlow\Shared\TenantContext;

class InsuranceService {
    private PDO $db;
    private AuditService $audit;

    public function __construct(PDO $db, ?AuditService $audit = null) {
        $this->db = $db;
        $this->audit = $audit ?? new AuditService($db);
    }

    public function createClaim(string $invoiceId, string $patientId, string $providerName, string $policyNumber, float $claimAmount, ?string $notes = null, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $id = Uuid::uuidv7();

        $stmt = $this->db->prepare("
            INSERT INTO insurance_claims (id, tenant_id, invoice_id, patient_id, provider_name, policy_number, claim_amount, status, notes)
            VALUES (:id, :tid, :inv_id, :pid, :provider, :policy, :amt, 'Submitted', :notes)
        ");

        $stmt->execute([
            'id' => $id,
            'tid' => $tenantId,
            'inv_id' => $invoiceId,
            'pid' => $patientId,
            'provider' => $providerName,
            'policy' => $policyNumber,
            'amt' => $claimAmount,
            'notes' => $notes
        ]);

        $this->audit->log('CREATE_INSURANCE_CLAIM', "Submitted insurance claim for $" . number_format($claimAmount, 2) . " to {$providerName}", null, null, null, $tenantId);

        return $this->getClaimById($id, $tenantId);
    }

    public function getClaimById(string $id, ?string $tenantId = null): ?array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM insurance_claims WHERE id = :id AND tenant_id = :tid LIMIT 1");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateClaimStatus(string $id, string $status, ?string $notes = null, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("
            UPDATE insurance_claims SET status = :status, notes = COALESCE(:notes, notes), updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute(['status' => $status, 'notes' => $notes, 'id' => $id, 'tid' => $tenantId]);

        $this->audit->log('UPDATE_INSURANCE_CLAIM', "Updated insurance claim {$id} status to {$status}", null, null, null, $tenantId);

        return $this->getClaimById($id, $tenantId);
    }
}
