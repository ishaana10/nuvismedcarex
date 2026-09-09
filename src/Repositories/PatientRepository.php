<?php
declare(strict_types=1);

namespace ClinicFlow\Repositories;

use ClinicFlow\Infrastructure\BaseRepository;
use PDO;

class PatientRepository extends BaseRepository {
    public function __construct(PDO $db) {
        parent::__construct($db, 'patients');
    }

    public function findAll(int $limit = 50, int $offset = 0, ?string $search = null): array {
        $concatExpr = ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql')
            ? "CONCAT(first_name, ' ', last_name)"
            : "(first_name || ' ' || last_name)";

        $tenantId = $this->getTenantId();

        if ($search) {
            $stmt = $this->db->prepare("SELECT *, {$concatExpr} AS full_name FROM patients WHERE tenant_id = :tid AND (first_name LIKE :s OR last_name LIKE :s OR mrn LIKE :s OR phone LIKE :s) ORDER BY id DESC LIMIT :limit OFFSET :offset");
            $searchTerm = "%{$search}%";
            $stmt->bindValue(':tid', $tenantId, PDO::PARAM_STR);
            $stmt->bindValue(':s', $searchTerm, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare("SELECT *, {$concatExpr} AS full_name FROM patients WHERE tenant_id = :tid ORDER BY id DESC LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':tid', $tenantId, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(string $id): ?array {
        $concatExpr = ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql')
            ? "CONCAT(first_name, ' ', last_name)"
            : "(first_name || ' ' || last_name)";

        $stmt = $this->db->prepare("SELECT *, {$concatExpr} AS full_name FROM patients WHERE id = :id AND tenant_id = :tid LIMIT 1");
        $stmt->execute(['id' => $id, 'tid' => $this->getTenantId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByMrn(string $mrn): ?array {
        $concatExpr = ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql')
            ? "CONCAT(first_name, ' ', last_name)"
            : "(first_name || ' ' || last_name)";

        $stmt = $this->db->prepare("SELECT *, {$concatExpr} AS full_name FROM patients WHERE mrn = :mrn AND tenant_id = :tid LIMIT 1");
        $stmt->execute(['mrn' => $mrn, 'tid' => $this->getTenantId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findAllActive(int $limit = 100, int $offset = 0): array {
        return $this->findAll($limit, $offset);
    }

    public function search(string $query, int $limit = 50, int $offset = 0): array {
        return $this->findAll($limit, $offset, $query);
    }

    public function update(string $id, array $data): bool {
        $fields = [];
        $params = [];
        $allowed = ['mrn', 'first_name', 'last_name', 'dob', 'age', 'gender', 'phone', 'email', 'address', 'emergency_contact_name', 'blood_group', 'known_allergies', 'chronic_conditions'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
        if (empty($fields)) {
            return true;
        }
        $fields[] = "updated_at = CURRENT_TIMESTAMP";
        $params[] = $id;
        $params[] = $this->getTenantId();
        $sql = "UPDATE patients SET " . implode(', ', $fields) . " WHERE id = ? AND tenant_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function create(array $data): string {
        $id = $data['id'] ?? ('pat-' . uniqid());
        $tenantId = $data['tenant_id'] ?? $this->getTenantId();
        $stmt = $this->db->prepare("
            INSERT INTO patients (id, tenant_id, mrn, first_name, last_name, dob, age, gender, phone, email, address, emergency_contact_name, blood_group, known_allergies, chronic_conditions, registration_date, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $id,
            $tenantId,
            $data['mrn'],
            $data['first_name'] ?? 'FirstName',
            $data['last_name'] ?? 'LastName',
            $data['dob'] ?? '1990-01-01',
            $data['age'] ?? 30,
            $data['gender'] ?? 'Other',
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
            $data['emergency_contact_name'] ?? null,
            $data['blood_group'] ?? null,
            $data['known_allergies'] ?? null,
            $data['chronic_conditions'] ?? null
        ]);
        return $id;
    }
}
