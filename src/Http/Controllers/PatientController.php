<?php
declare(strict_types=1);

namespace ClinicFlow\Http\Controllers;

use PDO;
use ClinicFlow\Application\Patient\RegisterPatientCommand;
use ClinicFlow\Application\Patient\RegisterPatientHandler;
use ClinicFlow\Repositories\PatientRepository;
use ClinicFlow\Services\AuditService;
use ClinicFlow\Shared\Container;

class PatientController {
    private PatientRepository $repo;
    private AuditService $audit;

    public function __construct(?PDO $db = null) {
        $pdo = $db ?? (function_exists('getDB') ? getDB() : Container::getInstance()->get(PDO::class));
        $this->repo = new PatientRepository($pdo);
        $this->audit = new AuditService($pdo);
    }

    public function register(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header("Location: ../patients.php");
            exit;
        }

        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $dob       = trim($_POST['dob'] ?? '');

        if ($firstName === '' || $lastName === '' || $dob === '') {
            setToast('Error', 'First name, last name, and date of birth are required.', 'error');
            header("Location: ../register_patient.php");
            exit;
        }

        $command = new RegisterPatientCommand(
            firstName: $firstName,
            lastName: $lastName,
            dateOfBirth: $dob,
            gender: $_POST['gender'] ?? 'Female',
            phone: trim($_POST['phone'] ?? '') ?: null,
            email: trim($_POST['email'] ?? '') ?: null,
            address: trim($_POST['address'] ?? '') ?: null,
            emergencyContactName: trim($_POST['emergency_contact_name'] ?? '') ?: null,
            emergencyContactRelationship: trim($_POST['emergency_contact_relationship'] ?? '') ?: null,
            emergencyContactPhone: trim($_POST['emergency_contact_phone'] ?? '') ?: null,
            insuranceProvider: trim($_POST['insurance_provider'] ?? '') ?: null,
            insurancePolicyNumber: trim($_POST['insurance_policy_number'] ?? '') ?: null,
            insuranceGroupNumber: trim($_POST['insurance_group_number'] ?? '') ?: null,
            knownAllergies: trim($_POST['known_allergies'] ?? 'None') ?: 'None',
            bloodGroup: $_POST['blood_group'] ?? 'O+',
            chronicConditions: trim($_POST['chronic_conditions'] ?? '') ?: null
        );

        $handler = Container::getInstance()->get(RegisterPatientHandler::class);
        $patient = $handler->handle($command);

        setToast("Patient Registered", "{$patient->firstName} {$patient->lastName} ({$patient->mrn->getValue()}) was registered successfully.");
        header("Location: ../patient_detail.php?id={$patient->id}");
        exit;
    }

    public function index(): void {
        $search = $_GET['search'] ?? null;
        $patients = $this->repo->findAll(100, 0, $search);
        if (function_exists('isAjaxRequest') && isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['patients' => $patients]);
            exit;
        }
    }

    public function store(): void {
        if (function_exists('validateCsrfRequest')) {
            validateCsrfRequest();
        }
        $mrn = trim($_POST['mrn'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');

        if ($mrn === '' || $firstName === '' || $lastName === '') {
            if (function_exists('isAjaxRequest') && isAjaxRequest()) {
                http_response_code(400);
                echo json_encode(['error' => 'MRN, First Name, and Last Name are required.']);
                exit;
            }
            setToast('Error', 'MRN, First Name, and Last Name are required.', 'error');
            header('Location: register_patient.php');
            exit;
        }

        $id = $this->repo->create([
            'mrn' => $mrn,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'dob' => $_POST['dob'] ?? '1990-01-01',
            'age' => (int)($_POST['age'] ?? 30),
            'gender' => $_POST['gender'] ?? 'Other',
            'phone' => $_POST['phone'] ?? null,
            'email' => $_POST['email'] ?? null,
            'address' => $_POST['address'] ?? null,
            'emergency_contact_name' => $_POST['emergency_contact_name'] ?? null,
            'blood_group' => $_POST['blood_group'] ?? null,
            'known_allergies' => $_POST['known_allergies'] ?? null,
            'chronic_conditions' => $_POST['chronic_conditions'] ?? null
        ]);

        $this->audit->log('REGISTER_PATIENT', "Registered patient {$firstName} {$lastName} ({$mrn})");

        if (function_exists('isAjaxRequest') && isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }

        setToast('Success', 'Patient registered successfully.');
        header("Location: patient_detail.php?id={$id}");
        exit;
    }

    public function update(string $id): void {
        if (function_exists('validateCsrfRequest')) {
            validateCsrfRequest();
        }
        $updated = $this->repo->update($id, $_POST);
        if ($updated) {
            $this->audit->log('UPDATE_PATIENT', "Updated patient record {$id}");
        }

        if (function_exists('isAjaxRequest') && isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $updated]);
            exit;
        }

        setToast('Success', 'Patient updated successfully.');
        header("Location: patient_detail.php?id={$id}");
        exit;
    }
}
