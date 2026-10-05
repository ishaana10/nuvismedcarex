<?php

namespace ClinicFlow\Services;

use ClinicFlow\Utils\Uuid;
use PDO;
use Throwable;

class PrescriptionVerificationService
{
    /**
     * Get an existing verification token for a prescription/visit, or create a new one.
     */
    public function getOrCreateToken(PDO $pdo, string $tenantId, string $patientId, ?string $visitId = null): string
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

        // Generate a new secure token
        $token = 'RXV-' . strtoupper(bin2hex(random_bytes(10)));
        $id = Uuid::uuidv7();

        $insertStmt = $pdo->prepare("INSERT INTO prescription_verifications (id, tenant_id, patient_id, visit_id, verification_token, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $insertStmt->execute([$id, $tenantId, $patientId, $visitIdClean, $token, date('Y-m-d H:i:s')]);

        return $token;
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
        $pStmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
        $pStmt->execute([$patientId]);
        $patient = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$patient) {
            return null;
        }

        // Fetch prescriptions
        if (!empty($visitId)) {
            $rxStmt = $pdo->prepare("SELECT * FROM prescriptions WHERE patient_id = ? AND visit_id = ? ORDER BY created_at ASC");
            $rxStmt->execute([$patientId, $visitId]);
        } else {
            $rxStmt = $pdo->prepare("SELECT * FROM prescriptions WHERE patient_id = ? ORDER BY created_at ASC");
            $rxStmt->execute([$patientId]);
        }
        $prescriptions = $rxStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch clinic settings
        $settingsRows = $pdo->query("SELECT * FROM clinic_settings")->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($settingsRows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }

        // Fetch doctor details
        $docStmt = $pdo->query("SELECT * FROM doctors WHERE role = 'Doctor' LIMIT 1");
        $attendingDoc = $docStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'name' => 'Dr. Sarah Jenkins',
            'specialty' => 'Internal Medicine',
            'prc_number' => 'PRC-0098412',
            'ptr_number' => 'PTR-8842109'
        ];

        // Fetch diagnosis / SOAP notes
        $soapStmt = $pdo->prepare("SELECT * FROM soap_notes WHERE patient_id = ? ORDER BY updated_at DESC LIMIT 1");
        $soapStmt->execute([$patientId]);
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
                'name' => $settings['clinic_name'] ?? 'Nuvis Medico Healthcare',
                'address' => $settings['clinic_address'] ?? '100 Healthcare Way, Suite 400, Springfield, OR 97477',
                'phone' => $settings['clinic_phone'] ?? '(555) 019-2831',
                'email' => $settings['clinic_email'] ?? 'medico@nuvistechnologies.com.fj',
                'dea' => $settings['clinic_dea'] ?? 'FC9823019',
                'npi' => $settings['clinic_npi'] ?? '1092830192',
                'disclaimer' => $settings['rx_disclaimer'] ?? 'Notice: This prescription is valid for 30 days from date of issue unless specified otherwise.'
            ],
            'doctor' => [
                'name' => $attendingDoc['name'],
                'specialty' => $attendingDoc['specialty'],
                'prc_number' => $attendingDoc['prc_number'] ?? ($settings['doc_prc_no'] ?? 'N/A'),
                'ptr_number' => $attendingDoc['ptr_number'] ?? ($settings['doc_ptr_no'] ?? 'N/A')
            ],
            'assessment' => !empty($assessmentCodes) ? ($assessmentCodes[0]['code'] . ' - ' . $assessmentCodes[0]['label']) : null,
            'prescriptions' => $prescriptions
        ];
    }
}
