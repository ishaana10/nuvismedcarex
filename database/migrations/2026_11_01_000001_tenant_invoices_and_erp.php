<?php
/**
 * Migration: NuvisERPX subscription invoices + sync log.
 *
 * Invoices for on-boarded tenants originate in NuvisERPX. They are mirrored
 * here so clinics can view them, and when a payment is reconciled in NuvisERPX
 * a webhook flips the local invoice to "paid" and records the payment.
 */

return new class {
    public function up(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tenant_invoices (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                erp_invoice_id VARCHAR(100),
                invoice_number VARCHAR(100),
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(10) NOT NULL DEFAULT 'FJD',
                status VARCHAR(30) NOT NULL DEFAULT 'issued',
                description VARCHAR(255),
                period_start DATE NULL,
                period_end DATE NULL,
                issue_date DATE NULL,
                due_date DATE NULL,
                pdf_url VARCHAR(500) NULL,
                erp_reference VARCHAR(191) NULL,
                reconciled_at DATETIME NULL,
                synced_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->addIndex($pdo, 'tenant_invoices', 'idx_tinv_tenant', '(tenant_id)');
        $this->addIndex($pdo, 'tenant_invoices', 'idx_tinv_status', '(status)');
        $this->addUnique($pdo, 'tenant_invoices', 'uk_tinv_erp', '(erp_invoice_id)');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS erp_sync_logs (
                id VARCHAR(50) PRIMARY KEY,
                direction VARCHAR(10) NOT NULL DEFAULT 'in',
                event VARCHAR(100),
                tenant_id VARCHAR(50) NULL,
                erp_invoice_id VARCHAR(100) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'ok',
                message VARCHAR(500),
                payload MEDIUMTEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->addIndex($pdo, 'erp_sync_logs', 'idx_erplog_created', '(created_at)');
    }

    public function down(PDO $pdo): void {}

    private function indexExists(PDO $pdo, string $table, string $index): bool {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
            $stmt->execute([$table, $index]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return true;
        }
    }
    private function addIndex(PDO $pdo, string $table, string $index, string $cols): void {
        try { if (!$this->indexExists($pdo, $table, $index)) $pdo->exec("CREATE INDEX `{$index}` ON `{$table}` {$cols}"); } catch (\Throwable $e) {}
    }
    private function addUnique(PDO $pdo, string $table, string $index, string $cols): void {
        try { if (!$this->indexExists($pdo, $table, $index)) $pdo->exec("CREATE UNIQUE INDEX `{$index}` ON `{$table}` {$cols}"); } catch (\Throwable $e) {}
    }
};
