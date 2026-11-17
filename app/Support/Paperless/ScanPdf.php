<?php

namespace App\Support\Paperless;

/**
 * Builds a PDF from scanned page images. Every page has exactly the size of its image (no white
 * margin, so a receipt stays a narrow strip instead of sitting on an A4 sheet). The images come as
 * data URIs (JPEG/PNG), already cropped and contrast-enhanced. Written directly (JPEG embedded as
 * DCTDecode), so it needs no PDF library.
 */
class ScanPdf
{
    public const MAX_PAGES = 30;

    /** Pixels per inch assumed for a photo (decides the physical page size). */
    private const DPI = 150;

    /** Longest page side in points (about 420 mm). */
    private const MAX_POINTS = 1190.0;

    /**
     * @param  list<string>  $dataUris  data:image/...;base64,... – one per page, in order
     *
     * @throws \InvalidArgumentException when no valid image page is given
     */
    public static function fromImages(array $dataUris): string
    {
        $images = [];

        foreach (array_slice($dataUris, 0, self::MAX_PAGES) as $dataUri) {
            $binary = self::decode($dataUri);
            $jpeg = $binary === null ? null : self::asJpeg($binary);

            if ($jpeg !== null) {
                $images[] = $jpeg;
            }
        }

        if ($images === []) {
            throw new \InvalidArgumentException('No valid scan pages were provided.');
        }

        return self::build($images);
    }

    /**
     * @param  list<array{data: string, width: int, height: int, gray: bool}>  $images
     */
    private static function build(array $images): string
    {
        $objects = [];
        $pageIds = [];
        // Object numbers: 1 catalog, 2 pages, then per page: page, content, image.
        foreach ($images as $index => $image) {
            $base = 3 + $index * 3;
            $pageIds[] = $base;
            $scale = min(1.0, self::MAX_POINTS / max($image['width'], $image['height'] * 1.0) * self::DPI / 72);
            $width = max(20.0, $image['width'] * 72 / self::DPI * $scale);
            $height = max(20.0, $image['height'] * 72 / self::DPI * $scale);
            $content = sprintf('q %.2f 0 0 %.2f 0 0 cm /Im0 Do Q', $width, $height);

            $objects[$base] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Contents %d 0 R /Resources << /XObject << /Im0 %d 0 R >> >> >>', $width, $height, $base + 1, $base + 2);
            $objects[$base + 1] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content);
            $objects[$base + 2] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream", $image['width'], $image['height'], $image['gray'] ? 'DeviceGray' : 'DeviceRGB', strlen($image['data']), $image['data']);
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $id) => $id.' 0 R', $pageIds)).'] /Count '.count($pageIds).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF";
    }

    /**
     * The image as baseline JPEG bytes (PNG and others are re-encoded).
     *
     * @return array{data: string, width: int, height: int, gray: bool}|null
     */
    private static function asJpeg(string $binary): ?array
    {
        $info = @getimagesizefromstring($binary);

        if ($info === false) {
            return null;
        }

        // A plain 8-bit RGB/grey JPEG can be embedded as it is; anything else is re-encoded.
        if ($info[2] === IMAGETYPE_JPEG && ($info['channels'] ?? 3) === 3 && ($info['bits'] ?? 8) === 8 && ! str_contains(substr($binary, 0, 2000), "\xFF\xC2")) {
            return ['data' => $binary, 'width' => $info[0], 'height' => $info[1], 'gray' => false];
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            return null;
        }

        ob_start();
        imagejpeg($image, null, 88);
        $data = (string) ob_get_clean();

        return $data === '' ? null : ['data' => $data, 'width' => imagesx($image), 'height' => imagesy($image), 'gray' => false];
    }

    private static function decode(string $dataUri): ?string
    {
        if (! preg_match('#^data:image/[a-z0-9.+-]+;base64,#i', $dataUri)) {
            return null;
        }

        $binary = base64_decode(substr($dataUri, strpos($dataUri, ',') + 1), true);

        return $binary === false ? null : $binary;
    }
}
