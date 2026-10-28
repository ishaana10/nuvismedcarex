<?php

/**
 * Consent forms a clinic designs for itself, and the signed records that come
 * out of them.
 *
 * A record keeps its own copy of the wording and the questions as they stood
 * when it was signed: editing a template later must never change what someone
 * already agreed to.
 */
return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $updatedAt = $driver === 'sqlite'
            ? 'updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            : 'updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';

        $pdo->exec("CREATE TABLE IF NOT EXISTS consent_forms (
            id VARCHAR(50) PRIMARY KEY,
            tenant_id VARCHAR(50) NOT NULL,
            name VARCHAR(160) NOT NULL,
            purpose VARCHAR(80) NULL,
            description VARCHAR(400) NULL,
            intro TEXT NULL,
            declaration TEXT NULL,
            fields TEXT NULL,
            require_witness TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            version INT NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_by VARCHAR(120) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            {$updatedAt}
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS consent_records (
            id VARCHAR(50) PRIMARY KEY,
            tenant_id VARCHAR(50) NOT NULL,
            form_id VARCHAR(50) NULL,
            form_name VARCHAR(160) NOT NULL,
            form_version INT NOT NULL DEFAULT 1,
            patient_id VARCHAR(50) NOT NULL,
            visit_id VARCHAR(80) NULL,
            snapshot TEXT NULL,
            answers TEXT NULL,
            patient_signature TEXT NULL,
            signed_by_name VARCHAR(160) NULL,
            signed_relationship VARCHAR(80) NULL,
            witness_name VARCHAR(160) NULL,
            witness_signature TEXT NULL,
            signed_at TIMESTAMP NULL,
            recorded_by VARCHAR(120) NULL,
            withdrawn_at TIMESTAMP NULL,
            withdrawn_reason VARCHAR(400) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );");

        foreach ([
            "CREATE INDEX IF NOT EXISTS idx_consent_forms_tenant ON consent_forms (tenant_id, is_active)",
            "CREATE INDEX IF NOT EXISTS idx_consent_records_patient ON consent_records (tenant_id, patient_id)",
            "CREATE INDEX IF NOT EXISTS idx_consent_records_visit ON consent_records (tenant_id, visit_id)",
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\Throwable $e) {
                error_log('[migration] consent index: ' . $e->getMessage());
            }
        }
    }
};
