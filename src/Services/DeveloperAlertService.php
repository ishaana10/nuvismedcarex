<?php

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;

/**
 * Developer broadcast alerts. Developers post alerts/messages that reach clinics
 * "across the board" — every tenant, a single tenant, or a role. Clinic users
 * see active, unexpired alerts and can dismiss (mark read) them.
 */
class DeveloperAlertService
{
    public const SEVERITIES = ['info', 'success', 'warning', 'critical'];
    public const AUDIENCES = ['all', 'tenant', 'role'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Every alert (developer management view), newest first. */
    public function listAllAlerts(int $limit = 200): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT a.*, t.name AS target_tenant_name,
                        (SELECT COUNT(*) FROM developer_alert_reads r WHERE r.alert_id = a.id) AS read_count
                 FROM developer_alerts a
                 LEFT JOIN tenants t ON t.id = a.target_tenant_id
                 ORDER BY a.created_at DESC
                 LIMIT " . max(1, min(1000, $limit))
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Active alerts that apply to a given user, in a given tenant, with a given
     * role, that they have not dismissed yet.
     */
    public function getActiveAlertsForUser(?string $userId, ?string $tenantId, ?string $role): array
    {
        try {
            $sql = "SELECT a.* FROM developer_alerts a
                    WHERE a.is_active = 1
                      AND (a.published_at IS NULL OR a.published_at <= NOW())
                      AND (a.expires_at IS NULL OR a.expires_at >= NOW())
                      AND (
                            a.audience = 'all'
                         OR (a.audience = 'tenant' AND a.target_tenant_id = ?)
                         OR (a.audience = 'role'   AND a.target_role = ?)
                      )";
            $params = [$tenantId, $role];
            if ($userId !== null && $userId !== '') {
                $sql .= " AND NOT EXISTS (SELECT 1 FROM developer_alert_reads r WHERE r.alert_id = a.id AND r.user_id = ?)";
                $params[] = $userId;
            }
            $sql .= " ORDER BY FIELD(a.severity,'critical','warning','success','info'), a.created_at DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function createAlert(array $data): string
    {
        $id = Uuid::uuidv7();
        $severity = in_array($data['severity'] ?? 'info', self::SEVERITIES, true) ? $data['severity'] : 'info';
        $audience = in_array($data['audience'] ?? 'all', self::AUDIENCES, true) ? $data['audience'] : 'all';

        $stmt = $this->db->prepare(
            "INSERT INTO developer_alerts
                (id, title, body, severity, audience, target_tenant_id, target_role, link_url,
                 is_active, created_by, created_by_name, published_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            trim((string) ($data['title'] ?? '')),
            trim((string) ($data['body'] ?? '')),
            $severity,
            $audience,
            $audience === 'tenant' ? ($data['target_tenant_id'] ?? null) : null,
            $audience === 'role' ? ($data['target_role'] ?? null) : null,
            trim((string) ($data['link_url'] ?? '')) ?: null,
            $data['created_by'] ?? null,
            $data['created_by_name'] ?? null,
            !empty($data['published_at']) ? $data['published_at'] : date('Y-m-d H:i:s'),
            !empty($data['expires_at']) ? $data['expires_at'] : null,
        ]);
        return $id;
    }

    public function setActive(string $id, bool $active): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE developer_alerts SET is_active = ? WHERE id = ?");
            $stmt->execute([$active ? 1 : 0, $id]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** A user dismisses an alert for themselves. */
    public function markRead(string $alertId, string $userId): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO developer_alert_reads (id, alert_id, user_id) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([Uuid::uuidv7(), $alertId, $userId]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
