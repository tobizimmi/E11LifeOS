<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A savings tip the member has done or hidden (so it no longer shows up as open).
 *
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property string $status
 */
#[Fillable(['user_id', 'key', 'status'])]
class SavingTipState extends Model
{
    public const DONE = 'done';

    public const HIDDEN = 'hidden';
}
