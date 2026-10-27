<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ClinicFlow\Domain\ValueObjects\MRN;
use ClinicFlow\Domain\Patient\Patient;
use ClinicFlow\Application\Patient\RegisterPatientCommand;
use ClinicFlow\Application\Patient\RegisterPatientHandler;
use ClinicFlow\Repositories\PatientRepository;
use ClinicFlow\Shared\TenantContext;
use ClinicFlow\Services\AuditService;

class PatientRegistrationTest extends TestCase {
    private PDO $pdo;
    private PatientRepository $repository;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE patients (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                mrn VARCHAR(50) NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                dob DATE NOT NULL,
                age INT NOT NULL,
                gender VARCHAR(20) NOT NULL,
                phone VARCHAR(50),
                email VARCHAR(255),
                address TEXT,
                emergency_contact_name VARCHAR(255),
                emergency_contact_relationship VARCHAR(100),
                emergency_contact_phone VARCHAR(50),
                insurance_provider VARCHAR(255),
                insurance_policy_number VARCHAR(100),
                insurance_group_number VARCHAR(100),
                known_allergies TEXT,
                blood_group VARCHAR(10),
                chronic_conditions TEXT,
                initials VARCHAR(10),
                registration_date DATE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE activities (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                type VARCHAR(50),
                title VARCHAR(255),
                detail TEXT,
                timestamp DATETIME,
                badge_type VARCHAR(50)
            );

            CREATE TABLE audit_logs (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50),
                user_id VARCHAR(50),
                user_name VARCHAR(100),
                user_role VARCHAR(50),
                patient_id VARCHAR(50),
                action VARCHAR(100),
                details TEXT,
                ip_address VARCHAR(45),
                created_at DATETIME
            );
        ");

        $this->repository = new PatientRepository($this->pdo);
    }

    public function testMRNValueObject(): void {
        $mrn = MRN::generate('#');
        $this->assertStringStartsWith('#', $mrn->getValue());
        $this->assertEquals($mrn->getValue(), (string)$mrn);

        $customMrn = MRN::fromString('#99999');
        $this->assertEquals('#99999', $customMrn->getValue());
    }

    public function testPatientDomainEntityCreation(): void {
        $dob = new DateTimeImmutable('1995-06-15');
        $patient = Patient::create(
            tenantId: 'clinic-test',
            firstName: 'John',
            lastName: 'Doe',
            dob: $dob,
            gender: 'Male',
            phone: '555-1234'
        );

        $this->assertEquals('clinic-test', $patient->tenantId);
        $this->assertEquals('John', $patient->firstName);
        $this->assertEquals('Doe', $patient->lastName);
        $this->assertEquals('JD', $patient->initials);
        $this->assertGreaterThan(0, $patient->age);
    }

    public function testRegisterPatientHandlerUseCase(): void {
        $tenantContext = new TenantContext('clinic-test');
        $auditService = new AuditService($this->pdo);

        $handler = new RegisterPatientHandler(
            $this->repository,
            $tenantContext,
            $auditService,
            $this->pdo
        );

        $command = new RegisterPatientCommand(
            firstName: 'Jane',
            lastName: 'Smith',
            dateOfBirth: '1990-01-01',
            gender: 'Female',
            phone: '999-8888',
            email: 'jane@example.com',
            address: '123 Main St',
            emergencyContactName: 'Bob Smith',
            emergencyContactRelationship: 'Spouse',
            emergencyContactPhone: '999-7777',
            insuranceProvider: 'HealthCorp',
            insurancePolicyNumber: 'POL-123',
            insuranceGroupNumber: 'GRP-456',
            knownAllergies: 'Penicillin',
            bloodGroup: 'A+',
            chronicConditions: 'Asthma'
        );

        $patient = $handler->handle($command);

        $this->assertNotNull($patient->id);
        $this->assertEquals('Jane', $patient->firstName);
        $this->assertEquals('Smith', $patient->lastName);

        // Verify database persistence
        $saved = $this->repository->findById($patient->id);
        $this->assertNotNull($saved);
        $this->assertEquals('Jane Smith', $saved['full_name']);
        $this->assertEquals('Penicillin', $saved['known_allergies']);
        $this->assertEquals('HealthCorp', $saved['insurance_provider']);

        // Verify activity log entry
        $actStmt = $this->pdo->query("SELECT * FROM activities WHERE tenant_id = 'clinic-test'");
        $activity = $actStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($activity);
        $this->assertStringContainsString('Jane Smith', $activity['title']);

        // Verify audit log entry
        $auditStmt = $this->pdo->query("SELECT * FROM audit_logs WHERE tenant_id = 'clinic-test'");
        $audit = $auditStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($audit);
        $this->assertEquals('PATIENT_REGISTERED', $audit['action']);
    }
}
