<?php

namespace App\Models;

use Database\Factories\InvoicePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received for an invoice (or refunded for a credit note, negative amount).
 */
#[Fillable(['invoice_id', 'paid_on', 'amount', 'method', 'note', 'transaction_id'])]
class InvoicePayment extends Model
{
    /** @use HasFactory<InvoicePaymentFactory> */
    use HasFactory;

    public const METHODS = ['transfer', 'cash', 'card', 'paypal', 'other'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'float',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
