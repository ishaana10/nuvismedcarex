<?php

namespace ClinicFlow\Services;

use ClinicFlow\Services\ClinicSettings;
use ClinicFlow\Utils\Uuid;
use PDO;
use Throwable;

class PrescriptionVerificationService
{
    /**
     * Get an existing verification token for a prescription/visit, or create a new one.
     */
    public function getOrCreateToken(PDO $pdo, string $tenantId, string $patientId, ?string $visitId = null, ?string $doctorId = null): string
    {
        $visitIdClean = !empty($visitId) ? $visitId : null;

        if ($visitIdClean !== null) {
            $stmt = $pdo->prepare("SELECT verification_token FROM prescription_verifications WHERE tenant_id = ? AND patient_id = ? AND visit_id = ? LIMIT 1");
            $stmt->execute([$tenantId, $patientId, $visitIdClean]);
        } else {
            $stmt = $pdo->prepare("SELECT verification_token FROM prescription_verifications WHERE tenant_id = ? AND patient_id = ? AND (visit_id IS NULL OR visit_id = '') LIMIT 1");
            $stmt->execute([$tenantId, $patientId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['verification_token'])) {
            return $row['verification_token'];
        }
        $hasDoctorCol = $this->hasDoctorColumn($pdo);

        // Generate a new secure token
        $token = 'RXV-' . strtoupper(bin2hex(random_bytes(10)));
        $id = Uuid::uuidv7();

        if ($hasDoctorCol) {
            $insertStmt = $pdo->prepare("INSERT INTO prescription_verifications (id, tenant_id, patient_id, visit_id, doctor_id, verification_token, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insertStmt->execute([$id, $tenantId, $patientId, $visitIdClean, $doctorId, $token, date('Y-m-d H:i:s')]);
        } else {
            $insertStmt = $pdo->prepare("INSERT INTO prescription_verifications (id, tenant_id, patient_id, visit_id, verification_token, created_at) VALUES (?, ?, ?, ?, ?, ?)");
            $insertStmt->execute([$id, $tenantId, $patientId, $visitIdClean, $token, date('Y-m-d H:i:s')]);
        }

        return $token;
    }

    private function hasDoctorColumn(PDO $pdo): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = (bool) $pdo->query("SHOW COLUMNS FROM prescription_verifications LIKE 'doctor_id'")->fetch();
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /**
     * Get public verification link URL for a given token.
     */
    public function getVerificationUrl(string $token): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/index.php';
        $dir = dirname($scriptName);
        $basePath = ($dir === '/' || $dir === '\\') ? '' : rtrim(str_replace('\\', '/', $dir), '/');

        return "{$protocol}://{$host}{$basePath}/verify_prescription.php?token=" . urlencode($token);
    }

    /**
     * Resolve verification details for a token.
     */
    public function getVerificationDetails(PDO $pdo, string $token): ?array
    {
        if (empty($token)) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT * FROM prescription_verifications WHERE verification_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $verification = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$verification) {
            return null;
        }

        $patientId = $verification['patient_id'];
        $visitId = $verification['visit_id'] ?? null;
        $tenantId = $verification['tenant_id'];

        // Fetch patient
        $pStmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND tenant_id = ?");
        $pStmt->execute([$patientId, $tenantId]);
        $patient = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$patient) {
            return null;
        }

        // Fetch prescriptions
        if (!empty($visitId)) {
            $rxStmt = $pdo->prepare("SELECT * FROM prescriptions WHERE patient_id = ? AND visit_id = ? AND tenant_id = ? ORDER BY created_at ASC");
            $rxStmt->execute([$patientId, $visitId, $tenantId]);
        } else {
            $rxStmt = $pdo->prepare("SELECT * FROM prescriptions WHERE patient_id = ? AND tenant_id = ? ORDER BY created_at ASC");
            $rxStmt->execute([$patientId, $tenantId]);
        }
        $prescriptions = $rxStmt->fetchAll(PDO::FETCH_ASSOC);

        // Details of the clinic that issued this prescription, not whichever
        // clinic happens to be active (this page is opened by patients)
        $clinicSettings = new ClinicSettings($pdo, (string) $tenantId);
        $settings = $clinicSettings->all();
        $issuingClinic = $clinicSettings->profile();

        // Fetch doctor details
        // The prescriber recorded when the link was created, and only if they
        // belong to the issuing clinic. Never "some doctor": this page exists so a
        // pharmacist can trust who issued the prescription. Older links with no
        // recorded prescriber show the clinic only.
        $attendingDoc = null;
        if (!empty($verification['doctor_id'])) {
            $docStmt = $pdo->prepare("SELECT name, specialty, prc_number, ptr_number FROM doctors WHERE id = ? AND tenant_id = ? LIMIT 1");
            $docStmt->execute([$verification['doctor_id'], $tenantId]);
            $attendingDoc = $docStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $attendingDoc = $attendingDoc ?: ['name' => 'Prescriber not recorded for this older link', 'specialty' => '', 'prc_number' => 'N/A', 'ptr_number' => 'N/A'];

        // Fetch diagnosis / SOAP notes
        $soapStmt = $pdo->prepare("SELECT * FROM soap_notes WHERE patient_id = ? AND tenant_id = ? ORDER BY updated_at DESC LIMIT 1");
        $soapStmt->execute([$patientId, $tenantId]);
        $soap = $soapStmt->fetch(PDO::FETCH_ASSOC);
        $assessmentCodes = json_decode($soap['assessment_codes'] ?? '[]', true) ?: [];

        return [
            'valid' => true,
            'token' => $token,
            'issued_at' => $verification['created_at'],
            'patient' => [
                'id' => $patient['id'],
                'name' => $patient['first_name'] . ' ' . $patient['last_name'],
                'mrn' => $patient['mrn'],
                'dob' => $patient['dob'],
                'allergies' => $patient['known_allergies'] ?: 'NKDA'
            ],
            'clinic' => [
                'name' => $issuingClinic['name'],
                'address' => $issuingClinic['address'],
                'phone' => $issuingClinic['phone'],
                'email' => $issuingClinic['email'],
                'disclaimer' => $settings['rx_disclaimer'] ?? 'Notice: This prescription is valid for 30 days from date of issue unless specified otherwise.'
            ],
            'doctor' => [
                'name' => $attendingDoc['name'],
                'specialty' => $attendingDoc['specialty'],
                'prc_number' => $attendingDoc['prc_number'] ?? ($settings['doc_prc_no'] ?? 'N/A')
            ],
            'assessment' => !empty($assessmentCodes) ? ($assessmentCodes[0]['code'] . ' - ' . $assessmentCodes[0]['label']) : null,
            'prescriptions' => $prescriptions
        ];
    }
}
