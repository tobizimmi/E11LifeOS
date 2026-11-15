<?php

namespace App\Support\Paperless;

use GdImage;

/**
 * Straightens and cleans up a photographed page: the four corners the member set are warped to a
 * rectangle (perspective correction), then the page is levelled (paper becomes white) and sharpened.
 * Works with GD only, so the server needs no extra software.
 */
class ScanImage
{
    public const FILTERS = ['original', 'color', 'gray', 'bw'];

    /**
     * @param  list<float>|null  $corners  normalised 0..1: top-left, top-right, bottom-right, bottom-left (x,y each)
     * @return string JPEG bytes
     *
     * @throws \InvalidArgumentException when the image cannot be read
     */
    public static function process(string $binary, ?array $corners, string $filter = 'color', int $rotate = 0, int $maxSide = 1800): string
    {
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '384M');
        }

        $source = @imagecreatefromstring($binary);

        if (! $source instanceof GdImage) {
            throw new \InvalidArgumentException('The image could not be read.');
        }

        $source = self::upright($source, $binary);
        $source = self::limit($source, 2600);
        $width = imagesx($source);
        $height = imagesy($source);

        $quad = self::quad($corners, $width, $height);
        $page = self::warp($source, $quad, $maxSide);

        if (in_array($rotate, [90, 180, 270], true)) {
            $rotated = imagerotate($page, -$rotate, 0);

            if ($rotated instanceof GdImage) {
                $page = $rotated;
            }
        }

        if ($filter !== 'original' && in_array($filter, self::FILTERS, true)) {
            self::enhance($page, $filter);
        }

        ob_start();
        imagejpeg($page, null, 86);
        $jpeg = (string) ob_get_clean();

        return $jpeg;
    }

    /**
     * Applies the EXIF orientation, which GD ignores.
     */
    private static function upright(GdImage $image, string $binary): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $binary);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    private static function limit(GdImage $image, int $side): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $side) {
            return $image;
        }

        $scaled = imagescale($image, $width >= $height ? $side : (int) round($width * $side / $height), $height > $width ? $side : (int) round($height * $side / $width));

        return $scaled instanceof GdImage ? $scaled : $image;
    }

    /**
     * Corner points in pixels; falls back to the full image when they are missing or implausible.
     *
     * @param  list<float>|null  $corners
     * @return list<array{0: float, 1: float}>
     */
    private static function quad(?array $corners, int $width, int $height): array
    {
        $full = [[0.0, 0.0], [$width - 1.0, 0.0], [$width - 1.0, $height - 1.0], [0.0, $height - 1.0]];

        if ($corners === null || count($corners) !== 8) {
            return $full;
        }

        $points = [];

        foreach ([0, 2, 4, 6] as $i) {
            $x = max(0.0, min(1.0, (float) $corners[$i]));
            $y = max(0.0, min(1.0, (float) $corners[$i + 1]));
            $points[] = [$x * ($width - 1), $y * ($height - 1)];
        }

        // A degenerate selection (tiny or twisted) is ignored.
        $area = 0.0;

        for ($i = 0; $i < 4; $i++) {
            $next = ($i + 1) % 4;
            $area += $points[$i][0] * $points[$next][1] - $points[$next][0] * $points[$i][1];
        }

        return abs($area) / 2 < 0.02 * $width * $height ? $full : $points;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $q
     */
    private static function warp(GdImage $source, array $q, int $maxSide): GdImage
    {
        $dist = fn (array $a, array $b) => hypot($a[0] - $b[0], $a[1] - $b[1]);
        $outW = max($dist($q[0], $q[1]), $dist($q[3], $q[2]));
        $outH = max($dist($q[0], $q[3]), $dist($q[1], $q[2]));
        $scale = min(1.0, $maxSide / max($outW, $outH, 1.0));
        $outW = max(16, (int) round($outW * $scale));
        $outH = max(16, (int) round($outH * $scale));

        [[$x0, $y0], [$x1, $y1], [$x2, $y2], [$x3, $y3]] = $q;
        $dx1 = $x1 - $x2;
        $dx2 = $x3 - $x2;
        $dx3 = $x0 - $x1 + $x2 - $x3;
        $dy1 = $y1 - $y2;
        $dy2 = $y3 - $y2;
        $dy3 = $y0 - $y1 + $y2 - $y3;

        if (abs($dx3) < 1e-9 && abs($dy3) < 1e-9) {
            [$g, $h] = [0.0, 0.0];
            [$a, $b, $c, $d, $e, $f] = [$x1 - $x0, $x3 - $x0, $x0, $y1 - $y0, $y3 - $y0, $y0];
        } else {
            $den = $dx1 * $dy2 - $dx2 * $dy1;
            $g = abs($den) < 1e-9 ? 0.0 : ($dx3 * $dy2 - $dx2 * $dy3) / $den;
            $h = abs($den) < 1e-9 ? 0.0 : ($dx1 * $dy3 - $dx3 * $dy1) / $den;
            [$a, $b, $c] = [$x1 - $x0 + $g * $x1, $x3 - $x0 + $h * $x3, $x0];
            [$d, $e, $f] = [$y1 - $y0 + $g * $y1, $y3 - $y0 + $h * $y3, $y0];
        }

        $target = imagecreatetruecolor($outW, $outH);
        $maxX = imagesx($source) - 2;
        $maxY = imagesy($source) - 2;

        for ($j = 0; $j < $outH; $j++) {
            $v = $j / max(1, $outH - 1);

            for ($i = 0; $i < $outW; $i++) {
                $u = $i / max(1, $outW - 1);
                $w = $g * $u + $h * $v + 1.0;
                $sx = ($a * $u + $b * $v + $c) / $w;
                $sy = ($d * $u + $e * $v + $f) / $w;
                $sx = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $ix = (int) $sx;
                $iy = (int) $sy;
                $fx = $sx - $ix;
                $fy = $sy - $iy;
                $p00 = imagecolorat($source, $ix, $iy);
                $p10 = imagecolorat($source, $ix + 1, $iy);
                $p01 = imagecolorat($source, $ix, $iy + 1);
                $p11 = imagecolorat($source, $ix + 1, $iy + 1);
                $w00 = (1 - $fx) * (1 - $fy);
                $w10 = $fx * (1 - $fy);
                $w01 = (1 - $fx) * $fy;
                $w11 = $fx * $fy;
                $r = (int) ((($p00 >> 16) & 255) * $w00 + (($p10 >> 16) & 255) * $w10 + (($p01 >> 16) & 255) * $w01 + (($p11 >> 16) & 255) * $w11);
                $gr = (int) ((($p00 >> 8) & 255) * $w00 + (($p10 >> 8) & 255) * $w10 + (($p01 >> 8) & 255) * $w01 + (($p11 >> 8) & 255) * $w11);
                $bl = (int) (($p00 & 255) * $w00 + ($p10 & 255) * $w10 + ($p01 & 255) * $w01 + ($p11 & 255) * $w11);
                imagesetpixel($target, $i, $j, ($r << 16) | ($gr << 8) | $bl);
            }
        }

        return $target;
    }

    /**
     * Levels the page (paper becomes white), sharpens and, for grey and black-and-white, drops the colour.
     */
    private static function enhance(GdImage $image, string $filter): void
    {
        if ($filter !== 'color') {
            imagefilter($image, IMG_FILTER_GRAYSCALE);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $histogram = array_fill(0, 256, 0);
        $samples = 0;

        for ($y = 0; $y < $height; $y += 3) {
            for ($x = 0; $x < $width; $x += 3) {
                $pixel = imagecolorat($image, $x, $y);
                $luma = (int) ((($pixel >> 16) & 255) * 0.299 + (($pixel >> 8) & 255) * 0.587 + ($pixel & 255) * 0.114);
                $histogram[$luma]++;
                $samples++;
            }
        }

        $low = self::percentile($histogram, $samples, 0.01);
        $high = self::percentile($histogram, $samples, 0.92);

        if ($high - $low >= 40) {
            $lut = [];

            for ($v = 0; $v < 256; $v++) {
                $lut[$v] = (int) max(0, min(255, ($v - $low) * 255 / ($high - $low)));
            }

            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $pixel = imagecolorat($image, $x, $y);
                    imagesetpixel($image, $x, $y, ($lut[($pixel >> 16) & 255] << 16) | ($lut[($pixel >> 8) & 255] << 8) | $lut[$pixel & 255]);
                }
            }
        }

        imageconvolution($image, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);

        if ($filter === 'bw') {
            imagefilter($image, IMG_FILTER_CONTRAST, -60);
        }
    }

    /**
     * @param  array<int, int>  $histogram
     */
    private static function percentile(array $histogram, int $samples, float $share): int
    {
        $target = $samples * $share;
        $sum = 0;

        foreach ($histogram as $value => $count) {
            $sum += $count;

            if ($sum >= $target) {
                return $value;
            }
        }

        return 255;
    }
}
