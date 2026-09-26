<?php

namespace App\Models;

use Database\Factories\BusinessProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Master data of a person's small business (Kleingewerbe), printed on every invoice.
 */
#[Fillable(['user_id', 'household_id', 'company_name', 'owner_name', 'street', 'postal_code', 'city', 'email', 'phone', 'website', 'tax_number', 'vat_id', 'small_business', 'default_vat_rate', 'bank_name', 'iban', 'bic', 'invoice_prefix', 'payment_days', 'invoice_footer'])]
class BusinessProfile extends Model
{
    /** @use HasFactory<BusinessProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'small_business' => 'boolean',
            'default_vat_rate' => 'float',
            'payment_days' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The small sender line above the recipient address ("Name · Street · ZIP City").
     */
    public function senderLine(): string
    {
        return collect([$this->company_name, $this->street, trim($this->postal_code.' '.$this->city)])->filter()->implode(' · ');
    }

    /**
     * Whether everything an invoice legally needs (§ 14 UStG) is filled in.
     */
    public function isCompleteForInvoices(): bool
    {
        return filled($this->company_name) && filled($this->street) && filled($this->city) && (filled($this->tax_number) || filled($this->vat_id));
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
