<?php

namespace App\Support\Paperless;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;

/**
 * Builds a PDF from scanned page images (one image per A4 page). The images come from the browser
 * as data URIs (JPEG/PNG), already cropped and contrast-enhanced client-side.
 */
class ScanPdf
{
    public const MAX_PAGES = 30;

    /**
     * @param  list<string>  $dataUris  data:image/...;base64,... – one per page, in order
     *
     * @throws \InvalidArgumentException when no valid image page is given
     */
    public static function fromImages(array $dataUris): string
    {
        $dir = storage_path('app/scan-tmp/'.Str::uuid()->toString());
        @mkdir($dir, 0700, true);

        try {
            $pages = [];

            foreach (array_slice($dataUris, 0, self::MAX_PAGES) as $index => $dataUri) {
                $binary = self::decode($dataUri);

                if ($binary === null || @getimagesizefromstring($binary) === false) {
                    continue;
                }

                $file = $dir.'/page-'.$index.'.img';
                file_put_contents($file, $binary);
                $pages[] = $file;
            }

            if ($pages === []) {
                throw new \InvalidArgumentException('No valid scan pages were provided.');
            }

            $dompdf = new Dompdf((new Options)->setChroot($dir)->setIsRemoteEnabled(false));
            $dompdf->loadHtml(self::html($pages), 'UTF-8');
            $dompdf->setPaper('A4');
            $dompdf->render();

            return (string) $dompdf->output();
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /**
     * @param  list<string>  $pages  absolute image paths
     */
    private static function html(array $pages): string
    {
        $last = count($pages) - 1;
        $imgs = '';

        foreach ($pages as $index => $path) {
            $break = $index < $last ? ' style="page-break-after: always;"' : '';
            $imgs .= '<div class="page"'.$break.'><img src="file://'.htmlspecialchars($path, ENT_QUOTES).'"></div>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'@page { margin: 0; } body { margin: 0; }'
            .'.page { width: 210mm; height: 297mm; text-align: center; }'
            .'img { max-width: 200mm; max-height: 287mm; margin-top: 5mm; }'
            .'</style></head><body>'.$imgs.'</body></html>';
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
