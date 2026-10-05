<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\User;
use App\Support\Modules\ModuleAccess;
use Illuminate\Support\Carbon;

/**
 * The dashboard's widgets: which exist, the user's choice and order, and the data of the
 * cross-collection widgets (deadlines, fixed monthly costs).
 */
class DashboardWidgets
{
    public const DEFAULT = ['weather', 'deadlines', 'life', 'tasks', 'habits', 'shopping', 'meals', 'portfolio', 'real_estate', 'tax', 'freedom', 'costs', 'pages', 'inbox', 'energy', 'smart_home', 'smart_home_board'];

    private const DEADLINE_DAYS = 30;

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function all(): array
    {
        return [
            'weather' => ['label' => __('Weather today'), 'icon' => '🌤️'],
            'deadlines' => ['label' => __('Deadlines (next 30 days)'), 'icon' => '🔔'],
            'tasks' => ['label' => __('Open tasks'), 'icon' => '✅'],
            'habits' => ['label' => __('Habits today'), 'icon' => '🔥'],
            'shopping' => ['label' => __('Shopping List'), 'icon' => '🛒'],
            'meals' => ['label' => __('Today on the meal plan'), 'icon' => '🍳'],
            'portfolio' => ['label' => __('Portfolio'), 'icon' => '📈'],
            'real_estate' => ['label' => __('Real estate'), 'icon' => '🏠'],
            'tax' => ['label' => __('Tax this year'), 'icon' => '🏛'],
            'costs' => ['label' => __('Fixed costs per month'), 'icon' => '💶'],
            'life' => ['label' => __('Life & provisions: next dates'), 'icon' => '🗂️'],
            'freedom' => ['label' => __('Financial freedom'), 'icon' => '🧭'],
            'pages' => ['label' => __('Recent pages'), 'icon' => '📄'],
            'inbox' => ['label' => __('Inbox'), 'icon' => '📥'],
            'energy' => ['label' => __('Energy today'), 'icon' => '⚡'],
            'smart_home' => ['label' => __('Favourite devices'), 'icon' => '💡'],
            'smart_home_board' => ['label' => __('Smart home dashboard'), 'icon' => '🧩'],
        ];
    }

    /**
     * The user's widgets in their order (unknown keys dropped).
     *
     * @return array<int, string>
     */
    public static function forUser(User $user): array
    {
        if (! is_array($user->dashboard_widgets)) {
            return self::DEFAULT;
        }

        // The stored choice and order; cards of areas the member can't use are skipped when shown (allowedFor).
        return array_values(array_unique(array_filter($user->dashboard_widgets, fn ($key) => is_string($key) && array_key_exists($key, self::all()))));
    }

    /**
     * The area a widget shows data from (widgets of locked areas are left out).
     */
    private const MODULES = [
        'habits' => 'journal',
        'shopping' => 'shopping',
        'meals' => Addons::RECIPES,
        'portfolio' => Addons::PORTFOLIO,
        'real_estate' => Addons::REAL_ESTATE,
        'tax' => Addons::FINANCE,
        'freedom' => Addons::FINANCE,
        'energy' => Addons::SMART_HOME,
        'smart_home' => Addons::SMART_HOME,
        'smart_home_board' => Addons::SMART_HOME,
    ];

    public static function allowedFor(User $user, string $widget): bool
    {
        $module = self::MODULES[$widget] ?? null;

        return match (true) {
            $module === null => true,
            array_key_exists($module, Addons::all()) => $user->canUseAddon($module),
            default => ModuleAccess::allows($user, $module),
        };
    }

    /**
     * Upcoming dates with a reminder (cancellation deadlines, best-before …) from all visible collections.
     *
     * @return array<int, array{entry: Item, collection: Collection, property: CollectionProperty, date: string, days: int}>
     */
    public static function deadlines(User $user, int $householdId, int $limit = 8): array
    {
        $today = now()->startOfDay();
        $until = $today->copy()->addDays(self::DEADLINE_DAYS)->toDateString();
        $rows = [];

        Collection::query()->visibleTo($user, $householdId)->whereNull('system_key')->with('properties')->get()
            ->each(function (Collection $collection) use ($user, $today, $until, &$rows) {
                $properties = $collection->properties->filter(fn (CollectionProperty $property) => $property->remindDays() !== null);

                if ($properties->isEmpty()) {
                    return;
                }

                Item::query()->forHousehold($collection->household_id)->visibleTo($user)
                    ->where('collection_id', $collection->id)->where('done', false)->get()
                    ->each(function (Item $entry) use ($collection, $properties, $today, $until, &$rows) {
                        foreach ($properties as $property) {
                            $date = (string) $entry->propertyValue($property);

                            if ($date !== '' && $date <= $until && $date >= $today->copy()->subDays(7)->toDateString()) {
                                $rows[] = ['entry' => $entry, 'collection' => $collection, 'property' => $property, 'date' => $date, 'days' => (int) $today->diffInDays(Carbon::parse($date), false)];
                            }
                        }
                    });
            });

        usort($rows, fn (array $a, array $b) => [$a['date'], $a['entry']->title] <=> [$b['date'], $b['entry']->title]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Monthly amounts ("Monatliche Kosten (€)" etc.) summed per collection.
     *
     * @return array{total: float, collections: array<int, array{collection: Collection, property: CollectionProperty, sum: float, count: int}>}
     */
    public static function monthlyCosts(User $user, int $householdId): array
    {
        $result = ['total' => 0.0, 'collections' => []];

        Collection::query()->visibleTo($user, $householdId)->whereNull('system_key')->with('properties')->get()
            ->each(function (Collection $collection) use ($user, &$result) {
                $property = $collection->properties->first(fn (CollectionProperty $property) => $property->type === CollectionProperty::TYPE_NUMBER
                    && PropertyDisplay::isCurrency($property)
                    && preg_match('/monat|monthly|month/i', $property->name));

                if (! $property) {
                    return;
                }

                $values = Item::query()->forHousehold($collection->household_id)->visibleTo($user)
                    ->where('collection_id', $collection->id)->where('done', false)->get()
                    ->map(fn (Item $entry) => $entry->propertyValue($property))
                    ->filter(fn ($value) => is_numeric($value));

                if ($values->isNotEmpty()) {
                    $sum = (float) $values->sum();
                    $result['collections'][] = ['collection' => $collection, 'property' => $property, 'sum' => $sum, 'count' => $values->count()];
                    $result['total'] += $sum;
                }
            });

        return $result;
    }
}
