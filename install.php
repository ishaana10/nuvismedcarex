<?php
/**
 * ClinicFlow / Nuvis Medico Web Installation Wizard
 * Sets up Database, Developer / Administrator Credentials, Clinic Profile, Access Control & Seed Initial Data
 */
session_start();

$step = $_GET['step'] ?? 1;
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbDriver = $_POST['db_driver'] ?? 'mysql';
    $dbHost   = trim($_POST['db_host'] ?? 'localhost');
    $dbPort   = trim($_POST['db_port'] ?? '3306');
    $dbName   = trim($_POST['db_name'] ?? 'clinicflow');
    $dbUser   = trim($_POST['db_user'] ?? 'root');
    $dbPass   = $_POST['db_pass'] ?? '';

    // Admin / Developer Account Setup
    $adminName  = trim($_POST['admin_name'] ?? 'System Developer');
    $adminEmail = trim($_POST['admin_email'] ?? 'medico@nuvistechnologies.com.fj');
    $adminPass  = $_POST['admin_pass'] ?? 'password';
    $adminRole  = trim($_POST['admin_role'] ?? 'Developer');

    // Clinic Profile Setup
    $clinicName     = trim($_POST['clinic_name'] ?? 'Nuvis Medico Healthcare');
    $clinicSubtitle = trim($_POST['clinic_subtitle'] ?? 'Integrated Primary & Specialist Healthcare Platform');
    $clinicPhone    = trim($_POST['clinic_phone'] ?? '(555) 019-2831');
    $clinicEmail    = trim($_POST['clinic_email'] ?? 'contact@nuvistechnologies.com.fj');
    $clinicAddress  = trim($_POST['clinic_address'] ?? '100 Healthcare Way, Suite 400, Springfield, OR 97477');

    // Dynamic Developer Custom Fields (Keys & Values)
    $customKeys   = $_POST['custom_keys'] ?? [];
    $customValues = $_POST['custom_values'] ?? [];

    $passHash = password_hash($adminPass, PASSWORD_DEFAULT);

    try {
        // First test connection to MySQL server
        $dsnNoDb = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
        $pdoTest = new PDO($dsnNoDb, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // Attempt to create database if it doesn't exist
        $pdoTest->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

        // Connect to specific database
        $dsnWithDb = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
        $pdo = new PDO($dsnWithDb, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        require_once __DIR__ . '/config/database.php';

        // Disable foreign key checks during schema creation
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

        // Run Schema Creation via executeAutoSchemaMigrations
        $schemaRes = executeAutoSchemaMigrations($pdo);
        if (!empty($schemaRes['errors'])) {
            throw new Exception("Schema execution encountered errors: " . implode('; ', $schemaRes['errors']));
        }

        // Re-enable foreign key checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

        // Seed Initial Developer / Administrator Account
        $stmtUser = $pdo->prepare("INSERT INTO doctors (id, name, specialty, email, password_hash, role, color, dot_color_class, avatar) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), password_hash = VALUES(password_hash), role = VALUES(role)");
        $stmtUser->execute([
            'doc-1', $adminName, 'Developer / IT Administrator', $adminEmail, $passHash, $adminRole, '#10B981', 'bg-emerald-500', 'assets/images/nuvis_medico_logo.png'
        ]);

        // Seed initial clinic settings
        $settingsStmt = $pdo->prepare("INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $settingsStmt->execute(['clinic_name', $clinicName]);
        $settingsStmt->execute(['clinic_subtitle', $clinicSubtitle]);
        $settingsStmt->execute(['clinic_phone', $clinicPhone]);
        $settingsStmt->execute(['clinic_email', $clinicEmail]);
        $settingsStmt->execute(['clinic_address', $clinicAddress]);

        // Insert Developer Custom Fields
        for ($i = 0; $i < count($customKeys); $i++) {
            $k = trim($customKeys[$i]);
            $v = trim($customValues[$i] ?? '');
            if ($k !== '') {
                $settingsStmt->execute([$k, $v]);
            }
        }

        // Seed rest of mock clinical data if patients table is empty
        $patCount = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
        if ($patCount == 0) {
            require_once __DIR__ . '/database/seed.php';
        }

        // Write Config File
        $configContent = "<?php\n" .
            "/**\n * Auto-generated Nuvis Medico Configuration\n */\n" .
            "return [\n" .
            "    'db_driver' => 'mysql',\n" .
            "    'db_host'   => " . var_export($dbHost, true) . ",\n" .
            "    'db_port'   => " . var_export($dbPort, true) . ",\n" .
            "    'db_name'   => " . var_export($dbName, true) . ",\n" .
            "    'db_user'   => " . var_export($dbUser, true) . ",\n" .
            "    'db_pass'   => " . var_export($dbPass, true) . ",\n" .
            "    'admin_account' => [\n" .
            "        'name'  => " . var_export($adminName, true) . ",\n" .
            "        'email' => " . var_export($adminEmail, true) . ",\n" .
            "        'role'  => " . var_export($adminRole, true) . ",\n" .
            "    ]\n" .
            "];\n";

        file_put_contents(__DIR__ . '/config/config.php', $configContent);

        $success = "Database installation completed successfully! Developer credentials and clinic profile saved.";
        $step = 2;

    } catch (Exception $e) {
        $error = "Database setup failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html class="light h-full bg-[#f8f9ff]" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Nuvis Medico Installation Wizard</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full font-sans text-slate-800 bg-[#f8f9ff] flex items-center justify-center p-6">

<div class="max-w-3xl w-full bg-white rounded-3xl shadow-xl border border-slate-200 overflow-hidden my-8">
    <!-- Header -->
    <div class="bg-blue-900 p-6 text-white flex items-center justify-between">
        <div class="flex items-center gap-3">
            <img src="assets/images/nuvis_medico_logo.png" alt="Nuvis Medico" class="h-10 bg-white p-1 rounded-lg object-contain">
            <div>
                <h1 class="text-xl font-bold">Nuvis Medico Installation Wizard</h1>
                <p class="text-xs text-blue-200">Configure Database, Developer Accounts, Access Levels & Clinic Profile</p>
            </div>
        </div>
        <span class="px-3 py-1 rounded-full bg-blue-800 text-blue-100 text-xs font-semibold">
            PHP 8.1+
        </span>
    </div>

    <div class="p-8">
        <?php if ($error): ?>
            <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 text-xs flex items-center gap-3">
                <span class="material-symbols-outlined text-red-600 text-xl">error</span>
                <div>
                    <strong class="font-bold block">Installation Error</strong>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($step == 2): ?>
            <div class="p-6 text-center space-y-4">
                <div class="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto">
                    <span class="material-symbols-outlined text-3xl">check_circle</span>
                </div>
                <h2 class="text-xl font-bold text-slate-900">Setup & Credentials Complete!</h2>
                <p class="text-xs text-slate-600 max-w-md mx-auto">
                    Your database has been initialized, admin/developer account configured, and settings saved to <code class="bg-slate-100 px-2 py-0.5 rounded font-mono text-blue-800">config/config.php</code>.
                </p>
                <div class="pt-4">
                    <a href="login.php" class="px-6 py-3 bg-blue-600 text-white font-semibold text-xs rounded-xl hover:bg-blue-700 transition inline-flex items-center gap-2 shadow-sm">
                        <span>Go to Login Page</span>
                        <span class="material-symbols-outlined text-base">arrow_forward</span>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <form action="install.php" method="POST" class="space-y-6 text-xs">

                <!-- 1. Database Configuration -->
                <div>
                    <h2 class="text-xs font-bold uppercase tracking-wider text-blue-900 mb-3 flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">database</span>
                        <span>1. Database Server Connection</span>
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Database Engine</label>
                            <select name="db_driver" class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-semibold focus:outline-none focus:border-blue-600">
                                <option value="mysql" selected>MySQL (Production & Local)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Database Host</label>
                            <input type="text" name="db_host" value="localhost" required class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Database Name</label>
                            <input type="text" name="db_name" value="clinicflow" required class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Port</label>
                            <input type="text" name="db_port" value="3306" required class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Database Username</label>
                            <input type="text" name="db_user" value="root" required class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Database Password</label>
                            <input type="password" name="db_pass" placeholder="Leave empty if none" class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>
                    </div>
                </div>

                <hr class="border-slate-200">

                <!-- 2. Developer & Admin Account Setup -->
                <div>
                    <h2 class="text-xs font-bold uppercase tracking-wider text-blue-900 mb-3 flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">badge</span>
                        <span>2. Developer / Admin Credentials & Access Level</span>
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Account Display Name</label>
                            <input type="text" name="admin_name" value="System Developer" required class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Access Level / Role</label>
                            <select name="admin_role" class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-semibold focus:outline-none focus:border-blue-600">
                                <option value="Developer" selected>Developer (Full System & Git Access)</option>
                                <option value="Administrator">Administrator (Clinic Management)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Developer Login Email</label>
                            <input type="email" name="admin_email" value="medico@nuvistechnologies.com.fj" required class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Developer Login Password</label>
                            <input type="password" name="admin_pass" value="password" required class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>
                    </div>
                </div>

                <hr class="border-slate-200">

                <!-- 3. Clinic Profile Setup -->
                <div>
                    <h2 class="text-xs font-bold uppercase tracking-wider text-blue-900 mb-3 flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">domain</span>
                        <span>3. Clinic Profile Setup</span>
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Clinic Name</label>
                            <input type="text" name="clinic_name" value="Nuvis Medico Healthcare" required class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Clinic Subtitle</label>
                            <input type="text" name="clinic_subtitle" value="Integrated Primary & Specialist Healthcare Platform" required class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Clinic Phone</label>
                            <input type="text" name="clinic_phone" value="(555) 019-2831" required class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Clinic Contact Email</label>
                            <input type="email" name="clinic_email" value="contact@nuvistechnologies.com.fj" required class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">Clinic Address</label>
                            <input type="text" name="clinic_address" value="100 Healthcare Way, Suite 400, Springfield, OR 97477" required class="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-300 font-medium focus:outline-none focus:border-blue-600">
                        </div>
                    </div>
                </div>

                <hr class="border-slate-200">

                <!-- 4. Dynamic Developer Custom Fields -->
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-blue-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-base">tune</span>
                            <span>4. Additional Custom Developer Fields (Extensible Key-Value Settings)</span>
                        </h2>
                        <button type="button" onclick="addCustomField()" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-[11px] rounded-lg transition flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">add</span>
                            <span>Add Field</span>
                        </button>
                    </div>

                    <div id="custom-fields-container" class="space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <input type="text" name="custom_keys[]" value="dev_environment" placeholder="Setting Key (e.g., dev_environment)" class="bg-slate-50 px-3 py-2 rounded-lg border border-slate-300 font-mono text-[11px]">
                            <input type="text" name="custom_values[]" value="Production - A2 Hosting" placeholder="Setting Value" class="bg-slate-50 px-3 py-2 rounded-lg border border-slate-300 text-[11px]">
                        </div>
                    </div>
                </div>

                <div class="pt-4 flex items-center justify-between border-t border-slate-200">
                    <span class="text-[11px] text-slate-500">Will initialize schema, save credentials, and seed initial records.</span>
                    <button type="submit" class="px-6 py-3 bg-blue-600 text-white font-bold text-xs rounded-xl hover:bg-blue-700 transition shadow-md flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">save</span>
                        <span>Save Config & Install Database</span>
                    </button>
                </div>
            </form>

            <script>
                function addCustomField() {
                    const container = document.getElementById('custom-fields-container');
                    const div = document.createElement('div');
                    div.className = 'grid grid-cols-2 gap-2 mt-2';
                    div.innerHTML = `
                        <input type="text" name="custom_keys[]" placeholder="Setting Key" class="bg-slate-50 px-3 py-2 rounded-lg border border-slate-300 font-mono text-[11px]">
                        <input type="text" name="custom_values[]" placeholder="Setting Value" class="bg-slate-50 px-3 py-2 rounded-lg border border-slate-300 text-[11px]">
                    `;
                    container.appendChild(div);
                }
            </script>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
