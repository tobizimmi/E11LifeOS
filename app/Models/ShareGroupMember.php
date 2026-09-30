<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $share_group_id
 * @property int $user_id
 * @property string $role owner|member
 * @property-read User $user
 */
#[Fillable(['share_group_id', 'user_id', 'role'])]
class ShareGroupMember extends Model
{
    /**
     * @return BelongsTo<ShareGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ShareGroup::class, 'share_group_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
