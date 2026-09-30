<?php

return new class {
    public function up(PDO $pdo): void {
        $sql = "CREATE TABLE IF NOT EXISTS prescription_verifications (
            id VARCHAR(50) PRIMARY KEY,
            tenant_id VARCHAR(50) NOT NULL,
            patient_id VARCHAR(50) NOT NULL,
            visit_id VARCHAR(100),
            verification_token VARCHAR(100) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );";
        $pdo->exec($sql);

        try {
            $pdo->exec("CREATE INDEX idx_rx_verifications_token ON prescription_verifications(verification_token);");
            $pdo->exec("CREATE INDEX idx_rx_verifications_pv ON prescription_verifications(patient_id, visit_id);");
        } catch (Throwable $e) {
            // Indices might already exist
        }
    }
};
