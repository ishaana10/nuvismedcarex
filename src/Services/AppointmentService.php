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

    public function scheduleAppointment(array $data, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();

        $patientId = $data['patient_id'] ?? '';
        $doctorId = $data['doctor_id'] ?? 'doc-1';
        $appointmentDate = $data['appointment_date'] ?? date('Y-m-d');
        $time = $data['time'] ?? '09:30 AM';
        $type = $data['type'] ?? 'Consultation';
        $status = $data['status'] ?? 'Waiting';
        if (isset($data['status'])) {
            $status = $data['status'];
        }
        $notes = trim($data['notes'] ?? '');

        // Fetch patient
        $pStmt = $this->db->prepare("SELECT * FROM patients WHERE id = ? AND tenant_id = ?");
        $pStmt->execute([$patientId, $tenantId]);
        $patient = $pStmt->fetch(PDO::FETCH_ASSOC);

        $patientName = $data['patient_name'] ?? (($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
        if (empty(trim($patientName)) && $patient) {
            $patientName = ($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '');
        }

        // Fetch doctor
        $dStmt = $this->db->prepare("SELECT * FROM doctors WHERE id = ? AND tenant_id = ?");
        $dStmt->execute([$doctorId, $tenantId]);
        $doctor = $dStmt->fetch(PDO::FETCH_ASSOC);
        $doctorName = $data['doctor_name'] ?? ($doctor['name'] ?? 'Dr. Jenkins');

        $aptId = "apt-" . time() . '-' . bin2hex(random_bytes(3));
        $timeSlot = "$time - 10:15 AM";

        $stmt = $this->db->prepare("INSERT INTO appointments (id, tenant_id, patient_id, patient_name, patient_mrn, patient_avatar, patient_initials, doctor_id, doctor_name, appointment_date, time, time_slot, type, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $aptId, $tenantId, $patientId, $patientName, $patient['mrn'] ?? ($data['patient_mrn'] ?? ''), $patient['avatar'] ?? ($data['patient_avatar'] ?? null), $patient['initials'] ?? ($data['patient_initials'] ?? 'PT'),
            $doctorId, $doctorName, $appointmentDate, $time, $timeSlot, $type, $status, $notes
        ]);

        // Add queue item
        $qId = "q-" . time() . '-' . bin2hex(random_bytes(3));
        $qStmt = $this->db->prepare("INSERT INTO queue (id, tenant_id, patient_id, patient_name, mrn, time, doctor_name, status, check_in_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $qStmt->execute([
            $qId, $tenantId, $patientId, $patientName, $patient['mrn'] ?? ($data['patient_mrn'] ?? ''), $time, $doctorName, $status, date('h:i A')
        ]);

        // Activity log
        $actStmt = $this->db->prepare("INSERT INTO activities (id, tenant_id, type, title, detail, timestamp, badge_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $actStmt->execute([
            "act-" . time(),
            $tenantId,
            "appointment_booked",
            "Appointment Booked: $patientName",
            "For $time with $doctorName",
            date('Y-m-d H:i:s'),
            "blue"
        ]);

        $this->audit->log("CREATE_APPOINTMENT", "Appointment scheduled for $patientName on $appointmentDate", null, null, $patientId, $tenantId);

        return $this->getAppointmentById($aptId, $tenantId);
    }

    public function updateAppointment(string $id, array $data, ?string $tenantId = null): bool {
        $tenantId = $tenantId ?? TenantContext::getTenantId();

        $appointmentDate = $data['appointment_date'] ?? date('Y-m-d');
        $time = $data['time'] ?? '09:30 AM';
        $type = $data['type'] ?? 'Consultation';
        $status = $data['status'] ?? 'Waiting';
        $notes = trim($data['notes'] ?? '');

        $stmt = $this->db->prepare("UPDATE appointments SET appointment_date = ?, time = ?, time_slot = ?, type = ?, status = ?, notes = ? WHERE id = ? AND tenant_id = ?");
        $timeSlot = "$time - 10:15 AM";
        $success = $stmt->execute([$appointmentDate, $time, $timeSlot, $type, $status, $notes, $id, $tenantId]);

        if ($success) {
            $this->audit->log("UPDATE_APPOINTMENT", "Appointment $id updated", null, null, null, $tenantId);
        }

        return $success;
    }

    public function deleteAppointment(string $id, ?string $tenantId = null): bool {
        $tenantId = $tenantId ?? TenantContext::getTenantId();

        $stmt = $this->db->prepare("DELETE FROM appointments WHERE id = ? AND tenant_id = ?");
        $success = $stmt->execute([$id, $tenantId]);

        if ($success) {
            $this->audit->log("DELETE_APPOINTMENT", "Appointment $id deleted", null, null, null, $tenantId);
        }

        return $success;
    }

    public function createAppointment(array $data, ?string $tenantId = null): array {
        if (empty($data['status'])) {
            $data['status'] = 'Scheduled';
        }
        return $this->scheduleAppointment($data, $tenantId);
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
