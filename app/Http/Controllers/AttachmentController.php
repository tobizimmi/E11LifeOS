<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a page's image or file to members who may see that page.
 */
class AttachmentController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->isVisibleTo($request->user()) && Storage::disk(Attachment::DISK)->exists($attachment->path), 404);

        $disposition = $attachment->isImage() ? 'inline' : 'attachment';

        return Storage::disk(Attachment::DISK)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ], $disposition);
    }
}
