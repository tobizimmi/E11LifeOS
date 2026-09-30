<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One content (trip, page, cost split) shared with one group.
 *
 * @property int $id
 * @property int $share_group_id
 * @property string $shareable_type
 * @property int $shareable_id
 * @property int $shared_by
 * @property-read ShareGroup $group
 * @property-read Model|null $shareable
 */
#[Fillable(['share_group_id', 'shareable_type', 'shareable_id', 'shared_by'])]
class Share extends Model
{
    /**
     * @return BelongsTo<ShareGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ShareGroup::class, 'share_group_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sharer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }
}
