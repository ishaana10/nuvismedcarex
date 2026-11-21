<?php
declare(strict_types=1);

use ClinicFlow\Services\AccessControl;
use PHPUnit\Framework\TestCase;

/** Keeps the strict script policy honest: no inline handlers, nonce on every script block. */
final class ContentSecurityPolicyTest extends TestCase
{
    /** @return string[] PHP files of the app (not vendor/tests) */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__);
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = $f->getPathname();
            if ($f->getExtension() === 'php' && !preg_match('#/(vendor|tests|node_modules|\.git)/#', $p)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public function testScriptPolicyHasNoUnsafeInline(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/security.php');
        $this->assertSame(1, preg_match('/Content-Security-Policy: ([^"]*)"/', $src . '"', $m) ? 1 : 0);
        preg_match("/script-src ([^;]*);/", $src, $s);
        $this->assertStringNotContainsString("unsafe-inline", $s[1] ?? '');
        $this->assertStringContainsString("script-src-attr 'none'", $src);
        $this->assertStringContainsString("nonce-", $src);
    }

    public function testNoInlineEventHandlerAttributesRemain(): void
    {
        foreach ($this->phpFiles() as $f) {
            $this->assertSame(
                0,
                preg_match('/\son(click|change|input|submit|keyup|keydown|error|load|focus|blur|mouse[a-z]+)\s*=\s*["\']/i', (string) file_get_contents($f)),
                basename($f) . ' has an inline event handler; use data-on-click (see assets/js/actions.js)'
            );
        }
    }

    public function testEveryInlineScriptBlockCarriesTheNonce(): void
    {
        foreach ($this->phpFiles() as $f) {
            $code = (string) file_get_contents($f);
            $this->assertSame(
                0,
                preg_match('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce=)[^>]*>/i', $code),
                basename($f) . ' has a <script> without nonce="<?= nxCspNonce() ?>"'
            );
        }
    }

    public function testEveryPageActionIsOnTheAllowlist(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/actions.js');
        preg_match('/var ACTIONS = \{(.*?)\};/s', $js, $m);
        preg_match_all('/([A-Za-z_$][\w$]*): 1/', $m[1] ?? '', $names);
        $allowed = array_flip($names[1]);
        $skip = array_flip(['this', 'document', 'window', 'NX', 'confirm', 'event']);
        $missing = [];
        foreach ($this->phpFiles() as $f) {
            preg_match_all('/data-on-(?:click|change|input|submit|keyup|error)=(?:"([^"]*)"|\'([^\']*)\')/', (string) file_get_contents($f), $all, PREG_SET_ORDER);
            foreach ($all as $a) {
                $body = preg_replace('/<\?=.*?\?>/s', '0', ($a[1] ?? '') . ($a[2] ?? ''));
                foreach (explode(';', $body) as $st) {
                    $st = trim(preg_replace('/^return\s+/', '', trim($st)));
                    if ($st !== '' && preg_match('/^([A-Za-z_$][\w$]*)/', $st, $r) && !isset($skip[$r[1]]) && !isset($allowed[$r[1]])) {
                        $missing[$r[1]] = basename($f);
                    }
                }
            }
        }
        $this->assertSame([], $missing, 'Add these to ACTIONS in assets/js/actions.js: ' . implode(', ', array_keys($missing)));
    }

    /** Emails are not web pages, so they keep inline styles. */
    private const EMAIL_FILES = ['EmailService.php', 'send_email.php', 'test_email.php', 'MonitoringService.php'];

    public function testNoInlineStyleAttributesRemainInPages(): void
    {
        foreach ($this->phpFiles() as $f) {
            if (in_array(basename($f), self::EMAIL_FILES, true)) {
                continue;
            }
            $this->assertSame(
                0,
                preg_match('/\sstyle\s*=\s*["\']/i', (string) file_get_contents($f)),
                basename($f) . ' has a style="" attribute; use data-css="..." (see assets/js/actions.js)'
            );
            $this->assertSame(
                0,
                preg_match('/<style(?![^>]*\bnonce=)[\s>]/i', (string) file_get_contents($f)),
                basename($f) . ' has a <style> block without the nonce'
            );
        }
    }

    public function testThePolicyAllowsNothingFromOtherSites(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/security.php');
        preg_match('/^.*Content-Security-Policy:.*$/m', $src, $m);
        $policy = $m[0] ?? '';
        $this->assertNotSame('', $policy);
        $this->assertStringNotContainsString('http', $policy, 'the browser may only talk to this site');
        $this->assertStringNotContainsString('unsafe-inline', $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertStringContainsString("style-src-attr 'none'", $policy);
    }

    public function testPagesLoadNoScriptsStylesOrImagesFromOtherSites(): void
    {
        foreach ($this->phpFiles() as $f) {
            if (in_array(basename($f), self::EMAIL_FILES, true)) {
                continue;
            }
            $this->assertSame(
                0,
                preg_match('/<(?:script|link|img|iframe)\b[^>]*\b(?:src|href)\s*=\s*["\']https?:\/\//i', (string) file_get_contents($f)),
                basename($f) . ' loads something from another website'
            );
        }
    }

    public function testReceptionistHasNoAccessToDocuments(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE tenant_settings (tenant_id TEXT, setting_key TEXT, setting_value TEXT)');
        $ac = new AccessControl($pdo, 't1');
        $this->assertFalse($ac->can('Receptionist', 'documents', AccessControl::VIEW));
        $this->assertTrue($ac->can('Doctor', 'documents', AccessControl::VIEW));
    }
}
