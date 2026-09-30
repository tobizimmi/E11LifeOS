<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Minimal QR code encoder (byte mode, error correction level M, versions 1–10) that renders
 * an SVG – enough for the authenticator setup link of two-factor authentication.
 * Follows ISO/IEC 18004 (structure after Project Nayuki's reference implementation).
 */
class QrCode
{
    /**
     * Per version: [error correction codewords per block, blocks of the first group, data
     * codewords per block of the first group, blocks of the second group] for level M.
     */
    private const BLOCKS = [
        1 => [10, 1, 16, 0], 2 => [16, 1, 28, 0], 3 => [26, 1, 44, 0], 4 => [18, 2, 32, 0], 5 => [24, 2, 43, 0],
        6 => [16, 4, 27, 0], 7 => [18, 4, 31, 0], 8 => [22, 2, 38, 2], 9 => [22, 3, 36, 2], 10 => [26, 4, 43, 1],
    ];

    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @var array<int, array<int, bool>> */
    private array $modules = [];

    /** @var array<int, array<int, bool>> */
    private array $isFunction = [];

    private int $size;

    private function __construct(private int $version) {}

    /**
     * The modules (true = dark) of the QR code for the text.
     *
     * @return array<int, array<int, bool>>
     */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);

        foreach (self::BLOCKS as $version => [$ecc, $blocks1, $data1, $blocks2]) {
            $capacity = $blocks1 * $data1 + $blocks2 * ($data1 + 1);
            $countBits = $version < 10 ? 8 : 16;

            if (4 + $countBits + count($bytes) * 8 <= $capacity * 8) {
                return (new self($version))->encode($bytes, $capacity, $countBits);
            }
        }

        throw new InvalidArgumentException('Text too long for a QR code up to version 10.');
    }

    /**
     * The QR code as SVG (with a quiet zone of four modules).
     */
    public static function svg(string $text, int $pixels = 200): string
    {
        $matrix = self::matrix($text);
        $size = count($matrix) + 8;
        $path = '';

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M'.($x + 4).' '.($y + 4).'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$size.' '.$size.'" width="'.$pixels.'" height="'.$pixels.'" shape-rendering="crispEdges" role="img">'
            .'<rect width="100%" height="100%" fill="#fff"/><path d="'.$path.'" fill="#000"/></svg>';
    }

    /**
     * @param  list<int>  $bytes
     * @return array<int, array<int, bool>>
     */
    private function encode(array $bytes, int $capacity, int $countBits): array
    {
        $this->size = 17 + 4 * $this->version;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = $this->modules;

        // Bit stream: mode (byte), length, data, terminator, padding.
        $bits = '0100'.str_pad(decbin(count($bytes)), $countBits, '0', STR_PAD_LEFT);

        foreach ($bytes as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $data = array_map('bindec', str_split($bits, 8));

        for ($pad = 0xEC; count($data) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $data[] = $pad;
        }

        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addErrorCorrection($data));

        $best = null;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();

            if ($penalty < $bestPenalty) {
                [$best, $bestPenalty] = [$mask, $penalty];
            }

            $this->applyMask($mask);
        }

        $this->applyMask($best);
        $this->drawFormatBits($best);

        return $this->modules;
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }

        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    $distance = max(abs($dx), abs($dy));

                    if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                        $this->set($x, $y, $distance !== 2 && $distance !== 4);
                    }
                }
            }
        }

        $positions = self::ALIGNMENT[$this->version];
        $last = count($positions) - 1;

        foreach ($positions as $i => $cx) {
            foreach ($positions as $j => $cy) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        $this->drawFormatBits(0);

        if ($this->version >= 7) {
            $remainder = $this->version;

            for ($i = 0; $i < 12; $i++) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }

            $versionBits = $this->version << 12 | $remainder;

            for ($i = 0; $i < 18; $i++) {
                $dark = (($versionBits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $dark);
                $this->set($b, $a, $dark);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = $mask; // Level M = 00.
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        $bits = ($data << 10 | $remainder) ^ 0x5412;
        $bit = fn (int $i) => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }

        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));

        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->set($this->size - 1 - $i, 8, $bit($i));
        }

        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $this->size - 15 + $i, $bit($i));
        }

        $this->set(8, $this->size - 8, true);
    }

    /**
     * Splits the data into blocks, adds Reed-Solomon codewords and interleaves them.
     *
     * @param  list<int>  $data
     * @return list<int>
     */
    private function addErrorCorrection(array $data): array
    {
        [$eccLength, $blocks1, $data1, $blocks2] = self::BLOCKS[$this->version];
        $divisor = $this->reedSolomonDivisor($eccLength);
        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;

        for ($block = 0; $block < $blocks1 + $blocks2; $block++) {
            $length = $block < $blocks1 ? $data1 : $data1 + 1;
            $chunk = array_slice($data, $offset, $length);
            $offset += $length;
            $dataBlocks[] = $chunk;
            $eccBlocks[] = $this->reedSolomonRemainder($chunk, $divisor);
        }

        $result = [];

        for ($i = 0; $i <= $data1; $i++) {
            foreach ($dataBlocks as $chunk) {
                if (isset($chunk[$i])) {
                    $result[] = $chunk[$i];
                }
            }
        }

        for ($i = 0; $i < $eccLength; $i++) {
            foreach ($eccBlocks as $chunk) {
                $result[] = $chunk[$i];
            }
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    private function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = $this->multiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = $this->multiply($root, 0x02);
        }

        return $result;
    }

    /**
     * @param  list<int>  $data
     * @param  list<int>  $divisor
     * @return list<int>
     */
    private function reedSolomonRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;

            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= $this->multiply($coefficient, $factor);
            }
        }

        return $result;
    }

    private function multiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    /**
     * @param  list<int>  $codewords
     */
    private function drawCodewords(array $codewords): void
    {
        $i = 0;
        $total = count($codewords) * 8;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vertical : $vertical;

                    if (! $this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
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
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };

                if ($invert && ! $this->isFunction[$y][$x]) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    /**
     * Simplified penalty (runs, 2×2 blocks, dark balance) to pick a readable mask.
     */
    private function penalty(): int
    {
        $penalty = 0;
        $dark = 0;

        for ($y = 0; $y < $this->size; $y++) {
            $runRow = 1;
            $runColumn = 1;

            for ($x = 0; $x < $this->size; $x++) {
                $dark += $this->modules[$y][$x] ? 1 : 0;

                if ($x > 0) {
                    $runRow = $this->modules[$y][$x] === $this->modules[$y][$x - 1] ? $runRow + 1 : 1;
                    $runColumn = $this->modules[$x][$y] === $this->modules[$x - 1][$y] ? $runColumn + 1 : 1;
                    $penalty += ($runRow === 5 ? 3 : ($runRow > 5 ? 1 : 0)) + ($runColumn === 5 ? 3 : ($runColumn > 5 ? 1 : 0));
                }

                if ($x > 0 && $y > 0) {
                    $color = $this->modules[$y][$x];

                    if ($color === $this->modules[$y][$x - 1] && $color === $this->modules[$y - 1][$x] && $color === $this->modules[$y - 1][$x - 1]) {
                        $penalty += 3;
                    }
                }
            }
        }

        return $penalty + intdiv(abs($dark * 20 - $this->size * $this->size * 10), $this->size * $this->size) * 10;
    }
}
