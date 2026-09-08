<?php
/**
 * Database Configuration & Connection Manager
 * Dynamically loads credentials from config/config.php or environment variables
 */

require_once __DIR__ . '/../includes/autoloader.php';

function getAppConfig(): array {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configFile = __DIR__ . '/config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
    } else {
        // Fallback default configuration
        $config = [
            'db_driver' => 'mysql',
            'db_host'   => getenv('DB_HOST')   ?: '127.0.0.1',
            'db_port'   => getenv('DB_PORT')   ?: '3306',
            'db_name'   => getenv('DB_NAME')   ?: 'clinicflow',
            'db_user'   => getenv('DB_USER')   ?: 'root',
            'db_pass'   => getenv('DB_PASS')   ?: '',
        ];
    }
    return $config;
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $cfg = getAppConfig();
    $host = $cfg['db_host'] ?? '127.0.0.1';
    $port = $cfg['db_port'] ?? '3306';
    $dbname = $cfg['db_name'] ?? 'clinicflow';
    $user = $cfg['db_user'] ?? 'root';
    $pass = $cfg['db_pass'] ?? '';

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        executeAutoSchemaMigrations($pdo);
        ensureDoctorColumnsExist($pdo);
        runDatabaseMigrations($pdo);
        seedDefaultUsersIfEmpty($pdo);
        return $pdo;
    } catch (PDOException $e) {
        // File-based SQLite fallback when local MySQL daemon is not reachable
        $dbDir = __DIR__ . '/../database';
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        $sqliteFile = $dbDir . '/clinicflow.sqlite';
        $pdo = new PDO("sqlite:" . $sqliteFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        executeAutoSchemaMigrations($pdo);
        ensureDoctorColumnsExist($pdo);
        runDatabaseMigrations($pdo);
        seedDefaultUsersIfEmpty($pdo);
        return $pdo;
    }
}

/**
 * Auto-migrate columns for doctor signatures & stamps if missing
 */
function seedDefaultUsersIfEmpty(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM doctors");
        if ($stmt && (int)$stmt->fetchColumn() === 0) {
            $defaultPasswordHash = password_hash('password', PASSWORD_DEFAULT);
            $doctors = [
                ['doc-1', 'default-clinic', 'System Developer', 'Developer / IT Administrator', 'medico@nuvistechnologies.com.fj', $defaultPasswordHash, 'Developer', '#10B981', 'bg-emerald-500', 'assets/images/nuvis_medico_logo.png', 'PRC-DEV-001', 'PTR-DEV-001', null, null, 1],
                ['doc-2', 'default-clinic', 'Dr. Sarah Jenkins', 'Internal Medicine', 'sjenkins@clinicflow.com', $defaultPasswordHash, 'Doctor', '#10B981', 'bg-emerald-500', 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?auto=format&fit=crop&q=80&w=200', 'PRC-0098412', 'PTR-8842109', null, null, 1]
            ];
            $stmtIns = $pdo->prepare("INSERT INTO doctors (id, clinic_id, name, specialty, email, password_hash, role, color, dot_color_class, avatar, prc_number, ptr_number, esignature, digital_stamp, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($doctors as $doc) {
                $stmtIns->execute($doc);
            }
        }
    } catch (Throwable $e) {
        // Table might not exist yet
    }
}

function runDatabaseMigrations(PDO $pdo): void {
    static $run = false;
    if ($run) return;
    $run = true;
    try {
        $runner = new \ClinicFlow\Services\MigrationRunner($pdo);
        $runner->run();
    } catch (Throwable $e) {
        // Migration logging or suppression if tables are being initialized
    }
}

