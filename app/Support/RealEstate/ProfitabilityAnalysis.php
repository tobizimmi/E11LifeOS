<?php

namespace App\Support\RealEstate;

use App\Models\RealEstate;

/**
 * Profitability of a rental property: purchase costs, financing, rent compared with the local
 * rent index (Mietspiegel), running costs, depreciation and income tax, key figures and a
 * projection over the years.
 */
class ProfitabilityAnalysis
{
    /**
     * @return array<string, float|int|string>
     */
    public static function defaults(): array
    {
        return [
            'purchase_price' => 300000, 'living_area' => 70, 'state' => 'BY', 'transfer_tax_rate' => RealEstate::TRANSFER_TAX['BY'],
            'notary_rate' => 2.0, 'broker_rate' => 3.57, 'renovation' => 0, 'building_share' => 75, 'construction_year' => 1995,
            'equity' => 60000, 'interest_rate' => 3.8, 'repayment_rate' => 2.0, 'fixed_years' => 10,
            'rent' => 1000, 'local_rent_per_sqm' => 14, 'vacancy_rate' => 2, 'non_allocable_costs' => 90,
            'maintenance_per_sqm' => 12, 'management' => 25, 'tax_rate' => 42, 'rent_increase' => 1.5, 'value_increase' => 1.5, 'years' => 15,
        ];
    }

    /**
     * Prefills the calculation with the data of an owned property.
     *
     * @return array<string, float|int|string>
     */
    public static function fromRealEstate(RealEstate $realEstate): array
    {
        $loans = $realEstate->loans;
        $loanSum = (float) $loans->sum('amount');
        $weighted = fn (string $field, float $fallback) => $loanSum > 0 ? round($loans->sum(fn ($loan) => $loan->amount * $loan->{$field}) / $loanSum, 2) : $fallback;
        $defaults = self::defaults();

        return array_merge($defaults, array_filter([
            'purchase_price' => $realEstate->purchase_price,
            'living_area' => $realEstate->living_area,
            'state' => $realEstate->state,
            'transfer_tax_rate' => $realEstate->transferTaxRate(),
            'notary_rate' => $realEstate->notary_rate,
            'broker_rate' => $realEstate->broker_rate,
            'building_share' => 100 - $realEstate->land_share,
            'construction_year' => $realEstate->construction_year,
            'equity' => $realEstate->equity,
            'interest_rate' => $weighted('interest_rate', (float) $defaults['interest_rate']),
            'repayment_rate' => $weighted('repayment_rate', (float) $defaults['repayment_rate']),
            'fixed_years' => $loans->min('fixed_years'),
            'rent' => $realEstate->monthly_rent,
            'non_allocable_costs' => $realEstate->monthly_costs,
            'tax_rate' => $realEstate->tax_rate,
        ], fn (mixed $value) => ! in_array($value, [null, ''], true)));
    }

