<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Habit;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\ShoppingListEntry;
use App\Models\SmartHomeEntity;
use App\Models\User;
use App\Support\DashboardWidgets;
use App\Support\Finance\CashflowStatement;
use App\Support\Finance\TaxYear;
use App\Support\Life\HabitTracker;
use App\Support\Life\LifeDates;
use App\Support\Portfolio\PortfolioLink;
use App\Support\RealEstate\RealEstateLink;
use App\Support\RealEstate\RealEstatePlan;
use App\Support\SmartHome\EnergyRecorder;
use App\Support\Weather;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The web dashboard's cards for the app. Every card has the same shape (a headline value and
 * rows), already formatted, so the app shows them all the same way.
 */
class DashboardController extends ApiController
{
    public function index(): JsonResponse
    {
        $user = $this->user();
        $householdId = ($user->currentHousehold() ?? abort(404, __('You are not a member of a household yet.')))->id;
        $chosen = DashboardWidgets::forUser($user);
        $all = DashboardWidgets::all();
        $available = array_values(array_filter(array_keys($all), fn (string $key) => $key !== 'smart_home_board' && DashboardWidgets::allowedFor($user, $key)));

        // Chosen cards in the member's order, then the others (switched off).
        $order = array_values(array_unique(array_merge(array_values(array_intersect($chosen, $available)), $available)));
        $widgets = [];
        $cards = [];

        foreach ($order as $key) {
            $visible = in_array($key, $chosen, true);
            $widgets[] = ['key' => $key, 'label' => $all[$key]['label'], 'icon' => $all[$key]['icon'], 'visible' => $visible];

            if ($visible) {
                $card = rescue(fn () => $this->card($key, $user, $householdId), null);

                if ($card !== null) {
                    $cards[] = ['key' => $key, 'title' => $all[$key]['label'], 'icon' => $all[$key]['icon']] + $card;
                }
            }
        }

        return $this->ok(['widgets' => $widgets, 'cards' => $cards]);
    }

    /**
     * Which cards are shown, in this order.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'widgets' => ['present', 'array', 'max:40'],
            'widgets.*' => ['string', 'in:'.implode(',', array_keys(DashboardWidgets::all()))],
        ]);
        $user = $this->user();
        // Cards the app does not offer keep their setting.
        $kept = array_values(array_intersect(DashboardWidgets::forUser($user), ['smart_home_board']));
        $user->forceFill(['dashboard_widgets' => array_values(array_unique(array_merge($data['widgets'], $kept)))])->save();

        return $this->ok();
    }

    /**
     * @return array{value: ?string, subtitle: ?string, tone: ?string, rows: list<array{title: string, detail: ?string, tone: ?string}>, link: ?string, empty: ?string}|null
     */
    private function card(string $key, User $user, int $householdId): ?array
    {
        return match ($key) {
            'weather' => $this->weather($user),
            'deadlines' => $this->build(link: '/collections', empty: __('Nothing due in the next 30 days.'), rows: array_map(
                fn (array $row) => $this->row($row['entry']->title, $this->day($row['date']), $row['days'] < 0 ? 'bad' : ($row['days'] <= 7 ? 'warn' : null)),
                DashboardWidgets::deadlines($user, $householdId),
            )),
            'life' => $this->build(link: '/provision', empty: __('No dates in the next 60 days – documents, vehicles and maintenance are all fine.'), rows: array_map(
                fn (array $event) => $this->row($event['title'], $this->day($event['date'])),
                array_slice(LifeDates::between($user, today(), today()->addDays(60)), 0, 6),
            )),
            'tasks' => null,
            'habits' => $this->habits($user),
            'shopping' => $this->shopping($householdId),
            'meals' => $this->build(link: '/meal-plan', empty: __('Nothing planned for today.'), rows: array_values(
                MealPlan::withVisibleRecipes(MealPlan::query()->visibleTo($user, $householdId)->whereDate('date', today())->with('recipe')->get(), $user, $householdId)
                    ->map(fn (MealPlan $meal) => $this->row((string) ($meal->recipe->title ?? $meal->title), __(ucfirst((string) $meal->meal_type))))->all()
            )),
            'portfolio' => $this->portfolio($user),
            'real_estate' => $this->realEstate($user, $householdId),
            'tax' => $this->tax($user, $householdId),
            'costs' => $this->costs($user, $householdId),
            'freedom' => $this->freedom($user),
            'pages' => $this->build(link: '/pages', rows: array_values(
                Item::query()->forHousehold($householdId)->ofType(Item::TYPE_NOTE)->visibleTo($user)->latest('updated_at')->limit(5)->get()
                    ->map(fn (Item $note) => $this->row((string) $note->title, $this->day((string) $note->updated_at?->toDateString())))->all()
            )),
            'inbox' => $this->inbox($user, $householdId),
            'energy' => $this->energy($householdId),
            'smart_home' => $this->build(link: '/smart-home', empty: __('Mark devices with ★ under Smart home › Connections & devices.'), rows: array_values(
                SmartHomeEntity::where('household_id', $householdId)->where('favorite', true)->orderBy('position')->limit(8)->get()
                    ->map(fn (SmartHomeEntity $device) => $this->row($device->icon().' '.$device->name, $device->isOn() ? __('On') : __('Off'), $device->isOn() ? 'good' : null))->all()
            )),
            default => null,
        };
    }

