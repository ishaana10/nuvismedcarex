<?php

namespace ClinicFlow\Services;

use PDO;
use Exception;
use Throwable;

class MigrationRunner {
    private PDO $pdo;
    private string $migrationsDir;

    /** Migrations that failed during the last run(): version => message */
    private array $failures = [];

    public function __construct(PDO $pdo, ?string $migrationsDir = null) {
        $this->pdo = $pdo;
        $this->migrationsDir = $migrationsDir ?? __DIR__ . '/../../database/migrations';
    }

    /**
     * Apply whatever has not been applied yet.
     *
     * A migration that fails no longer stops the ones after it. It used to:
     * the first failure threw, the caller swallowed the exception, and every
     * later schema change was silently never applied — which is how a database
     * ends up missing columns the code expects with nothing to show for it.
     * Failures are recorded instead, and reported by failures().
     *
     * @return string[] the versions applied by this call
     */
    public function run(): array {
        $this->failures = [];
        $this->ensureMigrationsTable();
        $applied = $this->getAppliedMigrations();
        $files = glob($this->migrationsDir . '/*.{sql,php}', GLOB_BRACE);

        if (!$files) {
            return [];
        }

        sort($files);
        $executed = [];

        foreach ($files as $file) {
            $version = basename($file);
            if (in_array($version, $applied, true)) {
                continue;
            }

            // DDL commits implicitly on MySQL, so the transaction is a
            // convenience here, not a guarantee; never assume it is still open.
            $this->begin();
            try {
                if (str_ends_with($file, '.sql')) {
                    $sql = file_get_contents($file);
                    if ($sql) {
                        $this->pdo->exec($sql);
                    }
                } elseif (str_ends_with($file, '.php')) {
                    $migration = require $file;
                    if (is_callable($migration)) {
                        $migration($this->pdo);
                    } elseif (is_object($migration) && method_exists($migration, 'up')) {
                        $migration->up($this->pdo);
                    }
                }

                $stmt = $this->pdo->prepare("INSERT INTO schema_migrations (version) VALUES (:v)");
                $stmt->execute(['v' => $version]);
                $this->finish(true);
                $executed[] = $version;
            } catch (Throwable $e) {
                $this->finish(false);
                $this->failures[$version] = $e->getMessage();
                error_log("[migrations] {$version} failed: " . $e->getMessage());
            }
        }

        return $executed;
    }

    /** version => error message, for whatever failed in the last run(). */
    public function failures(): array {
        return $this->failures;
    }

    /** Migration files that have not been applied yet. */
    public function pending(): array {
        $applied = $this->getAppliedMigrations();
        $files = glob($this->migrationsDir . '/*.{sql,php}', GLOB_BRACE) ?: [];
        sort($files);
        $pending = [];
        foreach ($files as $file) {
            $version = basename($file);
            if (!in_array($version, $applied, true)) {
                $pending[] = $version;
            }
        }
        return $pending;
    }

    private function begin(): void {
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }
        } catch (Throwable $e) {
            // Some drivers refuse nested transactions; carry on without one
        }
    }

    private function finish(bool $commit): void {
        try {
            if (!$this->pdo->inTransaction()) {
                return;
            }
            if ($commit) {
                $this->pdo->commit();
            } else {
                $this->pdo->rollBack();
            }
        } catch (Throwable $e) {
            // The statement already committed itself (MySQL DDL does)
        }
    }

    private function ensureMigrationsTable(): void {
        $sql = "CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(255) PRIMARY KEY,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );";
        $this->pdo->exec($sql);
    }

    public function getAppliedMigrations(): array {
        $this->ensureMigrationsTable();
        $stmt = $this->pdo->query("SELECT version FROM schema_migrations ORDER BY version ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
