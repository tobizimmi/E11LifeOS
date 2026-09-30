<?php

namespace App\Support\Portfolio;

use App\Models\Item;
use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioRecommendation;
use App\Models\PortfolioSnapshot;
use App\Models\PortfolioTransaction;
use App\Models\SavingsPlan;
use App\Models\Security;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Support\Addons;
use App\Support\Links;
use App\Support\QuickCapture;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Brings the portfolio into the rest of LifeOS: dashboard card, calendar, search, tasks and the
 * Second Brain AI. Only stored data is read (no market requests), so pages stay fast.
 */
class PortfolioLink
{
    /**
     * Words that make a Second Brain question about the portfolio.
     */
    private const QUESTION_WORDS = ['depot', 'portfolio', 'aktie', 'wertpapier', 'etf', 'dividend', 'börse', 'invest', 'watchlist', 'sparplan', 'kurs', 'rendite', 'gewinn', 'verlust', 'empfehl'];

    public static function enabledFor(User $user): bool
    {
        return $user->canUseAddon(Addons::PORTFOLIO);
    }

    /**
     * Value from the latest snapshots (updated every 15 minutes) and today's biggest movers.
     *
     * @return array{value: float, cost: float, gain: float, gain_percent: ?float, day_change: ?float, day_change_percent: ?float, movers: list<array{symbol: string, name: string, change: float}>}|null
     */
    public static function summary(User $user): ?array
    {
        $portfolioIds = Portfolio::where('user_id', $user->id)->pluck('id');

        if ($portfolioIds->isEmpty()) {
            return null;
        }

        $value = 0.0;
        $cost = 0.0;
        $yesterday = null;

        foreach ($portfolioIds as $portfolioId) {
            $latest = PortfolioSnapshot::where('portfolio_id', $portfolioId)->latest('taken_at')->first();

            if (! $latest) {
                continue;
            }

            $value += $latest->total_value;
            $cost += $latest->total_cost;
            $previous = PortfolioSnapshot::where('portfolio_id', $portfolioId)->where('taken_at', '<', today())->latest('taken_at')->first();

            if ($previous) {
                // Money added today is not a gain: compare against yesterday's value plus new cost.
                $yesterday = ($yesterday ?? 0.0) + $previous->total_value + ($latest->total_cost - $previous->total_cost);
            }
        }

        $symbols = PortfolioPosition::whereIn('portfolio_id', $portfolioIds)->where('quantity', '>', 0)->pluck('name', 'symbol');
        $movers = Security::whereIn('symbol', $symbols->keys())->get()
            ->map(fn (Security $security) => ['symbol' => $security->symbol, 'name' => $symbols[$security->symbol] ?: $security->name ?: $security->symbol, 'change' => $security->changePercent()])
            ->filter(fn (array $row) => $row['change'] !== null)
            ->sortByDesc(fn (array $row) => abs($row['change']))
            ->take(3)->values()->all();

        $dayChange = $yesterday !== null ? round($value - $yesterday, 2) : null;

        return [
            'value' => round($value, 2),
            'cost' => round($cost, 2),
            'gain' => round($value - $cost, 2),
            'gain_percent' => $cost > 0 ? round(($value - $cost) / $cost * 100, 2) : null,
            'day_change' => $dayChange,
            'day_change_percent' => $dayChange !== null && $yesterday > 0 ? round($dayChange / $yesterday * 100, 2) : null,
            'movers' => $movers,
        ];
    }

    /**
     * Savings plan executions and dividends expected from last year's payments of held positions.
     *
     * @return list<array{date: string, kind: string, title: string, amount: ?float, symbol: string, uid: string}>
     */
    public static function events(User $user, Carbon $from, Carbon $until): array
    {
        $portfolioIds = Portfolio::where('user_id', $user->id)->pluck('id');
        $events = [];

        SavingsPlan::whereIn('portfolio_id', $portfolioIds)->where('active', true)->whereNotNull('next_date')->get()
            ->each(function (SavingsPlan $plan) use ($from, $until, &$events) {
                $date = $plan->next_date->copy();

                for ($i = 0; $i < 60 && $date->lte($until); $i++, $date = SavingsPlans::nextDate($plan, $date)) {
                    if ($date->gte($from)) {
                        $events[] = ['date' => $date->toDateString(), 'kind' => 'savings', 'title' => __('Savings plan: :name', ['name' => $plan->name ?: $plan->symbol]), 'amount' => $plan->amount, 'symbol' => $plan->symbol, 'uid' => 'savings-'.$plan->id.'-'.$date->format('Ymd')];
                    }
                }
            });

        $held = PortfolioPosition::whereIn('portfolio_id', $portfolioIds)->where('quantity', '>', 0)->pluck('symbol')->unique()->all();

        PortfolioTransaction::whereIn('portfolio_id', $portfolioIds)->where('type', 'dividend')->whereIn('symbol', $held)
            ->where('executed_on', '>=', $from->copy()->subYear()->toDateString())
            ->where('executed_on', '<=', $until->copy()->subYear()->toDateString())
            ->get()
            ->each(function (PortfolioTransaction $dividend) use (&$events) {
                $date = $dividend->executed_on->copy()->addYear();

                if ($date->lt(today())) {
                    return;
                }

                $events[] = ['date' => $date->toDateString(), 'kind' => 'dividend', 'title' => __('Expected dividend: :name', ['name' => $dividend->name ?: $dividend->symbol]), 'amount' => $dividend->cashFlow(), 'symbol' => $dividend->symbol, 'uid' => 'dividend-'.$dividend->id];
            });

        usort($events, fn (array $a, array $b) => [$a['date'], $a['title']] <=> [$b['date'], $b['title']]);

        return $events;
    }