    /**
     * @param  array<string, float|int|string>  $in
     * @return array<string, mixed>
     */
    public static function calculate(array $in): array
    {
        $n = fn (string $key) => (float) ($in[$key] ?? 0);
        $price = $n('purchase_price');
        $area = max(0.0, $n('living_area'));
        $transferTax = $price * $n('transfer_tax_rate') / 100;
        $notary = $price * $n('notary_rate') / 100;
        $broker = $price * $n('broker_rate') / 100;
        $closing = $transferTax + $notary + $broker;
        $renovation = $n('renovation');
        $total = $price + $closing + $renovation;
        $equity = min(max(0.0, $n('equity')), $total);
        $loan = max(0.0, $total - $equity);
        $interest = $n('interest_rate') / 100;
        $annuity = $loan * ($interest + $n('repayment_rate') / 100);

        $rentYear = $n('rent') * 12;
        $effectiveRent = $rentYear * (1 - $n('vacancy_rate') / 100);
        $costs = $n('non_allocable_costs') * 12 + $n('maintenance_per_sqm') * $area + $n('management') * 12;
        $noi = $effectiveRent - $costs;

        // Building share of the price and the purchase costs is depreciated (renovation counted as building cost).
        $afaRate = RealEstateTax::depreciationRate((int) $in['construction_year'] ?: null) / 100;
        $depreciable = ($price + $closing) * $n('building_share') / 100 + $renovation;
        $depreciation = $depreciable * $afaRate;

        $rentPerSqm = $area > 0 ? $n('rent') / $area : null;
        $local = $n('local_rent_per_sqm');
        $projection = self::projection($in, $loan, $annuity, $effectiveRent, $costs, $depreciation);
        $first = $projection[0] ?? null;
        $fixedYears = max(1, (int) $n('fixed_years'));
        $remainingDebt = $projection[min($fixedYears, count($projection)) - 1]['debt'] ?? $loan;
        $followUpRate = $remainingDebt * ($interest + 0.02 + $n('repayment_rate') / 100) / 12;

        $result = [
            'price' => round($price, 2), 'renovation' => round($renovation, 2),
            'transfer_tax' => round($transferTax, 2), 'notary' => round($notary, 2), 'broker' => round($broker, 2), 'closing' => round($closing, 2),
            'total' => round($total, 2), 'equity' => round($equity, 2), 'loan' => round($loan, 2),
            'monthly_rate' => round($annuity / 12, 2),
            'rent_year' => round($rentYear, 2), 'effective_rent' => round($effectiveRent, 2), 'costs' => round($costs, 2), 'noi' => round($noi, 2),
            'depreciation' => round($depreciation, 2), 'afa_rate' => $afaRate * 100,
            'cashflow_pre_tax' => $first ? $first['cashflow_pre_tax'] : round($noi - $annuity, 2),
            'tax' => $first ? $first['tax'] : 0.0,
            'cashflow_after_tax' => $first ? $first['cashflow_after_tax'] : round($noi - $annuity, 2),
            'gross_yield' => $price > 0 ? round($rentYear / $price * 100, 2) : null,
            'net_yield' => $total > 0 ? round($noi / $total * 100, 2) : null,
            'price_factor' => $rentYear > 0 ? round($price / $rentYear, 1) : null,
            'price_per_sqm' => $area > 0 ? round($price / $area, 0) : null,
            'rent_per_sqm' => $rentPerSqm !== null ? round($rentPerSqm, 2) : null,
            'rent_vs_index' => $rentPerSqm !== null && $local > 0 ? round(($rentPerSqm / $local - 1) * 100, 1) : null,
            'equity_return' => $equity > 0 && $first ? round(($first['cashflow_after_tax'] + $first['principal']) / $equity * 100, 1) : null,
            'dscr' => $annuity > 0 ? round($noi / $annuity, 2) : null,
            'break_even_rent' => (1 - $n('vacancy_rate') / 100) > 0 ? round(($annuity + $costs) / 12 / (1 - $n('vacancy_rate') / 100), 2) : null,
            'remaining_debt' => round($remainingDebt, 2),
            'follow_up_rate' => round($followUpRate, 2),
            'projection' => $projection,
        ];
        $result['verdict'] = self::verdict($result);
        $result['hints'] = self::hints($in, $result);

        return $result;
    }

