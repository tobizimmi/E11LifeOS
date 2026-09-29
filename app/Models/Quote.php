<?php

namespace App\Models;

use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One offer within a quote comparison; its documents hang on it as external links ("quote").
 */
#[Fillable(['quote_comparison_id', 'vendor', 'amount', 'quoted_on', 'valid_until', 'delivery', 'rating', 'pros', 'cons', 'notes', 'url', 'position'])]
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'quoted_on' => 'date',
            'valid_until' => 'date',
            'rating' => 'integer',
        ];
    }

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(QuoteComparison::class, 'quote_comparison_id');
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->lt(today());
    }
}
