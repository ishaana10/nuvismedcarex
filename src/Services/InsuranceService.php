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

    public function editClaim(string $id, string $providerName, string $policyNumber, float $claimAmount, string $status, ?string $notes = null, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("
            UPDATE insurance_claims
            SET provider_name = :provider, policy_number = :policy, claim_amount = :amt, status = :status, notes = :notes, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute([
            'provider' => $providerName,
            'policy' => $policyNumber,
            'amt' => $claimAmount,
            'status' => $status,
            'notes' => $notes,
            'id' => $id,
            'tid' => $tenantId
        ]);

        $this->audit->log('EDIT_INSURANCE_CLAIM', "Edited insurance claim {$id}", null, null, null, $tenantId);

        return $this->getClaimById($id, $tenantId);
    }

    /**
     * Keep a claim in step with the insurance recorded on an invoice.
     *
     * Raising an invoice with an insurance portion is the clinic saying the
     * insurer owes that much, so the claim is created there and then rather
     * than being typed again later — which is how invoices ended up billed to
     * insurance with no claim ever submitted.
     *
     * Passing an amount of zero removes a claim that was raised automatically
     * and never touched, so correcting an invoice does not leave a stray claim.
     *
     * @return array{claim: ?array, action: string} action: created|updated|removed|unchanged|skipped
     */
    public function syncFromInvoice(
        string $invoiceId,
        string $patientId,
        float $insuranceCovered,
        string $providerName,
        string $policyNumber = '',
        ?string $signature = null,
        ?string $signedBy = null,
        ?string $tenantId = null
    ): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $insuranceCovered = round(max(0.0, $insuranceCovered), 2);

        $stmt = $this->db->prepare("SELECT * FROM insurance_claims WHERE invoice_id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$invoiceId, $tenantId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($insuranceCovered <= 0) {
            // Only withdraw a claim nobody has worked on yet
            if ($existing && in_array((string) $existing['status'], ['Submitted', 'Pending'], true)) {
                $this->deleteClaim((string) $existing['id'], $tenantId);
                return ['claim' => null, 'action' => 'removed'];
            }
            return ['claim' => $existing, 'action' => $existing ? 'unchanged' : 'skipped'];
        }

        $providerName = trim($providerName);
        if ($providerName === '') {
            return ['claim' => $existing, 'action' => 'skipped'];
        }

        if ($existing) {
            // A decided claim is the insurer's word; leave it alone
            if (!in_array((string) $existing['status'], ['Submitted', 'Pending'], true)) {
                return ['claim' => $existing, 'action' => 'unchanged'];
            }
            $claim = $this->editClaim(
                (string) $existing['id'],
                $providerName,
                $policyNumber !== '' ? $policyNumber : (string) $existing['policy_number'],
                $insuranceCovered,
                (string) $existing['status'],
                $existing['notes'] ?? null,
                $tenantId
            );
            if ($signature !== null && $signature !== '') {
                $claim = $this->recordSignature((string) $existing['id'], $signature, $signedBy, $tenantId) ?? $claim;
            }
            return ['claim' => $claim, 'action' => 'updated'];
        }

        $claim = $this->createClaim(
            $invoiceId,
            $patientId,
            $providerName,
            $policyNumber,
            $insuranceCovered,
            'Raised automatically from the invoice.',
            $tenantId
        );
        if ($signature !== null && $signature !== '') {
            $claim = $this->recordSignature((string) $claim['id'], $signature, $signedBy, $tenantId) ?? $claim;
        }
        return ['claim' => $claim, 'action' => 'created'];
    }

    /**
     * Store the member's signature against a claim. Only a drawn or uploaded
     * PNG/JPEG data URI is accepted, and anything larger than 400 KB is
     * refused rather than silently truncated by the column.
     */
    public function recordSignature(string $claimId, string $dataUri, ?string $signedBy = null, ?string $tenantId = null): ?array
    {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $clean = self::cleanSignature($dataUri);
        if ($clean === null) {
            throw new \InvalidArgumentException('That signature could not be read. Sign again, or upload a PNG or JPEG.');
        }
        $signedBy = $signedBy !== null ? mb_substr(trim($signedBy), 0, 120) : null;

        $stmt = $this->db->prepare("
            UPDATE insurance_claims
               SET patient_signature = :sig, signed_by_name = :name, signed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute(['sig' => $clean, 'name' => $signedBy, 'id' => $claimId, 'tid' => $tenantId]);

        $this->audit->log('SIGN_INSURANCE_CLAIM', "Signature captured for insurance claim {$claimId}", null, null, null, $tenantId);

        return $this->getClaimById($claimId, $tenantId);
    }

    /** Remove a signature, for when the wrong person signed. */
    public function clearSignature(string $claimId, ?string $tenantId = null): void
    {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("
            UPDATE insurance_claims
               SET patient_signature = NULL, signed_by_name = NULL, signed_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute(['id' => $claimId, 'tid' => $tenantId]);
        $this->audit->log('UNSIGN_INSURANCE_CLAIM', "Signature removed from insurance claim {$claimId}", null, null, null, $tenantId);
    }

    public const MAX_SIGNATURE_BYTES = 400 * 1024;

    /** Validate a signature data URI; null when it is not usable. */
    public static function cleanSignature(string $dataUri): ?string
    {
        $dataUri = trim($dataUri);
        if ($dataUri === '' || strlen($dataUri) > self::MAX_SIGNATURE_BYTES) {
            return null;
        }
        if (!preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=\s]+)$#', $dataUri, $m)) {
            return null;
        }
        $binary = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($binary === false || $binary === '') {
            return null;
        }
        // Make sure it really is the image it claims to be
        $info = @getimagesizefromstring($binary);
        $allowed = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];
        if (!$info || !in_array($info[2], $allowed, true)) {
            return null;
        }
        return 'data:image/' . $m[1] . ';base64,' . base64_encode($binary);
    }

    public function deleteClaim(string $id, ?string $tenantId = null): bool {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("DELETE FROM insurance_claims WHERE id = :id AND tenant_id = :tid");
        $result = $stmt->execute(['id' => $id, 'tid' => $tenantId]);

        if ($result) {
            $this->audit->log('DELETE_INSURANCE_CLAIM', "Deleted insurance claim {$id}", null, null, null, $tenantId);
        }

        return $result;
    }
}
