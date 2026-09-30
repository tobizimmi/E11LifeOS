<?php

namespace App\Livewire\Concerns;

use App\Models\Attachment;
use App\Models\Item;
use Illuminate\Support\Facades\Auth;

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
            'attachmentUpload' => ['required', ...Attachment::rules()],
        ]);

        $attachment = Attachment::storeUpload($this->attachmentUpload, (int) $this->householdId, $item?->id, (int) Auth::id());

        $this->reset('attachmentUpload');

        return ['markdown' => $attachment->markdown(), 'url' => $attachment->url(), 'name' => $attachment->original_name, 'isImage' => $attachment->isImage()];
    }
}
