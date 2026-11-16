<?php
declare(strict_types=1);

namespace ClinicFlow\Utils;

use InvalidArgumentException;

/**
 * Self-contained QR Code generator.
 *
 * - No external service, no GD/Imagick, no Composer package: it is plain PHP that
 *   ships with the app, so QR codes work offline and nothing leaves the server.
 * - Byte mode, error-correction level M (~15% recoverable), versions 1-20
 *   (up to 666 bytes, plenty for a verification URL).
 * - Output as SVG (crisp for print pages) or PNG (for e-mail, where SVG is not
 *   supported). PNG is written by hand with zlib, so GD is not required either.
 *
 * Usage:
 *   QrCode::svgDataUri($url)            // <img src="...">
 *   QrCode::pngDataUri($url, 4)         // e-mail (EmailService turns it into an inline CID image)
 */
final class QrCode
{
    public const MAX_VERSION = 20;

    /** Error-correction codewords per block and block count, level M, index = version - 1. */
    private const ECC_PER_BLOCK = [10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26];
    private const NUM_BLOCKS    = [1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16];

    private int $version;
    private int $size;
    private int $mask = 0;
    /** @var array<int, array<int, bool>> [y][x], true = dark */
    private array $modules = [];
    /** @var array<int, array<int, bool>> */
    private array $isFunction = [];

    private function __construct(int $version, string $data)
    {
        $this->version = $version;
        $this->size = $version * 4 + 17;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = $this->modules;

        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addEccAndInterleave($this->buildDataCodewords($data)));

