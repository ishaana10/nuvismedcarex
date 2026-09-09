<?php
use PHPUnit\Framework\TestCase;
use ClinicFlow\Repositories\PatientRepository;

class PatientRepositoryTest extends TestCase {
    private PDO $pdo;
    private PatientRepository $repo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE patients (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                mrn VARCHAR(50) UNIQUE NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                dob DATE NOT NULL,
                age INT NOT NULL,
                gender VARCHAR(20) NOT NULL,
                phone VARCHAR(50),
                email VARCHAR(255),
                address TEXT,
                emergency_contact_name VARCHAR(255),
                blood_group VARCHAR(10),
                known_allergies TEXT,
                chronic_conditions TEXT,
                registration_date DATE,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            );
        ");
        $this->repo = new PatientRepository($this->pdo);
    }

    public function testCreateAndFindPatient() {
        $patientId = $this->repo->create([
            'id' => 'pat-999',
            'mrn' => 'MRN-1001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'gender' => 'Male',
            'phone' => '123456789'
        ]);

        $this->assertEquals('pat-999', $patientId);
        $patient = $this->repo->findById($patientId);
        $this->assertNotNull($patient);
        $this->assertEquals('John Doe', $patient['full_name']);
        $this->assertEquals('MRN-1001', $patient['mrn']);
    }

    public function testFindByMrn() {
        $this->repo->create([
            'mrn' => 'MRN-2002',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'gender' => 'Female'
        ]);

        $patient = $this->repo->findByMrn('MRN-2002');
        $this->assertNotNull($patient);
        $this->assertEquals('Jane Smith', $patient['full_name']);
    }

    public function testFindAllActiveAndSearchAndUpdate() {
        $id = $this->repo->create([
            'mrn' => 'MRN-3003',
            'first_name' => 'Alice',
            'last_name' => 'Wonderland',
            'gender' => 'Female'
        ]);

        $active = $this->repo->findAllActive();
        $this->assertNotEmpty($active);

        $results = $this->repo->search('Alice');
        $this->assertCount(1, $results);

        $updated = $this->repo->update($id, ['first_name' => 'AliceUpdated']);
        $this->assertTrue($updated);

        $p = $this->repo->findById($id);
        $this->assertEquals('AliceUpdated', $p['first_name']);
    }
}
