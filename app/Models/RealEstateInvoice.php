<?php

namespace App\Models;

use Database\Factories\RealEstateInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An invoice for a property (purchase costs, craftsmen, material …), optionally with the
 * receipt, its budget line and the matching bank booking.
 */
#[Fillable(['real_estate_id', 'budget_item_id', 'created_by', 'vendor', 'number', 'invoice_date', 'due_on', 'amount', 'labor_amount', 'paid_on', 'tax_treatment', 'transaction_id', 'receipt_path', 'receipt_name', 'receipt_mime', 'notes'])]
class RealEstateInvoice extends Model
{
    /** @use HasFactory<RealEstateInvoiceFactory> */
    use HasFactory;

    public const RECEIPT_DISK = 'local';

    /**
     * auto: derived from the budget line and the usage of the property.
     */
    public const TAX_TREATMENTS = ['auto', 'acquisition', 'maintenance', 'craftsman', 'energy', 'none'];

    protected static function booted(): void
    {
        static::deleted(function (RealEstateInvoice $invoice) {
            if ($invoice->receipt_path) {
                Storage::disk(self::RECEIPT_DISK)->delete($invoice->receipt_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_on' => 'date',
            'paid_on' => 'date',
            'amount' => 'float',
            'labor_amount' => 'float',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function taxTreatmentLabels(): array
    {
        return [
            'auto' => __('Automatic'),
            'acquisition' => __('Purchase / production costs (depreciation)'),
            'maintenance' => __('Maintenance (deductible for letting)'),
            'craftsman' => __('Craftsman services (§ 35a)'),
            'energy' => __('Energy renovation (§ 35c)'),
            'none' => __('Not tax-relevant'),
        ];
    }

    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class);
    }

    public function budgetItem(): BelongsTo
    {
        return $this->belongsTo(RealEstateBudgetItem::class, 'budget_item_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isPaid(): bool
    {
        return $this->paid_on !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_on !== null && $this->due_on->lt(today());
    }
}
