<?php

namespace App\Support\Finance;

use App\Models\BankAccount;
use App\Models\BusinessProfile;
use App\Models\NetWorthItem;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\RealEstate;
use App\Models\RealEstateLoan;
use App\Models\RealEstateTenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Addons;
use App\Support\Business\EuerReport;
use App\Support\RealEstate\RealEstateLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Income statement and balance sheet in the style of "Rich Dad Poor Dad": income split into
 * earned (salary), portfolio (dividends) and passive income (rent, business profit), monthly
 * expenses, assets that put money in your pocket versus liabilities that take it out, and the
 * share of the expenses already covered by asset income (financial freedom).
 */
class CashflowStatement
{
    public const MONTHS = 12;

    /**
     * @return array{
     *     from: Carbon, to: Carbon, months: int,
     *     income: array{earned: float, portfolio: float, passive: float, other: float, total: float, rows: list<array{kind: string, label: string, value: float}>},
     *     expenses: array{total: float, rows: list<array{label: string, icon: string, value: float}>},
     *     cashflow: float, asset_income: float, freedom: float, gap: float,
     *     assets: array{total: float, rows: list<array{label: string, value: float, url: ?string}>},
     *     liabilities: array{total: float, rows: list<array{label: string, value: float, url: ?string}>},
     *     doodads: array{total: float, rows: list<array{label: string, value: float, url: ?string}>},
     *     pattern: string, has_data: bool
     * }
     */
    public static function for(User $user): array
    {
        $household = $user->currentHousehold();
        $to = today();
        $from = $to->copy()->subMonthsNoOverflow(self::MONTHS - 1)->startOfMonth();

        $properties = $household ? RealEstateLink::properties($user, $household->id)->load(['valuations', 'loans', 'tenants']) : collect();
        $tenants = $properties->flatMap(fn (RealEstate $realEstate) => $realEstate->isLet() ? $realEstate->tenants->filter(fn (RealEstateTenant $tenant) => $tenant->isActiveOn($to)) : []);

        $transactions = $household && $user->canUseAddon(Addons::FINANCE)
            ? Transaction::where('user_id', $user->id)->where('household_id', $household->id)->whereBetween('booked_on', [$from, $to])
                ->where('category', '!=', FinanceCategories::TRANSFER)->get()
            : new Collection;
        $bankMonths = self::monthsCovered($transactions, $from, $to);

        $references = $tenants->map(fn (RealEstateTenant $tenant) => mb_strtolower($tenant->reference()))->filter(fn (string $reference) => mb_strlen($reference) >= 3);
        $isRent = fn (Transaction $transaction) => $references->contains(fn (string $reference) => str_contains(mb_strtolower($transaction->counterparty.' '.$transaction->purpose), $reference));

        $credits = $transactions->filter(fn (Transaction $transaction) => $transaction->amount > 0 && $transaction->category !== 'refunds');
        $earned = (float) $credits->where('category', 'salary')->sum('amount') / $bankMonths;
        $other = (float) $credits->filter(fn (Transaction $transaction) => $transaction->category !== 'salary' && ! $isRent($transaction))->sum('amount') / $bankMonths;

        $dividends = $user->canUseAddon(Addons::PORTFOLIO)
            ? PortfolioTransaction::whereIn('portfolio_id', Portfolio::where('user_id', $user->id)->select('id'))->where('type', 'dividend')->whereBetween('executed_on', [$from, $to])->get()
                ->sum(fn (PortfolioTransaction $transaction) => $transaction->cashFlow()) / self::MONTHS
            : 0.0;

        $rent = (float) $tenants->sum('cold_rent');
        $business = self::businessProfit($user, $household?->id);

        $refunds = (float) $transactions->where('category', 'refunds')->sum('amount');
        $spending = $transactions->filter(fn (Transaction $transaction) => $transaction->amount < 0 && $transaction->category !== 'savings');
        $expenseRows = $spending->groupBy('category')
            ->map(fn (Collection $group, string $category) => ['label' => FinanceCategories::label($category), 'icon' => FinanceCategories::icon($category), 'value' => round(-$group->sum('amount') / $bankMonths, 2)])
            ->sortByDesc('value')->values()->all();
        $expenses = max(0.0, round((-$spending->sum('amount') - $refunds) / $bankMonths, 2));

        $income = [
            'earned' => round($earned, 2),
            'portfolio' => round($dividends, 2),
            'passive' => round($rent + $business, 2),
            'other' => round($other, 2),
            'rows' => array_values(array_filter([
                ['kind' => 'earned', 'label' => __('Salary & wages'), 'value' => round($earned, 2)],
                ['kind' => 'portfolio', 'label' => __('Dividends & distributions'), 'value' => round($dividends, 2)],
                ['kind' => 'passive', 'label' => __('Rental income (net cold rent)'), 'value' => round($rent, 2)],
                ['kind' => 'passive', 'label' => __('Business profit'), 'value' => round($business, 2)],
                ['kind' => 'other', 'label' => __('Other income'), 'value' => round($other, 2)],
            ], fn (array $row) => $row['value'] != 0)),
        ];
        $income['total'] = round($income['earned'] + $income['portfolio'] + $income['passive'] + $income['other'], 2);

        $balance = self::balanceSheet($user, $household?->id, $properties);
        $assetIncome = round($income['portfolio'] + $income['passive'], 2);
        $freedom = $expenses > 0 ? round($assetIncome / $expenses, 3) : ($assetIncome > 0 ? 1.0 : 0.0);

        return [
            'from' => $to->copy()->subMonthsNoOverflow($bankMonths - 1)->startOfMonth(),
            'to' => $to,
            'months' => $bankMonths,
            'income' => $income,
            'expenses' => ['total' => $expenses, 'rows' => $expenseRows],
            'cashflow' => round($income['total'] - $expenses, 2),
            'asset_income' => $assetIncome,
            'freedom' => $freedom,
            'gap' => round(max(0, $expenses - $assetIncome), 2),
            ...$balance,
            'pattern' => self::pattern($freedom, $assetIncome, $income['total'] - $expenses, $balance['assets']['total'], $balance['liabilities']['total']),
            'has_data' => $transactions->isNotEmpty() || $assetIncome > 0 || $balance['assets']['total'] > 0 || $balance['liabilities']['total'] > 0,
        ];
    }