    /**
     * @param  array<int, array{title: string, detail: ?string, tone: ?string}>  $rows
     * @return array{value: ?string, subtitle: ?string, tone: ?string, rows: list<array{title: string, detail: ?string, tone: ?string}>, link: ?string, empty: ?string}
     */
    private function build(?string $value = null, ?string $subtitle = null, ?string $tone = null, array $rows = [], ?string $link = null, ?string $empty = null): array
    {
        return ['value' => $value, 'subtitle' => $subtitle, 'tone' => $tone, 'rows' => array_values($rows), 'link' => $link, 'empty' => $empty];
    }

    /**
     * @return array{title: string, detail: ?string, tone: ?string}
     */
    private function row(string $title, ?string $detail = null, ?string $tone = null): array
    {
        return ['title' => $title, 'detail' => $detail, 'tone' => $tone];
    }

    private function day(string $date): string
    {
        return $date === '' ? '' : Carbon::parse($date)->format('d.m.');
    }

    private function euro(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, ',', '.').' €';
    }

    private function signed(float $value): string
    {
        return ($value >= 0 ? '+' : '').$this->euro($value);
    }

    private function tone(?float $value): ?string
    {
        return $value === null || $value == 0.0 ? null : ($value > 0 ? 'good' : 'bad');
    }

    /**
     * @return array{value: ?string, subtitle: ?string, tone: ?string, rows: list<array{title: string, detail: ?string, tone: ?string}>, link: ?string, empty: ?string}|null
     */
    private function weather(User $user): ?array
    {
        if ($user->weather_latitude === null || $user->weather_longitude === null) {
            return $this->build(link: '/preferences', empty: __('Set a place for the weather under Settings.'));
        }

        $weather = Weather::today($user->weather_latitude, $user->weather_longitude);

        return $weather ? $this->build(
            value: $weather['icon'].' '.$weather['max'].'°',
            subtitle: $weather['label'].' · '.$weather['min'].'–'.$weather['max'].' °C · '.$weather['rain'].' % '.__('rain'),
        ) : null;
    }

    /**
     * @return array{value: ?string, subtitle: ?string, tone: ?string, rows: list<array{title: string, detail: ?string, tone: ?string}>, link: ?string, empty: ?string}
     */
    private function habits(User $user): array
    {
        $habits = HabitTracker::active($user)->filter(fn (Habit $habit) => $habit->isDueOn(today()))->values();
        $logs = HabitTracker::logs($habits);
        $done = 0;
        $rows = [];

        foreach ($habits as $habit) {
            $fulfilled = $habit->isFulfilledBy($logs[$habit->id][today()->toDateString()] ?? 0);
            $done += $fulfilled ? 1 : 0;
            $streak = HabitTracker::stats($habit, $logs[$habit->id] ?? [])['streak'];
            $rows[] = $this->row(($fulfilled ? '✓ ' : '○ ').$habit->name, $streak > 0 ? '🔥 '.$streak : null, $fulfilled ? 'good' : null);
        }

        return $this->build(value: $rows === [] ? null : $done.' / '.count($rows), rows: $rows, link: '/habits', empty: __('No habits for today yet.'));
    }

    /**
     * @return array{value: ?string, subtitle: ?string, tone: ?string, rows: list<array{title: string, detail: ?string, tone: ?string}>, link: ?string, empty: ?string}
     */
    private function shopping(int $householdId): array
    {
        $open = ShoppingListEntry::where('household_id', $householdId)->where('checked', false);
        $count = (clone $open)->count();

        return $this->build(
            value: $count > 0 ? (string) $count : null,
            rows: array_values($open->latest()->limit(5)->get()->map(fn (ShoppingListEntry $entry) => $this->row((string) $entry->label))->all()),
            link: '/(tabs)/shopping',
            empty: __('The shopping list is empty.'),
        );
    }

    /**
     * @return array{value: ?string, subtitle: ?string, tone: ?string, rows: list<array{title: string, detail: ?string, tone: ?string}>, link: ?string, empty: ?string}
     */
    private function portfolio(User $user): array
    {
        $summary = PortfolioLink::summary($user);

        if ($summary === null) {
            return $this->build(link: '/portfolio', empty: __('Create a portfolio to see its value here.'));
        }

        $parts = [__('Total').' '.$this->signed($summary['gain'])];

        if ($summary['day_change'] !== null) {
            array_unshift($parts, __('Today').' '.$this->signed($summary['day_change']));
        }

        $rows = array_map(
            fn (array $mover) => $this->row($mover['name'], ($mover['change'] >= 0 ? '+' : '').number_format($mover['change'], 2, ',', '.').' %', $this->tone($mover['change'])),
            $summary['movers'],
        );

        foreach (array_slice(PortfolioLink::events($user, today(), today()->addDays(30)), 0, 3) as $event) {
            $rows[] = $this->row(($event['kind'] === 'dividend' ? '💰 ' : '📈 ').$event['title'], $this->day($event['date']));
        }

        return $this->build(value: $this->euro($summary['value'], 2), subtitle: implode(' · ', $parts), tone: $this->tone($summary['day_change']), rows: $rows, link: '/portfolio');
    }

    /**
     * @return array{value: ?string, subtitle: ?string, tone: ?string, rows: list<array{title: string, detail: ?string, tone: ?string}>, link: ?string, empty: ?string}
     */
    private function realEstate(User $user, int $householdId): array
    {
        $rows = [];

        foreach (RealEstateLink::properties($user, $householdId) as $property) {
            $summary = RealEstatePlan::summary($property);
            $gap = $summary['funding']['gap'] ?? 0;
            $rows[] = $this->row(
                '🏠 '.$property->name,
                __(':paid of :total paid', ['paid' => $this->euro((float) $summary['paid_total']), 'total' => $this->euro((float) $summary['expected_total'])]),
                $gap > 0 ? 'bad' : null,
            );
        }

        foreach (array_slice(RealEstateLink::events($user, $householdId, today()->subDays(30), today()->addDays(30)), 0, 3) as $event) {
            $rows[] = $this->row($event['title'], $this->day($event['date']), $event['overdue'] ? 'bad' : null);
        }

        return $this->build(rows: $rows, link: '/real-estate', empty: __('No property yet.'));
    }

    /**
     * @return array{value: ?string, subtitle: ?string, tone: ?string, rows: list<array{title: string, detail: ?string, tone: ?string}>, link: ?string, empty: ?string}
     */
    private function tax(User $user, int $householdId): array
    {
        $tax = TaxYear::build($user, $householdId, (int) now()->year);

        return $this->build(
            value: '≈ '.$this->euro(abs((float) $tax['effect'])),
            subtitle: ($tax['effect'] >= 0 ? __('less income tax (estimate)') : __('more income tax (estimate)')).' · '.trans_choice(':count tax-relevant booking|:count tax-relevant bookings', $tax['bookings']->count()),
            tone: $tax['effect'] >= 0 ? 'good' : 'bad',
            link: '/finance/tax',
        );
    }

    /**
     * @return array{value: ?string, subtitle: ?string, tone: ?string, rows: list<array{title: string, detail: ?string, tone: ?string}>, link: ?string, empty: ?string}
     */
    private function costs(User $user, int $householdId): array
    {
        $costs = DashboardWidgets::monthlyCosts($user, $householdId);

        return $this->build(
            value: $costs['total'] > 0 ? $this->euro($costs['total'], 2) : null,
            rows: array_map(fn (array $row) => $this->row((string) $row['collection']->name, $this->euro($row['sum'], 2)), $costs['collections']),
            link: '/contracts',
            empty: __('No monthly costs recorded yet.'),
        );
    }

    /**
     * @return array{value: ?string, subtitle: ?string, tone: ?string, rows: list<array{title: string, detail: ?string, tone: ?string}>, link: ?string, empty: ?string}
     */
    private function freedom(User $user): array
    {
        $statement = CashflowStatement::for($user);

        if (! $statement['has_data']) {
            return $this->build(link: '/wealth-plan', empty: __('Import bank transactions in Finances, add your portfolio or properties – the analysis builds itself from them.'));
        }

        return $this->build(
            value: (int) round(min(1, $statement['freedom']) * 100).' %',
            subtitle: __('Monthly cashflow').': '.$this->signed((float) $statement['cashflow']),
            tone: $this->tone((float) $statement['cashflow']),
            link: '/wealth-plan',
        );
    }

    /**
     * @return array{value: ?string, subtitle: ?string, tone: ?string, rows: list<array{title: string, detail: ?string, tone: ?string}>, link: ?string, empty: ?string}
     */
    private function inbox(User $user, int $householdId): array
    {
        $entries = Item::query()->forHousehold($householdId)->ofType(Item::TYPE_INBOX)->visibleTo($user);
        $count = (clone $entries)->count();

        return $this->build(
            value: $count > 0 ? (string) $count : null,
            rows: array_values($entries->latest()->limit(3)->get()->map(fn (Item $item) => $this->row((string) $item->title))->all()),
            link: '/inbox',
            empty: __('The inbox is empty.'),
        );
    }

    /**
     * @return array{value: ?string, subtitle: ?string, tone: ?string, rows: list<array{title: string, detail: ?string, tone: ?string}>, link: ?string, empty: ?string}
     */
    private function energy(int $householdId): array
    {
        $energy = EnergyRecorder::energy($householdId, today());
        $labels = ['pv_kwh' => '☀️ PV', 'home_kwh' => '🏠 '.__('Consumption'), 'import_kwh' => '⬇️ '.__('Grid import'), 'export_kwh' => '⬆️ '.__('Feed-in'), 'charged_kwh' => '🚗 '.__('Charged')];
        $rows = [];

        foreach ($labels as $key => $label) {
            if (($energy[$key] ?? null) !== null) {
                $rows[] = $this->row($label, number_format((float) $energy[$key], 1, ',', '.').' kWh');
            }
        }

        return $this->build(rows: $rows, link: '/smart-home', empty: __('Connect evcc under Smart home to see PV, consumption and charging here.'));
    }
}
