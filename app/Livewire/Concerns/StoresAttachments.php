<?php

namespace App\Livewire\Concerns;

use App\Models\Attachment;
use App\Models\Item;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Upload handling for the page editor ("📎 Image or file", drag & drop, paste).
 * The component needs WithFileUploads and a public $attachmentUpload property.
 */
trait StoresAttachments
{
    /**
     * Stores the uploaded file for the given page/entry (null = not saved yet)
     * and returns what the editor inserts.
     *
     * @return array{markdown: string, url: string, name: string, isImage: bool}
     */
    protected function storeAttachmentFor(?Item $item): array
    {
        $this->validate([
            'attachmentUpload' => ['required', 'file', 'max:'.Attachment::MAX_KILOBYTES, 'mimes:'.implode(',', Attachment::EXTENSIONS)],
        ]);

        $upload = $this->attachmentUpload;
        $extension = strtolower($upload->guessExtension() ?: $upload->getClientOriginalExtension());

        $attachment = Attachment::create([
            'household_id' => $this->householdId,
            'item_id' => $item?->id,
            'created_by' => Auth::id(),
            'path' => $upload->storeAs('attachments/'.$this->householdId, Str::uuid().'.'.$extension, Attachment::DISK),
            'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
            'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
            'size' => $upload->getSize(),
        ]);

        $this->reset('attachmentUpload');

        return ['markdown' => $attachment->markdown(), 'url' => $attachment->url(), 'name' => $attachment->original_name, 'isImage' => $attachment->isImage()];
    }
}
