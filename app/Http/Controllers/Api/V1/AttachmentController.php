<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Attachment;
use App\Models\Household;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mobile API for page attachments (images/files). Uploads are tied to a page the member may see;
 * the body embeds them as "/attachments/{id}", the same convention the web uses, so pages stay
 * compatible across web and app. Serving is token-authenticated (the app sends the Bearer header).
 */
class AttachmentController extends ApiController
{
    public function store(Request $request, Item $page): JsonResponse
    {
        abort_unless(
            Item::query()->forHousehold($this->household()->id)->visibleTo($this->user())->ofType(Item::TYPE_NOTE)->whereKey($page->id)->exists(),
            404,
        );

        $request->validate(['file' => ['required', ...Attachment::rules()]]);

        $attachment = Attachment::storeUpload($request->file('file'), $this->household()->id, $page->id, $this->user()->id);

        return $this->ok([
            'id' => $attachment->id,
            'is_image' => $attachment->isImage(),
            'name' => $attachment->original_name,
            'path' => '/attachments/'.$attachment->id,
        ], 201);
    }

    public function show(Attachment $attachment): StreamedResponse
    {
        abort_unless(
            $attachment->isVisibleTo($this->user()) && Storage::disk(Attachment::DISK)->exists($attachment->path),
            404,
        );

        return Storage::disk(Attachment::DISK)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ], $attachment->isImage() ? 'inline' : 'attachment');
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
