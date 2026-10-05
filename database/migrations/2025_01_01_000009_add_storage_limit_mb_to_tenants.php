<?php
/**
 * Migration: Add storage_limit_mb to tenants table
 */

return new class {
    public function up(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            if ($driver === 'sqlite') {
                $columns = $pdo->query("PRAGMA table_info(tenants)")->fetchAll(PDO::FETCH_ASSOC);
                $hasCol = false;
                foreach ($columns as $col) {
                    if ($col['name'] === 'storage_limit_mb') {
                        $hasCol = true;
                        break;
                    }
                }
                if (!$hasCol) {
                    $pdo->exec("ALTER TABLE tenants ADD COLUMN storage_limit_mb INTEGER NOT NULL DEFAULT 500");
                }
            } else {
                // MySQL / MariaDB
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
            }
        } catch (\Throwable $e) {
            // Ignore if column already exists
        }
    }

    public function down(PDO $pdo): void {
        // Drop column if needed, or leave blank for safety
    }
};
