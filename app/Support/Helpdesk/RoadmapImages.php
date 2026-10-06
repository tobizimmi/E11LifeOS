<?php

namespace App\Support\Helpdesk;

use App\Models\RoadmapMilestone;
use App\Models\RoadmapMilestoneImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Stores roadmap milestone images downscaled (max. 1600 px) as JPEG on the private disk and keeps
 * the RoadmapMilestoneImage rows in sync.
 */
class RoadmapImages
{
    public const MAX_SIZE = 1600;

    public static function store(RoadmapMilestone $milestone, UploadedFile $image, ?string $caption = null): RoadmapMilestoneImage
    {
        $decoded = (new ImageManager(new Driver))->read($image->getRealPath());

        $path = sprintf('roadmap/%d/%s.jpg', $milestone->id, Str::uuid());
        Storage::disk(RoadmapMilestoneImage::DISK)->put(
            $path,
            (string) $decoded->orient()->scaleDown(self::MAX_SIZE, self::MAX_SIZE)->toJpeg(82),
        );

        return $milestone->images()->create([
            'path' => $path,
            'caption' => $caption !== null && trim($caption) !== '' ? trim($caption) : null,
            'position' => (int) $milestone->images()->max('position') + 1,
        ]);
    }

    public static function delete(RoadmapMilestoneImage $image): void
    {
        Storage::disk(RoadmapMilestoneImage::DISK)->delete($image->path);
        $image->delete();
    }
}
