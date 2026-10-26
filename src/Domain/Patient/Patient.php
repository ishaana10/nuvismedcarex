<?php
declare(strict_types=1);

namespace ClinicFlow\Domain\Patient;

use ClinicFlow\Domain\ValueObjects\MRN;
use ClinicFlow\Utils\Uuid;
use DateTimeImmutable;

final class Patient
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly MRN $mrn,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly DateTimeImmutable $dateOfBirth,
        public readonly int $age,
        public readonly string $gender,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $address = null,
        public readonly ?string $emergencyContactName = null,
        public readonly ?string $emergencyContactRelationship = null,
        public readonly ?string $emergencyContactPhone = null,
        public readonly ?string $insuranceProvider = null,
        public readonly ?string $insurancePolicyNumber = null,
        public readonly ?string $insuranceGroupNumber = null,
        public readonly string $knownAllergies = 'None',
        public readonly string $bloodGroup = 'O+',
        public readonly ?string $chronicConditions = null,
        public readonly ?string $initials = null,
        public readonly ?string $registrationDate = null
    ) {}

    public static function create(
        string $tenantId,
        string $firstName,
        string $lastName,
        DateTimeImmutable $dob,
        string $gender = 'Female',
        ?string $phone = null,
        ?string $email = null,
        ?string $address = null,
        ?string $emergencyContactName = null,
        ?string $emergencyContactRelationship = null,
        ?string $emergencyContactPhone = null,
        ?string $insuranceProvider = null,
        ?string $insurancePolicyNumber = null,
        ?string $insuranceGroupNumber = null,
        string $knownAllergies = 'None',
        string $bloodGroup = 'O+',
        ?string $chronicConditions = null,
        ?MRN $mrn = null,
        ?string $id = null
    ): self {
        $age = max(0, (new DateTimeImmutable())->diff($dob)->y);
        $initials = strtoupper(
            substr($firstName, 0, 1) . substr($lastName, 0, 1)
        );
        $registrationDate = date('Y-m-d');

        return new self(
            id: $id ?? Uuid::uuidv7(),
            tenantId: $tenantId,
            mrn: $mrn ?? MRN::generate('#'),
            firstName: $firstName,
            lastName: $lastName,
            dateOfBirth: $dob,
            age: $age,
            gender: $gender,
            phone: $phone,
            email: $email,
            address: $address,
            emergencyContactName: $emergencyContactName,
            emergencyContactRelationship: $emergencyContactRelationship,
            emergencyContactPhone: $emergencyContactPhone,
            insuranceProvider: $insuranceProvider,
            insurancePolicyNumber: $insurancePolicyNumber,
            insuranceGroupNumber: $insuranceGroupNumber,
            knownAllergies: $knownAllergies,
            bloodGroup: $bloodGroup,
            chronicConditions: $chronicConditions,
            initials: $initials,
            registrationDate: $registrationDate
        );
    }
}