    /**
     * Held, watched and known securities matching the search term.
     *
     * @return list<array{label: string, url: string, private: bool}>
     */
    public static function search(User $user, string $term, int $limit = 5): array
    {
        $like = '%'.mb_strtolower($term).'%';
        $held = PortfolioPosition::whereIn('portfolio_id', Portfolio::where('user_id', $user->id)->select('id'))->pluck('symbol');
        $watched = WatchlistItem::where('user_id', $user->id)->pluck('symbol');
        $own = $held->merge($watched)->unique();

        return Security::query()
            ->where(fn ($query) => $query->whereRaw('LOWER(symbol) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(isin) LIKE ?', [$like]))
            ->get()
            ->sortBy(fn (Security $security) => [$held->contains($security->symbol) ? 0 : ($watched->contains($security->symbol) ? 1 : 2), $security->name])
            ->take($limit)
            ->map(fn (Security $security) => [
                'label' => ($held->contains($security->symbol) ? '📈 ' : ($watched->contains($security->symbol) ? '⭐ ' : '🔎 ')).($security->name ?: $security->symbol).' · '.$security->symbol,
                'url' => route('portfolio.security', ['symbol' => $security->symbol]),
                'private' => $own->contains($security->symbol),
            ])
            ->values()->all();
    }

    /**
     * Pages, tasks and entries that mention the security by symbol or name.
     *
     * @return list<array<string, mixed>>
     */
    public static function mentions(User $user, int $householdId, string $symbol, ?string $name): array
    {
        $terms = array_values(array_filter([$symbol, $name && mb_strlen($name) >= 3 ? $name : null]));
        $items = collect();

        foreach ($terms as $term) {
            $items = $items->concat(Item::query()->forHousehold($householdId)->visibleTo($user)->where('type', '!=', Item::TYPE_INBOX)->matching($term)->with('collection')->latest('updated_at')->limit(8)->get());
        }

        return $items->unique('id')->take(8)->map(fn (Item $item) => Links::itemNode($item))->values()->all();
    }

    /**
     * A private task about a security (tag #portfolio, link to the security page in the note).
     */
    public static function createTask(User $user, int $householdId, string $symbol, string $title, ?string $due = null): ?Item
    {
        $security = Security::firstWhere('symbol', strtoupper($symbol));
        $name = $security?->name ?: strtoupper($symbol);
        $task = QuickCapture::task($user, $householdId, $title !== '' ? $title : __('Check :name', ['name' => $name]), $due, extraTags: ['portfolio'], private: true);

        $task?->update(['content' => ['body' => $name.' ('.strtoupper($symbol).'): '.route('portfolio.security', ['symbol' => strtoupper($symbol)])]]);

        return $task;
    }

    /**
     * The portfolio as a text document for the Second Brain, when the question is about it.
     *
     * @return array{key: string, kind: string, title: string, text: string}|null
     */
    public static function brainDocument(User $user, string $question): ?array
    {
        $question = mb_strtolower($question);
        $positions = PortfolioPosition::whereIn('portfolio_id', Portfolio::where('user_id', $user->id)->select('id'))->where('quantity', '>', 0)->with(['portfolio', 'security'])->get();
        $watchlist = WatchlistItem::where('user_id', $user->id)->with('security')->get();
        $mentioned = $positions->concat($watchlist)->contains(fn ($row) => str_contains($question, mb_strtolower($row->symbol)) || ($row->name && str_contains($question, mb_strtolower(Str::before($row->name, ' ')))));

        if (! $mentioned && ! Str::contains($question, self::QUESTION_WORDS)) {
            return null;
        }

        $lines = [];
        $summary = self::summary($user);

        if ($summary) {
            $lines[] = sprintf('Depotwert gesamt: %.2f EUR, investiert %.2f EUR, Gewinn %.2f EUR (%s %%)', $summary['value'], $summary['cost'], $summary['gain'], $summary['gain_percent'] ?? '–');
        }

        foreach ($positions as $position) {
            $price = $position->security?->last_price;
            $lines[] = sprintf('Position im Depot "%s": %s (%s), %s Stück, Einstand %.2f %s%s', $position->portfolio->name, $position->name ?: $position->symbol, $position->symbol, rtrim(rtrim(number_format($position->quantity, 4, '.', ''), '0'), '.'), $position->avg_buy_price, $position->currency, $price ? sprintf(', aktueller Kurs %.2f %s', $price, $position->security->currency) : '');
        }

        foreach ($watchlist as $item) {
            $lines[] = sprintf('Watchlist: %s (%s)%s', $item->name ?: $item->symbol, $item->symbol, $item->security?->last_price ? sprintf(', Kurs %.2f %s', $item->security->last_price, $item->security->currency) : '');
        }

        PortfolioRecommendation::where('user_id', $user->id)->orderByDesc('score')->limit(5)->get()
            ->each(function (PortfolioRecommendation $recommendation) use (&$lines) {
                $lines[] = sprintf('Kaufempfehlung: %s (%s), %.1f %% unter Hoch, Bewertung %s', $recommendation->name, $recommendation->symbol, $recommendation->drop_percent, $recommendation->rating);
            });

        foreach (array_slice(self::events($user, today(), today()->addDays(90)), 0, 10) as $event) {
            $lines[] = sprintf('Termin %s: %s%s', $event['date'], $event['title'], $event['amount'] ? sprintf(' (%.2f EUR)', $event['amount']) : '');
        }

        return $lines === [] ? null : ['key' => 'portfolio:overview', 'kind' => __('Portfolio'), 'title' => __('Portfolio'), 'text' => Str::limit(implode("\n", $lines), 4000)];
    }
}
