<?php
/**
 * Migration: give each staff account a place to keep its own preferences
 * (theme, rows per page, which alerts to show, where to land after signing in).
 */

return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $hasColumn = function (string $table, string $column) use ($pdo, $driver): bool {
            try {
                if ($driver === 'sqlite') {
                    foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                        if (strcasecmp((string) ($col['name'] ?? ''), $column) === 0) return true;
                    }
                    return false;
                }
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
                );
                $stmt->execute([$table, $column]);
                return (int) $stmt->fetchColumn() > 0;
            } catch (\Throwable $e) {
                return false;
            }
        };

        if (!$hasColumn('doctors', 'preferences')) {
            $type = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
            $pdo->exec("ALTER TABLE doctors ADD COLUMN preferences {$type} DEFAULT NULL");
        }
    }
};
