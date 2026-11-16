<?php

/**
 * Indexes for the queries that run on every page or every few seconds.
 *
 *  - patients / appointments (tenant_id, created_at): the live-notification
 *    stream asks "anything new since ..." for each open browser tab.
 *  - patients (tenant_id, last_name): the Patients list sorts and searches by name.
 *  - invoices (tenant_id, status): billing totals and the overdue count.
 *
 * Skipped when an index with the same name exists or a column is missing.
 */
return new class {
    private const INDEXES = [
        ['patients',     'idx_patients_tenant_created',  ['tenant_id', 'created_at']],
        ['appointments', 'idx_appointments_tenant_created', ['tenant_id', 'created_at']],
        ['patients',     'idx_patients_tenant_lastname', ['tenant_id', 'last_name']],
        ['invoices',     'idx_invoices_tenant_status',   ['tenant_id', 'status']],
    ];

    public function up(PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return; // SQLite (tests) builds its own schema
        }
        foreach (self::INDEXES as [$table, $name, $cols]) {
            try {
                $has = $pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.statistics
                      WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?"
                );
                $has->execute([$table, $name]);
                if ((int) $has->fetchColumn() > 0) continue;

                $col = $pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN ("
                    . implode(',', array_fill(0, count($cols), '?')) . ")"
                );
                $col->execute(array_merge([$table], $cols));
                if ((int) $col->fetchColumn() !== count($cols)) continue;

                $pdo->exec("CREATE INDEX `{$name}` ON `{$table}` (`" . implode('`,`', $cols) . "`)");
            } catch (Throwable $e) {
                error_log("[migration] index {$name}: " . $e->getMessage());
            }
        }
    }
};
