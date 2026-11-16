<?php

/**
 * Patient identity documents (social welfare ID, NHN, BRN, voter ID, driving
 * licence, ...). Many per patient, searchable. Idempotent.
 */
return new class {
    public function up(PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS patient_identifiers (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                patient_id VARCHAR(50) NOT NULL,
                id_type VARCHAR(60) NOT NULL,
                id_number VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_patient_ids_number ON patient_identifiers(tenant_id, id_number)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_patient_ids_patient ON patient_identifiers(patient_id)");
            return;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS patient_identifiers (
                id VARCHAR(50) PRIMARY KEY,
                tenant_id VARCHAR(50) NOT NULL,
                patient_id VARCHAR(50) NOT NULL,
                id_type VARCHAR(60) NOT NULL,
                id_number VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_patient_ids_number (tenant_id, id_number),
                INDEX idx_patient_ids_patient (patient_id),
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
            )");
        } catch (Throwable $e) {
            error_log('[migration] patient identifiers: ' . $e->getMessage());
        }
    }
};
