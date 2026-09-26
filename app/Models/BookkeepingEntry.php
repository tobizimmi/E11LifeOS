<?php

namespace App\Models;

use Database\Factories\BookkeepingEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business receipt: an expense (Betriebsausgabe) or income without an invoice from the app.
 * The amount is gross; vat_amount is the VAT it contains.
 */
#[Fillable(['user_id', 'household_id', 'kind', 'booked_on', 'description', 'counterparty', 'category', 'amount', 'vat_amount', 'receipt_path', 'receipt_name', 'receipt_mime'])]
class BookkeepingEntry extends Model
{
    /** @use HasFactory<BookkeepingEntryFactory> */
    use HasFactory;

    public const EXPENSE = 'expense';

    public const INCOME = 'income';

    public const RECEIPT_DISK = 'local';

    protected function casts(): array
    {
        return [
            'booked_on' => 'date',
            'amount' => 'float',
            'vat_amount' => 'float',
        ];
    }

    public function isExpense(): bool
    {
        return $this->kind === self::EXPENSE;
    }

    public function netAmount(): float
    {
        return round($this->amount - $this->vat_amount, 2);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
