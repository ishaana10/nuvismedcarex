<?php

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;
use ClinicFlow\Utils\Encryption;
use ClinicFlow\Shared\TenantContext;

class EncounterService {
    private PDO $db;
    private AuditService $audit;

    public function __construct(PDO $db, ?AuditService $audit = null) {
        $this->db = $db;
        $this->audit = $audit ?? new AuditService($db);
    }

    public function getEncountersByPatient(string $patientId, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM past_visits WHERE patient_id = :pid AND tenant_id = :tid ORDER BY created_at DESC");
        $stmt->execute(['pid' => $patientId, 'tid' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            if (!empty($row['soap_notes'])) {
                $decryptedSoap = Encryption::decrypt($row['soap_notes']);
                $jsonSoap = json_decode($decryptedSoap, true);
                $row['soap_notes_parsed'] = $jsonSoap ?: $decryptedSoap;
            }
            if (!empty($row['vitals'])) {
                $decryptedVitals = Encryption::decrypt($row['vitals']);
                $row['vitals_parsed'] = json_decode($decryptedVitals, true) ?: $decryptedVitals;
            }
        }

        return $rows;
    }

    public function saveEncounter(
        string $patientId,
        array $vitalsData,
        array $soapData,
        array $prescriptions = [],
        bool $finalize = false,
        ?string $visitId = null,
        ?string $tenantId = null
    ): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $visitId = $visitId ?: Uuid::uuidv7();
        $doctorName = $_SESSION['user_name'] ?? $_SESSION['user']['name'] ?? 'Attending Physician';

        // 1. Update / Insert Vitals
        $vStmt = $this->db->prepare("DELETE FROM vitals WHERE patient_id = ? AND tenant_id = ?");
        $vStmt->execute([$patientId, $tenantId]);

        $vInsert = $this->db->prepare(
            "INSERT INTO vitals (id, tenant_id, patient_id, blood_pressure, heart_rate, temperature, oxygen_sat, weight, height, bmi) " .
            "VALUES (:id, :tid, :pid, :bp, :hr, :temp, :spo2, :wt, :ht, :bmi)"
        );
        $vInsert->execute([
            'id' => Uuid::uuidv7(),
            'tid' => $tenantId,
            'pid' => $patientId,
            'bp' => $vitalsData['blood_pressure'] ?? '120/80',
            'hr' => (int)($vitalsData['heart_rate'] ?? 72),
            'temp' => (float)($vitalsData['temperature'] ?? 98.6),
            'spo2' => (int)($vitalsData['oxygen_sat'] ?? 99),
            'wt' => (int)($vitalsData['weight'] ?? 145),
            'ht' => (int)($vitalsData['height'] ?? 66),
            'bmi' => (float)($vitalsData['bmi'] ?? 23.4)
        ]);

        // 2. Encrypt and save SOAP Notes
        $sStmt = $this->db->prepare("DELETE FROM soap_notes WHERE patient_id = ? AND tenant_id = ?");
        $sStmt->execute([$patientId, $tenantId]);

        $rawSoapJson = json_encode($soapData);
        $encryptedSoap = Encryption::encrypt($rawSoapJson);

        $sInsert = $this->db->prepare(
            "INSERT INTO soap_notes (id, tenant_id, patient_id, subjective, objective, assessment_codes, plan) " .
            "VALUES (:id, :tid, :pid, :sub, :obj, :codes, :plan)"
        );
        $sInsert->execute([
            'id' => Uuid::uuidv7(),
            'tid' => $tenantId,
            'pid' => $patientId,
            'sub' => Encryption::encrypt($soapData['subjective'] ?? ''),
            'obj' => Encryption::encrypt($soapData['objective'] ?? ''),
            'codes' => json_encode([['code' => $soapData['icd_code'] ?? 'J01.90', 'label' => $soapData['icd_code'] ?? 'J01.90']]),
            'plan' => Encryption::encrypt($soapData['plan'] ?? '')
        ]);

        $pastVisitId = null;

        if ($finalize) {
            $pastVisitId = Uuid::uuidv7();
            $icdCode = $soapData['icd_code'] ?? 'J01.90';
            $title = "Clinical Encounter ({$icdCode})";
            $planText = $soapData['plan'] ?? '';
            $summary = !empty($planText) ? (substr($planText, 0, 120) . (strlen($planText) > 120 ? '...' : '')) : "Clinical encounter completed.";

            $pvInsert = $this->db->prepare(
                "INSERT INTO past_visits (id, tenant_id, patient_id, visit_id, visit_date, title, summary, doctor_name, vitals, soap_notes, prescriptions) " .
                "VALUES (:id, :tid, :pid, :vid, :vdate, :title, :summary, :doc, :vitals, :soaps, :rxs)"
            );
            $pvInsert->execute([
                'id' => $pastVisitId,
                'tid' => $tenantId,
                'pid' => $patientId,
                'vid' => $visitId,
                'vdate' => date('M d, Y'),
                'title' => $title,
                'summary' => $summary,
                'doc' => $doctorName,
                'vitals' => Encryption::encrypt(json_encode($vitalsData)),
                'soaps' => $encryptedSoap,
                'rxs' => json_encode($prescriptions)
            ]);

            // Remove patient from queue
            $qStmt = $this->db->prepare("DELETE FROM queue WHERE patient_id = ? AND tenant_id = ?");
            $qStmt->execute([$patientId, $tenantId]);

            // Update appointment status to Completed if appointment_id is provided or matched
            $appointmentId = $_REQUEST['appointment_id'] ?? null;
            if ($appointmentId) {
                $apptStmt = $this->db->prepare("UPDATE appointments SET status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?");
                $apptStmt->execute([$appointmentId, $tenantId]);
            } else {
                // Otherwise update any active or in-progress appointment for this patient today/recent
                $apptStmt = $this->db->prepare("UPDATE appointments SET status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE patient_id = ? AND tenant_id = ? AND (status != 'Completed' OR status IS NULL)");
                $apptStmt->execute([$patientId, $tenantId]);
            }
        }

        $this->audit->log($finalize ? 'FINALIZE_ENCOUNTER' : 'SAVE_ENCOUNTER', "Patient $patientId encounter saved", null, null, null, $tenantId);

        return [
            'visit_id' => $visitId,
            'past_visit_id' => $pastVisitId,
            'finalized' => $finalize,
            'vitals' => $vitalsData,
            'soap' => $soapData
        ];
    }

