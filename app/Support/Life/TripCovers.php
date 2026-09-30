<?php

namespace App\Support\Life;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Cover photos of trips: downscaled (max. 2000 px wide) JPEGs on the private disk.
 */
class TripCovers
{
    public const DISK = 'local';

    public const MAX_WIDTH = 2000;

    public static function store(UploadedFile $image, int $householdId): string
    {
        $decoded = (new ImageManager(new Driver))->read($image->getRealPath());
        $path = sprintf('trips/%d/%s.jpg', $householdId, Str::uuid());

        Storage::disk(self::DISK)->put($path, (string) $decoded->orient()->scaleDown(self::MAX_WIDTH, self::MAX_WIDTH)->toJpeg(80));

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
