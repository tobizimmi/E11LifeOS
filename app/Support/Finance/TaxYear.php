<?php

namespace App\Support\Finance;

use App\Models\BusinessProfile;
use App\Models\Portfolio;
use App\Models\PortfolioSetting;
use App\Models\PortfolioTransaction;
use App\Models\RealEstate;
use App\Models\Security;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Addons;
use App\Support\Business\EuerReport;
use App\Support\Portfolio\TaxReport;
use App\Support\RealEstate\RealEstateTax;
use Illuminate\Support\Collection;

/**
 * The tax year of a member across the app: tagged private bookings (deductions and § 35a
 * credits), properties, the small business and the portfolio. A rough estimate with the
 * personal tax rate – not tax advice.
 */
class TaxYear
{
    public const WORK_FLAT_RATE = 1230;

    public const CHILDCARE_SHARE = 0.8;

    public const CHILDCARE_MAX_PER_CHILD = 4800;

    public const HOUSEHOLD_SERVICES_MAX = 4000;

    public const CRAFTSMAN_MAX = 1200;

    /**
     * @return array<string, mixed>
     */
    public static function build(User $user, int $householdId, int $year): array
    {
        $rate = (float) ($user->tax_rate ?? 30) / 100;
        $bookings = self::bookings($user, $householdId, $year);
        $sums = collect(TaxCategories::all())->map(fn (array $category, string $key) => round((float) $bookings->where('tax_category', $key)->sum(fn (Transaction $transaction) => self::relevantAmount($transaction)), 2));

        $work = max(0, $sums['work'] - self::WORK_FLAT_RATE);
        $childcare = min($sums['childcare'] * self::CHILDCARE_SHARE, self::CHILDCARE_MAX_PER_CHILD * max(1, (int) $user->tax_children));
        $deductions = [
            'work' => ['spent' => $sums['work'], 'deductible' => round($work, 2)],
            'insurance' => ['spent' => $sums['insurance'], 'deductible' => $sums['insurance']],
            'pension' => ['spent' => $sums['pension'], 'deductible' => $sums['pension']],
            'donations' => ['spent' => $sums['donations'], 'deductible' => $sums['donations']],
            'church_tax' => ['spent' => $sums['church_tax'], 'deductible' => $sums['church_tax']],
            'childcare' => ['spent' => $sums['childcare'], 'deductible' => round($childcare, 2)],
            'medical' => ['spent' => $sums['medical'], 'deductible' => $sums['medical']],
        ];
        $deductible = round(array_sum(array_column($deductions, 'deductible')), 2);

        // § 35a from bookings and from properties lived in share one cap per household.
        $properties = [];
        $propertyCraftsmanLabor = 0.0;

        if (Addons::allowed($user, Addons::REAL_ESTATE)) {
            foreach (RealEstate::query()->visibleTo($user, $householdId)->orderBy('name')->get() as $realEstate) {
                $tax = RealEstateTax::forYear($realEstate, $year);
                $propertyCraftsmanLabor += $tax['owner']['labor'] ?? 0;
                $properties[] = ['model' => $realEstate, 'tax' => $tax];
            }
        }

        $householdServices = min(self::HOUSEHOLD_SERVICES_MAX, $sums['household_services'] * 0.2);
        $craftsman = min(self::CRAFTSMAN_MAX, ($sums['craftsman'] + $propertyCraftsmanLabor) * 0.2);
        $energy = round((float) collect($properties)->sum(fn (array $row) => $row['tax']['owner']['energy'] ?? 0), 2);
        $rentalResult = round((float) collect($properties)->sum(fn (array $row) => $row['tax']['rental']['result'] ?? 0), 2);

        $business = null;

        if (Addons::allowed($user, Addons::BUSINESS) && ($profile = BusinessProfile::where('user_id', $user->id)->first())) {
            $report = EuerReport::build($user, $householdId, $year, $profile);
            $business = ['profit' => $report['profit'], 'effect' => round(-$report['profit'] * $rate, 2)];
        }

        $portfolio = null;

        if (Addons::allowed($user, Addons::PORTFOLIO)) {
            $ids = Portfolio::where('user_id', $user->id)->pluck('id');
            $transactions = PortfolioTransaction::whereIn('portfolio_id', $ids)->where('executed_on', '<=', $year.'-12-31')->get();

            if ($transactions->isNotEmpty()) {
                $settings = PortfolioSetting::where('user_id', $user->id)->first();
                $assetTypes = Security::whereIn('symbol', $transactions->pluck('symbol')->unique())->pluck('asset_type', 'symbol')->all();
                $report = TaxReport::build($transactions, $year, (float) ($settings->tax_allowance ?? 1000), (float) ($settings->church_tax_rate ?? 0), $assetTypes);
                $portfolio = ['net' => $report['net'], 'dividends' => $report['dividends'], 'tax' => $report['tax'], 'taxes_paid' => $report['taxes_paid'], 'allowance_used' => $report['allowance_used'], 'allowance' => $report['allowance']];
            }
        }

        $credits = round($householdServices + $craftsman + $energy, 2);
        $rentalEffect = round(-$rentalResult * $rate, 2);

        return [
            'year' => $year,
            'rate' => $rate * 100,
            'bookings' => $bookings,
            'deductions' => $deductions,
            'deductible' => $deductible,
            'deduction_effect' => round($deductible * $rate, 2),
            'credits' => [
                'household_services' => ['spent' => $sums['household_services'], 'credit' => round($householdServices, 2)],
                'craftsman' => ['spent' => round($sums['craftsman'] + $propertyCraftsmanLabor, 2), 'credit' => round($craftsman, 2), 'from_properties' => round($propertyCraftsmanLabor, 2)],
                'energy' => ['credit' => $energy],
                'total' => $credits,
            ],
            'properties' => $properties,
            'rental_result' => $rentalResult,
            'rental_effect' => $rentalEffect,
            'business' => $business,
            'portfolio' => $portfolio,
            // Positive = less income tax (refund), negative = more.
            'effect' => round($deductible * $rate + $credits + $rentalEffect + ($business['effect'] ?? 0), 2),
        ];
    }

    /**
     * Tagged bookings of the member in the year.
     *
     * @return Collection<int, Transaction>
     */
    public static function bookings(User $user, int $householdId, int $year): Collection
    {
        return Transaction::where('user_id', $user->id)->where('household_id', $householdId)
            ->whereNotNull('tax_category')
            ->whereBetween('booked_on', [$year.'-01-01', $year.'-12-31'])
            ->orderBy('booked_on')->orderBy('id')->get();
    }

    /**
     * The tax-relevant part of a booking: the entered amount (e.g. labour costs) or the whole expense.
     */
    public static function relevantAmount(Transaction $transaction): float
    {
        return $transaction->tax_amount !== null ? abs($transaction->tax_amount) : max(0, -$transaction->amount);
    }
}
