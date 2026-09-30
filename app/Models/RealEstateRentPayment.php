<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rent received from a tenant for a month – matched to a bank booking or entered by hand.
 */
#[Fillable(['tenant_id', 'month', 'amount', 'paid_on', 'transaction_id'])]
class RealEstateRentPayment extends Model
{
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'paid_on' => 'date',
            'amount' => 'float',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(RealEstateTenant::class, 'tenant_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
