<?php

return new class {
    public function up(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS clinic_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );");
            $pdo->exec("INSERT INTO clinic_settings (setting_key, setting_value) VALUES ('sse_notifications_enabled', '1') ON CONFLICT(setting_key) DO NOTHING;");
            $pdo->exec("INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES ('ts-default-sse', 'default-clinic', 'sse_notifications_enabled', '1') ON CONFLICT(tenant_id, setting_key) DO NOTHING;");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS clinic_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $pdo->exec("INSERT IGNORE INTO clinic_settings (setting_key, setting_value) VALUES ('sse_notifications_enabled', '1');");
            $pdo->exec("INSERT IGNORE INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES ('ts-default-sse', 'default-clinic', 'sse_notifications_enabled', '1');");
        }
    }
};
