<?php
declare(strict_types=1);

namespace ClinicFlow\Http\Controllers;

use PDO;
use ClinicFlow\Repositories\PatientRepository;
use ClinicFlow\Services\AuditService;

class PatientController {
    private PatientRepository $repo;
    private AuditService $audit;

    public function __construct(PDO $db) {
        $this->repo = new PatientRepository($db);
        $this->audit = new AuditService($db);
    }

    public function index(): void {
        $search = $_GET['search'] ?? null;
        $patients = $this->repo->findAll(100, 0, $search);
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['patients' => $patients]);
            exit;
        }
    }

    public function store(): void {
        validateCsrfRequest();
        $mrn = trim($_POST['mrn'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');

        if ($mrn === '' || $firstName === '' || $lastName === '') {
            if (isAjaxRequest()) {
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

        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }

        setToast('Success', 'Patient registered successfully.');
        header("Location: patient_detail.php?id={$id}");
        exit;
    }

    public function update(string $id): void {
        validateCsrfRequest();
        $updated = $this->repo->update($id, $_POST);
        if ($updated) {
            $this->audit->log('UPDATE_PATIENT', "Updated patient record {$id}");
        }

        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $updated]);
            exit;
        }

        setToast('Success', 'Patient updated successfully.');
        header("Location: patient_detail.php?id={$id}");
        exit;
    }
}
