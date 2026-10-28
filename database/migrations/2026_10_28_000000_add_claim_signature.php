<?php

/**
 * Insurers want the member's signature on a claim. Keep it with the claim
 * rather than as a loose scan: who signed, when, and the drawn image.
 */
return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $existing = [];
        try {
            $rows = $driver === 'sqlite'
                ? $pdo->query("PRAGMA table_info(insurance_claims)")->fetchAll(PDO::FETCH_ASSOC)
                : $pdo->query("SHOW COLUMNS FROM insurance_claims")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $existing[] = strtolower((string) ($row['name'] ?? $row['Field'] ?? ''));
            }
        } catch (\Throwable $e) {
            return; // table is not there yet; its own migration will create it
        }

        $columns = [
            'patient_signature' => 'TEXT NULL',
            'signed_by_name'    => 'VARCHAR(120) NULL',
            'signed_at'         => 'TIMESTAMP NULL',
        ];
        foreach ($columns as $name => $definition) {
            if (in_array($name, $existing, true)) {
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE insurance_claims ADD COLUMN {$name} {$definition}");
            } catch (\Throwable $e) {
                error_log('[migration] add ' . $name . ' to insurance_claims: ' . $e->getMessage());
            }
        }
    }
};
