<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An own note or hint on a share, e.g. from the annual report or an idea for the next purchase.
 *
 * @property int $id
 * @property int $user_id
 * @property string $symbol
 * @property string $body
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'symbol', 'body'])]
class SecurityNote extends Model
{
    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
        ];
    }
}
