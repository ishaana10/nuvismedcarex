<?php
declare(strict_types=1);

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;

final class UserGuideTest extends TestCase
{
    private function sections(): array
    {
        return require __DIR__ . '/../includes/user_guide_content.php';
    }

    public function testEverySectionHasWhatThePageNeeds(): void
    {
        $ids = [];
        foreach ($this->sections() as $s) {
            foreach (['id', 'title', 'icon', 'who', 'summary', 'body'] as $k) {
                $this->assertArrayHasKey($k, $s, "section " . ($s['id'] ?? '?') . " lacks $k");
                $this->assertNotEmpty($s[$k], "section " . ($s['id'] ?? '?') . " has empty $k");
            }
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', $s['id']);
            $this->assertArrayNotHasKey($s['id'], $ids, 'duplicate section id ' . $s['id']);
            $ids[$s['id']] = true;
        }
        $this->assertGreaterThanOrEqual(15, count($ids));
    }

    public function testInPageLinksPointAtRealAnchors(): void
    {
        $anchors = [];
        $html = '';
        foreach ($this->sections() as $s) {
            $anchors[$s['id']] = true;
            $html .= $s['body'];
        }
        preg_match_all('/\sid="([^"]+)"/', $html, $m);
        foreach ($m[1] as $id) $anchors[$id] = true;

        preg_match_all('/href="#([^"]+)"/', $html, $links);
        $this->assertNotEmpty($links[1]);
        foreach (array_unique($links[1]) as $target) {
            $this->assertArrayHasKey($target, $anchors, "broken link #$target in the user guide");
        }
    }

    public function testGuideModulesAreRealAccessAreas(): void
    {
        foreach ($this->sections() as $s) {
            if ($s['module'] !== null) {
                $this->assertArrayHasKey($s['module'], \ClinicFlow\Services\AccessControl::MODULES, $s['id']);
            }
        }
    }

    public function testTheMenuLinkIsForEveryone(): void
    {
        // nxNavItems() needs a database, so check how the item is declared instead:
        // no role list, no permission entry and not one of the hideable modules.
        $nav = (string) file_get_contents(__DIR__ . '/../includes/nav.php');
        $line = '';
        foreach (explode("\n", $nav) as $l) {
            if (str_contains($l, "'id' => 'guide'")) $line = $l;
        }
        $this->assertNotSame('', $line, 'the user guide is missing from the menu');
        $this->assertStringContainsString("'url' => 'user_guide.php'", $line);
        $this->assertStringNotContainsString("'roles'", $line);
        $this->assertArrayNotHasKey('guide', \ClinicFlow\Services\AppearanceSettings::OPTIONAL_NAV);
    }

    public function testGuideNeedsNoModulePermission(): void
    {
        require_once __DIR__ . '/../includes/access.php';
        $this->assertArrayNotHasKey('guide', NX_PAGE_ACCESS);
        $this->assertArrayNotHasKey('user_guide.php', NX_SCRIPT_ACCESS);
    }

    public function testEveryKeyboardShortcutInTheGuideExistsInTheApp(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../assets/js/app.js');
        $guide = implode('', array_column($this->sections(), 'body'));
        preg_match_all('/<kbd>G<\/kbd> then <kbd>([A-Z])<\/kbd>/', $guide, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $key) {
            $this->assertMatchesRegularExpression('/\b' . strtolower($key) . ": '[a-z_]+\.php'/", $js, "G then $key is documented but not wired");
        }
    }

    public function testEverySectionHasAHomeLinkToTheTop(): void
    {
        $page = file_get_contents(__DIR__ . '/../user_guide.php');
        $this->assertStringContainsString('id="guide-top"', $page);
        $this->assertStringContainsString('href="#guide-top"', $page);
        $start = strpos($page, 'data-guide-section');
        $card = substr($page, $start, strpos($page, '</section>', $start) - $start);
        $this->assertStringContainsString('href="#guide-top"', $card, 'the Home link must sit inside each section card');
    }

    public function testNoInsurersArePreInserted(): void
    {
        $this->assertFalse(defined(\ClinicFlow\Services\InsuranceProviders::class . '::SUGGESTED'));
    }
}
