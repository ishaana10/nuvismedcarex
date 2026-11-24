<?php
/**
 * Patient Registration Action Handler (Thin Bootstrap)
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
// "Another insurer…" carries the name in its own box
if ($insuranceProvider === '__other__') {
    $insuranceProvider = \ClinicFlow\Services\InsuranceReportSettings::cleanProviderName(
        (string) ($_POST['insurance_provider_other'] ?? '')
    );
}
$insurancePolicy = trim($_POST['insurance_policy_number'] ?? '');
$insuranceGroup = trim($_POST['insurance_group_number'] ?? '');

$allergies = trim($_POST['known_allergies'] ?? ($_POST === [] || nxCanViewCharts() ? 'None' : ''));
$bloodGroup = $_POST['blood_group'] ?? '';
$chronic = trim($_POST['chronic_conditions'] ?? '');
$clinicalNotes = trim($_POST['clinical_notes'] ?? '');
$bloodGroup = trim((string) $bloodGroup);

if ($firstName === '' || $lastName === '' || $dob === '') {
    setToast('Error', 'First name, last name, and date of birth are required.', 'error');
    header("Location: ../register_patient.php");
    exit;
}

// Refuse impossible dates and unusable phone numbers, and keep what was typed so nothing is lost
$problem = \ClinicFlow\Services\PatientIntake::firstProblem($_POST);
if ($problem === null && empty($_POST['confirm_duplicate'])) {
    $dup = \ClinicFlow\Services\PatientIntake::findDuplicate(getDB(), \ClinicFlow\Shared\TenantContext::getTenantId(), $firstName, $lastName, $dob);
    if ($dup !== null) {
        $_SESSION['patient_duplicate'] = $dup;
        $problem = $dup['name'] . ' (' . $dup['mrn'] . ') is already registered with this date of birth. If this is a different person, tick "register anyway" and save again.';
    }
}
if ($problem !== null) {
    $_SESSION['patient_old'] = array_diff_key($_POST, ['csrf_token' => 1]);
    setToast('Check the details', $problem, 'error');
    header("Location: ../register_patient.php");
    exit;
}
unset($_SESSION['patient_old'], $_SESSION['patient_duplicate']);

// Calculate age
$birthYear = (int)date('Y', strtotime($dob));
$age = max(0, date_diff(date_create($dob), date_create('today'))->y);

$patientId = \ClinicFlow\Utils\Uuid::uuidv7();
$initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
$regDate = date('Y-m-d');

$pdo = getDB();

$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

$stmt = $pdo->prepare("INSERT INTO patients (id, tenant_id, mrn, first_name, last_name, dob, age, gender, phone, email, address, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, insurance_provider, insurance_policy_number, insurance_group_number, known_allergies, blood_group, chronic_conditions, clinical_notes, initials, registration_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

// Patient numbers run on from the clinic's highest, so two patients never share one
[$mrn] = \ClinicFlow\Services\DocumentNumbers::issue($pdo, $tenantId, 'patients', 'mrn', '#', 5, function (string $mrn) use ($stmt, $patientId, $tenantId, $firstName, $lastName, $dob, $age, $gender, $phone, $email, $address, $emergencyName, $emergencyRel, $emergencyPhone, $insuranceProvider, $insurancePolicy, $insuranceGroup, $allergies, $bloodGroup, $chronic, $clinicalNotes, $initials, $regDate) {
    $stmt->execute([
        $patientId, $tenantId, $mrn, $firstName, $lastName, $dob, $age, $gender, $phone, $email, $address,
        $emergencyName, $emergencyRel, $emergencyPhone,
        $insuranceProvider, $insurancePolicy, $insuranceGroup,
        $allergies, $bloodGroup, $chronic, $clinicalNotes, $initials, $regDate
    ]);
}, 10001);

\ClinicFlow\Services\PatientIdentifiers::save($pdo, $tenantId, $patientId, \ClinicFlow\Services\PatientIdentifiers::fromPost($_POST));

// Add to activity log
$actStmt = $pdo->prepare("INSERT INTO activities (id, tenant_id, type, title, detail, timestamp, badge_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
$actStmt->execute([
    \ClinicFlow\Utils\Uuid::uuidv7(),
    $tenantId,
    "patient_registered",
    "New Patient Registered: $firstName $lastName",
    "via Portal",
    date('Y-m-d H:i:s'),
    "emerald"
]);

setToast("Patient Registered", "$firstName $lastName ($mrn) was registered successfully.");
header("Location: " . nxPatientLanding((string) $patientId, '../'));
exit;
$controller = new \ClinicFlow\Http\Controllers\PatientController();
$controller->register();