    /**
     * Year by year: rent and value grow, the annuity stays, the interest share shrinks.
     *
     * @param  array<string, float|int|string>  $in
     * @return list<array{year: int, rent: float, interest: float, principal: float, debt: float, cashflow_pre_tax: float, tax: float, cashflow_after_tax: float, value: float, net_equity: float}>
     */
    private static function projection(array $in, float $loan, float $annuity, float $effectiveRent, float $costs, float $depreciation): array
    {
        $rows = [];
        $debt = $loan;
        $value = (float) $in['purchase_price'];
        $interestRate = (float) $in['interest_rate'] / 100;
        $taxRate = (float) $in['tax_rate'] / 100;
        $years = max(1, min(40, (int) $in['years']));

        for ($year = 1; $year <= $years; $year++) {
            $growth = (1 + (float) $in['rent_increase'] / 100) ** ($year - 1);
            $rent = $effectiveRent * $growth;
            $yearCosts = $costs * $growth;
            $interest = $debt * $interestRate;
            $principal = min($debt, max(0.0, $annuity - $interest));
            $payment = $debt > 0 ? $interest + $principal : 0.0;
            $debt -= $principal;
            $value *= 1 + (float) $in['value_increase'] / 100;
            // Negative taxable income reduces the tax on other income (refund).
            $tax = ($rent - $yearCosts - $interest - $depreciation) * $taxRate;
            $preTax = $rent - $yearCosts - $payment;

            $rows[] = [
                'year' => $year,
                'rent' => round($rent, 2),
                'interest' => round($interest, 2),
                'principal' => round($principal, 2),
                'debt' => round(max(0.0, $debt), 2),
                'cashflow_pre_tax' => round($preTax, 2),
                'tax' => round($tax, 2),
                'cashflow_after_tax' => round($preTax - $tax, 2),
                'value' => round($value, 2),
                'net_equity' => round($value - max(0.0, $debt), 2),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{level: string, text: string}
     */
    private static function verdict(array $result): array
    {
        $factor = $result['price_factor'];
        $monthly = $result['cashflow_after_tax'] / 12;

        if ($factor === null) {
            return ['level' => 'unknown', 'text' => __('Enter the rent to judge the property.')];
        }

        if ($factor <= 22 && $monthly >= 0) {
            return ['level' => 'green', 'text' => __('Looks solid: the property carries itself after tax and the price is reasonable for the rent.')];
        }

        if ($factor <= 28 && $monthly >= -250) {
            return ['level' => 'yellow', 'text' => __('Possible, but you add :amount per month after tax – check price, equity and rent again.', ['amount' => number_format(max(0, -$monthly), 0, ',', '.').' €'])];
        }

        return ['level' => 'red', 'text' => __('Critical: price factor :factor and :amount per month after tax – the property does not pay for itself.', ['factor' => number_format($factor, 1, ',', '.'), 'amount' => number_format(-$monthly, 0, ',', '.').' €'])];
    }

    /**
     * @param  array<string, float|int|string>  $in
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private static function hints(array $in, array $result): array
    {
        $hints = [];
        $euro = fn (float $value) => number_format($value, 0, ',', '.').' €';

        if ($result['rent_vs_index'] !== null && $result['rent_vs_index'] > 10) {
            $hints[] = __('The rent is :percent % above the local rent index – in areas with a rent cap (Mietpreisbremse) at most 10 % above is allowed for new contracts, and follow-up tenants may pay less.', ['percent' => number_format($result['rent_vs_index'], 1, ',', '.')]);
        } elseif ($result['rent_vs_index'] !== null && $result['rent_vs_index'] < -10) {
            $hints[] = __('The rent is :percent % below the local rent index – there is room for increases (within the legal cap of 20 % in three years, 15 % in tight markets).', ['percent' => number_format(-$result['rent_vs_index'], 1, ',', '.')]);
        }

        if ((float) $in['equity'] < $result['closing']) {
            $hints[] = __('The equity does not even cover the purchase costs (:amount) – banks then charge higher interest or finance only with a surcharge.', ['amount' => $euro($result['closing'])]);
        }

        if ($result['dscr'] !== null && $result['dscr'] < 1.2) {
            $hints[] = __('The net rent covers the loan rate only :dscr times – banks usually want at least 1.2.', ['dscr' => number_format($result['dscr'], 2, ',', '.')]);
        }

        if ($result['loan'] > 0 && $result['remaining_debt'] > 0) {
            $hints[] = __('After the fixed-interest period :debt remain. If interest is 2 percentage points higher then, the rate rises to about :rate per month.', ['debt' => $euro($result['remaining_debt']), 'rate' => $euro($result['follow_up_rate'])]);
        }

        if ($result['tax'] < 0) {
            $hints[] = __('In the first year the property saves about :amount income tax (interest and depreciation exceed the rent).', ['amount' => $euro(-$result['tax'])]);
        }

        if ((float) $in['renovation'] > 0) {
            $hints[] = __('Renovation within three years of purchase above 15 % of the building value counts as acquisition cost and is only depreciated, not deducted at once.');
        }

        return $hints;
    }
}
