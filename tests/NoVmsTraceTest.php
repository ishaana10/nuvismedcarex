<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The fiscal-invoicing (VMS / FRCS) feature was removed; keep it out. */
final class NoVmsTraceTest extends TestCase
{
    public function testNoSourceFileMentionsVmsOrFrcs(): void
    {
        $root = dirname(__DIR__);
        $skip = ['vendor', '.git', 'node_modules', 'uploads', 'storage', 'logs', 'tests', 'migrations'];
        $hits = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = $f->getPathname();
            $rel = substr($path, strlen($root) + 1);
            if (array_intersect(explode('/', $rel), $skip) || !preg_match('/\.(php|js|json|md|sql|html|css|txt)$/i', $rel)) {
                continue;
            }
            if (preg_match('/\b(vms|frcs|vsdc|fiscali[sz]\w*)\b|vms_/i', (string) file_get_contents($path), $m)) {
                $hits[] = $rel . ' (' . $m[0] . ')';
            }
        }
        $this->assertSame([], $hits, "VMS/FRCS wording is back in:\n" . implode("\n", $hits));
    }

    public function testRemovedFilesStayGone(): void
    {
        foreach (['src/Services/VMSService.php', 'api/vms.php', 'actions/vms_actions.php', 'actions/vms_save_settings.php'] as $f) {
            $this->assertFileDoesNotExist(dirname(__DIR__) . '/' . $f);
        }
    }
}
