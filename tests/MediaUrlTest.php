<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class MediaUrlTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../includes/settings.php';
    }

    public function testUploadedPicturesGoThroughTheMediaScript(): void
    {
        $this->assertSame('media.php?p=uploads%2Fsignatures%2Fsig_doc-1_1794872851.png', nxMediaUrl('uploads/signatures/sig_doc-1_1794872851.png'));
        $this->assertSame('media.php?p=uploads%2Fstamps%2Fstamp_a_1.png', nxMediaUrl('uploads/stamps/stamp_a_1.png'));
        $this->assertSame('media.php?p=uploads%2Favatars%2Fa.jpg', nxMediaUrl('uploads/avatars/a.jpg'));
    }

    public function testOtherValuesAreLeftAlone(): void
    {
        foreach (['assets/images/avatar-default.svg', 'data:image/png;base64,AAAA', 'https://example.com/x.png', '', 'uploads/tenants/x/y.png', 'uploads/signatures/../../config/database.php'] as $v) {
            $this->assertSame($v, nxMediaUrl($v));
        }
        $this->assertSame('', nxMediaUrl(null));
    }
}
