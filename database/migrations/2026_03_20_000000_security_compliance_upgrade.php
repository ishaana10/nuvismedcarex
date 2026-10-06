<?php

return new class {
    public function up(PDO $pdo): void {
        $getField = function(array $row): string {
            $val = $row['Field'] ?? $row['field'] ?? array_values($row)[0] ?? '';
            return strtolower((string)$val);
        };

        // 1. Add mfa_secret and mfa_enabled to doctors table
        try {
            $docCols = [];
            $stmt = $pdo->query("DESCRIBE doctors");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $fieldName = $getField($row);
                if ($fieldName !== '') $docCols[] = $fieldName;
            }

            if (!in_array('mfa_secret', $docCols)) {
                $pdo->exec("ALTER TABLE doctors ADD COLUMN mfa_secret VARCHAR(255) DEFAULT NULL");
            }
            if (!in_array('mfa_enabled', $docCols)) {
                $pdo->exec("ALTER TABLE doctors ADD COLUMN mfa_enabled TINYINT(1) DEFAULT 0");
            }
        } catch (Throwable $e) {
            // Log or ignore if exists
        }

        // 2. Add patient_id to audit_logs table
        try {
            $auditCols = [];
            $stmt = $pdo->query("DESCRIBE audit_logs");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $fieldName = $getField($row);
                if ($fieldName !== '') $auditCols[] = $fieldName;
            }

            if (!in_array('patient_id', $auditCols)) {
                $pdo->exec("ALTER TABLE audit_logs ADD COLUMN patient_id VARCHAR(50) DEFAULT NULL");
            }
        } catch (Throwable $e) {
            // Log or ignore if exists
        }
    }
};
