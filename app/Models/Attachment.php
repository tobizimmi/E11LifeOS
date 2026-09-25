<?php

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['household_id', 'item_id', 'created_by', 'path', 'original_name', 'mime_type', 'size'])]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    public const DISK = 'local';

    /**
     * Upload limit in kilobytes (validation rule "max").
     */
    public const MAX_KILOBYTES = 20480;

    /**
     * Allowed extensions; no SVG/HTML, which a browser could run as a page.
     */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'zip'];

    /**
     * Matches attachment URLs in a page body; group 1 is the id.
     */
    public const URL_PATTERN = '#/attachments/(\d+)(?:/[^\s)"\']*)?#';

    protected static function booted(): void
    {
        static::deleted(fn (Attachment $attachment) => Storage::disk(self::DISK)->delete($attachment->path));
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function isImage(): bool
    {
        return in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    public function url(): string
    {
        return route('attachments.show', ['attachment' => $this->id, 'name' => Str::slug(pathinfo($this->original_name, PATHINFO_FILENAME)) ?: 'datei']);
    }

    /**
     * Markdown that embeds the file: an image, or a link for everything else.
     */
    public function markdown(): string
    {
        $label = str_replace(['[', ']'], '', $this->original_name);

        return $this->isImage() ? '!['.$label.']('.$this->url().')' : '[📎 '.$label.']('.$this->url().')';
    }

    /**
     * Members see a file like the page embedding it; a not yet saved upload only its uploader.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->currentHousehold()?->id !== $this->household_id) {
            return false;
        }

        return $this->item_id === null
            ? $this->created_by === $user->id
            : Item::query()->visibleTo($user)->whereKey($this->item_id)->exists();
    }

    /**
     * Assigns the user's unassigned uploads referenced in the page body to the page.
     */
    public static function attachReferencedTo(Item $page, User $user): void
    {
        preg_match_all(self::URL_PATTERN, $page->body(), $matches);

        if ($matches[1] === []) {
            return;
        }

        self::query()
            ->whereIn('id', array_map('intval', $matches[1]))
            ->where('household_id', $page->household_id)
            ->where('created_by', $user->id)
            ->whereNull('item_id')
            ->update(['item_id' => $page->id]);
    }
}
