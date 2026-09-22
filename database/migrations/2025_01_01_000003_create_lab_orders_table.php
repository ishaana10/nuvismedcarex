<?php

return new class {
    public function up(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS lab_orders (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                patient_id VARCHAR(50) NOT NULL,
                order_type VARCHAR(50) NOT NULL DEFAULT 'Lab',
                test_name VARCHAR(255) NOT NULL,
                category VARCHAR(100) DEFAULT 'General',
                status VARCHAR(50) NOT NULL DEFAULT 'Ordered',
                is_abnormal INTEGER DEFAULT 0,
                results TEXT,
                ordered_by VARCHAR(255) DEFAULT 'Doctor',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS lab_orders (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                patient_id VARCHAR(50) NOT NULL,
                order_type VARCHAR(50) NOT NULL DEFAULT 'Lab',
                test_name VARCHAR(255) NOT NULL,
                category VARCHAR(100) DEFAULT 'General',
                status VARCHAR(50) NOT NULL DEFAULT 'Ordered',
                is_abnormal TINYINT(1) DEFAULT 0,
                results TEXT,
                ordered_by VARCHAR(255) DEFAULT 'Doctor',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
            );");
        }
    }
};
