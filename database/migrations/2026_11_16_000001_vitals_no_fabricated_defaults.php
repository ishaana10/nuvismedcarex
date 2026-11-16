<?php

/**
 * Stop the database inventing vital signs.
 *
 * The vitals table defaulted every column to a "normal" value (120/80, 72 bpm,
 * 98.6, SpO2 99 ...), so any row written without a measurement looked like a
 * recorded set of normal vitals. Missing now means NULL (DEFAULT NULL, not "no default", so inserts that omit a column still work).
 */
return new class {
    public function up(PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return; // SQLite (tests) rebuilds its schema from scratch; nothing to alter.
        }
        foreach (['blood_pressure', 'heart_rate', 'temperature', 'weight', 'height', 'bmi',
                  'oxygen_sat', 'respiratory_rate', 'sugar', 'heart_rhythm'] as $col) {
            try {
                $pdo->exec("ALTER TABLE vitals ALTER COLUMN `{$col}` SET DEFAULT NULL");
            } catch (Throwable $e) {
                // Column absent on this install, or already without a default.
            }
        }
    }
};
