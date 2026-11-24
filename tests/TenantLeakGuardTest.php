<?php
declare(strict_types=1);

namespace ClinicFlow\Tests;

use ClinicFlow\Services\LabOrderService;
use ClinicFlow\Shared\TenantContext;
use PDO;
use PHPUnit\Framework\TestCase;

/** Pre-release guards against one clinic seeing or changing another clinic's data. */
final class TenantLeakGuardTest extends TestCase
{
    private function root(): string { return dirname(__DIR__); }

    public function testClinicRecordsCanOnlyBeEditedByDeveloper(): void
    {
        $src = (string) file_get_contents($this->root() . '/actions/tenant_actions.php');
        $this->assertMatchesRegularExpression("/save_tenant'\)\s*\{[^}]*?requireRole\(\['Developer'\]\)/s", $src,
            'a clinic administrator must not be able to edit plan, price, status or any clinic record');
    }

    public function testLabCatalogOfOneClinicIsNotSeenByAnother(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE clinic_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
                    CREATE TABLE tenant_settings (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, setting_key TEXT NOT NULL, setting_value TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE(tenant_id, setting_key));
                    CREATE TABLE audit_logs (id TEXT PRIMARY KEY, tenant_id TEXT, user_id TEXT, user_name TEXT, user_role TEXT, action TEXT, details TEXT, patient_id TEXT, ip_address TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);");
        $svc = new LabOrderService($pdo);
        $mine = ['Secret Panel' => [['name' => 'Clinic B only test', 'category' => 'X']]];
        $svc->saveLabCatalog($mine, 'clinic-b');

        $other = $svc->getLabCatalog('clinic-c');
        $this->assertStringNotContainsString('Clinic B only test', json_encode($other));
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM clinic_settings WHERE setting_key = 'lab_catalog'")->fetchColumn(),
            'another clinic saving must not write the shared catalog row');
        TenantContext::clear();
    }

    public function testCloudDriveLoginIsNotInheritedFromTheSharedRow(): void
    {
        $src = (string) file_get_contents($this->root() . '/src/Services/FileUploadService.php');
        $this->assertStringContainsString("\$tenantId === 'default-clinic' && array_key_exists(\$key, \$settings)", $src);
    }

    public function testTaxRatesSavedForOneClinicDoNotRewriteTheSharedDefault(): void
    {
        $src = (string) file_get_contents($this->root() . '/actions/tax_save_settings.php');
        $this->assertStringContainsString("if (\$tenantId === 'default-clinic') { \$stmt->execute", $src);
    }

    /**
     * Every SELECT/UPDATE/DELETE on a clinic-owned table must name the clinic within the statement,
     * except the reviewed spots below (sign-in, a person's own account, public tokens, developer tools).
     */
    public function testQueriesOnClinicTablesAreScoped(): void
    {
        $schema = (string) file_get_contents($this->root() . '/database/schema.sql');
        preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\n\);/s', $schema, $m, PREG_SET_ORDER);
        $scoped = [];
        foreach ($m as $t) {
            if (str_contains($t[2], 'tenant_id')) { $scoped[$t[1]] = true; }
        }
        // file => tables reviewed and accepted
        $reviewed = [
            'actions/change_password.php' => ['doctors'], 'actions/login.php' => ['doctors'],
            'actions/profile_save.php' => ['doctors'], 'actions/request_otp.php' => ['doctors'],
            'actions/reset_password_otp.php' => ['doctors'], 'api/auth.php' => ['doctors'],
            'config/database.php' => ['doctors'], 'includes/access.php' => ['doctors'],
            'settings.php' => ['doctors'], 'src/Services/UserPreferences.php' => ['doctors'],
            'actions/erp_actions.php' => ['tenant_invoices'], 'src/Services/NuvisErpService.php' => ['tenant_invoices', 'erp_sync_logs'],
            'src/Services/DeveloperAlertService.php' => ['developer_alerts'], 'src/Services/MonitoringService.php' => ['monitoring_incidents'],
            'src/Services/WishBoardService.php' => ['feature_wishes'],
            'src/Services/PrescriptionVerificationService.php' => ['prescription_verifications'],
            // reached only after the parent record was found within the clinic
            'src/Services/Cloud/FileStore.php' => ['uploaded_files'], 'src/Services/TaxService.php' => ['invoice_items'],
            'src/Services/PatientIdentifiers.php' => ['patient_identifiers'],
            // the where clause starts with tenant_id built earlier in the file
            'admin.php' => ['audit_logs'],
        ];
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($this->root()) + 1);
            if (!str_ends_with($rel, '.php') || preg_match('#^(vendor|tests|database|bin|\.git)/#', $rel)) { continue; }
            $s = (string) file_get_contents($f->getPathname());
            preg_match_all('/\b(SELECT\b[^;]{0,300}?\bFROM|UPDATE|DELETE\s+FROM)\s+`?(\w+)`?/s', $s, $q, PREG_OFFSET_CAPTURE);
            foreach ($q[2] as $i => [$table, $pos]) {
                if (!isset($scoped[$table])) { continue; }
                $start = $q[0][$i][1];
                $win = substr($s, $start, 900);
                $e = strpos($win, ');');
                $win = substr($win, 0, $e !== false ? $e + 250 : 600);
                if (preg_match('/tenant_?[iI]d|TenantContext|scope|\$where|\$tid|\$tenant/', $win)) { continue; }
                if (in_array($table, $reviewed[$rel] ?? [], true)) { continue; }
                $found[] = "$rel ($table)";
            }
        }
        $this->assertSame([], array_values(array_unique($found)),
            "These queries touch clinic data without naming the clinic. Scope them, or review and add them to the list:\n" . implode("\n", array_unique($found)));
    }
}
