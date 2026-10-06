<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RoadmapMilestoneImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a roadmap milestone image – only to admins.
 */
class RoadmapImageController extends Controller
{
    public function __invoke(Request $request, RoadmapMilestoneImage $image): StreamedResponse
    {
        abort_unless($request->user()?->can('admin'), 403);
        abort_unless(Storage::disk(RoadmapMilestoneImage::DISK)->exists($image->path), 404);

        return Storage::disk(RoadmapMilestoneImage::DISK)->response($image->path, null, [
            'Cache-Control' => 'private, max-age=2592000',
        ]);
    }
}
