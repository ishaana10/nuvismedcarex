<?php
declare(strict_types=1);

namespace ClinicFlow\Tests;

use ClinicFlow\Services\AppearanceSettings;
use ClinicFlow\Services\ClinicSettings;
use ClinicFlow\Services\UserPreferences;
use PDO;
use PHPUnit\Framework\TestCase;

final class AppearanceSettingsTest extends TestCase
{
    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE clinic_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
        $pdo->exec("CREATE TABLE tenant_settings (id TEXT PRIMARY KEY, tenant_id TEXT, setting_key TEXT, setting_value TEXT, updated_at TEXT, UNIQUE (tenant_id, setting_key))");
        $pdo->exec("CREATE TABLE doctors (id TEXT PRIMARY KEY, preferences TEXT)");
        return $pdo;
    }

    private function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    public function testStandardColourAddsNoOverride(): void
    {
        $this->assertSame('', AppearanceSettings::css(AppearanceSettings::DEFAULT_ACCENT));
        $this->assertSame('', AppearanceSettings::css('not a colour'));
    }

    public function testAccentBecomesTheBrandShade(): void
    {
        $css = AppearanceSettings::css('#0E7C63');
        $this->assertStringContainsString('--c-blue-700:14 124 99', $css);
        $this->assertStringContainsString('html.dark{', $css);
        $this->assertSame(22, substr_count($css, '--c-blue-'), 'eleven shades in each of light and dark');
    }

    public function testCssContainsOnlyColourVariables(): void
    {
        // Whatever was submitted, the output is made of numbers, never of the input text
        $css = AppearanceSettings::css('#4F46E5');
        $this->assertMatchesRegularExpression('/^:root\{(--c-blue-\d+:\d+ \d+ \d+;?)+\}html\.dark\{(--c-blue-\d+:\d+ \d+ \d+;?)+\}$/', $css);
    }

    /** @dataProvider lightColours */
    public function testLightColoursAreDarkenedForReadableWhiteText(string $hex): void
    {
        $usable = AppearanceSettings::readable($hex);
        $this->assertGreaterThanOrEqual(4.5, AppearanceSettings::contrastWithWhite($this->rgb($usable)));
    }

    public static function lightColours(): array
    {
        return [['#FACC15'], ['#FFFFFF'], ['#93C5FD'], ['#A3E635'], ['#00FFFF']];
    }

    public function testReadableColourIsLeftAloneWhenAlreadyDarkEnough(): void
    {
        $this->assertSame('#164487', AppearanceSettings::readable('#164487'));
    }

    public function testFormRejectsAnythingThatIsNotAHexColour(): void
    {
        foreach (['red', '#12', 'javascript:alert(1)', '#GGGGGG', '</style><script>1</script>', ''] as $bad) {
            $r = AppearanceSettings::fromForm(['accent' => $bad]);
            $this->assertSame('', $r['values']['ui_accent'], "rejected: {$bad}");
        }
    }

    public function testFormKeepsOnlyKnownChoices(): void
    {
        $r = AppearanceSettings::fromForm([
            'accent' => '#0e7c63', 'theme' => 'purple', 'density' => 'compact', 'date_format' => "'; DROP TABLE x;--",
            'time_format' => 'H:i', 'landing_page' => 'evil.php', 'sidebar' => 'collapsed',
            'hidden_nav' => ['inventory', 'patients', 'admin', 'uploads'], 'announcement' => "  Hello\n\n<b>world</b>\x07  ",
        ]);
        $v = $r['values'];
        $this->assertSame('#0E7C63', $v['ui_accent']);
        $this->assertSame('', $v['ui_default_theme']);
        $this->assertSame('compact', $v['ui_default_density']);
        $this->assertSame('', $v['ui_default_date_format']);
        $this->assertSame('H:i', $v['ui_default_time_format']);
        $this->assertSame('', $v['ui_default_landing']);
        $this->assertSame('collapsed', $v['ui_default_sidebar']);
        $this->assertSame('inventory,uploads', $v['ui_hidden_nav'], 'only optional modules can be hidden');
        $this->assertSame('Hello <b>world</b>', $v['ui_announcement'], 'whitespace and control characters tidied; HTML is escaped on output');
        $this->assertNull($r['adjusted']);
    }

    public function testNoticeIsCapped(): void
    {
        $this->assertSame(AppearanceSettings::ANNOUNCEMENT_MAX, mb_strlen(AppearanceSettings::cleanAnnouncement(str_repeat('é', 1000))));
    }

    public function testAdjustedColourIsReportedBack(): void
    {
        $r = AppearanceSettings::fromForm(['accent' => '#FACC15']);
        $this->assertSame('#8C7103', $r['adjusted']);
        $this->assertSame('#8C7103', $r['values']['ui_accent']);
    }

    public function testEachClinicKeepsItsOwnAppearance(): void
    {
        $pdo = $this->db();
        $a = new ClinicSettings($pdo, 'clinic-a');
        $b = new ClinicSettings($pdo, 'clinic-b');
        $a->save(['ui_accent' => '#0E7C63', 'ui_hidden_nav' => 'inventory', 'ui_announcement' => 'A only']);

        $this->assertTrue(ClinicSettings::isPerClinic('ui_accent'));
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM clinic_settings WHERE setting_key LIKE 'ui_%'")->fetchColumn(), 'never written to the shared table');
        $this->assertSame('#0E7C63', AppearanceSettings::load($a->all())['accent']);
        $this->assertSame(AppearanceSettings::DEFAULT_ACCENT, AppearanceSettings::load($b->all())['accent']);
        $this->assertSame([], AppearanceSettings::load($b->all())['hidden_nav']);
        $this->assertSame('', AppearanceSettings::load($b->all())['announcement']);
    }

    public function testClinicDefaultsApplyUntilSomeoneChoosesTheirOwn(): void
    {
        $pdo = $this->db();
        $pdo->exec("INSERT INTO doctors (id, preferences) VALUES ('new-user', NULL), ('picky', '{\"theme\":\"light\"}')");
        $clinic = new ClinicSettings($pdo, 'clinic-a');
        $clinic->save(['ui_default_theme' => 'dark', 'ui_default_density' => 'compact', 'ui_default_sidebar' => 'collapsed', 'ui_default_landing' => 'patients.php']);

        $svc = UserPreferences::forTenant($pdo, 'clinic-a');
        $new = $svc->load('new-user');
        $this->assertSame('dark', $new['theme']);
        $this->assertSame('compact', $new['density']);
        $this->assertTrue($new['sidebar_collapsed']);
        $this->assertSame('patients.php', $new['landing_page']);

        $picky = $svc->load('picky');
        $this->assertSame('light', $picky['theme'], 'a person\'s own choice wins');
        $this->assertSame('compact', $picky['density'], 'what they did not choose follows the clinic');

        $other = UserPreferences::forTenant($pdo, 'clinic-b')->load('new-user');
        $this->assertSame('system', $other['theme'], 'another clinic is unaffected');
    }

    public function testUnknownStoredValuesFallBackToStandard(): void
    {
        $v = AppearanceSettings::load(['ui_accent' => 'nope', 'ui_default_theme' => 'x', 'ui_hidden_nav' => 'patients,inventory,,billing']);
        $this->assertSame(AppearanceSettings::DEFAULT_ACCENT, $v['accent']);
        $this->assertSame('system', $v['theme']);
        $this->assertSame(['inventory'], $v['hidden_nav']);
    }
}
