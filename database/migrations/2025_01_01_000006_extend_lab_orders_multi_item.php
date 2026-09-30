<?php

return new class {
    public function up(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS lab_order_items (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                lab_order_id VARCHAR(50) NOT NULL,
                test_name VARCHAR(255) NOT NULL,
                category VARCHAR(100) DEFAULT 'General',
                instructions TEXT,
                status VARCHAR(50) NOT NULL DEFAULT 'Ordered',
                is_abnormal INTEGER DEFAULT 0,
                results TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS lab_order_items (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                lab_order_id VARCHAR(50) NOT NULL,
                test_name VARCHAR(255) NOT NULL,
                category VARCHAR(100) DEFAULT 'General',
                instructions TEXT,
                status VARCHAR(50) NOT NULL DEFAULT 'Ordered',
                is_abnormal TINYINT(1) DEFAULT 0,
                results TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (lab_order_id) REFERENCES lab_orders(id) ON DELETE CASCADE
            );");
        }

        // Insert default customizable lab catalog if not present
        $defaultCatalog = json_encode([
            [
                'category' => 'Hematology',
                'tests' => ['Complete Blood Count (CBC)', 'Erythrocyte Sedimentation Rate (ESR)', 'Blood Grouping & Rh', 'Hemoglobin (Hb)']
            ],
            [
                'category' => 'Biochemistry',
                'tests' => ['Lipid Profile', 'Fasting Blood Sugar (FBS)', 'HbA1c', 'Liver Function Test (LFT)', 'Renal Function Test (RFT)', 'Serum Electrolytes']
            ],
            [
                'category' => 'Urinalysis & Clinical Pathology',
                'tests' => ['Routine Urinalysis', 'Urine Culture & Sensitivity', 'Stool Microscopy']
            ],
            [
                'category' => 'Microbiology & Serology',
                'tests' => ['Dengue NS1 / IgM / IgG', 'Typhoid Widal Test', 'HIV 1 & 2 Rapid Test', 'HBsAg Rapid Test']
            ],
            [
                'category' => 'Imaging & Diagnostics',
                'tests' => ['Chest X-Ray (PA View)', 'Abdominal Ultrasound', 'Electrocardiogram (ECG)']
            ]
        ], JSON_PRETTY_PRINT);

        $stmt = $pdo->prepare("INSERT OR IGNORE INTO clinic_settings (setting_key, setting_value) VALUES ('lab_catalog', ?)");
        $stmt->execute([$defaultCatalog]);
    }
};
