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
            'subsidy' => 0, 'kfw_loan' => 0, 'kfw_rate' => 0, 'sale_costs_rate' => 2.0, 'alternative_rate' => 5.0,
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
        // A one-time subsidy (grant) lowers the sum that has to be paid from equity and loan.
        $subsidy = min(max(0.0, $n('subsidy')), $total);
        $financed = $total - $subsidy;
        $equity = min(max(0.0, $n('equity')), $financed);
        $loan = max(0.0, $financed - $equity);
        // A subsidised (e.g. KfW) tranche carries a lower rate; the rest is the bank loan.
        $kfwLoan = min(max(0.0, $n('kfw_loan')), $loan);
        $bankLoan = $loan - $kfwLoan;
        $repayment = $n('repayment_rate') / 100;
        $interest = $loan > 0 ? ($kfwLoan * $n('kfw_rate') / 100 + $bankLoan * $n('interest_rate') / 100) / $loan : $n('interest_rate') / 100;
        $annuity = $loan * ($interest + $repayment);
        // The projection runs on the blended loan rate.
        $in['interest_rate'] = $interest * 100;

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
        $followUpRate = $remainingDebt * ($interest + 0.02 + $repayment) / 12;

        $exit = self::exit($in, $projection, $total, $closing, $depreciation);
        $equityReturn = self::equityReturn($in, $equity, $exit, $projection);

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
            // Operating cash flow: does the rent cover running costs and interest (before repayment)?
            'operating_cashflow' => $first ? round($noi - $first['interest'], 2) : round($noi, 2),
            'principal_first' => $first ? $first['principal'] : 0.0,
            'remaining_debt' => round($remainingDebt, 2),
            'follow_up_rate' => round($followUpRate, 2),
            'subsidy' => round($subsidy, 2),
            'kfw_loan' => round($kfwLoan, 2),
            'bank_loan' => round($bankLoan, 2),
            'blended_rate' => round($interest * 100, 2),
            'projection' => $projection,
        ];
        $result = array_merge($result, $exit, $equityReturn);
        $result['own_use'] = self::ownUse($in, $result, $area, $annuity);
        $result['verdict'] = self::verdict($result);
        $result['hints'] = self::hints($in, $result);

        return $result;
    }

    /**
     * Living in the property instead of letting it: you earn no rent (and get no depreciation or
     * rental tax effect), but you save the cold rent you would otherwise pay – tax-free – and a
     * later owner-occupied sale is free of speculation tax. A simplified, clearly-bounded
     * comparison of the total result over the holding period against letting it out.
     *
     * @param  array<string, float|int|string>  $in
     * @param  array<string, mixed>  $result
     * @return array{saved_rent_first: float, own_total: float, let_total: float, difference: float, better: string}
     */
    private static function ownUse(array $in, array $result, float $area, float $annuity): array
    {
        $n = fn (string $key) => (float) ($in[$key] ?? 0);
        $years = max(1, min(40, (int) $in['years']));
        $maintenance = $n('maintenance_per_sqm') * $area;
        $savedRentYear = $n('rent') * 12;
        $benefitSum = 0.0;

        for ($year = 1; $year <= $years; $year++) {
            $growth = (1 + $n('rent_increase') / 100) ** ($year - 1);
            // Saved cold rent minus the running maintenance and the loan instalment.
            $benefitSum += $savedRentYear * $growth - $maintenance * $growth - $annuity;
        }

        // Same sale, but an owner-occupied home is exempt from speculation tax.
        $ownProceeds = (float) $result['sale_price'] - (float) $result['sale_costs'] - (float) $result['exit_debt'];
        $ownTotal = $benefitSum + $ownProceeds - (float) $result['equity'];
        $letTotal = (float) $result['total_profit'];

        return [
            'saved_rent_first' => round($savedRentYear, 2),
            'own_total' => round($ownTotal, 2),
            'let_total' => round($letTotal, 2),
            'difference' => round(abs($ownTotal - $letTotal), 2),
            'better' => $letTotal >= $ownTotal ? 'let' : 'own',
        ];
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
     * Selling at the end of the projection: sale price, costs, remaining debt, speculation tax
     * (gain is taxable when sold within ten years) and the net proceeds.
     *
     * @param  array<string, float|int|string>  $in
     * @param  list<array{value: float, debt: float}>  $projection
     * @return array<string, mixed>
     */
    private static function exit(array $in, array $projection, float $total, float $closing, float $depreciation): array
    {
        $last = end($projection) ?: null;
        $years = max(1, min(40, (int) $in['years']));

        if (! $last) {
            return ['sale_price' => 0.0, 'sale_costs' => 0.0, 'exit_debt' => 0.0, 'speculation_tax' => 0.0, 'net_sale_proceeds' => 0.0, 'speculation_period' => false];
        }

        $salePrice = (float) $last['value'];
        $saleCosts = $salePrice * (float) $in['sale_costs_rate'] / 100;
        $exitDebt = (float) $last['debt'];

        // Within ten years the whole gain is taxed; the depreciation already claimed raises it.
        $withinPeriod = $years < 10;
        $book = $total - $depreciation * $years;
        $gain = $salePrice - $saleCosts - $book;
        $speculationTax = $withinPeriod ? max(0.0, $gain) * (float) $in['tax_rate'] / 100 : 0.0;
        $netProceeds = $salePrice - $saleCosts - $exitDebt - $speculationTax;

        return [
            'sale_price' => round($salePrice, 2),
            'sale_costs' => round($saleCosts, 2),
            'exit_debt' => round($exitDebt, 2),
            'speculation_tax' => round($speculationTax, 2),
            'net_sale_proceeds' => round($netProceeds, 2),
            'speculation_period' => $withinPeriod,
        ];
    }

    /**
     * Total return over the holding period: the after-tax cash flows plus the net sale proceeds
     * against the equity put in – as a sum, as an internal rate of return (IRR) and against a
     * simple alternative investment of the same equity.
     *
     * @param  array<string, float|int|string>  $in
     * @param  array{net_sale_proceeds: float}  $exit
     * @param  list<array{cashflow_after_tax: float}>  $projection
     * @return array<string, mixed>
     */
    private static function equityReturn(array $in, float $equity, array $exit, array $projection): array
    {
        $flows = [-$equity];

        foreach ($projection as $index => $row) {
            $flows[] = $row['cashflow_after_tax'] + ($index === count($projection) - 1 ? $exit['net_sale_proceeds'] : 0.0);
        }

        $cashSum = array_sum(array_map(fn (array $row) => $row['cashflow_after_tax'], $projection));
        $totalProfit = $cashSum + $exit['net_sale_proceeds'] - $equity;
        // The same equity put into an alternative at a fixed yearly return, for comparison.
        $years = max(1, min(40, (int) $in['years']));
        $alternative = $equity * ((1 + (float) $in['alternative_rate'] / 100) ** $years - 1);

        return [
            'total_cashflow' => round($cashSum, 2),
            'total_profit' => round($totalProfit, 2),
            'irr' => self::irr($flows),
            'alternative_profit' => round($alternative, 2),
        ];
    }

    /**
     * Internal rate of return of a yearly cash-flow series (first entry is the outlay), by
     * bisection; null when it has no sign change (e.g. never profitable).
     *
     * @param  list<float>  $flows
     */
    private static function irr(array $flows): ?float
    {
        $npv = fn (float $rate) => array_sum(array_map(fn (float $flow, int $year) => $flow / (1 + $rate) ** $year, $flows, array_keys($flows)));
        $low = -0.9;
        $high = 1.0;

        if ($npv($low) * $npv($high) > 0) {
            return null;
        }

        for ($i = 0; $i < 100; $i++) {
            $mid = ($low + $high) / 2;
            $value = $npv($mid);

            if (abs($value) < 0.01) {
                return round($mid * 100, 1);
            }

            ($npv($low) * $value < 0) ? $high = $mid : $low = $mid;
        }

        return round(($low + $high) / 2 * 100, 1);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{level: string, text: string}
     */
    private static function verdict(array $result): array
    {
        $factor = $result['price_factor'];

        if ($factor === null) {
            return ['level' => 'unknown', 'text' => __('Enter the rent to judge the property.')];
        }

        $irr = $result['irr'];
        $operating = $result['operating_cashflow'];
        $monthly = $result['cashflow_after_tax'] / 12;
        $principal = $result['principal_first'] / 12;
        $euro = fn (float $value) => number_format($value, 0, ',', '.').' €';
        // A negative cash flow is a top-up; the repayment part of it builds your own equity.
        $topUp = $monthly < 0
            ? __('You add :amount per month after tax, of which :principal goes into repayment (your own equity).', ['amount' => $euro(-$monthly), 'principal' => $euro(min(-$monthly, $principal))])
            : __('The property carries itself: :amount per month are left after tax.', ['amount' => $euro($monthly)]);

        // The total return (incl. repayment, tax and value growth) decides, not the cash flow alone.
        if ($irr !== null && $irr >= 4.5 && $operating >= 0 && $factor <= 27) {
            return ['level' => 'green', 'text' => __('Attractive overall: about :irr % return per year incl. repayment, tax and value growth. The rent covers running costs and interest. :topup', ['irr' => number_format($irr, 1, ',', '.'), 'topup' => $topUp])];
        }

        if ($irr !== null && $irr >= 2.5 && $factor <= 32) {
            return ['level' => 'yellow', 'text' => __('Borderline: about :irr % return per year. :topup Check price, equity and rent, and watch the interest after the fixed period.', ['irr' => number_format($irr, 1, ',', '.'), 'topup' => $topUp])];
        }

        $irrText = $irr !== null ? __('only about :irr % return per year', ['irr' => number_format($irr, 1, ',', '.')]) : __('the equity is not earning a return');

        return ['level' => 'red', 'text' => __('Critical: price factor :factor and :irrtext. :topup That is little for the risk – compare it with a simpler investment.', ['factor' => number_format($factor, 1, ',', '.'), 'irrtext' => $irrText, 'topup' => $topUp])];
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

        if ($result['speculation_period'] && $result['speculation_tax'] > 0) {
            $hints[] = __('Selling after :years years is still within the ten-year speculation period – about :amount income tax falls due on the gain. From the eleventh year the gain is tax-free.', ['years' => (int) $in['years'], 'amount' => $euro($result['speculation_tax'])]);
        }

        if ($result['irr'] !== null) {
            $hints[] = __('Over :years years the equity earns about :irr % per year (IRR, incl. sale) – :profit after tax in total.', ['years' => (int) $in['years'], 'irr' => number_format($result['irr'], 1, ',', '.'), 'profit' => $euro($result['total_profit'])]);

            if ($result['total_profit'] < $result['alternative_profit']) {
                $hints[] = __('An alternative investment of the :equity equity at :rate % would yield about :alt in the same time – more than this property after tax. Weigh the effort against it.', ['equity' => $euro((float) $in['equity']), 'rate' => number_format((float) $in['alternative_rate'], 1, ',', '.'), 'alt' => $euro($result['alternative_profit'])]);
            }
        }

        if ($result['subsidy'] > 0) {
            $hints[] = __('The subsidy of :amount lowers the capital needed. Check the exact conditions and apply before signing – many grants are lost once the contract is signed or work has started.', ['amount' => $euro($result['subsidy'])]);
        }

        return $hints;
    }
}
