<?php

/**
 * Medical certificate: the fields a paper "clinic form" certificate asks for.
 *
 *   problem_category  which problem boxes are ticked (comma separated)
 *   illness_from      "gives history of illness from"
 *   resume_on         "should resume duties on"
 *   review_on         review date
 *
 * All optional. Certificates issued before this keep printing as they did, and
 * the app works (without these four fields) until the migration has run.
 */
return new class {
    private const COLUMNS = [
        'problem_category' => 'VARCHAR(500) NULL',
        'illness_from'     => 'DATE NULL',
        'resume_on'        => 'DATE NULL',
        'review_on'        => 'DATE NULL',
    ];

    public function up(PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return; // SQLite (tests) builds its own schema
        }
        try {
            $has = $pdo->query(
                "SELECT column_name FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'medical_certificates'"
            )->fetchAll(PDO::FETCH_COLUMN);
            if (!$has) return; // table not created yet
            foreach (self::COLUMNS as $name => $def) {
                if (!in_array($name, $has, true)) {
                    $pdo->exec("ALTER TABLE medical_certificates ADD COLUMN `{$name}` {$def}");
                }
            }
        } catch (Throwable $e) {
            error_log('[migration] certificate form fields: ' . $e->getMessage());
        }
    }
};
