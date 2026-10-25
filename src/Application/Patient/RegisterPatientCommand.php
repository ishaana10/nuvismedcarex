<?php
declare(strict_types=1);

namespace ClinicFlow\Application\Patient;

final class RegisterPatientCommand
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $dateOfBirth,
        public readonly string $gender = 'Female',
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
        public readonly ?string $chronicConditions = null
    ) {}
}
