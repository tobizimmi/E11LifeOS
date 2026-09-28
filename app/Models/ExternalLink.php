<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A document that lives outside the app, attached to an entry, a property or an invoice:
 * a paperless-ngx document (only its id), a link to an e-mail, or a copy of the mail as a file.
 */
#[Fillable(['household_id', 'created_by', 'linkable_type', 'linkable_id', 'kind', 'paperless_instance_id', 'paperless_document_id', 'paperless_task_id', 'url', 'title', 'path', 'mime_type'])]
class ExternalLink extends Model
{
    public const PAPERLESS = 'paperless';

    public const MAIL = 'mail';

    public const FILE = 'file';

    public const DISK = 'local';

    protected static function booted(): void
    {
        static::deleted(function (ExternalLink $link) {
            if ($link->path) {
                Storage::disk(self::DISK)->delete($link->path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'paperless_document_id' => 'integer',
        ];
    }

    public function paperlessInstance(): BelongsTo
    {
        return $this->belongsTo(PaperlessInstance::class);
    }

    public function isPending(): bool
    {
        return $this->kind === self::PAPERLESS && $this->paperless_document_id === null;
    }
}
