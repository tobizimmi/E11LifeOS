<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gift idea for a person: noted, bought or given.
 */
#[Fillable(['contact_id', 'created_by', 'title', 'url', 'price', 'status', 'occasion', 'given_on'])]
class GiftIdea extends Model
{
    public const STATUSES = ['idea', 'bought', 'given'];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'given_on' => 'date',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
