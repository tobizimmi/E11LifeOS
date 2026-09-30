<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A loan financing a property (annuity loan, KfW loan, building society, private loan).
 */
#[Fillable(['real_estate_id', 'lender', 'kind', 'amount', 'interest_rate', 'repayment_rate', 'fixed_years', 'start_on'])]
class RealEstateLoan extends Model
{
    public const KINDS = ['annuity', 'kfw', 'building_society', 'private'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'interest_rate' => 'float',
            'repayment_rate' => 'float',
            'fixed_years' => 'integer',
            'start_on' => 'date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return ['annuity' => __('Annuity loan'), 'kfw' => __('KfW loan'), 'building_society' => __('Building society'), 'private' => __('Private loan')];
    }

    public function realEstate(): BelongsTo
    {
        return $this->belongsTo(RealEstate::class);
    }

    /**
     * Monthly instalment (interest + initial repayment).
     */
    public function monthlyRate(): float
    {
        return round($this->amount * ($this->interest_rate + $this->repayment_rate) / 100 / 12, 2);
    }

    /**
     * Remaining debt after the given number of months, and the interest paid in that time.
     *
     * @return array{balance: float, interest: float}
     */
    public function after(int $months): array
    {
        $balance = $this->amount;
        $interest = 0.0;
        $rate = $this->monthlyRate();

        for ($month = 0; $month < $months && $balance > 0; $month++) {
            $monthInterest = $balance * $this->interest_rate / 100 / 12;
            $interest += $monthInterest;
            $balance -= min($balance, $rate - $monthInterest);
        }

        return ['balance' => round(max(0, $balance), 2), 'interest' => round($interest, 2)];
    }

    /**
     * Interest paid in a calendar year (0 before the loan starts).
     */
    public function interestIn(int $year): float
    {
        $start = $this->start_on ?? $this->realEstate?->purchase_date;

        if (! $start || $start->year > $year) {
            return 0.0;
        }

        $monthsBefore = max(0, ($year - $start->year) * 12 - ($start->month - 1));
        $monthsUntilEnd = ($year - $start->year) * 12 + 12 - ($start->month - 1);

        return round($this->after($monthsUntilEnd)['interest'] - $this->after($monthsBefore)['interest'], 2);
    }
}
