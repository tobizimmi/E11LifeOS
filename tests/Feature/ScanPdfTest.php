<?php

namespace Tests\Feature;

use App\Support\Paperless\ScanPdf;
use Tests\TestCase;

class ScanPdfTest extends TestCase
{
    private function image(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 60, 60));
        ob_start();
        imagejpeg($image);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    public function test_every_page_has_the_size_of_its_image_without_margin(): void
    {
        $pdf = ScanPdf::fromImages([$this->image(600, 1800), $this->image(1240, 1754), 'kein bild']);

        $this->assertStringStartsWith('%PDF-', $pdf);
        preg_match_all('/\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/', $pdf, $boxes, PREG_SET_ORDER);
        $this->assertCount(2, $boxes);
        // 600x1800 px at 150 dpi = 288 x 864 pt: a narrow receipt strip, not an A4 sheet.
        $this->assertEqualsWithDelta(288.0, (float) $boxes[0][1], 0.5);
        $this->assertEqualsWithDelta(864.0, (float) $boxes[0][2], 0.5);
        $this->assertEqualsWithDelta(595.0, (float) $boxes[1][1], 1.0);
    }

    public function test_without_a_valid_image_nothing_is_built(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScanPdf::fromImages(['data:text/plain;base64,AAAA']);
    }
}