    public function saveEncounterData(string $patientId, array $vitalsData, array $soapData, ?string $visitId = null, ?string $tenantId = null): array {
        return $this->saveEncounter($patientId, $vitalsData, $soapData, [], false, $visitId, $tenantId);
    }

    public function finalizeEncounter(string $patientId, ?string $visitId = null, array $finalizeOptions = [], ?string $tenantId = null): array {
        // Fetch current prescriptions for visit
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $rxStmt = $this->db->prepare("SELECT medication_name, dosage, frequency, duration, instructions FROM prescriptions WHERE patient_id = ? AND tenant_id = ?");
        $rxStmt->execute([$patientId, $tenantId]);
        $prescriptions = $rxStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fetch vitals
        $vStmt = $this->db->prepare("SELECT * FROM vitals WHERE patient_id = ? AND tenant_id = ? LIMIT 1");
        $vStmt->execute([$patientId, $tenantId]);
        $vitalsRow = $vStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Fetch soap
        $sStmt = $this->db->prepare("SELECT * FROM soap_notes WHERE patient_id = ? AND tenant_id = ? LIMIT 1");
        $sStmt->execute([$patientId, $tenantId]);
        $soapRow = $sStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $vitalsData = [
            'blood_pressure' => $vitalsRow['blood_pressure'] ?? '120/80',
            'heart_rate' => $vitalsRow['heart_rate'] ?? 72,
            'temperature' => $vitalsRow['temperature'] ?? 98.6,
            'oxygen_sat' => $vitalsRow['oxygen_sat'] ?? 99
        ];

        $subjective = !empty($soapRow['subjective']) ? Encryption::decrypt($soapRow['subjective']) : '';
        $objective = !empty($soapRow['objective']) ? Encryption::decrypt($soapRow['objective']) : '';
        $plan = !empty($soapRow['plan']) ? Encryption::decrypt($soapRow['plan']) : '';
        $codes = json_decode($soapRow['assessment_codes'] ?? '[]', true) ?: [];
        $icdCode = $codes[0]['code'] ?? 'J01.90';

        $soapData = [
            'subjective' => $subjective,
            'objective' => $objective,
            'icd_code' => $icdCode,
            'plan' => $plan
        ];

        $res = $this->saveEncounter($patientId, $vitalsData, $soapData, $prescriptions, true, $visitId, $tenantId);

        if (!empty($finalizeOptions['create_invoice'])) {
            $billingService = new BillingService($this->db);
            $pStmt = $this->db->prepare("SELECT * FROM patients WHERE id = ?");
            $pStmt->execute([$patientId]);
            $patient = $pStmt->fetch(PDO::FETCH_ASSOC);

            if ($patient) {
                $billingService->createInvoice([
                    'patient_id' => $patientId,
                    'patient_name' => $patient['first_name'] . ' ' . $patient['last_name'],
                    'patient_mrn' => $patient['mrn'],
                    'service_date' => date('Y-m-d'),
                    'due_date' => date('Y-m-d', strtotime('+30 days')),
                    'amount' => $finalizeOptions['amount'] ?? 150.00,
                    'insurance_covered' => $finalizeOptions['insurance_covered'] ?? 0.00,
                    'services' => [$finalizeOptions['service_description'] ?? 'Clinical Consultation & Examination']
                ]);
            }
        }

        return $res;
    }
}
