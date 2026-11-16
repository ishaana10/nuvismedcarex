<?php

/**
 * Record WHO issued each prescription verification link.
 *
 * The public verification page used to show "the first Doctor in the database"
 * (any clinic), so a pharmacist could be shown the wrong prescriber. Existing
 * links keep a NULL doctor_id and the page then shows the clinic only, rather
 * than guessing a doctor.
 */
return new class {
    public function up(PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return; // SQLite (tests) rebuilds its schema from scratch; nothing to alter.
        }
        $has = $pdo->query("SHOW COLUMNS FROM prescription_verifications LIKE 'doctor_id'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE prescription_verifications ADD COLUMN doctor_id VARCHAR(50) NULL AFTER visit_id");
        }
    }
};
