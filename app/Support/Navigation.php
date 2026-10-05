<?php

namespace App\Support;

use App\Models\User;
use App\Support\Modules\ModuleAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The modules of the main navigation in their groups. Each member can pin modules (shown at the
 * top), hide the ones they never use and gets the most visited modules suggested.
 */
class Navigation
{
    /** Modules that can't be hidden – the way back to everything else. */
    private const FIXED = ['dashboard'];

    /**
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            'start' => __('Start'),
            'everyday' => __('Everyday'),
            'money' => __('Money'),
            'home' => __('Home & family'),
            'travel' => __('Travel & leisure'),
            'provisions' => __('Provisions & documents'),
            'household' => __('Household'),
        ];
    }

    /**
     * All modules the member may use, in menu order.
     *
     * @return array<string, array{group: string, label: string, icon: string, route: string, active: list<string>}>
     */
    public static function modules(User $user): array
    {
        $finance = $user->canUseAddon(Addons::FINANCE);
        $portfolio = $user->canUseAddon(Addons::PORTFOLIO);
        $realEstate = $user->canUseAddon(Addons::REAL_ESTATE);

        $modules = [
            'dashboard' => ['start', __('Dashboard'), 'home', 'dashboard', ['dashboard']],
            'inbox' => ['start', __('Inbox'), 'inbox', 'inbox.index', ['inbox.index']],
            'brain' => ['start', __('Ask your Second Brain'), 'sparkles', 'brain.ask', ['brain.ask']],
            'learnings' => ['start', __('Lessons learned'), 'academic-cap', 'learnings.index', ['learnings.*']],
            'tasks' => ['everyday', __('To-Do'), 'check-circle', 'tasks.index', ['tasks.index']],
            'calendar' => ['everyday', __('Calendar'), 'calendar-days', 'calendar.index', ['calendar.*']],
            'journal' => ['everyday', __('Routines & journal'), 'face-smile', 'journal.index', ['journal.*']],
            'shopping' => ['everyday', __('Shopping List'), 'shopping-cart', 'shopping-list.index', ['shopping-list.*']],
            'pantry' => ['everyday', __('Pantry'), 'archive-box', 'pantry.index', ['pantry.*']],
            'recipes' => $user->canUseAddon(Addons::RECIPES) ? ['everyday', __('Recipes'), 'book-open', 'recipes.index', ['recipes.*']] : null,
            'finance' => $finance ? ['money', __('Finances'), 'banknotes', 'finance.index', ['finance.*']] : null,
            'portfolio' => $portfolio ? ['money', __('Portfolio'), 'arrow-trending-up', 'portfolio.index', ['portfolio.*']] : null,
            'business' => $user->canUseAddon(Addons::BUSINESS) ? ['money', __('Small business'), 'briefcase', 'business.index', ['business.*']] : null,
            'net_worth' => $finance || $portfolio || $realEstate ? ['money', __('Net worth'), 'chart-pie', 'net-worth.index', ['net-worth.*']] : null,
            'cashflow' => $finance || $portfolio || $realEstate ? ['money', __('Cashflow analysis'), 'arrows-right-left', 'cashflow.index', ['cashflow.*']] : null,
            'contracts' => ['money', __('Contract costs'), 'document-text', 'contracts.index', ['contracts.*', 'letters.*']],
            'quotes' => ['money', __('Quote comparisons'), 'scale', 'quotes.index', ['quotes.*']],
            'wishlist' => ['money', __('Wishlist'), 'gift', 'wishlist.index', ['wishlist.*']],
            'knowledge' => ['money', __('Money knowledge'), 'light-bulb', 'knowledge.index', ['knowledge.*']],
            'house' => ['home', __('At home'), 'wrench-screwdriver', 'house.index', ['house.*']],
            'smart_home' => $user->canUseAddon(Addons::SMART_HOME) ? ['home', __('Smart home'), 'bolt', 'smart-home.index', ['smart-home.*']] : null,
            'automations' => ['home', __('Automations'), 'arrow-path', 'automations.index', ['automations.*']],
            'real_estate' => $realEstate ? ['home', __('Real estate'), 'building-office-2', 'real-estate.index', ['real-estate.*']] : null,
            'vehicles' => ['home', __('Vehicles'), 'truck', 'vehicles.index', ['vehicles.*']],
            'health' => ['home', __('Health'), 'heart', 'health.index', ['health.*']],
            'people' => ['home', __('People & occasions'), 'cake', 'people.index', ['people.*']],
            'kids' => ['home', __('Kids & pocket money'), 'star', 'kids.index', ['kids.*']],
            'work' => ['home', __('Working time & leave'), 'clock', 'work.index', ['work.*']],
            'career' => ['home', __('Career'), 'briefcase', 'career.index', ['career.*']],
            'trips' => ['travel', __('Trips'), 'paper-airplane', 'trips.index', ['trips.*']],
            'places' => ['travel', __('Places'), 'map-pin', 'places.index', ['places.*']],
            'splits' => ['travel', __('Split costs'), 'calculator', 'splits.index', ['splits.*']],
            'review' => ['travel', __('Year in review'), 'sparkles', 'review.index', ['review.*']],
            'emergency' => ['provisions', __('Emergency folder'), 'shield-check', 'emergency.index', ['emergency.*']],
            'provision' => ['provisions', __('Provision check'), 'scale', 'provision.index', ['provision.*']],
            'drives' => ['provisions', __('Network drives'), 'server-stack', 'drives.index', ['drives.*']],
            'household' => ['household', __('Members'), 'user-group', 'household.show', ['household.show']],
            'groups' => ['household', __('Groups & sharing'), 'share', 'groups.index', ['groups.*']],
            'pdf' => ['household', __('PDF design'), 'document-text', 'pdf.editor', ['pdf.editor']],
        ];

        // Areas the administrator locked for the household or the account (or not licensed).
        $modules = array_filter($modules, fn (?array $module, string $key) => $module !== null && ModuleAccess::allows($user, $key), ARRAY_FILTER_USE_BOTH);

        return array_map(
            fn (array $module) => ['group' => $module[0], 'label' => $module[1], 'icon' => $module[2], 'route' => $module[3], 'active' => $module[4]],
            $modules,
        );
    }

