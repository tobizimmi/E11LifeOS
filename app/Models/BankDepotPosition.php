<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A holding as reported by the bank at the last sync.
 */
#[Fillable(['bank_connection_id', 'account_number', 'isin', 'wkn', 'name', 'quantity', 'acquisition_price', 'price', 'value', 'currency', 'price_date'])]
class BankDepotPosition extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'acquisition_price' => 'float',
            'price' => 'float',
            'value' => 'float',
            'price_date' => 'date',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class, 'bank_connection_id');
    }
}
