<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A regular investment (Sparplan) into one security.
 *
 * @property int $id
 * @property int $portfolio_id
 * @property string $symbol
 * @property ?string $name
 * @property float $amount
 * @property string $frequency
 * @property int $day_of_month
 * @property Carbon $next_date
 * @property ?Carbon $last_executed_on
 * @property bool $active
 */
#[Fillable(['portfolio_id', 'symbol', 'name', 'amount', 'frequency', 'day_of_month', 'next_date', 'last_executed_on', 'active'])]
class SavingsPlan extends Model
{
    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'next_date' => 'date',
            'last_executed_on' => 'date',
            'active' => 'boolean',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }
}
