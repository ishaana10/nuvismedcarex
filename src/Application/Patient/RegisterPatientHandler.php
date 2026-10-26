<?php
declare(strict_types=1);

namespace ClinicFlow\Application\Patient;

use ClinicFlow\Domain\Patient\Patient;
use ClinicFlow\Repositories\PatientRepository;
use ClinicFlow\Shared\TenantContext;
use ClinicFlow\Services\AuditService;
use ClinicFlow\Utils\Uuid;
use DateTimeImmutable;
use PDO;

final class RegisterPatientHandler
{
    public function __construct(
        private PatientRepository $patientRepository,
        private TenantContext $tenantContext,
        private AuditService $auditService,
        private PDO $db
    ) {}

    public function handle(RegisterPatientCommand $command): Patient
    {
        $tenantId = $this->tenantContext->getTenantId();

        $dob = new DateTimeImmutable($command->dateOfBirth);

        $patient = Patient::create(
            tenantId: $tenantId,
            firstName: $command->firstName,
            lastName: $command->lastName,
            dob: $dob,
            gender: $command->gender,
            phone: $command->phone,
            email: $command->email,
            address: $command->address,
            emergencyContactName: $command->emergencyContactName,
            emergencyContactRelationship: $command->emergencyContactRelationship,
            emergencyContactPhone: $command->emergencyContactPhone,
            insuranceProvider: $command->insuranceProvider,
            insurancePolicyNumber: $command->insurancePolicyNumber,
            insuranceGroupNumber: $command->insuranceGroupNumber,
            knownAllergies: $command->knownAllergies,
            bloodGroup: $command->bloodGroup,
            chronicConditions: $command->chronicConditions
        );

        $this->patientRepository->save($patient);

        // Record Activity Log
        $actStmt = $this->db->prepare(
            "INSERT INTO activities (id, tenant_id, type, title, detail, timestamp, badge_type) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $actStmt->execute([
            Uuid::uuidv7(),
            $tenantId,
            "patient_registered",
            "New Patient Registered: {$patient->firstName} {$patient->lastName}",
            "via Portal",
            date('Y-m-d H:i:s'),
            "emerald"
        ]);

        // Record Audit Log
        $this->auditService->log(
            'PATIENT_REGISTERED',
            "Registered new patient {$patient->firstName} {$patient->lastName} ({$patient->mrn->getValue()})",
            null,
            null,
            null,
            $tenantId,
            $patient->id
        );

        return $patient;
    }
}
