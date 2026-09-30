<?php

namespace App\Models;

use Database\Factories\RealEstateApplicantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An applicant for a let property with the self-disclosure (Selbstauskunft) from the public form.
 */
#[Fillable([
    'real_estate_id', 'status', 'rating', 'name', 'email', 'phone', 'birth_date', 'current_address', 'occupation', 'employer', 'employed_since',
    'net_income', 'persons', 'children', 'pets', 'smoker', 'current_landlord', 'rent_arrears', 'insolvency', 'eviction', 'credit_report',
    'desired_move_in', 'message', 'notes', 'consent_at',
])]
class RealEstateApplicant extends Model
{
    /** @use HasFactory<RealEstateApplicantFactory> */
    use HasFactory;

    public const STATUSES = ['new', 'shortlist', 'viewing', 'accepted', 'rejected'];

    /**
     * Rejected applicants are deleted after this many days (data minimisation).
     */
    public const RETENTION_DAYS = 180;

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'birth_date' => 'date',
            'employed_since' => 'date',
            'net_income' => 'float',
            'persons' => 'integer',
            'children' => 'integer',
            'smoker' => 'boolean',
            'rent_arrears' => 'boolean',
            'insolvency' => 'boolean',
            'eviction' => 'boolean',
            'credit_report' => 'boolean',
            'desired_move_in' => 'date',
            'consent_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            'new' => __('New'),
            'shortlist' => __('Shortlist'),
            'viewing' => __('Viewing'),
            'accepted' => __('Accepted'),
            'rejected' => __('Rejected'),
        ];
    }

    /**
     * Share of the net income the total rent would take, in % (null without income).
     */
    public function rentToIncome(float $monthlyRent): ?float
    {
        return $this->net_income > 0 && $monthlyRent > 0 ? round($monthlyRent / $this->net_income * 100, 1) : null;
    }

    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class);
    }
}