        // Pick the mask with the lowest penalty, as the standard recommends.
        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $this->applyMask($m);
            $this->drawFormatBits($m);
            $p = $this->penaltyScore();
            if ($p < $bestPenalty) {
                $best = $m;
                $bestPenalty = $p;
            }
            $this->applyMask($m); // XOR again = undo
        }
        $this->mask = $best;
        $this->applyMask($best);
        $this->drawFormatBits($best);
        $this->isFunction = []; // not needed any more
    }

    public static function encode(string $text): self
    {
        $len = strlen($text);
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            $ccBits = $v <= 9 ? 8 : 16;
            $needBits = 4 + $ccBits + $len * 8;
            if ($needBits <= self::numDataCodewords($v) * 8) {
                return new self($v, $text);
            }
        }
        throw new InvalidArgumentException("Text is too long for a QR code ({$len} bytes).");
    }

    // ------------------------------------------------------------ public API

    public function version(): int { return $this->version; }
    public function size(): int { return $this->size; }
    public function mask(): int { return $this->mask; }

    /** @return array<int, array<int, bool>> */
    public function matrix(): array { return $this->modules; }

    /** SVG markup. $px is the rendered width/height in pixels. */
    public function toSvg(int $px = 160, int $border = 4): string
    {
        $dim = $this->size + $border * 2;
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            $x = 0;
            while ($x < $this->size) {
                if ($this->modules[$y][$x]) {
                    $start = $x;
                    while ($x < $this->size && $this->modules[$y][$x]) { $x++; }
                    $path .= sprintf('M%d,%dh%dv1h-%dz', $start + $border, $y + $border, $x - $start, $x - $start);
                } else {
                    $x++;
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '"'
            . ' width="' . $px . '" height="' . $px . '" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="#ffffff"/>'
            . '<path d="' . $path . '" fill="#000000"/></svg>';
    }

    /** 1-bit greyscale PNG, $scale pixels per module. */
    public function toPng(int $scale = 4, int $border = 4): string
    {
        $scale = max(1, $scale);
        $dim = ($this->size + $border * 2) * $scale;
        $rowBytes = intdiv($dim + 7, 8);
        $raw = '';
        for ($py = 0; $py < $dim; $py++) {
            $my = intdiv($py, $scale) - $border;
            $row = array_fill(0, $rowBytes, 0xFF); // 1 = white
            if ($my >= 0 && $my < $this->size) {
                for ($px = 0; $px < $dim; $px++) {
                    $mx = intdiv($px, $scale) - $border;
                    if ($mx >= 0 && $mx < $this->size && $this->modules[$my][$mx]) {
                        $row[$px >> 3] &= ~(0x80 >> ($px & 7)); // 0 = black
                    }
                }
            }
            $raw .= "\0" . pack('C*', ...$row); // filter type 0
        }
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $dim, $dim, 1, 0, 0, 0, 0)) // 1-bit greyscale
            . $chunk('IDAT', gzcompress($raw, 9))
            . $chunk('IEND', '');
    }

    public static function svg(string $text, int $px = 160): string
    {
        return self::encode($text)->toSvg($px);
    }

    public static function png(string $text, int $scale = 4): string
    {
        return self::encode($text)->toPng($scale);
    }

    /** For <img src="..."> on web/print pages. */
    public static function svgDataUri(string $text, int $px = 160): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($text, $px));
    }

    /** For HTML e-mail: EmailService converts it into an inline attachment. */
    public static function pngDataUri(string $text, int $scale = 4): string
    {
        return 'data:image/png;base64,' . base64_encode(self::png($text, $scale));
    }

    // ------------------------------------------------------------- encoding

    private static function numRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function numDataCodewords(int $ver): int
    {
        return intdiv(self::numRawDataModules($ver), 8) - self::ECC_PER_BLOCK[$ver - 1] * self::NUM_BLOCKS[$ver - 1];
    }

    /** @return int[] */
    private function buildDataCodewords(string $data): array
    {
        $bits = [];
        $put = static function (int $val, int $len) use (&$bits): void {
            for ($i = $len - 1; $i >= 0; $i--) { $bits[] = ($val >> $i) & 1; }
        };
        $put(0b0100, 4); // byte mode
        $put(strlen($data), $this->version <= 9 ? 8 : 16);
        foreach (str_split($data) as $ch) { $put(ord($ch), 8); }

        $capacity = self::numDataCodewords($this->version) * 8;
        $put(0, min(4, $capacity - count($bits))); // terminator
        while (count($bits) % 8 !== 0) { $bits[] = 0; }
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) { $put($pad, 8); }

        $codewords = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $codewords[] = bindec(implode('', $byte));
        }
        return $codewords;
    }

    /**
     * @param int[] $data
     * @return int[]
     */
    private function addEccAndInterleave(array $data): array
    {
        $v = $this->version;
        $numBlocks = self::NUM_BLOCKS[$v - 1];
        $eccLen = self::ECC_PER_BLOCK[$v - 1];
        $rawCodewords = intdiv(self::numRawDataModules($v), 8);
        $numShort = $numBlocks - $rawCodewords % $numBlocks;
        $shortLen = intdiv($rawCodewords, $numBlocks);

        $divisor = self::rsDivisor($eccLen);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $datLen = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $datLen);
            $k += $datLen;
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $numShort) { $dat[] = 0; } // placeholder so all blocks line up
            $blocks[] = array_merge($dat, $ecc);
        }

        $result = [];
        for ($i = 0; $i < count($blocks[0]); $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                    $result[] = $block[$i];
                }
            }
        }
        return $result;
    }

    // ---------------------------------------------------------- Reed-Solomon

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    /** @return int[] */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) { $result[$j] ^= $result[$j + 1]; }
            }
            $root = self::gfMul($root, 0x02);
        }
        return $result;
    }

    /**
     * @param int[] $data
     * @param int[] $divisor
     * @return int[]
     */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gfMul($coef, $factor);
            }
        }
        return $result;
    }

    // ----------------------------------------------------- drawing the symbol

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $n = $this->size;
        for ($i = 0; $i < $n; $i++) { // timing patterns
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($n - 4, 3);
        $this->drawFinder(3, $n - 4);

        $pos = $this->alignmentPositions();
        $num = count($pos);
        for ($i = 0; $i < $num; $i++) {
            for ($j = 0; $j < $num; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $num - 1) || ($i === $num - 1 && $j === 0)) {
                    continue; // would overlap a finder pattern
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->setFunction($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0); // reserve the area; redrawn with the real mask later
        $this->drawVersion();
    }

    private function drawFinder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                    $this->setFunction($x, $y, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    /** @return int[] */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $num = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $num * 3 + 5, $num * 4 - 4) * 2; // matches the standard's table (incl. v32 = 26)
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $num; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }
        return $result;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (0b00 << 3) | $mask; // error-correction level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) { $rem = ($rem << 1) ^ (($rem >> 9) * 0x537); }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) !== 0;
        $n = $this->size;

        for ($i = 0; $i <= 5; $i++) { $this->setFunction(8, $i, $bit($i)); }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) { $this->setFunction(14 - $i, 8, $bit($i)); }

        for ($i = 0; $i < 8; $i++) { $this->setFunction($n - 1 - $i, 8, $bit($i)); }
        for ($i = 8; $i < 15; $i++) { $this->setFunction(8, $n - 15 + $i, $bit($i)); }
        $this->setFunction(8, $n - 8, true); // the always-dark module
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) { $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25); }
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $b = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $c = intdiv($i, 3);
            $this->setFunction($a, $c, $b);
            $this->setFunction($c, $a, $b);
        }
    }

    /** @param int[] $data */
    private function drawCodewords(array $data): void
    {
        $i = 0;
        $totalBits = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) { $right = 5; }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (empty($this->isFunction[$y][$x]) && $i < $totalBits) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if (!empty($this->isFunction[$y][$x])) { continue; }
                switch ($mask) {
                    case 0: $invert = ($x + $y) % 2 === 0; break;
                    case 1: $invert = $y % 2 === 0; break;
                    case 2: $invert = $x % 3 === 0; break;
                    case 3: $invert = ($x + $y) % 3 === 0; break;
                    case 4: $invert = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                    case 5: $invert = $x * $y % 2 + $x * $y % 3 === 0; break;
                    case 6: $invert = ($x * $y % 2 + $x * $y % 3) % 2 === 0; break;
                    default: $invert = (($x + $y) % 2 + $x * $y % 3) % 2 === 0; break;
                }
                if ($invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    // --------------------------------------------------------------- penalty

    private function penaltyScore(): int
    {
        $n = $this->size;
        $m = $this->modules;
        $score = 0;

        // N1: runs of 5+ same-colour modules; N3: finder-like 1:1:3:1:1 patterns
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $lines[] = $m[$i];
            $lines[] = array_column($m, $i);
        }
        foreach ($lines as $line) {
            $run = 1;
            for ($k = 1; $k < $n; $k++) {
                if ($line[$k] === $line[$k - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) { $score += 3 + ($run - 5); }
                    $run = 1;
                }
            }
            if ($run >= 5) { $score += 3 + ($run - 5); }

            $str = '';
            foreach ($line as $d) { $str .= $d ? '1' : '0'; }
            $score += 40 * (substr_count($str, '10111010000') + substr_count($str, '00001011101'));
        }

        // N2: 2x2 blocks of one colour
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // N4: balance of dark and light modules
        $dark = 0;
        foreach ($m as $row) { $dark += count(array_filter($row)); }
        $total = $n * $n;
        $k = (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        return $score + max(0, $k) * 10;
    }
}
