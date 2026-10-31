<?php
/**
 * Migration: monitoring incident log. Records health issues detected by the
 * developer monitoring dashboard / cron so there's a history of what was
 * flagged and when developers were notified.
 */

return new class {
    public function up(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS monitoring_incidents (
                id VARCHAR(50) PRIMARY KEY,
                incident_key VARCHAR(191) NOT NULL,
                severity VARCHAR(20) NOT NULL DEFAULT 'warning',
                scope VARCHAR(30) NOT NULL DEFAULT 'system',
                tenant_id VARCHAR(50) NULL,
                title VARCHAR(255) NOT NULL,
                detail TEXT,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                notified TINYINT(1) NOT NULL DEFAULT 0,
                notified_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME NULL
            )
        ");
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monitoring_incidents' AND INDEX_NAME = 'idx_mon_key'");
            $stmt->execute();
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec("CREATE INDEX idx_mon_key ON monitoring_incidents(incident_key, status)");
            }
        } catch (\Throwable $e) {}
    }

    public function down(PDO $pdo): void {}
};
