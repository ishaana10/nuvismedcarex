<?php

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;
use ClinicFlow\Shared\TenantContext;

class AppointmentService {
    private PDO $db;
    private AuditService $audit;

    public function __construct(PDO $db, ?AuditService $audit = null) {
        $this->db = $db;
        $this->audit = $audit ?? new AuditService($db);
    }

    public function getAppointments(?string $date = null, ?string $doctorId = null, ?string $patientId = null, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $sql = "SELECT * FROM appointments WHERE tenant_id = :tid";
        $params = ['tid' => $tenantId];

        if ($date) {
            $sql .= " AND appointment_date = :adate";
            $params['adate'] = $date;
        }

        if ($doctorId) {
            $sql .= " AND doctor_id = :docid";
            $params['docid'] = $doctorId;
        }

        if ($patientId) {
            $sql .= " AND patient_id = :pid";
            $params['pid'] = $patientId;
        }

        $sql .= " ORDER BY appointment_date ASC, time ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAppointmentById(string $id, ?string $tenantId = null): ?array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM appointments WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createAppointment(array $data, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $id = Uuid::uuidv7();

        $stmt = $this->db->prepare(
            "INSERT INTO appointments (id, tenant_id, patient_id, patient_name, patient_mrn, patient_avatar, patient_initials, doctor_id, doctor_name, appointment_date, time, end_time, time_slot, type, status, room, notes, is_urgent) " .
            "VALUES (:id, :tid, :pid, :pname, :pmrn, :pavatar, :pinitials, :docid, :docname, :adate, :time, :etime, :slot, :type, :status, :room, :notes, :urgent)"
        );

        $stmt->execute([
            'id' => $id,
            'tid' => $tenantId,
            'pid' => $data['patient_id'],
            'pname' => $data['patient_name'] ?? 'Patient',
            'pmrn' => $data['patient_mrn'] ?? 'MRN-00',
            'pavatar' => $data['patient_avatar'] ?? null,
            'pinitials' => $data['patient_initials'] ?? 'PT',
            'docid' => $data['doctor_id'],
            'docname' => $data['doctor_name'] ?? 'Doctor',
            'adate' => $data['appointment_date'] ?? date('Y-m-d'),
            'time' => $data['time'] ?? '09:00 AM',
            'etime' => $data['end_time'] ?? '09:30 AM',
            'slot' => $data['time_slot'] ?? '09:00 AM - 09:30 AM',
            'type' => $data['type'] ?? 'General Consultation',
            'status' => $data['status'] ?? 'Scheduled',
            'room' => $data['room'] ?? 'Room 1',
            'notes' => $data['notes'] ?? null,
            'urgent' => !empty($data['is_urgent']) ? 1 : 0
        ]);

        $this->audit->log("CREATE_APPOINTMENT", "Appointment created for " . ($data['patient_name'] ?? 'Patient') . " on " . ($data['appointment_date'] ?? date('Y-m-d')), null, null, null, $tenantId);

        return $this->getAppointmentById($id, $tenantId);
    }

    public function updateStatus(string $id, string $status, ?string $tenantId = null): bool {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("UPDATE appointments SET status = :status WHERE id = :id AND tenant_id = :tid");
        $res = $stmt->execute(['status' => $status, 'id' => $id, 'tid' => $tenantId]);
        if ($res) {
            $this->audit->log("UPDATE_APPOINTMENT_STATUS", "Status updated to $status for appointment $id", null, null, null, $tenantId);
        }
        return $res;
    }
}
