<?php
declare(strict_types=1);

namespace ClinicFlow\Tests;

use ClinicFlow\Services\EmailService;
use ClinicFlow\Utils\QrCode;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class QrCodeTest extends TestCase
{
    private function fingerprint(QrCode $q): string
    {
        $s = '';
        foreach ($q->matrix() as $row) {
            foreach ($row as $dark) { $s .= $dark ? '1' : '0'; }
        }
        return sha1($s);
    }

    /**
     * Golden vectors. These module patterns were checked identical, module for module, against
     * the reference Python `qrcode` library, forced to a single byte-mode segment as this encoder
     * uses (by default that library splits long upper-case/digit runs into an extra alphanumeric
     * segment: also valid, but a different bit stream). The encoder was also decoded with OpenCV and ZXing.
     * If this fails, the encoder's output changed: re-verify with a real scanner before updating.
     */
    public function testOutputMatchesIndependentlyVerifiedReference(): void
    {
        $url = QrCode::encode('https://clinic.example.com/verify_prescription.php?token=RXV-0123456789ABCDEF0123');
        $this->assertSame(5, $url->version());
        $this->assertSame(2, $url->mask());
        $this->assertSame('a1a3061883ff977eb9fa1673685076ab9e80ea61', $this->fingerprint($url));

        $hello = QrCode::encode('HELLO');
        $this->assertSame(1, $hello->version());
        $this->assertSame('11360a79f4902e7c053b1a4f362b3e484e1d0e10', $this->fingerprint($hello));
    }

    public function testSizeGrowsWithTheTextAndStaysWithinSpec(): void
    {
        $this->assertSame(21, QrCode::encode(str_repeat('a', 14))->size()); // version 1 holds 14 bytes at level M
        $this->assertSame(25, QrCode::encode(str_repeat('a', 15))->size()); // one more byte => version 2
        $max = QrCode::encode(str_repeat('x', 666));
        $this->assertSame(20, $max->version());
        $this->assertSame(97, $max->size());
    }

    public function testTooLongTextIsRejectedNotTruncated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QrCode::encode(str_repeat('x', 667));
    }

    public function testFinderPatternsAndTimingPatternArePresent(): void
    {
        $m = QrCode::encode('https://example.com')->matrix();
        $n = count($m);
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$y0, $x0]) {
            for ($i = 0; $i < 7; $i++) {
                $this->assertTrue($m[$y0][$x0 + $i] && $m[$y0 + 6][$x0 + $i] && $m[$y0 + $i][$x0] && $m[$y0 + $i][$x0 + 6], 'finder outline');
            }
            $this->assertTrue($m[$y0 + 3][$x0 + 3], 'finder centre');
            $this->assertFalse($m[$y0 + 1][$x0 + 1], 'finder white ring');
        }
        for ($i = 8; $i < $n - 8; $i++) {
            $this->assertSame($i % 2 === 0, $m[6][$i], 'horizontal timing pattern');
            $this->assertSame($i % 2 === 0, $m[$i][6], 'vertical timing pattern');
        }
    }

    public function testSvgIsWellFormedAndSelfContained(): void
    {
        $svg = QrCode::svg('https://example.com/x?y=1&z=2', 120);
        $xml = simplexml_load_string($svg);
        $this->assertNotFalse($xml, 'valid XML');
        $this->assertStringNotContainsString('http://', str_replace('http://www.w3.org/2000/svg', '', $svg), 'no external references');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', QrCode::svgDataUri('x'));
    }

    public function testPngIsAValidOneBitImageOfTheRightSize(): void
    {
        $q = QrCode::encode('https://example.com');
        $png = $q->toPng(4, 4);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        $ihdr = unpack('Nlen/a4name/Nw/Nh/Cdepth/Ccolor', substr($png, 8, 21));
        $expected = ($q->size() + 8) * 4;
        $this->assertSame('IHDR', $ihdr['name']);
        $this->assertSame($expected, $ihdr['w']);
        $this->assertSame($expected, $ihdr['h']);
        $this->assertSame(1, $ihdr['depth']);
        $this->assertSame(0, $ihdr['color'], 'greyscale');
        $this->assertStringContainsString('IEND', $png);
        $this->assertStringStartsWith('data:image/png;base64,', QrCode::pngDataUri('x'));
    }

    public function testEmailBuilderTurnsEmbeddedImagesIntoInlineAttachments(): void
    {
        $svc = new EmailService(new PDO('sqlite::memory:'));
        $build = new ReflectionMethod($svc, 'buildMessage');
        $build->setAccessible(true);
        $uri = QrCode::pngDataUri('https://clinic.example.com/v?t=1', 3);

        $msg = $build->invoke($svc, 'p@example.com', 'Rx', "<p>Hi</p>\n.dot line\n<img src='$uri' alt='QR'>", 'c@example.com', 'Clinic', '', 'mail.example.com');

        $this->assertStringContainsString('Content-Type: multipart/related', $msg);
        $this->assertStringNotContainsString('data:image/png', $msg, 'Gmail/Outlook strip data: URIs');
        $this->assertMatchesRegularExpression("/src='cid:([0-9a-f]+@mail\\.example\\.com)'/", $msg);
        preg_match("/src='cid:([^']+)'/", $msg, $m);
        $this->assertStringContainsString('Content-ID: <' . $m[1] . '>', $msg);
        $this->assertStringContainsString('Content-Type: image/png', $msg);
        $this->assertStringContainsString("\r\n..dot line", $msg, 'SMTP dot-stuffing still applied');
    }

    public function testPlainEmailIsUnchanged(): void
    {
        $svc = new EmailService(new PDO('sqlite::memory:'));
        $build = new ReflectionMethod($svc, 'buildMessage');
        $build->setAccessible(true);
        $msg = $build->invoke($svc, 'p@example.com', 'Hi', '<p>Plain</p>', 'c@example.com', 'Clinic', '', 'mail.example.com');
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $msg);
        $this->assertStringNotContainsString('multipart', $msg);
    }

    public function testNoExternalQrServiceIsReferencedAnywhere(): void
    {
        $root = dirname(__DIR__);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = $f->getPathname();
            if (preg_match('#/(vendor|node_modules|\.git|tests|dist)/#', $path) || !preg_match('/\.(php|js|html)$/', $path)) { continue; }
            $this->assertStringNotContainsString('qrserver.com', (string) file_get_contents($path), "$path still calls an external QR service");
        }
    }
}
