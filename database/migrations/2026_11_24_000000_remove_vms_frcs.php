<?php

/**
 * Removes the old FRCS VMS (fiscal invoicing) data: the audit log table, the fiscal columns on
 * invoices, the tenant credentials column, and the VMS settings. The tax label on stock items
 * and the tax rates are kept under neutral names (tax_code, tax_rate_a..p). Idempotent.
 */
return new class {
    private function columns(PDO $pdo, string $table): array
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                return array_map(static fn($r) => $r['name'], $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC));
            }
            return array_map(static fn($r) => $r['Field'], $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    private function dropColumns(PDO $pdo, string $table, array $drop): void
    {
        $have = $this->columns($pdo, $table);
        foreach ($drop as $col) {
            if (!in_array($col, $have, true)) {
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE $table DROP COLUMN $col");
            } catch (Throwable $e) {
                error_log("[migration] drop $table.$col: " . $e->getMessage());
            }
        }
    }

    public function up(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

        // Stock tax label: keep the values under the new name
        $inv = $this->columns($pdo, 'inventory');
        if (in_array('vms_tax_code', $inv, true)) {
            try {
                if (!in_array('tax_code', $inv, true)) {
                    $pdo->exec("ALTER TABLE inventory ADD COLUMN tax_code VARCHAR(10) DEFAULT 'A'");
                }
                $pdo->exec("UPDATE inventory SET tax_code = vms_tax_code WHERE vms_tax_code IS NOT NULL AND vms_tax_code <> ''");
            } catch (Throwable $e) {
                error_log('[migration] tax_code copy: ' . $e->getMessage());
            }
            $this->dropColumns($pdo, 'inventory', ['vms_tax_code']);
        }

        // Fiscal columns on invoices
        try {
            $pdo->exec('DROP INDEX idx_invoices_tenant_fiscalized' . ($sqlite ? '' : ' ON invoices'));
        } catch (Throwable $e) {
            // not there
        }
        $this->dropColumns($pdo, 'invoices', [
            'seller_tin', 'business_location', 'buyer_tin', 'buyer_cost_center', 'pos_number', 'pos_time',
            'ref_time', 'is_fiscalized', 'sdc_invoice_no', 'sdc_time', 'invoice_counter',
            'verification_url', 'digital_signature',
        ]);

        $this->dropColumns($pdo, 'tenants', ['vms_credentials']);

        try {
            $pdo->exec('DROP TABLE IF EXISTS vms_logs');
        } catch (Throwable $e) {
            error_log('[migration] vms_logs: ' . $e->getMessage());
        }

        // Settings: keep the tax rates under the new names, delete everything else VMS
        foreach (['clinic_settings' => null, 'tenant_settings' => 'tenant_id'] as $table => $scope) {
            try {
                foreach (['a', 'e', 'f', 'p'] as $l) {
                    $old = "vms_tax_rate_$l";
                    $new = "tax_rate_$l";
                    $where = $scope ? "AND o.$scope = n.$scope" : '';
                    $pdo->exec("UPDATE $table SET setting_key = '$new' WHERE setting_key = '$old' AND NOT EXISTS
                        (SELECT 1 FROM (SELECT * FROM $table) n WHERE n.setting_key = '$new' " . str_replace('o.', "$table.", $where) . ")");
                }
                $pdo->exec("DELETE FROM $table WHERE setting_key LIKE 'vms\\_%' ESCAPE '\\'");
            } catch (Throwable $e) {
                error_log("[migration] $table vms settings: " . $e->getMessage());
            }
        }
    }
};
