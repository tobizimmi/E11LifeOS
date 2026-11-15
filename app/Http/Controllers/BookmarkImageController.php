<?php

namespace App\Http\Controllers;

use App\Models\Bookmark;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a bookmark's stored preview image only to members who may see the bookmark.
 */
class BookmarkImageController extends Controller
{
    public function __invoke(Request $request, Bookmark $bookmark): StreamedResponse
    {
        $household = $request->user()->currentHousehold();

        abort_unless(
            $household && Bookmark::query()->visibleTo($request->user(), $household->id)->whereKey($bookmark->id)->exists(),
            404,
        );

        abort_unless(is_string($bookmark->image_path) && $bookmark->image_path !== '' && Storage::disk(Bookmark::IMAGE_DISK)->exists($bookmark->image_path), 404);

        return Storage::disk(Bookmark::IMAGE_DISK)->response($bookmark->image_path, null, [
            'Cache-Control' => 'private, max-age=2592000',
        ]);
    }
}
