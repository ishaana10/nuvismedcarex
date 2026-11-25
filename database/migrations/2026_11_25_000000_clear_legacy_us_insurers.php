<?php

/**
 * Clears the old US insurer names (Aetna, Cigna, Humana, Kaiser, UnitedHealthcare, Blue Cross
 * Blue Shield) left on patient records by earlier demo data. Each clinic now enters only its own
 * Fiji insurers under Administration → Insurance. Patient records only: claims already raised
 * are financial records and keep the name they were raised under. A name the clinic has itself
 * added to its list is left alone. Idempotent.
 */
return new class {
    public function up(PDO $pdo): void
    {
        $legacy = ['Aetna', 'Aetna Health', 'Blue Cross Blue Shield', 'Cigna Health', 'Cigna',
                   'Humana', 'Kaiser Permanente', 'UnitedHealthcare'];
        try {
            $tenants = $pdo->query("SELECT DISTINCT tenant_id FROM patients")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($tenants as $tenantId) {
                $st = $pdo->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = 'insurance_providers' LIMIT 1");
                $st->execute([$tenantId]);
                $own = [];
                foreach ((json_decode((string) $st->fetchColumn(), true) ?: []) as $row) {
                    if (is_array($row) && !empty($row['name'])) {
                        $own[mb_strtolower((string) $row['name'])] = true;
                    }
                }
                $clear = array_values(array_filter($legacy, static fn($n) => !isset($own[mb_strtolower($n)])));
                if (!$clear) {
                    continue;
                }
                $in = implode(',', array_fill(0, count($clear), '?'));
                $pdo->prepare("UPDATE patients SET insurance_provider = NULL WHERE tenant_id = ? AND insurance_provider IN ($in)")
                    ->execute(array_merge([$tenantId], $clear));
            }
        } catch (Throwable $e) {
            error_log('[migration] clear legacy insurers: ' . $e->getMessage());
        }
    }
};