    /**
     * @return array{pinned: list<string>, hidden: list<string>}
     */
    public static function preferences(User $user): array
    {
        $stored = $user->nav_preferences ?? [];

        return [
            'pinned' => $stored['pinned'] ?? [],
            'hidden' => array_values(array_diff($stored['hidden'] ?? [], self::FIXED)),
        ];
    }

    public static function isHideable(string $key): bool
    {
        return ! in_array($key, self::FIXED, true);
    }

    /**
     * @param  array{pinned?: list<string>, hidden?: list<string>}  $changes
     */
    public static function save(User $user, array $changes): void
    {
        $known = array_keys(self::modules($user));
        $preferences = array_merge(self::preferences($user), $changes);

        // Other keys (e.g. the dashboard shown on the overview) stay as they are.
        $user->forceFill(['nav_preferences' => array_merge($user->nav_preferences ?? [], [
            'pinned' => array_values(array_unique(array_intersect($preferences['pinned'], $known))),
            'hidden' => array_values(array_unique(array_diff(array_intersect($preferences['hidden'], $known), self::FIXED))),
        ])])->save();
    }

    public static function togglePinned(User $user, string $key): void
    {
        $pinned = self::preferences($user)['pinned'];
        self::save($user, ['pinned' => in_array($key, $pinned, true) ? array_values(array_diff($pinned, [$key])) : [...$pinned, $key]]);
    }

    public static function toggleHidden(User $user, string $key): void
    {
        $preferences = self::preferences($user);
        $hidden = in_array($key, $preferences['hidden'], true) ? array_values(array_diff($preferences['hidden'], [$key])) : [...$preferences['hidden'], $key];
        // A hidden module is not pinned either.
        self::save($user, ['hidden' => $hidden, 'pinned' => array_values(array_diff($preferences['pinned'], $hidden))]);
    }

    /**
     * Counts a visit of the module whose route is open (for "often used").
     */
    public static function recordVisit(User $user, ?string $routeName): void
    {
        if (! $routeName) {
            return;
        }

        foreach (self::modules($user) as $key => $module) {
            if ($key !== 'dashboard' && collect($module['active'])->contains(fn (string $pattern) => Str::is($pattern, $routeName))) {
                $visits = Cache::get('nav:visits:'.$user->id, []);
                $visits[$key] = ($visits[$key] ?? 0) + 1;
                Cache::put('nav:visits:'.$user->id, $visits, now()->addDays(60));

                return;
            }
        }
    }

    /**
     * The most visited modules that are neither pinned nor hidden (at least five visits).
     *
     * @return list<string>
     */
    public static function frequent(User $user, int $limit = 3): array
    {
        $preferences = self::preferences($user);
        $available = array_keys(self::modules($user));

        return collect(Cache::get('nav:visits:'.$user->id, []))
            ->filter(fn (int $count, string $key) => $count >= 5 && in_array($key, $available, true) && ! in_array($key, [...$preferences['pinned'], ...$preferences['hidden']], true))
            ->sortDesc()->keys()->take($limit)->values()->all();
    }

    /**
     * Ready-made selections for the setup assistant: which modules stay visible.
     *
     * @return array<string, array{label: string, description: string, modules: list<string>|null}>
     */
    public static function presets(): array
    {
        return [
            'everything' => ['label' => __('Everything'), 'description' => __('All modules in the menu.'), 'modules' => null],
            'family' => ['label' => __('Family & household'), 'description' => __('Tasks, calendar, shopping, recipes, home, health, people, trips.'), 'modules' => ['dashboard', 'inbox', 'tasks', 'calendar', 'journal', 'shopping', 'pantry', 'recipes', 'house', 'smart_home', 'automations', 'vehicles', 'health', 'people', 'kids', 'trips', 'places', 'splits', 'household']],
            'money' => ['label' => __('Money focus'), 'description' => __('Finances, portfolio, contracts, net worth plus the everyday basics.'), 'modules' => ['dashboard', 'inbox', 'tasks', 'calendar', 'shopping', 'finance', 'portfolio', 'business', 'net_worth', 'cashflow', 'contracts', 'quotes', 'wishlist', 'knowledge', 'real_estate', 'emergency', 'provision']],
            'minimal' => ['label' => __('Minimal'), 'description' => __('Just tasks, calendar, shopping list and notes.'), 'modules' => ['dashboard', 'inbox', 'tasks', 'calendar', 'shopping']],
        ];
    }

    public static function applyPreset(User $user, string $preset): void
    {
        $definition = self::presets()[$preset] ?? null;

        if (! $definition) {
            return;
        }

        $all = array_keys(self::modules($user));
        self::save($user, ['hidden' => $definition['modules'] === null ? [] : array_values(array_diff($all, $definition['modules']))]);
    }
}
