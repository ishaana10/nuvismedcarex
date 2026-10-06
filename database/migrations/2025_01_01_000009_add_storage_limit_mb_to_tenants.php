<?php
/**
 * Migration: Add storage_limit_mb to tenants table
 */

return new class {
    public function up(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'tenants'
                  AND COLUMN_NAME = 'storage_limit_mb'
            ");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE tenants ADD COLUMN storage_limit_mb INT NOT NULL DEFAULT 500");
            }
        } catch (\Throwable $e) {
            // Ignore if column already exists
        }
    }

    public function down(PDO $pdo): void {
        // Drop column if needed, or leave blank for safety
    }
};
