<?php

namespace App\Models;

use Database\Factories\RealEstateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A property that is bought, renovated, lived in or let: purchase, financing, budget,
 * invoices, subsidies and tax (addon "real_estate"). Shared with the household unless private.
 */
#[Fillable([
    'household_id', 'created_by', 'visibility', 'name', 'street', 'postal_code', 'city', 'state', 'usage', 'rental_share', 'status',
    'purchase_price', 'purchase_date', 'land_share', 'transfer_tax_rate', 'notary_rate', 'land_registry_rate', 'broker_rate',
    'living_area', 'plot_area', 'construction_year', 'equity', 'monthly_rent', 'monthly_costs', 'tax_rate', 'notes',
])]
class RealEstate extends Model
{
    /** @use HasFactory<RealEstateFactory> */
    use HasFactory;

    public const OWNER = 'owner';

    public const RENTAL = 'rental';

    public const MIXED = 'mixed';

    public const USAGES = [self::OWNER, self::RENTAL, self::MIXED];

    public const STATUSES = ['search', 'planning', 'purchased', 'renovation', 'done'];

    /**
     * Real estate transfer tax per state in % (as of 2026 – can be overridden per property).
     */
    public const TRANSFER_TAX = [
        'BW' => 5.0, 'BY' => 3.5, 'BE' => 6.0, 'BB' => 6.5, 'HB' => 5.5, 'HH' => 5.5, 'HE' => 6.0, 'MV' => 6.0,
        'NI' => 5.0, 'NW' => 6.5, 'RP' => 5.0, 'SL' => 6.5, 'SN' => 5.5, 'ST' => 5.0, 'SH' => 6.5, 'TH' => 5.0,
    ];

    protected $attributes = [
        'visibility' => Item::VISIBILITY_SHARED,
    ];

    protected static function booted(): void
    {
        // Invoices one by one, so their receipts and document links are removed too.
        static::deleting(function (RealEstate $realEstate): void {
            $realEstate->invoices()->get()->each->delete();
            ExternalLink::where('linkable_type', 'real_estate')->where('linkable_id', $realEstate->id)->get()->each->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'rental_share' => 'integer',
            'purchase_price' => 'float',
            'land_share' => 'float',
            'transfer_tax_rate' => 'float',
            'notary_rate' => 'float',
            'land_registry_rate' => 'float',
            'broker_rate' => 'float',
            'living_area' => 'float',
            'plot_area' => 'float',
            'construction_year' => 'integer',
            'equity' => 'float',
            'monthly_rent' => 'float',
            'monthly_costs' => 'float',
            'tax_rate' => 'float',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function stateNames(): array
    {
        return [
            'BW' => 'Baden-Württemberg', 'BY' => 'Bayern', 'BE' => 'Berlin', 'BB' => 'Brandenburg', 'HB' => 'Bremen', 'HH' => 'Hamburg',
            'HE' => 'Hessen', 'MV' => 'Mecklenburg-Vorpommern', 'NI' => 'Niedersachsen', 'NW' => 'Nordrhein-Westfalen', 'RP' => 'Rheinland-Pfalz',
            'SL' => 'Saarland', 'SN' => 'Sachsen', 'ST' => 'Sachsen-Anhalt', 'SH' => 'Schleswig-Holstein', 'TH' => 'Thüringen',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function usageLabels(): array
    {
        return [self::OWNER => __('Lived in myself'), self::RENTAL => __('Let'), self::MIXED => __('Partly let')];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            'search' => __('Searching'),
            'planning' => __('Planning purchase'),
            'purchased' => __('Bought'),
            'renovation' => __('Renovating'),
            'done' => __('Finished'),
        ];
    }

    /**
     * Shared properties of the household plus the user's own private ones.
     *
     * @param  Builder<RealEstate>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function budgetItems(): HasMany
    {
        return $this->hasMany(RealEstateBudgetItem::class)->orderBy('phase')->orderBy('position')->orderBy('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(RealEstateInvoice::class)->orderByDesc('invoice_date')->orderByDesc('id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(RealEstateLoan::class)->orderBy('id');
    }

    public function subsidies(): HasMany
    {
        return $this->hasMany(RealEstateSubsidy::class)->orderBy('id');
    }

    public function isPrivate(): bool
    {
        return $this->visibility === Item::VISIBILITY_PRIVATE;
    }

    /**
     * Let share in % (0 = lived in, 100 = fully let).
     */
    public function rentalShare(): int
    {
        return match ($this->usage) {
            self::RENTAL => 100,
            self::MIXED => max(0, min(100, $this->rental_share)),
            default => 0,
        };
    }

    public function transferTaxRate(): float
    {
        return $this->transfer_tax_rate ?? (self::TRANSFER_TAX[$this->state] ?? 5.0);
    }

    public function address(): string
    {
        return trim(implode(', ', array_filter([$this->street, trim($this->postal_code.' '.$this->city)])));
    }
}
