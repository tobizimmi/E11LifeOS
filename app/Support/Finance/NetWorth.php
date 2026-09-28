<?php

namespace App\Support\Finance;

use App\Models\BankAccount;
use App\Models\NetWorthItem;
use App\Models\NetWorthSnapshot;
use App\Models\Portfolio;
use App\Models\RealEstate;
use App\Models\RealEstateLoan;
use App\Models\User;
use App\Support\Addons;
use App\Support\RealEstate\RealEstateLink;

/**
 * Net worth of a member across the modules: bank accounts with an entered balance, portfolios
 * (last valuation), properties (last valuation or purchase price) minus their loans, and assets
 * or debts entered by hand.
 */
class NetWorth
{
    /**
     * @return array{assets: float, liabilities: float, net: float, groups: list<array{key: string, label: string, icon: string, liability: bool, total: float, rows: list<array{id?: int, label: string, value: ?float, hint: ?string, url: ?string}>}>}
     */
    public static function for(User $user): array
    {
        $household = $user->currentHousehold();
        $groups = [];

        if ($household && $user->canUseAddon(Addons::FINANCE)) {
            $rows = BankAccount::where('user_id', $user->id)->where('household_id', $household->id)->orderBy('name')->get()
                ->map(fn (BankAccount $account) => ['id' => $account->id, 'label' => $account->name, 'value' => $account->currentBalance(), 'hint' => $account->balance === null ? __('No balance entered yet') : null, 'url' => route('finance.index')])
                ->all();
            $groups[] = self::group('accounts', __('Bank accounts'), '🏦', $rows);
        }

        if ($user->canUseAddon(Addons::PORTFOLIO)) {
            $rows = Portfolio::where('user_id', $user->id)->with(['snapshots' => fn ($query) => $query->latest('taken_at')->limit(1)])->orderBy('name')->get()
                ->map(fn (Portfolio $portfolio) => ['label' => $portfolio->name, 'value' => $portfolio->snapshots->first()?->total_value, 'hint' => $portfolio->snapshots->first()?->taken_at?->translatedFormat('j. M Y'), 'url' => route('portfolio.index')])
                ->all();
            $groups[] = self::group('portfolio', __('Portfolio'), '📈', $rows);
        }

        if ($household) {
            $properties = RealEstateLink::properties($user, $household->id)->load(['valuations', 'loans']);
            $groups[] = self::group('real_estate', __('Real estate'), '🏠', $properties->map(fn (RealEstate $realEstate) => [
                'label' => $realEstate->name,
                'value' => ($valuation = $realEstate->valuations->sortByDesc('valued_on')->first())?->value ?? $realEstate->purchase_price,
                'hint' => $valuation ? __('Valuation of :date', ['date' => $valuation->valued_on->translatedFormat('j. M Y')]) : __('Purchase price'),
                'url' => route('real-estate.show', ['realEstate' => $realEstate->id, 'tab' => 'value']),
            ])->all());
            $groups[] = self::group('loans', __('Property loans'), '🏦', $properties->flatMap(fn (RealEstate $realEstate) => $realEstate->loans->map(fn (RealEstateLoan $loan) => [
                'label' => trim(($loan->lender ?: __('Loan')).' · '.$realEstate->name),
                'value' => self::remainingDebt($loan, $realEstate),
                'hint' => null,
                'url' => route('real-estate.show', ['realEstate' => $realEstate->id, 'tab' => 'purchase']),
            ]))->all(), liability: true);
        }

        $items = NetWorthItem::where('user_id', $user->id)->orderBy('position')->orderBy('id')->get();
        $groups[] = self::group('other_assets', __('Other assets'), '💎', $items->reject->isLiability()->map(fn (NetWorthItem $item) => ['label' => $item->name, 'value' => $item->value, 'hint' => null, 'url' => null, 'id' => $item->id])->values()->all());
        $groups[] = self::group('other_liabilities', __('Other debts'), '📉', $items->filter->isLiability()->map(fn (NetWorthItem $item) => ['label' => $item->name, 'value' => $item->value, 'hint' => null, 'url' => null, 'id' => $item->id])->values()->all(), liability: true);

        $assets = round(collect($groups)->reject(fn (array $group) => $group['liability'])->sum('total'), 2);
        $liabilities = round(collect($groups)->filter(fn (array $group) => $group['liability'])->sum('total'), 2);

        return ['assets' => $assets, 'liabilities' => $liabilities, 'net' => round($assets - $liabilities, 2), 'groups' => $groups];
    }

    /**
     * Stores (or updates) today's value for the history.
     */
    public static function snapshot(User $user): NetWorthSnapshot
    {
        $netWorth = self::for($user);

        $snapshot = NetWorthSnapshot::where('user_id', $user->id)->whereDate('taken_on', today())->first()
            ?? new NetWorthSnapshot(['user_id' => $user->id, 'taken_on' => today()]);

        $snapshot->fill(['assets' => $netWorth['assets'], 'liabilities' => $netWorth['liabilities'], 'breakdown' => collect($netWorth['groups'])->mapWithKeys(fn (array $group) => [$group['key'] => $group['total']])->all()])->save();

        return $snapshot;
    }

    /**
     * Debt left today: repayment starts with the loan (or the purchase); without a date the full amount.
     */
    private static function remainingDebt(RealEstateLoan $loan, RealEstate $realEstate): float
    {
        $start = $loan->start_on ?? $realEstate->purchase_date;

        if (! $start || $start->isFuture()) {
            return (float) $loan->amount;
        }

        return $loan->after((int) $start->diffInMonths(today()))['balance'];
    }

    /**
     * @param  list<array{label: string, value: ?float, hint: ?string, url: ?string}>  $rows
     * @return array{key: string, label: string, icon: string, liability: bool, total: float, rows: list<array<string, mixed>>}
     */
    private static function group(string $key, string $label, string $icon, array $rows, bool $liability = false): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'liability' => $liability,
            'total' => round(collect($rows)->sum(fn (array $row) => (float) ($row['value'] ?? 0)), 2),
            'rows' => $rows,
        ];
    }
}
