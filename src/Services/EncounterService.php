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
}
