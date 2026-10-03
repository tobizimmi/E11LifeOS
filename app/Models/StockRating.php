<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Own points of a member for a share in the points system (factor => 0–3), e.g. for the business
 * model or reputation, or to overrule a point given from the figures.
 *
 * @property int $id
 * @property int $user_id
 * @property string $symbol
 * @property array<string, int> $points
 */
#[Fillable(['user_id', 'symbol', 'points'])]
class StockRating extends Model
{
    protected function casts(): array
    {
        return [
            'points' => 'array',
        ];
    }
}
