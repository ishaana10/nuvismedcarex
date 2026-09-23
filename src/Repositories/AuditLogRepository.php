<?php
declare(strict_types=1);

namespace ClinicFlow\Repositories;

use ClinicFlow\Infrastructure\BaseRepository;
use PDO;

class AuditLogRepository extends BaseRepository {
    public function __construct(PDO $db) {
        parent::__construct($db, 'audit_logs');
    }

    public function findLogs(?string $action = null, ?string $userId = null, int $limit = 100, int $offset = 0): array {
        $tenantId = $this->getTenantId();
        $sql = "SELECT * FROM audit_logs WHERE tenant_id = :tid";
        $params = ['tid' => $tenantId];

        if ($action) {
            $sql .= " AND action LIKE :act";
            $params['act'] = "%{$action}%";
        }

        if ($userId) {
            $sql .= " AND user_id = :uid";
            $params['uid'] = $userId;
        }

        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
