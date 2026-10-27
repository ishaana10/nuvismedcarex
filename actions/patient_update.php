<?php
/**
 * Action Handler: Update Patient Details (Editable by Doctors & Administrators)
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();
validateCsrfRequest();

$userRole = $_SESSION['user']['role'] ?? '';
if (!in_array($userRole, ['Doctor', 'Administrator', 'Developer', 'Admin'])) {
    setToast("Access Denied", "Only Doctors and Administrators can modify patient details.", "error");
    header("Location: ../patients.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../patients.php");
    exit;
}

$pdo = getDB();

$patientId = trim($_POST['patient_id'] ?? '');
if (empty($patientId)) {
    setToast("Error", "Missing patient identifier.", "error");
    header("Location: ../patients.php");
    exit;
}

$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$dob = trim($_POST['dob'] ?? '');
$gender = trim($_POST['gender'] ?? 'Other');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');

$emergencyContactName = trim($_POST['emergency_contact_name'] ?? '');
$emergencyContactRelationship = trim($_POST['emergency_contact_relationship'] ?? '');
$emergencyContactPhone = trim($_POST['emergency_contact_phone'] ?? '');

$insuranceProvider = trim($_POST['insurance_provider'] ?? '');
// "Another insurer…" carries the name in its own box
if ($insuranceProvider === '__other__') {
    $insuranceProvider = \ClinicFlow\Services\InsuranceReportSettings::cleanProviderName(
        (string) ($_POST['insurance_provider_other'] ?? '')
    );
}
$insurancePolicyNumber = trim($_POST['insurance_policy_number'] ?? '');
$insuranceGroupNumber = trim($_POST['insurance_group_number'] ?? '');

$knownAllergies = trim($_POST['known_allergies'] ?? '');
$bloodGroup = trim($_POST['blood_group'] ?? '');
$chronicConditions = trim($_POST['chronic_conditions'] ?? '');
$clinicalNotes = trim($_POST['clinical_notes'] ?? '');

if (empty($firstName) || empty($lastName) || empty($dob)) {
    setToast("Missing Information", "First name, last name, and date of birth are required.", "error");
    header("Location: ../patient_detail.php?id=" . urlencode($patientId));
    exit;
}

// Calculate age automatically
$age = date_diff(date_create($dob), date_create('today'))->y;

// Check existing record
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND tenant_id = ?");
$stmt->execute([$patientId, \ClinicFlow\Shared\TenantContext::getTenantId()]);
$existing = $stmt->fetch();

if (!$existing) {
    setToast("Error", "Patient record not found.", "error");
    header("Location: ../patients.php");
    exit;
}

// Generate initials
$initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));

$updateSql = "UPDATE patients SET
    first_name = ?,
    last_name = ?,
    dob = ?,
    age = ?,
    gender = ?,
    phone = ?,
    email = ?,
    address = ?,
    emergency_contact_name = ?,
    emergency_contact_relationship = ?,
    emergency_contact_phone = ?,
    insurance_provider = ?,
    insurance_policy_number = ?,
    insurance_group_number = ?,
    known_allergies = ?,
    blood_group = ?,
    chronic_conditions = ?,
    clinical_notes = ?,
    initials = ?
WHERE id = ?";

$stmt = $pdo->prepare($updateSql);
$stmt->execute([
    $firstName,
    $lastName,
    $dob,
    $age,
    $gender,
    $phone,
    $email,
    $address,
    $emergencyContactName,
    $emergencyContactRelationship,
    $emergencyContactPhone,
    $insuranceProvider,
    $insurancePolicyNumber,
    $insuranceGroupNumber,
    $knownAllergies,
    $bloodGroup,
    $chronicConditions,
    $clinicalNotes,
    $initials,
    $patientId
]);

setToast("Patient Updated", "Patient record for " . htmlspecialchars($firstName . ' ' . $lastName) . " updated successfully.");
header("Location: ../patient_detail.php?id=" . urlencode($patientId));
exit;
