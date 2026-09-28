<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\RealEstateTenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A tenant of a let property together with the terms of the lease.
 */
#[Fillable([
    'real_estate_id', 'created_by', 'unit', 'name', 'email', 'phone', 'previous_address', 'persons', 'area', 'move_in_on', 'move_out_on',
    'cold_rent', 'utilities_advance', 'deposit', 'deposit_paid_on', 'payment_reference', 'lease_end_on', 'notice_months', 'rent_adjustment',
    'due_day', 'special_terms', 'notes',
])]
class RealEstateTenant extends Model
{
    /** @use HasFactory<RealEstateTenantFactory> */
    use HasFactory;

    public const RENT_ADJUSTMENTS = ['none', 'index', 'graduated'];

    protected function casts(): array
    {
        return [
            'persons' => 'integer',
            'area' => 'float',
            'move_in_on' => 'date',
            'move_out_on' => 'date',
            'cold_rent' => 'float',
            'utilities_advance' => 'float',
            'deposit' => 'float',
            'deposit_paid_on' => 'date',
            'lease_end_on' => 'date',
            'notice_months' => 'integer',
            'due_day' => 'integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function rentAdjustmentLabels(): array
    {
        return ['none' => __('Fixed rent'), 'index' => __('Index rent'), 'graduated' => __('Graduated rent')];
    }

    public function monthlyTotal(): float
    {
        return round($this->cold_rent + $this->utilities_advance, 2);
    }

    /**
     * Text that identifies the tenant's transfers: the payment reference or the name.
     */
    public function reference(): string
    {
        return trim((string) ($this->payment_reference ?: $this->name));
    }

    public function isActiveOn(CarbonInterface $date): bool
    {
        return $this->move_in_on->lte($date) && ($this->move_out_on === null || $this->move_out_on->gte($date));
    }

    /**
     * Whether the tenant lives there during (a part of) the month.
     */
    public function livesInMonth(CarbonInterface $month): bool
    {
        return $this->move_in_on->lte($month->copy()->endOfMonth()) && ($this->move_out_on === null || $this->move_out_on->gte($month->copy()->startOfMonth()));
    }

    /**
     * Days the tenant lived there within the period.
     */
    public function daysIn(CarbonInterface $start, CarbonInterface $end): int
    {
        $from = $this->move_in_on->max($start);
        $to = ($this->move_out_on ?? Carbon::parse($end))->min($end);

        return $from->gt($to) ? 0 : (int) $from->diffInDays($to) + 1;
    }

    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(RealEstateRentPayment::class, 'tenant_id')->orderBy('month');
    }
}
