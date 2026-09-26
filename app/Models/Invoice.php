<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An invoice of the small business. Drafts can be edited; issuing assigns the next gapless
 * number and freezes the content (GoBD). Issued invoices are only paid or cancelled.
 */
#[Fillable(['user_id', 'household_id', 'customer_id', 'number', 'status', 'issued_on', 'service_period', 'due_on', 'paid_on', 'recipient', 'subject', 'intro', 'closing', 'small_business', 'net_total', 'vat_total', 'gross_total'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'paid_on' => 'date',
            'small_business' => 'boolean',
            'net_total' => 'float',
            'vat_total' => 'float',
            'gross_total' => 'float',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isOverdue(): bool
    {
        return $this->status === self::OPEN && $this->due_on !== null && $this->due_on->lt(today());
    }

    /**
     * Status for display: draft, open, overdue, paid or cancelled.
     */
    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status;
    }

    /**
     * Recomputes the totals from the lines (no VAT for small businesses).
     */
    public function recalculate(): void
    {
        $net = 0.0;
        $vat = 0.0;

        foreach ($this->items()->get() as $item) {
            $net += $item->lineTotal();
            $vat += $this->small_business ? 0 : $item->lineTotal() * $item->vat_rate / 100;
        }

        $this->forceFill([
            'net_total' => round($net, 2),
            'vat_total' => round($vat, 2),
            'gross_total' => round($net, 2) + round($vat, 2),
        ])->save();
    }

    /**
     * VAT per rate, e.g. [19.0 => ['net' => 100.0, 'vat' => 19.0]].
     *
     * @return array<string, array{rate: float, net: float, vat: float}>
     */
    public function vatBreakdown(): array
    {
        if ($this->small_business) {
            return [];
        }

        return $this->items->groupBy(fn (InvoiceItem $item) => (string) $item->vat_rate)
            ->map(fn ($items, string $rate) => [
                'rate' => (float) $rate,
                'net' => round($items->sum(fn (InvoiceItem $item) => $item->lineTotal()), 2),
                'vat' => round($items->sum(fn (InvoiceItem $item) => $item->lineTotal()) * (float) $rate / 100, 2),
            ])->sortKeysDesc()->all();
    }

    /**
     * Issues a draft: assigns the next number of the year ("RE-2026-001") and the dates.
     */
    public function issue(BusinessProfile $profile, ?Carbon $issuedOn = null): void
    {
        $issuedOn ??= today();

        DB::transaction(function () use ($profile, $issuedOn) {
            $prefix = $profile->invoice_prefix.$issuedOn->year.'-';
            $last = self::where('user_id', $this->user_id)->where('number', 'like', $prefix.'%')->lockForUpdate()->pluck('number')
                ->map(fn (string $number) => (int) substr($number, strlen($prefix)))->max() ?? 0;

            $this->forceFill([
                'number' => $prefix.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT),
                'status' => self::OPEN,
                'issued_on' => $issuedOn,
                'due_on' => $this->due_on ?? $issuedOn->copy()->addDays($profile->payment_days),
            ])->save();
        });
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
