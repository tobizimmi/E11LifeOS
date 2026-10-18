<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scanned or uploaded document that is waiting to be sent to paperless because the instance was
 * unreachable when it was created. The PDF is stored on the local disk until the upload succeeds.
 */
#[Fillable(['household_id', 'user_id', 'paperless_instance_id', 'title', 'filename', 'path', 'tag', 'attempts', 'last_error', 'last_tried_at'])]
class PaperlessPendingUpload extends Model
{
    protected function casts(): array
    {
        return [
            'last_tried_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PaperlessInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(PaperlessInstance::class, 'paperless_instance_id');
    }
}
