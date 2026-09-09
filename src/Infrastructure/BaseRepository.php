<?php
declare(strict_types=1);

namespace ClinicFlow\Infrastructure;

use ClinicFlow\Shared\TenantContext;
use PDO;
use PDOStatement;

abstract class BaseRepository {
    protected PDO $db;
    protected string $tableName;
    protected string $tenantColumn = 'tenant_id';

    public function __construct(PDO $db, string $tableName) {
        $this->db = $db;
        $this->tableName = $tableName;
    }

    protected function getTenantId(): string {
        return TenantContext::getTenantId();
    }

    public function findById(string $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM {$this->tableName} WHERE id = :id AND {$this->tenantColumn} = :tid LIMIT 1");
        $stmt->execute(['id' => $id, 'tid' => $this->getTenantId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findAll(int $limit = 50, int $offset = 0): array {
        $stmt = $this->db->prepare("SELECT * FROM {$this->tableName} WHERE {$this->tenantColumn} = :tid ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':tid', $this->getTenantId(), PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(string $id): bool {
        $stmt = $this->db->prepare("DELETE FROM {$this->tableName} WHERE id = :id AND {$this->tenantColumn} = :tid");
        return $stmt->execute(['id' => $id, 'tid' => $this->getTenantId()]);
    }
}
