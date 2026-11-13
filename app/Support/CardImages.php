<?php

namespace App\Support;

use App\Http\Controllers\Banking\LoyaltyCardImageController;
use App\Models\LoyaltyCard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Stores loyalty-card photos downscaled as JPEG on the private disk (served via
 * {@see LoyaltyCardImageController} with an access check).
 */
class CardImages
{
    public const MAX_SIZE = 1200;

    public static function store(UploadedFile $image, int $householdId): string
    {
        $decoded = (new ImageManager(new Driver))->read($image->getRealPath());

        $path = sprintf('loyalty-cards/%d/%s.jpg', $householdId, Str::uuid());
        Storage::disk(LoyaltyCard::IMAGE_DISK)->put(
            $path,
            (string) $decoded->orient()->scaleDown(self::MAX_SIZE, self::MAX_SIZE)->toJpeg(82),
        );

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(LoyaltyCard::IMAGE_DISK)->delete($path);
        }
    }
}
