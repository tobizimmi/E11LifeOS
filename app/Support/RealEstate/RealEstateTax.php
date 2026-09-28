<?php

namespace App\Support\RealEstate;

use App\Models\RealEstate;
use App\Models\RealEstateInvoice;
use App\Models\RealEstateLoan;
use Illuminate\Support\Collection;

/**
 * Estimated tax effect of a property in a year (German income tax, simplified):
 * letting – rent against depreciation (§ 7 Abs. 4 EStG), interest, maintenance and running
 * costs (Anlage V), including the 15 % rule for renovation right after the purchase (§ 6
 * Abs. 1 Nr. 1a EStG); living in it – craftsman services (§ 35a Abs. 3 EStG) and energy
 * renovation (§ 35c EStG). Mixed use is split by the let share. Not tax advice.
 */
class RealEstateTax
{
    public const CRAFTSMAN_RATE = 20;

    public const CRAFTSMAN_MAX = 1200;

    public const ENERGY_MAX = 40000;

    /**
     * Depreciation rate in % by year of construction.
     */
    public static function depreciationRate(?int $constructionYear): float
    {
        return match (true) {
            $constructionYear !== null && $constructionYear < 1925 => 2.5,
            $constructionYear !== null && $constructionYear >= 2023 => 3.0,
            default => 2.0,
        };
    }

    /**
     * @return array{year: int, rental_share: int, rental: ?array<string, float>, owner: ?array<string, float>, effect: float, warnings: list<string>, capitalized: bool}
     */
    public static function forYear(RealEstate $realEstate, int $year): array
    {
        $realEstate->loadMissing(['invoices.budgetItem', 'loans', 'subsidies']);
        $share = $realEstate->rentalShare() / 100;
        $warnings = [];
        $purchase = RealEstatePlan::purchase($realEstate);
        $buildingCost = $purchase['total'] * (1 - $realEstate->land_share / 100);
        $classified = self::classify($realEstate, $buildingCost);

        if ($classified['capitalized'] && $share > 0) {
            $warnings[] = __('Renovation within three years of the purchase exceeds 15 % of the building costs (net). It is added to the depreciation base instead of being deducted at once.');
        }

        $rental = null;
        $owner = null;

        if ($share > 0) {
            $months = self::monthsOwned($realEstate, $year);
            $depreciationBase = $buildingCost + $classified['capitalized_amount'];
            $depreciation = $depreciationBase * self::depreciationRate($realEstate->construction_year) / 100 * $months / 12;
            $interest = (float) $realEstate->loans->sum(fn (RealEstateLoan $loan) => $loan->interestIn($year));
            $maintenance = (float) $classified['maintenance']->filter(fn (RealEstateInvoice $invoice) => $invoice->paid_on?->year === $year)->sum('amount');
            $rent = (float) $realEstate->monthly_rent * $months;
            $costs = (float) $realEstate->monthly_costs * $months;
            $expenses = ($depreciation + $interest + $maintenance + $costs) * $share;
            $result = $rent - $expenses;

            $rental = [
                'rent' => round($rent, 2),
                'depreciation' => round($depreciation * $share, 2),
                'depreciation_base' => round($depreciationBase, 2),
                'interest' => round($interest * $share, 2),
                'maintenance' => round($maintenance * $share, 2),
                'costs' => round($costs * $share, 2),
                'result' => round($result, 2),
                // A loss lowers the tax, a surplus raises it.
                'effect' => round(-$result * $realEstate->tax_rate / 100, 2),
            ];
        }

        if ($share < 1) {
            $ownShare = 1 - $share;
            $labor = (float) $classified['craftsman']->filter(fn (RealEstateInvoice $invoice) => $invoice->paid_on?->year === $year)->sum('labor_amount') * $ownShare;
            $craftsman = min(self::CRAFTSMAN_MAX, $labor * self::CRAFTSMAN_RATE / 100);
            $energy = self::energyReduction($classified['energy'], $year, $ownShare);

            if ($classified['energy']->isNotEmpty() && $realEstate->construction_year && $realEstate->construction_year > now()->year - 10) {
                $warnings[] = __('§ 35c only applies to buildings older than ten years.');
                $energy = 0.0;
            }

            $owner = [
                'labor' => round($labor, 2),
                'craftsman' => round($craftsman, 2),
                'energy' => round($energy, 2),
                'effect' => round($craftsman + $energy, 2),
            ];
        }

        if ($classified['subsidised'] > 0) {
            $warnings[] = trans_choice(':count invoice belongs to a subsidised measure and is left out of § 35a/§ 35c.|:count invoices belong to subsidised measures and are left out of § 35a/§ 35c.', $classified['subsidised']);
        }

        return [
            'year' => $year,
            'rental_share' => $realEstate->rentalShare(),
            'rental' => $rental,
            'owner' => $owner,
            'effect' => round(($rental['effect'] ?? 0) + ($owner['effect'] ?? 0), 2),
            'warnings' => $warnings,
            'capitalized' => $classified['capitalized'],
        ];
    }