function ensureDoctorColumnsExist(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $cols = [];
        $stmt = $pdo->query("DESCRIBE doctors");
        while ($row = $stmt->fetch()) {
            $cols[] = strtolower($row['Field']);
        }

        $newCols = [
            'prc_number' => 'VARCHAR(100)',
            'ptr_number' => 'VARCHAR(100)',
            'esignature' => 'TEXT',
            'digital_stamp' => 'TEXT',
            'is_active' => 'INTEGER DEFAULT 1'
        ];

        foreach ($newCols as $colName => $colDef) {
            if (!in_array($colName, $cols)) {
                $pdo->exec("ALTER TABLE doctors ADD COLUMN {$colName} {$colDef}");
            }
        }

        // Auto-migrate visit_id for prescriptions table
        $rxCols = [];
        $stmt = $pdo->query("DESCRIBE prescriptions");
        while ($row = $stmt->fetch()) {
            $rxCols[] = strtolower($row['Field']);
        }

        if (!in_array('visit_id', $rxCols)) {
            $pdo->exec("ALTER TABLE prescriptions ADD COLUMN visit_id VARCHAR(100)");
        }

        // Auto-migrate columns for past_visits table
        $pvCols = [];
        $stmt = $pdo->query("DESCRIBE past_visits");
        while ($row = $stmt->fetch()) {
            $pvCols[] = strtolower($row['Field']);
        }

        $newPvCols = [
            'vitals' => 'TEXT',
            'prescriptions' => 'TEXT',
            'soap_notes' => 'TEXT',
            'visit_id' => 'VARCHAR(100)'
        ];

        foreach ($newPvCols as $colName => $colDef) {
            if (!in_array($colName, $pvCols)) {
                $pdo->exec("ALTER TABLE past_visits ADD COLUMN {$colName} {$colDef}");
            }
        }

        // Auto-migrate inventory table
        $invCols = [];
        $stmt = $pdo->query("DESCRIBE inventory");
        while ($row = $stmt->fetch()) {
            $invCols[] = strtolower($row['Field']);
        }

        $newInvCols = [
            'cost_price' => 'DECIMAL(10,2) DEFAULT 0.00',
            'unit_price' => 'DECIMAL(10,2) DEFAULT 0.00',
            'batch_number' => 'VARCHAR(100)',
            'expiry_date' => 'DATE',
            'is_active' => 'INTEGER DEFAULT 1',
            'vms_tax_code' => "VARCHAR(10) DEFAULT 'A'",
            'custom_fields' => 'TEXT'
        ];

        foreach ($newInvCols as $colName => $colDef) {
            if (!in_array($colName, $invCols)) {
                $pdo->exec("ALTER TABLE inventory ADD COLUMN {$colName} {$colDef}");
            }
        }

        // Auto-migrate invoices table columns
        $invTableCols = [];
        $stmt = $pdo->query("DESCRIBE invoices");
        while ($row = $stmt->fetch()) {
            $invTableCols[] = strtolower($row['Field']);
        }

        if (!empty($invTableCols)) {
            $newInvoiceCols = [
                'invoice_type' => "VARCHAR(20) NOT NULL DEFAULT 'Normal'",
                'transaction_type' => "VARCHAR(20) NOT NULL DEFAULT 'Sale'",
                'seller_tin' => "VARCHAR(50) DEFAULT '502579006'",
                'business_location' => "VARCHAR(255) DEFAULT 'Suva Central Clinic, 2 Woodstand Road, Suva'",
                'cashier' => "VARCHAR(100) DEFAULT 'Admin'",
                'buyer_tin' => 'VARCHAR(50)',
                'buyer_cost_center' => 'VARCHAR(100)',
                'pos_number' => "VARCHAR(50) DEFAULT 'CF-POS-V3/1.0'",
                'pos_time' => 'VARCHAR(50)',
                'ref_no' => 'VARCHAR(100)',
                'ref_time' => 'VARCHAR(50)',
                'is_fiscalized' => 'TINYINT(1) DEFAULT 0',
                'sdc_invoice_no' => 'VARCHAR(100)',
                'sdc_time' => 'VARCHAR(50)',
                'invoice_counter' => 'VARCHAR(100)',
                'verification_url' => 'TEXT',
                'digital_signature' => 'TEXT',
                'total_tax' => 'DECIMAL(10,2) DEFAULT 0.00',
                'payment_methods' => 'TEXT'
            ];

            foreach ($newInvoiceCols as $colName => $colDef) {
                if (!in_array($colName, $invTableCols)) {
                    $pdo->exec("ALTER TABLE invoices ADD COLUMN {$colName} {$colDef}");
                }
            }
        }

        // Auto-create inventory_logs if missing
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_logs (
            id VARCHAR(50) PRIMARY KEY,
            inventory_id VARCHAR(50) NOT NULL,
            change_amount INT NOT NULL,
            previous_stock INT NOT NULL,
            new_stock INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            supplier VARCHAR(255),
            unit_cost DECIMAL(10,2) DEFAULT 0.00,
            notes TEXT,
            created_by VARCHAR(255) DEFAULT 'System',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );");
    } catch (Exception $e) {
        // Table might not exist yet during fresh install
    }
}

/**
 * Execute automatic database schema migrations/updates from SQL schema files.
 */
function executeAutoSchemaMigrations(PDO $pdo): array {
    $results = [
        'executed' => 0,
        'skipped' => 0,
        'errors' => []
    ];

    $schemaFile = __DIR__ . '/../database/schema.sql';
    if (!file_exists($schemaFile)) {
        return $results;
    }

    $sqlContent = file_get_contents($schemaFile);
    if (!$sqlContent) {
        return $results;
    }

    // Split SQL content into individual statements
    $lines = explode("\n", $sqlContent);
    $queries = [];
    $currentQuery = '';

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
            continue;
        }
        $currentQuery .= $line . "\n";
        if (substr(rtrim($trimmed), -1) === ';') {
            $queries[] = trim($currentQuery);
            $currentQuery = '';
        }
    }

    if (!empty(trim($currentQuery))) {
        $queries[] = trim($currentQuery);
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    foreach ($queries as $query) {
        if (empty($query)) continue;
        try {
            $pdo->exec($query);
            $results['executed']++;
        } catch (PDOException $e) {
            // Check if error is due to existing table/column/index (benign migration error)
            $msg = $e->getMessage();
            if (
                stripos($msg, 'already exists') !== false ||
                stripos($msg, 'duplicate') !== false ||
                stripos($msg, '1050') !== false || // MySQL Table already exists
                stripos($msg, '1060') !== false || // MySQL Duplicate column name
                stripos($msg, '1061') !== false || // MySQL Duplicate key name
                stripos($msg, '1072') !== false || // MySQL Key column doesn't exist
                stripos($msg, '1091') !== false    // MySQL Can't DROP key/column; check that column/key exists
            ) {
                $results['skipped']++;
            } else {
                $results['errors'][] = $msg;
            }
        }
    }

    // Ensure specific column checks are applied
    ensureDoctorColumnsExist($pdo);

    return $results;
}

// Session & Toast helper
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function setToast(string $title, string $message, string $type = 'success'): void {
    $_SESSION['toast'] = [
        'id' => uniqid('toast_'),
        'title' => $title,
        'message' => $message,
        'type' => $type
    ];
}

function getToast(): ?array {
    if (isset($_SESSION['toast'])) {
        $toast = $_SESSION['toast'];
        unset($_SESSION['toast']);
        return $toast;
    }
    return null;
}
