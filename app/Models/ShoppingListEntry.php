<?php

namespace App\Models;

use App\Observers\ShoppingListEntryObserver;
use Database\Factories\ShoppingListEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['household_id', 'label', 'quantity', 'checked', 'created_by'])]
#[ObservedBy(ShoppingListEntryObserver::class)]
class ShoppingListEntry extends Model
{
    /** @use HasFactory<ShoppingListEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'checked' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
