<?php

namespace App\Support\RealEstate;

use App\Models\RealEstate;
use App\Models\RealEstateBudgetItem;
use App\Models\RealEstateDraw;
use App\Models\RealEstateInvoice;
use App\Models\RealEstateLoan;
use App\Models\RealEstateSubsidy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures of a property: purchase costs, budget against invoices, financing and monthly burden.
 */
class RealEstatePlan
{
    /**
     * Purchase price and closing costs.
     *
     * @return array{price: float, transfer_tax: float, notary: float, land_registry: float, broker: float, closing: float, total: float}
     */
    public static function purchase(RealEstate $realEstate): array
    {
        $price = (float) $realEstate->purchase_price;
        $costs = [
            'transfer_tax' => round($price * $realEstate->transferTaxRate() / 100, 2),
            'notary' => round($price * $realEstate->notary_rate / 100, 2),
            'land_registry' => round($price * $realEstate->land_registry_rate / 100, 2),
            'broker' => round($price * $realEstate->broker_rate / 100, 2),
        ];
        $closing = round(array_sum($costs), 2);

        return ['price' => $price, ...$costs, 'closing' => $closing, 'total' => round($price + $closing, 2)];
    }

    /**
     * Everything for the overview.
     *
     * @return array<string, mixed>
     */
    public static function summary(RealEstate $realEstate): array
    {
        $realEstate->loadMissing(['budgetItems.invoices', 'invoices', 'loans', 'subsidies']);
        $purchase = self::purchase($realEstate);

        $items = $realEstate->budgetItems->map(function (RealEstateBudgetItem $item) {
            $invoiced = round((float) $item->invoices->sum('amount'), 2);
            $paid = round((float) $item->invoices->filter(fn (RealEstateInvoice $invoice) => $invoice->isPaid())->sum('amount'), 2);

            return [
                'item' => $item,
                'planned' => $item->planned_amount,
                'invoiced' => $invoiced,
                'paid' => $paid,
                // The expected cost: the plan, or more once the invoices exceed it.
                'expected' => max($item->planned_amount, $invoiced),
                'over' => $invoiced > $item->planned_amount && $item->planned_amount > 0,
            ];
        });

        $unassigned = $realEstate->invoices->whereNull('budget_item_id');
        $renovation = $items->filter(fn (array $row) => $row['item']->phase !== 'purchase');
        $extraPurchase = $items->filter(fn (array $row) => $row['item']->phase === 'purchase');
        $expected = round($purchase['total'] + $items->sum('expected') + (float) $unassigned->sum('amount'), 2);

        $loans = round((float) $realEstate->loans->sum('amount'), 2);
        $grants = $realEstate->subsidies->where('kind', 'grant');
        $securedGrants = round((float) $grants->filter(fn (RealEstateSubsidy $subsidy) => $subsidy->isSecured())->sum('amount'), 2);
        $pendingGrants = round((float) $grants->whereIn('status', ['idea', 'applied'])->sum('amount'), 2);
        $equity = (float) $realEstate->equity;
        $monthlyLoan = round((float) $realEstate->loans->sum(fn (RealEstateLoan $loan) => $loan->monthlyRate()), 2);
        $invoices = $realEstate->invoices;
        // The rent is entered for the let part only.
        $rent = $realEstate->rentalShare() > 0 ? (float) $realEstate->monthly_rent : 0.0;

        return [
            'purchase' => $purchase,
            'items' => $items,
            'renovation' => [
                'planned' => round($renovation->sum('planned'), 2),
                'invoiced' => round($renovation->sum('invoiced'), 2),
                'expected' => round($renovation->sum('expected'), 2),
            ],
            'extra_purchase' => round($extraPurchase->sum('expected'), 2),
            'unassigned' => round((float) $unassigned->sum('amount'), 2),
            'expected_total' => $expected,
            'invoiced_total' => round((float) $invoices->sum('amount'), 2),
            'paid_total' => round((float) $invoices->filter(fn (RealEstateInvoice $invoice) => $invoice->isPaid())->sum('amount'), 2),
            'open_invoices' => $invoices->reject(fn (RealEstateInvoice $invoice) => $invoice->isPaid())->values(),
            'funding' => [
                'equity' => $equity,
                'loans' => $loans,
                'grants' => $securedGrants,
                'pending_grants' => $pendingGrants,
                'gap' => round($expected - $equity - $loans - $securedGrants, 2),
            ],
            'monthly' => [
                'loan' => $monthlyLoan,
                'rent' => $rent,
                'costs' => (float) $realEstate->monthly_costs,
                'cashflow' => round($rent - (float) $realEstate->monthly_costs - $monthlyLoan, 2),
            ],
            'yield' => $realEstate->purchase_price > 0 && $realEstate->monthly_rent > 0
                ? round($realEstate->monthly_rent * 12 / $realEstate->purchase_price * 100, 2)
                : null,
            'price_per_sqm' => $realEstate->purchase_price > 0 && $realEstate->living_area > 0 ? round($realEstate->purchase_price / $realEstate->living_area) : null,
            'progress' => $items->isEmpty() ? null : (int) round($items->filter(fn (array $row) => $row['item']->status === 'done')->count() / $items->count() * 100),
        ];
    }

