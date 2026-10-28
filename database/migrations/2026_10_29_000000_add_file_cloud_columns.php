<?php

/**
 * Columns the document store needs to know whether a file has reached the
 * clinic's drive, and where it landed.
 */
return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sqlite = $driver === 'sqlite';

        try {
            $rows = $sqlite
                ? $pdo->query("PRAGMA table_info(uploaded_files)")->fetchAll(PDO::FETCH_ASSOC)
                : $pdo->query("SHOW COLUMNS FROM uploaded_files")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return; // the table's own migration has not run yet
        }
        $have = array_map(static fn($r) => strtolower((string) ($r['name'] ?? $r['Field'] ?? '')), $rows);

        $columns = [
            'remote_status' => $sqlite ? "TEXT DEFAULT 'none'" : "VARCHAR(10) NOT NULL DEFAULT 'none'",
            'remote_error'  => $sqlite ? 'TEXT' : 'VARCHAR(500) NULL',
            'remote_folder' => $sqlite ? 'TEXT' : 'VARCHAR(500) NULL',
            'file_sha256'   => $sqlite ? 'TEXT' : 'CHAR(64) NULL',
            'synced_at'     => $sqlite ? 'TIMESTAMP' : 'TIMESTAMP NULL',
        ];
        foreach ($columns as $name => $definition) {
            if (in_array($name, $have, true)) {
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE uploaded_files ADD COLUMN {$name} {$definition}");
            } catch (\Throwable $e) {
                error_log('[migration] add ' . $name . ' to uploaded_files: ' . $e->getMessage());
            }
        }

        // A file kept only on the drive has no local path
        if (!$sqlite) {
            try {
                $pdo->exec("ALTER TABLE uploaded_files MODIFY file_path VARCHAR(500) NULL");
            } catch (\Throwable $e) {
                error_log('[migration] relax uploaded_files.file_path: ' . $e->getMessage());
            }
        }

        // Files already on the server count as not yet copied
        try {
            $pdo->exec("UPDATE uploaded_files SET remote_status = 'none' WHERE remote_status IS NULL");
        } catch (\Throwable $e) {
            error_log('[migration] backfill remote_status: ' . $e->getMessage());
        }
    }
};