    /**
     * Sorts the invoices into their tax treatment.
     *
     * @return array{maintenance: Collection<int, RealEstateInvoice>, craftsman: Collection<int, RealEstateInvoice>, energy: Collection<int, RealEstateInvoice>, capitalized: bool, capitalized_amount: float, subsidised: int}
     */
    public static function classify(RealEstate $realEstate, float $buildingCost): array
    {
        $subsidisedItems = $realEstate->subsidies->whereIn('kind', ['grant', 'loan'])->whereNotIn('status', ['rejected'])->pluck('budget_item_id')->filter()->all();
        $maintenance = collect();
        $craftsman = collect();
        $energy = collect();
        $acquisition = collect();
        $subsidised = 0;

        foreach ($realEstate->invoices as $invoice) {
            $treatment = self::treatment($invoice);
            $isSubsidised = $invoice->budget_item_id && in_array($invoice->budget_item_id, $subsidisedItems, true);

            match ($treatment) {
                'acquisition' => $acquisition->push($invoice),
                'maintenance' => $maintenance->push($invoice),
                'craftsman' => $isSubsidised ? null : $craftsman->push($invoice),
                'energy' => $isSubsidised ? null : $energy->push($invoice),
                default => null,
            };

            if ($isSubsidised && in_array($treatment, ['craftsman', 'energy'], true)) {
                $subsidised++;
            }

            // Renovation counts as maintenance for the let share as well.
            if (in_array($treatment, ['craftsman', 'energy'], true)) {
                $maintenance->push($invoice);
            }
        }

        // 15 % rule: renovation within three years after the purchase, compared without VAT.
        $withinThreeYears = $maintenance->filter(fn (RealEstateInvoice $invoice) => $realEstate->purchase_date
            && $invoice->invoice_date->between($realEstate->purchase_date, $realEstate->purchase_date->copy()->addYears(3)));
        $capitalized = $buildingCost > 0 && $withinThreeYears->sum('amount') / 1.19 > $buildingCost * 0.15;

        if ($capitalized) {
            $maintenance = $maintenance->reject(fn (RealEstateInvoice $invoice) => $withinThreeYears->contains($invoice));
        }

        return [
            'maintenance' => $maintenance->unique('id')->values(),
            'craftsman' => $craftsman,
            'energy' => $energy,
            'capitalized' => $capitalized,
            'capitalized_amount' => round((float) $acquisition->sum('amount') + ($capitalized ? (float) $withinThreeYears->unique('id')->sum('amount') : 0), 2),
            'subsidised' => $subsidised,
        ];
    }

    /**
     * The invoice's treatment: fixed, or derived from its budget line.
     */
    public static function treatment(RealEstateInvoice $invoice): string
    {
        if ($invoice->tax_treatment !== 'auto') {
            return $invoice->tax_treatment;
        }

        $item = $invoice->budgetItem;

        return match (true) {
            ! $item => 'craftsman',
            $item->phase === 'purchase' => 'acquisition',
            $item->energy_measure => 'energy',
            default => 'craftsman',
        };
    }

    /**
     * § 35c: 7 % of the costs in the year of the measure and the next, 6 % in the third,
     * at most 40,000 € in total.
     *
     * @param  Collection<int, RealEstateInvoice>  $invoices
     */
    private static function energyReduction(Collection $invoices, int $year, float $ownShare): float
    {
        $rates = [0 => 0.07, 1 => 0.07, 2 => 0.06];
        $total = 0.0;
        $before = 0.0;

        foreach ($invoices->filter(fn (RealEstateInvoice $invoice) => $invoice->paid_on !== null) as $invoice) {
            $offset = $year - $invoice->paid_on->year;
            $cost = $invoice->amount * $ownShare;

            if (isset($rates[$offset])) {
                $total += $cost * $rates[$offset];
            }

            for ($earlier = 0; $earlier < $offset && $earlier <= 2; $earlier++) {
                $before += $cost * $rates[$earlier];
            }
        }

        return max(0, min($total, self::ENERGY_MAX - $before));
    }

    private static function monthsOwned(RealEstate $realEstate, int $year): int
    {
        $start = $realEstate->purchase_date;

        return match (true) {
            ! $start || $start->year > $year => 0,
            $start->year === $year => 13 - $start->month,
            default => 12,
        };
    }
}