    /**
     * Financing controlling: the funds drawn so far against the planned amount, split into equity
     * and loan draws, plus the running renovation costs by month.
     *
     * @param  array<string, mixed>  $summary  the result of self::summary()
     * @return array{cost: float, planned_total: float, actual_total: float, remaining: float, coverage_gap: float, sources: array<string, array{planned: float, actual: float, rows: Collection<int, RealEstateDraw>}>, renovation_by_month: list<array{month: string, label: string, amount: float}>}
     */
    public static function controlling(RealEstate $realEstate, array $summary): array
    {
        $realEstate->loadMissing(['draws', 'invoices']);

        $sources = [];

        foreach (RealEstateDraw::SOURCES as $source) {
            $rows = $realEstate->draws->where('source', $source)->values();
            $sources[$source] = [
                'planned' => round((float) $rows->sum('planned_amount'), 2),
                'actual' => round((float) $rows->sum('actual_amount'), 2),
                'rows' => $rows,
            ];
        }

        $plannedTotal = round((float) $realEstate->draws->sum('planned_amount'), 2);
        $actualTotal = round((float) $realEstate->draws->sum('actual_amount'), 2);
        $cost = (float) $summary['expected_total'];

        $byMonth = DB::table('real_estate_invoices')
            ->where('real_estate_id', $realEstate->id)
            ->where('amount', '>', 0)
            ->get(['invoice_date', 'amount'])
            ->groupBy(fn (object $row) => Carbon::parse((string) $row->invoice_date)->format('Y-m'))
            ->map(fn (Collection $group, string $month) => [
                'month' => $month,
                'label' => Carbon::createFromFormat('Y-m-d', $month.'-01')->translatedFormat('M Y'),
                'amount' => round((float) $group->sum('amount'), 2),
            ])
            ->sortKeys()
            ->values()
            ->all();

        return [
            'cost' => $cost,
            'planned_total' => $plannedTotal,
            'actual_total' => $actualTotal,
            'remaining' => round($plannedTotal - $actualTotal, 2),
            'coverage_gap' => round($cost - $plannedTotal, 2),
            'sources' => $sources,
            'renovation_by_month' => $byMonth,
        ];
    }

    /**
     * Repayment plan of a loan by year: rate, interest, repayment and remaining debt.
     *
     * @return list<array{year: int, interest: float, repayment: float, balance: float}>
     */
    public static function schedule(RealEstateLoan $loan, int $years = 30): array
    {
        $rows = [];
        $previous = ['balance' => $loan->amount, 'interest' => 0.0];

        for ($year = 1; $year <= $years; $year++) {
            $current = $loan->after($year * 12);
            $interest = round($current['interest'] - $previous['interest'], 2);
            $rows[] = ['year' => $year, 'interest' => $interest, 'repayment' => round($previous['balance'] - $current['balance'], 2), 'balance' => $current['balance']];
            $previous = $current;

            if ($current['balance'] <= 0) {
                break;
            }
        }

        return $rows;
    }
}
