<?php

namespace App\Support;

use App\Models\Recipe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Stores recipe photos downscaled (max. 1600 px) as JPEG on the private disk.
 */
class RecipeImages
{
    public const MAX_SIZE = 1600;

    /**
     * @param  UploadedFile|string  $image  An upload or raw image bytes (e.g. from the import).
     * @return string Path on Recipe::IMAGE_DISK.
     */
    public static function store(UploadedFile|string $image, int $householdId): string
    {
        $manager = new ImageManager(new Driver);
        $decoded = $image instanceof UploadedFile ? $manager->read($image->getRealPath()) : $manager->read($image);

        $path = sprintf('recipes/%d/%s.jpg', $householdId, Str::uuid());
        Storage::disk(Recipe::IMAGE_DISK)->put(
            $path,
            (string) $decoded->orient()->scaleDown(self::MAX_SIZE, self::MAX_SIZE)->toJpeg(82),
        );

        return $path;
    }

    /**
     * Photo or screenshot prepared for the Claude API: JPEG, long edge at most 1568 px.
     *
     * @return array{data: string, mediaType: string}
     */
    public static function forAi(UploadedFile $image): array
    {
        $decoded = (new ImageManager(new Driver))->read($image->getRealPath());

        return ['data' => (string) $decoded->orient()->scaleDown(1568, 1568)->toJpeg(85), 'mediaType' => 'image/jpeg'];
    }

    /**
     * Copies a stored photo (e.g. when duplicating a recipe); null if it is missing.
     */
    public static function copy(?string $path, int $householdId): ?string
    {
        $disk = Storage::disk(Recipe::IMAGE_DISK);

        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        $copy = sprintf('recipes/%d/%s.jpg', $householdId, Str::uuid());
        $disk->copy($path, $copy);

        return $copy;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(Recipe::IMAGE_DISK)->delete($path);
        }
    }
}
