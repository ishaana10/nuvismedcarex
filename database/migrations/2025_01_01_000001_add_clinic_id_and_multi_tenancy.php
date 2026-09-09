<?php

return new class {
    public function up(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // 1. Create tenants table if not exists
        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (
                id VARCHAR(50) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                code VARCHAR(50) UNIQUE NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'active',
                plan VARCHAR(50) NOT NULL DEFAULT 'standard',
                address TEXT,
                phone VARCHAR(50),
                email VARCHAR(255),
                timezone VARCHAR(50) DEFAULT 'Pacific/Fiji',
                locale VARCHAR(10) DEFAULT 'en_FJ',
                currency VARCHAR(10) DEFAULT 'FJD',
                branding TEXT,
                vms_credentials TEXT,
                feature_flags TEXT,
                custom_fields TEXT,
                billing_info TEXT,
                is_active INTEGER DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (
                id VARCHAR(50) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                code VARCHAR(50) UNIQUE NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'active',
                plan VARCHAR(50) NOT NULL DEFAULT 'standard',
                address TEXT,
                phone VARCHAR(50),
                email VARCHAR(255),
                timezone VARCHAR(50) DEFAULT 'Pacific/Fiji',
                locale VARCHAR(10) DEFAULT 'en_FJ',
                currency VARCHAR(10) DEFAULT 'FJD',
                branding JSON,
                vms_credentials JSON,
                feature_flags JSON,
                custom_fields JSON,
                billing_info JSON,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            );");
        }

        // Insert default tenant if not exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE id = 'default-clinic'");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $insert = $pdo->prepare("INSERT INTO tenants (id, name, code, status, plan, address, phone, email) VALUES (:id, :name, :code, :status, :plan, :address, :phone, :email)");
            $insert->execute([
                'id' => 'default-clinic',
                'name' => 'Main Suva Central Clinic',
                'code' => 'default-clinic',
                'status' => 'active',
                'plan' => 'enterprise',
                'address' => '2 Woodstand Road, Suva',
                'phone' => '+679 330 1234',
                'email' => 'suva@clinicflow.org'
            ]);
        }

        // Create user_tenants table
        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_tenants (
                id VARCHAR(50) PRIMARY KEY,
                user_id VARCHAR(50) NOT NULL,
                tenant_id VARCHAR(50) NOT NULL,
                role VARCHAR(50) NOT NULL DEFAULT 'practitioner',
                permissions TEXT,
                is_default INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_tenants (
                id VARCHAR(50) PRIMARY KEY,
                user_id VARCHAR(50) NOT NULL,
                tenant_id VARCHAR(50) NOT NULL,
                role VARCHAR(50) NOT NULL DEFAULT 'practitioner',
                permissions JSON,
                is_default TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_user_tenant (user_id, tenant_id)
            );");
        }

        // Create tenant_settings table
        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_settings (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_settings (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_tenant_setting (tenant_id, setting_key)
            );");
        }

        // 2. Rename clinic_id to tenant_id on all relevant tables if present
        $tables = [
            'doctors',
            'patients',
            'appointments',
            'queue',
            'vitals',
            'soap_notes',
            'prescriptions',
            'past_visits',
            'activities',
            'invoices',
            'invoice_items',
            'vms_logs',
            'inventory',
            'inventory_logs',
            'medical_certificates',
            'audit_logs'
        ];

        foreach ($tables as $table) {
            $cols = [];
            try {
                if ($driver === 'sqlite') {
                    $check = $pdo->query("PRAGMA table_info({$table})");
                    while ($row = $check->fetch()) {
                        $cols[] = strtolower($row['name']);
                    }
                } else {
                    $check = $pdo->query("DESCRIBE {$table}");
                    while ($row = $check->fetch()) {
                        $cols[] = strtolower($row['Field']);
                    }
                }
            } catch (Exception $e) {
                continue;
            }

            if (in_array('clinic_id', $cols, true) && !in_array('tenant_id', $cols, true)) {
                if ($driver === 'sqlite') {
                    $pdo->exec("ALTER TABLE {$table} RENAME COLUMN clinic_id TO tenant_id");
                } else {
                    $pdo->exec("ALTER TABLE {$table} CHANGE COLUMN clinic_id tenant_id VARCHAR(50) NOT NULL");
                }
            } elseif (!in_array('tenant_id', $cols, true)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN tenant_id VARCHAR(50) NOT NULL DEFAULT 'default-clinic'");
            }

            $pdo->exec("UPDATE {$table} SET tenant_id = 'default-clinic' WHERE tenant_id IS NULL OR tenant_id = ''");
        }
    }
};
