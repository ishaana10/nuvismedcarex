<?php

return new class {
    public function up(PDO $pdo): void {
        $sql = "CREATE TABLE IF NOT EXISTS uploaded_files (
            id VARCHAR(50) PRIMARY KEY,
            tenant_id VARCHAR(50) NOT NULL,
            patient_id VARCHAR(50) NULL,
            file_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NULL,
            storage_provider VARCHAR(50) NOT NULL DEFAULT 'server',
            file_size BIGINT UNSIGNED DEFAULT 0,
            file_type VARCHAR(100) NULL,
            external_id VARCHAR(255) NULL,
            external_url TEXT NULL,
            uploaded_by VARCHAR(100) NULL,
            category VARCHAR(100) DEFAULT 'General',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );";
        $pdo->exec($sql);

        try {
            $pdo->exec("CREATE INDEX idx_uploaded_files_tenant ON uploaded_files(tenant_id);");
            $pdo->exec("CREATE INDEX idx_uploaded_files_patient ON uploaded_files(patient_id);");
            $pdo->exec("CREATE INDEX idx_uploaded_files_provider ON uploaded_files(storage_provider);");
        } catch (Throwable $e) {
            // Indices might already exist
        }
    }
};