    /**
     * Cash-flow pattern after Kiyosaki: rich = asset income covers the expenses, middle class =
     * liabilities outweigh the assets, poor = no asset income and nothing left over, otherwise on the way.
     */
    public static function pattern(float $freedom, float $assetIncome, float $cashflow, float $assets, float $liabilities): string
    {
        return match (true) {
            $freedom >= 1 && $assetIncome > 0 => 'rich',
            $liabilities > 0 && $liabilities >= $assets => 'middle',
            $assetIncome <= 0 && $cashflow <= 0 => 'poor',
            default => 'building',
        };
    }

    /**
     * @param  Collection<int, RealEstate>  $properties
     * @return array{assets: array{total: float, rows: list<array{label: string, value: float, url: ?string}>}, liabilities: array{total: float, rows: list<array{label: string, value: float, url: ?string}>}, doodads: array{total: float, rows: list<array{label: string, value: float, url: ?string}>}}
     */
    private static function balanceSheet(User $user, ?int $householdId, Collection $properties): array
    {
        $assets = [];
        $liabilities = [];
        $doodads = [];

        if ($householdId && $user->canUseAddon(Addons::FINANCE)) {
            $cash = (float) BankAccount::where('user_id', $user->id)->where('household_id', $householdId)->get()->sum(fn (BankAccount $account) => (float) $account->currentBalance());
            $assets[] = ['label' => __('Bank accounts'), 'value' => round($cash, 2), 'url' => route('net-worth.index')];
        }

        if ($user->canUseAddon(Addons::PORTFOLIO)) {
            $value = (float) Portfolio::where('user_id', $user->id)->with(['snapshots' => fn ($query) => $query->latest('taken_at')->limit(1)])->get()
                ->sum(fn (Portfolio $portfolio) => (float) $portfolio->snapshots->first()?->total_value);
            $assets[] = ['label' => __('Securities'), 'value' => round($value, 2), 'url' => route('portfolio.index')];
        }

        foreach ($properties as $realEstate) {
            $value = (float) ($realEstate->valuations->sortByDesc('valued_on')->first()?->value ?? $realEstate->purchase_price);
            $share = $realEstate->rentalShare() / 100;
            $url = route('real-estate.show', ['realEstate' => $realEstate->id, 'tab' => 'value']);

            if ($share > 0) {
                $assets[] = ['label' => $realEstate->name.($share < 1 ? ' ('.__('let part').')' : ''), 'value' => round($value * $share, 2), 'url' => $url];
            }

            if ($share < 1) {
                $doodads[] = ['label' => $realEstate->name.' ('.__('lived in yourself').')', 'value' => round($value * (1 - $share), 2), 'url' => $url];
            }

            foreach ($realEstate->loans as $loan) {
                $liabilities[] = ['label' => trim(($loan->lender ?: __('Loan')).' · '.$realEstate->name), 'value' => self::remainingDebt($loan, $realEstate), 'url' => route('real-estate.show', ['realEstate' => $realEstate->id, 'tab' => 'purchase'])];
            }
        }

        if ($householdId) {
            foreach (Vehicle::where('household_id', $householdId)->where('created_by', $user->id)->get() as $vehicle) {
                $doodads[] = ['label' => $vehicle->name.' ('.__('vehicle').')', 'value' => (float) ($vehicle->current_value ?? $vehicle->purchase_price), 'url' => route('vehicles.show', $vehicle)];
            }
        }

        foreach (NetWorthItem::where('user_id', $user->id)->orderBy('position')->get() as $item) {
            $row = ['label' => $item->name, 'value' => (float) $item->value, 'url' => route('net-worth.index')];
            if ($item->isLiability()) {
                $liabilities[] = $row;
            } else {
                $assets[] = $row;
            }
        }

        $wrap = fn (array $rows) => ['total' => round(collect($rows)->sum('value'), 2), 'rows' => array_values(array_filter($rows, fn (array $row) => $row['value'] != 0))];

        return ['assets' => $wrap($assets), 'liabilities' => $wrap($liabilities), 'doodads' => $wrap($doodads)];
    }

    /**
     * Monthly business profit of the current year so far.
     */
    private static function businessProfit(User $user, ?int $householdId): float
    {
        if (! $householdId || ! Addons::allowed($user, Addons::BUSINESS) || ! ($profile = BusinessProfile::where('user_id', $user->id)->first())) {
            return 0.0;
        }

        $report = EuerReport::build($user, $householdId, (int) today()->year, $profile);

        return (float) $report['profit'] / max(1, today()->month);
    }

    /**
     * Months with bookings (from the first booking in the period until today), so a short import is not divided by 12.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private static function monthsCovered(Collection $transactions, Carbon $from, Carbon $to): int
    {
        $first = $transactions->min('booked_on');

        if (! $first) {
            return self::MONTHS;
        }

        return max(1, min(self::MONTHS, (int) Carbon::parse($first)->startOfMonth()->diffInMonths($to->copy()->startOfMonth()) + 1));
    }

    private static function remainingDebt(RealEstateLoan $loan, RealEstate $realEstate): float
    {
        $start = $loan->start_on ?? $realEstate->purchase_date;

        if (! $start || $start->isFuture()) {
            return (float) $loan->amount;
        }

        return round($loan->after((int) $start->diffInMonths(today()))['balance'], 2);
    }
}
