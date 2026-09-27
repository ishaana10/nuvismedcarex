<?php
/**
 * Patient Registration Form POST Handler
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();
validateCsrfRequest();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../patients.php");
    exit;
}

$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$dob = trim($_POST['dob'] ?? '');
$gender = $_POST['gender'] ?? 'Female';
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');

$emergencyName = trim($_POST['emergency_contact_name'] ?? '');
$emergencyRel = trim($_POST['emergency_contact_relationship'] ?? '');
$emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');

$insuranceProvider = trim($_POST['insurance_provider'] ?? '');
$insurancePolicy = trim($_POST['insurance_policy_number'] ?? '');
$insuranceGroup = trim($_POST['insurance_group_number'] ?? '');

$allergies = trim($_POST['known_allergies'] ?? 'None');
$bloodGroup = $_POST['blood_group'] ?? 'O+';
$chronic = trim($_POST['chronic_conditions'] ?? '');

if ($firstName === '' || $lastName === '' || $dob === '') {
    setToast('Error', 'First name, last name, and date of birth are required.', 'error');
    header("Location: ../register_patient.php");
    exit;
}

// Calculate age
$birthYear = (int)date('Y', strtotime($dob));
$age = max(0, (int)date('Y') - $birthYear);

$mrnNum = rand(10000, 99999);
$mrn = "#" . $mrnNum;
$patientId = \ClinicFlow\Utils\Uuid::uuidv7();
$initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
$regDate = date('Y-m-d');

$pdo = getDB();

$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

$stmt = $pdo->prepare("INSERT INTO patients (id, tenant_id, mrn, first_name, last_name, dob, age, gender, phone, email, address, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, insurance_provider, insurance_policy_number, insurance_group_number, known_allergies, blood_group, chronic_conditions, initials, registration_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$stmt->execute([
    $patientId, $tenantId, $mrn, $firstName, $lastName, $dob, $age, $gender, $phone, $email, $address,
    $emergencyName, $emergencyRel, $emergencyPhone,
    $insuranceProvider, $insurancePolicy, $insuranceGroup,
    $allergies, $bloodGroup, $chronic, $initials, $regDate
]);

// Add to activity log
$actStmt = $pdo->prepare("INSERT INTO activities (id, tenant_id, type, title, detail, timestamp, badge_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
$actStmt->execute([
    \ClinicFlow\Utils\Uuid::uuidv7(),
    $tenantId,
    "patient_registered",
    "New Patient Registered: $firstName $lastName",
    "Just now • via Portal",
    "Just now",
    "emerald"
]);

setToast("Patient Registered", "$firstName $lastName ($mrn) was registered successfully.");
header("Location: ../patient_detail.php?id=$patientId");
exit;
