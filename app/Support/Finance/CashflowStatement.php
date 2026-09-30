<?php

namespace App\Support\Finance;

use App\Models\BankAccount;
use App\Models\BusinessProfile;
use App\Models\CashflowEntry;
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
     *     liquid: float, reserve_months: ?float,
     *     pattern: string, has_data: bool
     * }
     */
    public static function for(User $user): array
    {
        $household = $user->currentHousehold();
        $manualOnly = (bool) $user->cashflow_manual_only;
        $to = today();
        $from = $to->copy()->subMonthsNoOverflow(self::MONTHS - 1)->startOfMonth();

        $properties = $household && ! $manualOnly ? RealEstateLink::properties($user, $household->id)->load(['valuations', 'loans', 'tenants']) : collect();
        $tenants = $properties->flatMap(fn (RealEstate $realEstate) => $realEstate->isLet() ? $realEstate->tenants->filter(fn (RealEstateTenant $tenant) => $tenant->isActiveOn($to)) : []);

        $transactions = $household && ! $manualOnly && $user->canUseAddon(Addons::FINANCE)
            ? Transaction::where('user_id', $user->id)->where('household_id', $household->id)->whereBetween('booked_on', [$from, $to])
                ->where('category', '!=', FinanceCategories::TRANSFER)->get()
            : new Collection;
        $bankMonths = self::monthsCovered($transactions, $from, $to);

        $references = $tenants->map(fn (RealEstateTenant $tenant) => mb_strtolower($tenant->reference()))->filter(fn (string $reference) => mb_strlen($reference) >= 3);
        $isRent = fn (Transaction $transaction) => $references->contains(fn (string $reference) => str_contains(mb_strtolower($transaction->counterparty.' '.$transaction->purpose), $reference));

        $credits = $transactions->filter(fn (Transaction $transaction) => $transaction->amount > 0 && $transaction->category !== 'refunds');
        $earned = (float) $credits->where('category', 'salary')->sum('amount') / $bankMonths;
        $other = (float) $credits->filter(fn (Transaction $transaction) => $transaction->category !== 'salary' && ! $isRent($transaction))->sum('amount') / $bankMonths;

        $dividends = ! $manualOnly && $user->canUseAddon(Addons::PORTFOLIO)
            ? PortfolioTransaction::whereIn('portfolio_id', Portfolio::where('user_id', $user->id)->select('id'))->where('type', 'dividend')->whereBetween('executed_on', [$from, $to])->get()
                ->sum(fn (PortfolioTransaction $transaction) => $transaction->cashFlow()) / self::MONTHS
            : 0.0;

        $rent = (float) $tenants->sum('cold_rent');
        $business = $manualOnly ? 0.0 : self::businessProfit($user, $household?->id);

        $refunds = (float) $transactions->where('category', 'refunds')->sum('amount');
        $spending = $transactions->filter(fn (Transaction $transaction) => $transaction->amount < 0 && $transaction->category !== 'savings');
        $expenseRows = $spending->groupBy('category')
            ->map(fn (Collection $group, string $category) => ['label' => FinanceCategories::label($category), 'icon' => FinanceCategories::icon($category), 'value' => round(-$group->sum('amount') / $bankMonths, 2), 'id' => null])
            ->sortByDesc('value')->values()->all();
        $expenses = max(0.0, (-$spending->sum('amount') - $refunds) / $bankMonths);

        $incomeRows = [
            ['kind' => 'earned', 'label' => __('Salary & wages'), 'value' => round($earned, 2), 'id' => null],
            ['kind' => 'self_employed', 'label' => __('Business profit'), 'value' => round($business, 2), 'id' => null],
            ['kind' => 'portfolio', 'label' => __('Dividends & distributions'), 'value' => round($dividends, 2), 'id' => null],
            ['kind' => 'passive', 'label' => __('Rental income (net cold rent)'), 'value' => round($rent, 2), 'id' => null],
            ['kind' => 'other', 'label' => __('Other income'), 'value' => round($other, 2), 'id' => null],
        ];

        // The member's own lines come on top (or alone with "manual only").
        $manual = CashflowEntry::where('user_id', $user->id)->orderBy('position')->orderBy('id')->get();
        $payments = 0.0;

        foreach ($manual as $entry) {
            match ($entry->kind) {
                CashflowEntry::INCOME => $incomeRows[] = ['kind' => $entry->category, 'label' => $entry->label, 'value' => round($entry->amount, 2), 'id' => $entry->id],
                CashflowEntry::EXPENSE => $expenseRows[] = ['label' => $entry->label, 'icon' => '✍️', 'value' => round($entry->amount, 2), 'id' => $entry->id],
                CashflowEntry::ASSET => $entry->productive && $entry->amount > 0
                    ? $incomeRows[] = ['kind' => in_array($entry->category, ['stocks', 'cash'], true) ? 'portfolio' : 'passive', 'label' => __('Income from :asset', ['asset' => $entry->label]), 'value' => round($entry->amount, 2), 'id' => null]
                    : null,
                CashflowEntry::LIABILITY => $entry->amount > 0
                    ? $expenseRows[] = ['label' => __('Instalment: :debt', ['debt' => $entry->label]), 'icon' => '🏦', 'value' => round($entry->amount, 2), 'id' => null, 'payment' => true]
                    : null,
                default => null,
            };

            if (in_array($entry->kind, [CashflowEntry::EXPENSE, CashflowEntry::LIABILITY], true)) {
                $expenses += $entry->amount;
                $payments += $entry->kind === CashflowEntry::LIABILITY ? $entry->amount : 0;
            }
        }

        $incomeRows = array_values(array_filter($incomeRows, fn (array $row) => $row['value'] != 0));
        usort($expenseRows, fn (array $a, array $b) => $b['value'] <=> $a['value']);
        $sum = fn (array $kinds) => round((float) collect($incomeRows)->whereIn('kind', $kinds)->sum('value'), 2);

        $income = [
            'earned' => $sum(['earned', 'self_employed']),
            'portfolio' => $sum(['portfolio']),
            'passive' => $sum(['passive', 'business']),
            'other' => $sum(['other']),
            'rows' => $incomeRows,
        ];
        $income['total'] = round($income['earned'] + $income['portfolio'] + $income['passive'] + $income['other'], 2);
        $expenses = round($expenses, 2);

        $balance = self::balanceSheet($user, $manualOnly ? null : $household?->id, $properties, $manual);
        $assetIncome = round($income['portfolio'] + $income['passive'], 2);
        $freedom = $expenses > 0 ? round($assetIncome / $expenses, 3) : ($assetIncome > 0 ? 1.0 : 0.0);
        $quadrant = ['E' => $sum(['earned']), 'S' => $sum(['self_employed']), 'B' => $sum(['business']), 'I' => $sum(['portfolio', 'passive'])];
        $quadrantTotal = array_sum($quadrant);

        return [
            'from' => $to->copy()->subMonthsNoOverflow($bankMonths - 1)->startOfMonth(),
            'to' => $to,
            'months' => $bankMonths,
            'manual_only' => $manualOnly,
            'income' => $income,
            'expenses' => ['total' => $expenses, 'rows' => $expenseRows],
            'payments' => round($payments, 2),
            'cashflow' => round($income['total'] - $expenses, 2),
            'asset_income' => $assetIncome,
            'freedom' => $freedom,
            'gap' => round(max(0, $expenses - $assetIncome), 2),
            ...$balance,
            'net_worth' => [
                'rich_dad' => round($balance['assets']['total'] - $balance['liabilities']['total'], 2),
                'classic' => round($balance['assets']['total'] + $balance['doodads']['total'] - $balance['liabilities']['total'], 2),
            ],
            'wealth_days' => $expenses > 0 ? round($assetIncome / $expenses * 30, 1) : null,
            'reserve_months' => $expenses > 0 ? round($balance['liquid'] / $expenses, 1) : null,
            'quadrant' => collect($quadrant)->map(fn (float $value) => ['amount' => $value, 'share' => $quadrantTotal > 0 ? (int) round($value / $quadrantTotal * 100) : 0])->all(),
            'manual' => $manual,
            'pattern' => self::pattern($freedom, $assetIncome, $income['total'] - $expenses, $balance['assets']['total'], $balance['liabilities']['total']),
            'has_data' => $transactions->isNotEmpty() || $manual->isNotEmpty() || $assetIncome > 0 || $balance['assets']['total'] > 0 || $balance['liabilities']['total'] > 0,
        ];
    }

    /**
     * Month by month for the last months: income (including business profit), expenses, income from
     * assets (dividends, net cold rent of the tenants living there), cash flow and the freedom share in
     * percent. Months without bank transactions are left out; the member's own monthly lines are added.
     *
     * @return list<array{month: string, label: string, income: float, expenses: float, asset_income: float, cashflow: float, freedom: float}>
     */
    public static function history(User $user, int $months = self::MONTHS): array
    {
        $household = $user->currentHousehold();

        if (! $household || ! $user->canUseAddon(Addons::FINANCE) || $user->cashflow_manual_only) {
            return [];
        }

        $manual = CashflowEntry::where('user_id', $user->id)->get();
        $manualAssetIncome = (float) $manual->filter(fn (CashflowEntry $entry) => ($entry->kind === CashflowEntry::ASSET && $entry->productive) || ($entry->kind === CashflowEntry::INCOME && in_array($entry->category, ['portfolio', 'passive', 'business'], true)))->sum('amount');
        $manualIncome = (float) $manual->where('kind', CashflowEntry::INCOME)->whereNotIn('category', ['portfolio', 'passive', 'business'])->sum('amount');
        $manualExpenses = (float) $manual->whereIn('kind', [CashflowEntry::EXPENSE, CashflowEntry::LIABILITY])->sum('amount');
        $to = today();
        $from = $to->copy()->subMonthsNoOverflow($months - 1)->startOfMonth();
        $transactions = Transaction::where('user_id', $user->id)->where('household_id', $household->id)->whereBetween('booked_on', [$from, $to])
            ->where('category', '!=', FinanceCategories::TRANSFER)->get();

        if ($transactions->isEmpty()) {
            return [];
        }

        $tenants = RealEstateLink::properties($user, $household->id)->load('tenants')
            ->flatMap(fn (RealEstate $realEstate) => $realEstate->isLet() ? $realEstate->tenants : []);
        $references = $tenants->map(fn (RealEstateTenant $tenant) => mb_strtolower($tenant->reference()))->filter(fn (string $reference) => mb_strlen($reference) >= 3);
        $isRent = fn (Transaction $transaction) => $references->contains(fn (string $reference) => str_contains(mb_strtolower($transaction->counterparty.' '.$transaction->purpose), $reference));

        $dividends = $user->canUseAddon(Addons::PORTFOLIO)
            ? PortfolioTransaction::whereIn('portfolio_id', Portfolio::where('user_id', $user->id)->select('id'))->where('type', 'dividend')->whereBetween('executed_on', [$from, $to])->get()
            : new Collection;

        $business = [];

        if (Addons::allowed($user, Addons::BUSINESS) && ($profile = BusinessProfile::where('user_id', $user->id)->first())) {
            for ($year = $from->year; $year <= $to->year; $year++) {
                foreach (EuerReport::build($user, $household->id, $year, $profile)['monthly'] as $index => $month) {
                    $business[sprintf('%04d-%02d', $year, $index + 1)] = $month['income'] - $month['expenses'];
                }
            }
        }

        $rows = [];

        for ($month = $from->copy(); $month->lte($to); $month->addMonth()) {
            $key = $month->format('Y-m');
            $inMonth = $transactions->filter(fn (Transaction $transaction) => $transaction->booked_on->format('Y-m') === $key);

            if ($inMonth->isEmpty()) {
                continue;
            }

            $income = (float) $inMonth->filter(fn (Transaction $transaction) => $transaction->amount > 0 && $transaction->category !== 'refunds' && ! $isRent($transaction))->sum('amount')
                + (float) ($business[$key] ?? 0) + $manualIncome;
            $expenses = max(0.0, (float) -$inMonth->filter(fn (Transaction $transaction) => $transaction->amount < 0 && $transaction->category !== 'savings')->sum('amount') - (float) $inMonth->where('category', 'refunds')->sum('amount')) + $manualExpenses;
            $assetIncome = (float) $dividends->filter(fn (PortfolioTransaction $transaction) => $transaction->executed_on->format('Y-m') === $key)->sum(fn (PortfolioTransaction $transaction) => $transaction->cashFlow())
                + (float) $tenants->filter(fn (RealEstateTenant $tenant) => $tenant->livesInMonth($month))->sum('cold_rent')
                + $manualAssetIncome;

            $rows[] = [
                'month' => $key,
                'label' => $month->translatedFormat('M y'),
                'income' => round($income + $assetIncome, 2),
                'expenses' => round($expenses, 2),
                'asset_income' => round($assetIncome, 2),
                'cashflow' => round($income + $assetIncome - $expenses, 2),
                'freedom' => $expenses > 0 ? round($assetIncome / $expenses * 100, 1) : 0.0,
            ];
        }

        return $rows;
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
     * Assets, liabilities (debt that financed an asset that pays counts as "good debt") and the
     * lifestyle items Kiyosaki calls doodads; "liquid" is money available at short notice.
     *
     * @param  Collection<int, RealEstate>  $properties
     * @param  Collection<int, CashflowEntry>  $manual
     * @return array{assets: array{total: float, rows: list<array{label: string, value: float, url: ?string, id: ?int}>}, liabilities: array{total: float, rows: list<array{label: string, value: float, url: ?string, id: ?int, good: bool}>}, doodads: array{total: float, rows: list<array{label: string, value: float, url: ?string, id: ?int}>}, liquid: float, good_debt: float}
     */
    private static function balanceSheet(User $user, ?int $householdId, Collection $properties, Collection $manual): array
    {
        $assets = [];
        $liabilities = [];
        $doodads = [];
        $liquid = 0.0;
        $automatic = $householdId !== null;

        if ($automatic && $user->canUseAddon(Addons::FINANCE)) {
            $cash = (float) BankAccount::where('user_id', $user->id)->where('household_id', $householdId)->get()->sum(fn (BankAccount $account) => (float) $account->currentBalance());
            $assets[] = ['label' => __('Bank accounts'), 'value' => round($cash, 2), 'url' => route('net-worth.index'), 'id' => null];
            $liquid += max(0, $cash);
        }

        if ($automatic && $user->canUseAddon(Addons::PORTFOLIO)) {
            $value = (float) Portfolio::where('user_id', $user->id)->with(['snapshots' => fn ($query) => $query->latest('taken_at')->limit(1)])->get()
                ->sum(fn (Portfolio $portfolio) => (float) $portfolio->snapshots->first()?->total_value);
            $assets[] = ['label' => __('Securities'), 'value' => round($value, 2), 'url' => route('portfolio.index'), 'id' => null];
        }

        foreach ($properties as $realEstate) {
            $value = (float) ($realEstate->valuations->sortByDesc('valued_on')->first()?->value ?? $realEstate->purchase_price);
            $share = $realEstate->rentalShare() / 100;
            $url = route('real-estate.show', ['realEstate' => $realEstate->id, 'tab' => 'value']);

            if ($share > 0) {
                $assets[] = ['label' => $realEstate->name.($share < 1 ? ' ('.__('let part').')' : ''), 'value' => round($value * $share, 2), 'url' => $url, 'id' => null];
            }

            if ($share < 1) {
                $doodads[] = ['label' => $realEstate->name.' ('.__('lived in yourself').')', 'value' => round($value * (1 - $share), 2), 'url' => $url, 'id' => null];
            }

            foreach ($realEstate->loans as $loan) {
                $liabilities[] = ['label' => trim(($loan->lender ?: __('Loan')).' · '.$realEstate->name), 'value' => self::remainingDebt($loan, $realEstate), 'url' => route('real-estate.show', ['realEstate' => $realEstate->id, 'tab' => 'purchase']), 'id' => null, 'good' => $share > 0];
            }
        }

        if ($automatic) {
            foreach (Vehicle::where('household_id', $householdId)->where('created_by', $user->id)->get() as $vehicle) {
                $doodads[] = ['label' => $vehicle->name.' ('.__('vehicle').')', 'value' => (float) ($vehicle->current_value ?? $vehicle->purchase_price), 'url' => route('vehicles.show', $vehicle), 'id' => null];
            }

            foreach (NetWorthItem::where('user_id', $user->id)->orderBy('position')->get() as $item) {
                $row = ['label' => $item->name, 'value' => (float) $item->value, 'url' => route('net-worth.index'), 'id' => null];

                if ($item->isLiability()) {
                    $liabilities[] = $row + ['good' => false];
                } else {
                    $assets[] = $row;
                }
            }
        }

        foreach ($manual as $entry) {
            $row = ['label' => $entry->label, 'value' => (float) $entry->value, 'url' => null, 'id' => $entry->id];

            if ($entry->kind === CashflowEntry::ASSET) {
                if ($entry->productive) {
                    $assets[] = $row;
                } else {
                    $doodads[] = $row;
                }

                $liquid += $entry->category === 'cash' ? (float) $entry->value : 0;
            } elseif ($entry->kind === CashflowEntry::LIABILITY) {
                $liabilities[] = $row + ['good' => $entry->productive];
            }
        }

        $wrap = fn (array $rows) => ['total' => round(collect($rows)->sum('value'), 2), 'rows' => array_values(array_filter($rows, fn (array $row) => $row['value'] != 0))];

        return [
            'assets' => $wrap($assets),
            'liabilities' => $wrap($liabilities),
            'doodads' => $wrap($doodads),
            'liquid' => round($liquid, 2),
            'good_debt' => round((float) collect($liabilities)->where('good', true)->sum('value'), 2),
        ];
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
