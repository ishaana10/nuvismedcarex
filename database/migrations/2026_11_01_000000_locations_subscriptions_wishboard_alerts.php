<?php
/**
 * Migration: Multi-location clinics, subscription payment history,
 * clinic feature wish board, and developer broadcast alerts.
 *
 * All statements are idempotent so a partial re-run is safe.
 */

return new class {
    public function up(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // --- New tables -----------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS clinic_locations (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                name VARCHAR(255) NOT NULL,
                code VARCHAR(50) NOT NULL,
                address TEXT,
                phone VARCHAR(50),
                email VARCHAR(255),
                timezone VARCHAR(50) DEFAULT 'Pacific/Fiji',
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->addIndex($pdo, 'clinic_locations', 'idx_locations_tenant', '(tenant_id)');
        $this->addUnique($pdo, 'clinic_locations', 'uk_location_tenant_code', '(tenant_id, code)');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS subscription_payments (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(10) NOT NULL DEFAULT 'FJD',
                plan VARCHAR(50),
                billing_cycle VARCHAR(50) DEFAULT 'monthly',
                period_start DATE,
                period_end DATE,
                status VARCHAR(30) NOT NULL DEFAULT 'paid',
                method VARCHAR(50),
                reference VARCHAR(191),
                invoice_no VARCHAR(100),
                paid_at DATETIME NULL,
                notes TEXT,
                recorded_by VARCHAR(50),
                recorded_by_name VARCHAR(191),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->addIndex($pdo, 'subscription_payments', 'idx_subpay_tenant', '(tenant_id)');
        $this->addIndex($pdo, 'subscription_payments', 'idx_subpay_status', '(tenant_id, status)');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS feature_wishes (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                created_by VARCHAR(50),
                created_by_name VARCHAR(191),
                title VARCHAR(255) NOT NULL,
                description TEXT,
                category VARCHAR(50) DEFAULT 'general',
                clinic_priority VARCHAR(20) DEFAULT 'medium',
                status VARCHAR(30) NOT NULL DEFAULT 'submitted',
                dev_notes TEXT,
                target_date DATE NULL,
                started_at DATETIME NULL,
                shipped_at DATETIME NULL,
                vote_count INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->addIndex($pdo, 'feature_wishes', 'idx_wishes_tenant', '(tenant_id)');
        $this->addIndex($pdo, 'feature_wishes', 'idx_wishes_status', '(status)');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS feature_wish_comments (
                id VARCHAR(50) PRIMARY KEY,
                wish_id VARCHAR(50) NOT NULL,
                author_id VARCHAR(50),
                author_name VARCHAR(191),
                author_role VARCHAR(50),
                is_developer TINYINT(1) NOT NULL DEFAULT 0,
                body TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->addIndex($pdo, 'feature_wish_comments', 'idx_wishcomments_wish', '(wish_id)');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS developer_alerts (
                id VARCHAR(50) PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                body TEXT,
                severity VARCHAR(20) NOT NULL DEFAULT 'info',
                audience VARCHAR(20) NOT NULL DEFAULT 'all',
                target_tenant_id VARCHAR(50) NULL,
                target_role VARCHAR(50) NULL,
                link_url VARCHAR(255) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by VARCHAR(50),
                created_by_name VARCHAR(191),
                published_at DATETIME NULL,
                expires_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->addIndex($pdo, 'developer_alerts', 'idx_alerts_active', '(is_active)');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS developer_alert_reads (
                id VARCHAR(50) PRIMARY KEY,
                alert_id VARCHAR(50) NOT NULL,
                user_id VARCHAR(50) NOT NULL,
                read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->addUnique($pdo, 'developer_alert_reads', 'uk_alert_user', '(alert_id, user_id)');

        // --- Optional location tagging on operational records ---------------
        // Nullable so every existing row and query keeps working untouched.
        foreach (['appointments', 'invoices', 'queue', 'inventory'] as $table) {
            $this->addColumn($pdo, $table, 'location_id', "VARCHAR(50) NULL");
        }

        // --- Seed a primary location for every existing tenant --------------
        try {
            $tenants = $pdo->query("SELECT id, name, address, phone, email FROM tenants")->fetchAll(PDO::FETCH_ASSOC);
            $check = $pdo->prepare("SELECT COUNT(*) FROM clinic_locations WHERE tenant_id = ?");
            $ins = $pdo->prepare("
                INSERT INTO clinic_locations (id, tenant_id, name, code, address, phone, email, is_primary, is_active)
                VALUES (?, ?, ?, 'main', ?, ?, ?, 1, 1)
            ");
            foreach ($tenants as $t) {
                $check->execute([$t['id']]);
                if ((int) $check->fetchColumn() === 0) {
                    $ins->execute([
                        $this->uuid(),
                        $t['id'],
                        ($t['name'] ?? 'Main') . ' — Main',
                        $t['address'] ?? null,
                        $t['phone'] ?? null,
                        $t['email'] ?? null,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Tenants table may not be ready during a fresh install; ignore.
        }
    }

    public function down(PDO $pdo): void {
        // Intentionally left as a no-op for safety (keeps clinic data).
    }

    private function uuid(): string {
        if (class_exists('\\ClinicFlow\\Utils\\Uuid')) {
            return \ClinicFlow\Utils\Uuid::uuidv7();
        }
        return bin2hex(random_bytes(16));
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return true; // assume present so we don't crash the run
        }
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
            ");
            $stmt->execute([$table, $index]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function addColumn(PDO $pdo, string $table, string $column, string $definition): void {
        try {
            if (!$this->columnExists($pdo, $table, $column)) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            // ignore — table may not exist on this install
        }
    }

    private function addIndex(PDO $pdo, string $table, string $index, string $cols): void {
        try {
            if (!$this->indexExists($pdo, $table, $index)) {
                $pdo->exec("CREATE INDEX `{$index}` ON `{$table}` {$cols}");
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function addUnique(PDO $pdo, string $table, string $index, string $cols): void {
        try {
            if (!$this->indexExists($pdo, $table, $index)) {
                $pdo->exec("CREATE UNIQUE INDEX `{$index}` ON `{$table}` {$cols}");
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
