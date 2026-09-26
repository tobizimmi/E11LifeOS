<?php

namespace App\Models;

use Database\Factories\RecurringInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Template that creates an invoice every month, quarter, half year or year (subscriptions,
 * maintenance contracts, retainers).
 */
#[Fillable(['user_id', 'household_id', 'customer_id', 'name', 'recipient', 'subject', 'intro', 'closing', 'items', 'frequency', 'next_run_on', 'ends_on', 'auto_issue', 'auto_send', 'active'])]
class RecurringInvoice extends Model
{
    /** @use HasFactory<RecurringInvoiceFactory> */
    use HasFactory;

    public const FREQUENCIES = ['monthly' => 1, 'quarterly' => 3, 'halfyearly' => 6, 'yearly' => 12];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'next_run_on' => 'date',
            'ends_on' => 'date',
            'auto_issue' => 'boolean',
            'auto_send' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * Net amount of one invoice created from this template.
     */
    public function amount(): float
    {
        return round(collect($this->items)->sum(fn (array $item) => round((float) $item['quantity'] * (float) $item['unit_price'] * (1 - (float) ($item['discount_percent'] ?? 0) / 100), 2)), 2);
    }

    /**
     * Template from an existing invoice, starting one period after it.
     */
    public static function fromInvoice(Invoice $invoice, string $frequency): self
    {
        $start = ($invoice->issued_on ?? today())->copy()->addMonthsNoOverflow(self::FREQUENCIES[$frequency] ?? 1);

        return self::create([
            'user_id' => $invoice->user_id,
            'household_id' => $invoice->household_id,
            'customer_id' => $invoice->customer_id,
            'name' => $invoice->subject ?: strtok($invoice->recipient, "\n"),
            'recipient' => $invoice->recipient,
            'subject' => $invoice->subject,
            'intro' => $invoice->intro,
            'closing' => $invoice->closing,
            'items' => $invoice->items->map(fn (InvoiceItem $item) => $item->only(['description', 'quantity', 'unit', 'unit_price', 'discount_percent', 'vat_rate']))->values()->all(),
            'frequency' => $frequency,
            'next_run_on' => $start->max(today()->addDay()),
        ]);
    }

    /**
     * Creates the invoice of the current period (issued when configured) and moves on to the next date.
     */
    public function run(BusinessProfile $profile): Invoice
    {
        $runDate = $this->next_run_on->copy();
        $invoice = Invoice::create([
            'user_id' => $this->user_id,
            'household_id' => $this->household_id,
            'type' => Invoice::INVOICE,
            'customer_id' => $this->customer_id,
            'recurring_invoice_id' => $this->id,
            'status' => Invoice::DRAFT,
            'recipient' => $this->customer?->addressBlock() ?: $this->recipient,
            'subject' => $this->subject,
            'service_period' => self::periodLabel($runDate, $this->frequency),
            'intro' => $this->intro,
            'closing' => $this->closing,
            'small_business' => $profile->small_business,
        ]);

        foreach (array_values($this->items) as $position => $item) {
            $invoice->items()->create([
                'position' => $position + 1,
                'description' => (string) $item['description'],
                'quantity' => (float) $item['quantity'],
                'unit' => $item['unit'] ?? null,
                'unit_price' => (float) $item['unit_price'],
                'discount_percent' => (float) ($item['discount_percent'] ?? 0),
                'vat_rate' => $profile->small_business ? 0 : (float) ($item['vat_rate'] ?? $profile->default_vat_rate),
            ]);
        }

        $invoice->recalculate();

        if ($this->auto_issue && $profile->isCompleteForInvoices()) {
            $invoice->issue($profile, $runDate);
        }

        $next = $runDate->copy()->addMonthsNoOverflow(self::FREQUENCIES[$this->frequency] ?? 1);
        $this->update(['next_run_on' => $next, 'active' => $this->ends_on === null || $next->lte($this->ends_on)]);

        return $invoice;
    }

    /**
     * "Oktober 2026", "Q4 2026", "2. Halbjahr 2026" or "2026".
     */
    public static function periodLabel(Carbon $date, string $frequency): string
    {
        return match ($frequency) {
            'quarterly' => 'Q'.$date->quarter.' '.$date->year,
            'halfyearly' => __(':half. half-year :year', ['half' => $date->month <= 6 ? 1 : 2, 'year' => $date->year]),
            'yearly' => (string) $date->year,
            default => $date->translatedFormat('F Y'),
        };
    }
}
