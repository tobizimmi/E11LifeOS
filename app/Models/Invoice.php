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
 * A business document: invoice, quote or credit note (also cancellation invoices). Drafts can be
 * edited; issuing assigns the next gapless number of its number range and freezes the content
 * (GoBD). Invoices are then paid (also in parts) or cancelled; quotes accepted, declined or
 * turned into an invoice.
 */
#[Fillable(['user_id', 'household_id', 'type', 'customer_id', 'related_invoice_id', 'number', 'status', 'issued_on', 'service_period', 'due_on', 'valid_until', 'paid_on', 'recipient', 'subject', 'intro', 'closing', 'small_business', 'net_total', 'vat_total', 'gross_total', 'paid_total'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    public const INVOICE = 'invoice';

    public const QUOTE = 'quote';

    public const CREDIT_NOTE = 'credit_note';

    public const TYPES = [self::INVOICE, self::QUOTE, self::CREDIT_NOTE];

    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    /** Quote states. */
    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const INVOICED = 'invoiced';

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'valid_until' => 'date',
            'paid_on' => 'date',
            'small_business' => 'boolean',
            'net_total' => 'float',
            'vat_total' => 'float',
            'gross_total' => 'float',
            'paid_total' => 'float',
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

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('paid_on')->orderBy('id');
    }

    /**
     * The quote an invoice was made from, or the invoice a credit note corrects.
     */
    public function related(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_invoice_id');
    }

    /**
     * Documents referring to this one (invoice made from a quote, credit notes of an invoice).
     */
    public function derived(): HasMany
    {
        return $this->hasMany(self::class, 'related_invoice_id');
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isQuote(): bool
    {
        return $this->type === self::QUOTE;
    }

    public function isCreditNote(): bool
    {
        return $this->type === self::CREDIT_NOTE;
    }

    /**
     * Invoices and credit notes carry money; quotes do not.
     */
    public function isPayable(): bool
    {
        return ! $this->isQuote() && $this->status === self::OPEN;
    }

    public function outstanding(): float
    {
        return round($this->gross_total - $this->paid_total, 2);
    }

    public function isOverdue(): bool
    {
        return $this->type === self::INVOICE && $this->status === self::OPEN && $this->due_on !== null && $this->due_on->lt(today());
    }

    public function isExpired(): bool
    {
        return $this->isQuote() && $this->status === self::OPEN && $this->valid_until !== null && $this->valid_until->lt(today());
    }

    /**
     * Status for display: draft, open, partial, overdue, paid, cancelled; quotes also expired,
     * accepted, declined and invoiced.
     */
    public function displayStatus(): string
    {
        return match (true) {
            $this->isOverdue() => 'overdue',
            $this->isExpired() => 'expired',
            $this->status === self::OPEN && ! $this->isQuote() && abs($this->paid_total) > 0.004 => 'partial',
            default => $this->status,
        };
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

    public function numberPrefix(BusinessProfile $profile): string
    {
        return match ($this->type) {
            self::QUOTE => (string) $profile->quote_prefix,
            self::CREDIT_NOTE => (string) $profile->credit_note_prefix,
            default => (string) $profile->invoice_prefix,
        };
    }

    /**
     * Issues a draft: assigns the next number of the year in the document's number range
     * ("RE-2026-001", "AN-2026-001", "GS-2026-001") and the dates.
     */
    public function issue(BusinessProfile $profile, ?Carbon $issuedOn = null): void
    {
        $issuedOn ??= today();

        DB::transaction(function () use ($profile, $issuedOn) {
            $prefix = $this->numberPrefix($profile).$issuedOn->year.'-';
            $last = self::where('user_id', $this->user_id)->where('number', 'like', $prefix.'%')->lockForUpdate()->pluck('number')
                ->map(fn (string $number) => (int) substr($number, strlen($prefix)))->max() ?? 0;

            $this->forceFill([
                'number' => $prefix.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT),
                'status' => self::OPEN,
                'issued_on' => $issuedOn,
                'due_on' => $this->type === self::INVOICE ? ($this->due_on ?? $issuedOn->copy()->addDays($profile->payment_days)) : null,
                'valid_until' => $this->isQuote() ? ($this->valid_until ?? $issuedOn->copy()->addDays(30)) : null,
            ])->save();
        });
    }

    /**
     * Records money received (or refunded for a credit note) and updates the status.
     */
    public function recordPayment(Carbon $paidOn, float $amount, ?string $method = null, ?string $note = null, ?int $transactionId = null): InvoicePayment
    {
        $payment = $this->payments()->create([
            'paid_on' => $paidOn->toDateString(),
            'amount' => round($amount, 2),
            'method' => $method,
            'note' => $note,
            'transaction_id' => $transactionId,
        ]);

        $this->refreshPaymentStatus();

        return $payment;
    }

    /**
     * Sums the payments: fully paid documents become "paid" (with the date of the last payment).
     */
    public function refreshPaymentStatus(): void
    {
        $payments = $this->payments()->get();
        $paid = round((float) $payments->sum('amount'), 2);
        $settled = abs($this->gross_total) > 0 && abs($paid) >= abs($this->gross_total) - 0.004;

        $this->forceFill([
            'paid_total' => $paid,
            'status' => in_array($this->status, [self::OPEN, self::PAID], true) ? ($settled ? self::PAID : self::OPEN) : $this->status,
            'paid_on' => $settled ? $payments->last()?->paid_on : null,
        ])->save();
    }

    /**
     * New draft with the same content, e.g. an invoice from a quote or a credit note for an
     * invoice ($negate: amounts become negative).
     */
    public function copyAs(string $type, bool $negate = false): self
    {
        $copy = $this->replicate(['number', 'status', 'issued_on', 'due_on', 'valid_until', 'paid_on', 'paid_total', 'net_total', 'vat_total', 'gross_total']);
        $copy->forceFill(['type' => $type, 'status' => self::DRAFT, 'related_invoice_id' => $this->id, 'paid_total' => 0])->save();

        foreach ($this->items as $item) {
            $copy->items()->create([
                ...$item->only(['position', 'description', 'quantity', 'unit', 'discount_percent', 'vat_rate']),
                'unit_price' => $negate ? -$item->unit_price : $item->unit_price,
            ]);
        }

        $copy->recalculate();

        return $copy;
    }
}
