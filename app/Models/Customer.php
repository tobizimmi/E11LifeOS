<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer of the small business.
 */
#[Fillable(['user_id', 'household_id', 'name', 'contact_person', 'street', 'postal_code', 'city', 'country', 'email', 'vat_id', 'notes'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * The address as printed in the invoice window.
     */
    public function addressBlock(): string
    {
        return collect([
            $this->name,
            $this->contact_person,
            $this->street,
            trim($this->postal_code.' '.$this->city),
            $this->country && ! in_array(mb_strtolower($this->country), ['deutschland', 'germany', 'de'], true) ? $this->country : null,
        ])->filter()->implode("\n");
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
