<?php

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;

/**
 * Feature wish board. Clinics submit feature wishes; developers triage them
 * across every tenant, set a status and a target timeline, and reply in a
 * shared comment thread.
 */
class WishBoardService
{
    /** Lifecycle statuses, in order. */
    public const STATUSES = ['submitted', 'under_review', 'planned', 'in_progress', 'shipped', 'declined'];

    public const STATUS_LABELS = [
        'submitted'   => 'Submitted',
        'under_review' => 'Under review',
        'planned'     => 'Planned',
        'in_progress' => 'In progress',
        'shipped'     => 'Shipped',
        'declined'    => 'Declined',
    ];

    public const PRIORITIES = ['low', 'medium', 'high'];
    public const CATEGORIES = ['general', 'billing', 'clinical', 'reports', 'inventory', 'ui', 'integration', 'other'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * List wishes. Pass tenantId to scope to one clinic (clinic view); omit it
     * for the developer cross-board view. Optional status filter.
     */
    public function listWishes(?string $tenantId = null, ?string $status = null): array
    {
        try {
            $sql = "SELECT w.*, t.name AS tenant_name,
                           (SELECT COUNT(*) FROM feature_wish_comments c WHERE c.wish_id = w.id) AS comment_count
                    FROM feature_wishes w
                    LEFT JOIN tenants t ON t.id = w.tenant_id
                    WHERE 1=1";
            $params = [];
            if ($tenantId !== null) {
                $sql .= " AND w.tenant_id = ?";
                $params[] = $tenantId;
            }
            if ($status !== null && $status !== '' && in_array($status, self::STATUSES, true)) {
                $sql .= " AND w.status = ?";
                $params[] = $status;
            }
            $sql .= " ORDER BY FIELD(w.status,'in_progress','planned','under_review','submitted','shipped','declined'),
                               w.vote_count DESC, w.created_at DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getWish(string $id): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT w.*, t.name AS tenant_name FROM feature_wishes w
                 LEFT JOIN tenants t ON t.id = w.tenant_id WHERE w.id = ? LIMIT 1"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Count by status for a board summary. Pass tenantId to scope. */
    public function statusCounts(?string $tenantId = null): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        try {
            $sql = "SELECT status, COUNT(*) AS n FROM feature_wishes";
            $params = [];
            if ($tenantId !== null) {
                $sql .= " WHERE tenant_id = ?";
                $params[] = $tenantId;
            }
            $sql .= " GROUP BY status";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($counts[$r['status']])) {
                    $counts[$r['status']] = (int) $r['n'];
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $counts;
    }

    public function createWish(string $tenantId, array $data): string
    {
        $id = Uuid::uuidv7();
        $priority = in_array($data['clinic_priority'] ?? 'medium', self::PRIORITIES, true) ? $data['clinic_priority'] : 'medium';
        $category = in_array($data['category'] ?? 'general', self::CATEGORIES, true) ? $data['category'] : 'general';

        $stmt = $this->db->prepare(
            "INSERT INTO feature_wishes
                (id, tenant_id, created_by, created_by_name, title, description, category, clinic_priority, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted')"
        );
        $stmt->execute([
            $id,
            $tenantId,
            $data['created_by'] ?? null,
            $data['created_by_name'] ?? null,
            trim((string) ($data['title'] ?? '')),
            trim((string) ($data['description'] ?? '')),
            $category,
            $priority,
        ]);
        return $id;
    }

    /** Developer action: move status, set the target timeline, add dev notes. */
    public function updateWish(string $id, array $data): void
    {
        $wish = $this->getWish($id);
        if (!$wish) {
            return;
        }
        $status = in_array($data['status'] ?? $wish['status'], self::STATUSES, true) ? $data['status'] : $wish['status'];

        $startedAt = $wish['started_at'];
        $shippedAt = $wish['shipped_at'];
        if ($status === 'in_progress' && empty($startedAt)) {
            $startedAt = date('Y-m-d H:i:s');
        }
        if ($status === 'shipped' && empty($shippedAt)) {
            $shippedAt = date('Y-m-d H:i:s');
        }

        $stmt = $this->db->prepare(
            "UPDATE feature_wishes
                SET status = ?, dev_notes = ?, target_date = ?, started_at = ?, shipped_at = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?"
        );
        $stmt->execute([
            $status,
            isset($data['dev_notes']) ? trim((string) $data['dev_notes']) : $wish['dev_notes'],
            $this->dateOrNull($data['target_date'] ?? $wish['target_date']),
            $startedAt,
            $shippedAt,
            $id,
        ]);
    }

    public function vote(string $id): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE feature_wishes SET vote_count = vote_count + 1 WHERE id = ?");
            $stmt->execute([$id]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    // --- Comments ------------------------------------------------------------

    public function listComments(string $wishId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM feature_wish_comments WHERE wish_id = ? ORDER BY created_at ASC"
            );
            $stmt->execute([$wishId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function addComment(string $wishId, array $data): string
    {
        $id = Uuid::uuidv7();
        $stmt = $this->db->prepare(
            "INSERT INTO feature_wish_comments
                (id, wish_id, author_id, author_name, author_role, is_developer, body)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            $wishId,
            $data['author_id'] ?? null,
            $data['author_name'] ?? null,
            $data['author_role'] ?? null,
            !empty($data['is_developer']) ? 1 : 0,
            trim((string) ($data['body'] ?? '')),
        ]);
        // Touch the wish so it resurfaces.
        try {
            $this->db->prepare("UPDATE feature_wishes SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$wishId]);
        } catch (\Throwable $e) {
            // ignore
        }
        return $id;
    }

    private function dateOrNull($v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
