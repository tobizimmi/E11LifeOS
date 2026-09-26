<?php

namespace App\Models;

use App\Support\ActivityLog;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
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
        static::created(fn (Attachment $attachment) => ActivityLog::documentAdded($attachment));
    }

    /**
     * Stores an uploaded file for a household (and optionally an item right away).
     */
    public static function storeUpload(UploadedFile $upload, int $householdId, ?int $itemId, int $userId): self
    {
        $extension = strtolower($upload->guessExtension() ?: $upload->getClientOriginalExtension());

        return self::create([
            'household_id' => $householdId,
            'item_id' => $itemId,
            'created_by' => $userId,
            'path' => $upload->storeAs('attachments/'.$householdId, Str::uuid().'.'.$extension, self::DISK),
            'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
            'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
            'size' => $upload->getSize(),
        ]);
    }

    /**
     * Upload validation rules for one file.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['file', 'max:'.self::MAX_KILOBYTES, 'mimes:'.implode(',', self::EXTENSIONS)];
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function sizeLabel(): string
    {
        return $this->size >= 1048576 ? number_format($this->size / 1048576, 1, ',', '.').' MB' : max(1, (int) round($this->size / 1024)).' KB';
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
