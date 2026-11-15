<?php

namespace App\Support;

use App\Http\Controllers\BookmarkImageController;
use App\Models\Bookmark;
use App\Support\Security\UrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Downloads a chosen preview image for a bookmark (SSRF-guarded) and stores it downscaled as JPEG on
 * the private disk, served through {@see BookmarkImageController}.
 */
class BookmarkImages
{
    public const MAX_SIZE = 1000;

    /**
     * Downloads and stores the image at the URL; returns the stored path, or null on any problem
     * (the caller then falls back to the remote URL or no image).
     */
    public static function download(string $url, int $householdId): ?string
    {
        if (! UrlGuard::hasValidShape($url) || ! UrlGuard::isPublicHost((string) parse_url($url, PHP_URL_HOST))) {
            return null;
        }

        try {
            $response = Http::timeout(15)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; E11LifeOS link preview)'])->get($url);

            if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                return null;
            }

            $decoded = (new ImageManager(new Driver))->read($response->body());
            $path = sprintf('bookmarks/%d/%s.jpg', $householdId, Str::uuid());
            Storage::disk(Bookmark::IMAGE_DISK)->put($path, (string) $decoded->orient()->scaleDown(self::MAX_SIZE, self::MAX_SIZE)->toJpeg(80));

            return $path;
        } catch (Throwable) {
            return null;
        }
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(Bookmark::IMAGE_DISK)->delete($path);
        }
    }
}
