<?php

namespace App\Support\Modules;

use App\Support\Addons;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Every area of the tool that can be switched on or off for a household or an account – and later
 * be bought on its own. Core areas (overview, inbox, tasks, calendar, pages, household) are not in
 * here: they are always available.
 */
class ModuleCatalog
{
    /**
     * @return array<string, array{label: string, icon: string, routes: list<string>, addon: bool}>
     */
    public static function all(): array
    {
        $addons = Addons::all();
        $module = fn (string $label, string $icon, array $routes, bool $addon = false) => ['label' => $label, 'icon' => $icon, 'routes' => $routes, 'addon' => $addon];

        return [
            'brain' => $module(__('Ask your Second Brain'), '✨', ['brain.*', 'api.v1.brain.*']),
            'learnings' => $module(__('Lessons learned'), '🎓', ['learnings.*', 'api.v1.learnings.*']),
            'journal' => $module(__('Routines & journal'), '🙂', ['journal.*', 'api.v1.journal.*']),
            'shopping' => $module(__('Shopping List'), '🛒', ['shopping-list.*', 'api.v1.shopping.*']),
            'cards' => $module(__('Loyalty cards'), '💳', ['loyalty-cards.*', 'api.v1.cards.*']),
            'focus' => $module(__('Focus & time boxing'), '🎯', ['focus.*', 'api.v1.focus.*']),
            'bookmarks' => $module(__('Bookmarks & read later'), '🔖', ['bookmarks.*', 'api.v1.bookmarks.*']),
            'mail' => $module(__('Mail'), '📧', ['mail.*']),
            'pantry' => $module(__('Pantry'), '🥫', ['pantry.*', 'api.v1.pantry.*']),
            Addons::RECIPES => $module($addons[Addons::RECIPES]['label'], $addons[Addons::RECIPES]['icon'], ['recipes.*'], true),
            Addons::FINANCE => $module($addons[Addons::FINANCE]['label'], $addons[Addons::FINANCE]['icon'], ['finance.*'], true),
            Addons::PORTFOLIO => $module($addons[Addons::PORTFOLIO]['label'], $addons[Addons::PORTFOLIO]['icon'], ['portfolio.*', 'api.v1.portfolios.*', 'api.v1.quotes', 'api.v1.quote', 'api.v1.recommendations', 'api.v1.watchlist.*'], true),
            Addons::BUSINESS => $module($addons[Addons::BUSINESS]['label'], $addons[Addons::BUSINESS]['icon'], ['business.*', 'api.v1.business.*'], true),
            'net_worth' => $module(__('Net worth'), '🥧', ['net-worth.*', 'api.v1.net-worth']),
            'cashflow' => $module(__('Cashflow analysis'), '🔁', ['cashflow.*']),
            'wealth_plan' => $module(__('Wealth plan'), '🚀', ['wealth-plan.*', 'api.v1.wealth-plan.*']),
            'contracts' => $module(__('Contract costs'), '📄', ['contracts.*', 'letters.*', 'api.v1.contracts.*', 'api.v1.letters.*']),
            'quotes' => $module(__('Quote comparisons'), '⚖️', ['quotes.*', 'api.v1.quote-comparisons.*']),
            'wishlist' => $module(__('Wishlist'), '🎁', ['wishlist.*', 'api.v1.wishlist.*']),
            'knowledge' => $module(__('Money knowledge'), '💡', ['knowledge.*', 'api.v1.knowledge.*']),
            'house' => $module(__('At home'), '🛠', ['house.*', 'api.v1.house.*']),
            Addons::SMART_HOME => $module($addons[Addons::SMART_HOME]['label'], $addons[Addons::SMART_HOME]['icon'], ['smart-home.*', 'api.v1.smart-home.*'], true),
            'automations' => $module(__('Automations'), '🔄', ['automations.*', 'api.v1.automations.*']),
            Addons::REAL_ESTATE => $module($addons[Addons::REAL_ESTATE]['label'], $addons[Addons::REAL_ESTATE]['icon'], ['real-estate.*', 'api.v1.real-estate.*'], true),
            'vehicles' => $module(__('Vehicles'), '🚗', ['vehicles.*', 'api.v1.vehicles.*']),
            'health' => $module(__('Health'), '❤️', ['health.*', 'api.v1.health.*']),
            'people' => $module(__('People & occasions'), '🎂', ['people.*', 'api.v1.people.*']),
            'kids' => $module(__('Kids & pocket money'), '⭐', ['kids.*', 'api.v1.kids.*']),
            'work' => $module(__('Working time & leave'), '⏱', ['work.*', 'api.v1.work.*']),
            'career' => $module(__('Career'), '💼', ['career.*', 'api.v1.career.*']),
            'trips' => $module(__('Trips'), '✈️', ['trips.*']),
            'places' => $module(__('Places'), '📍', ['places.*', 'api.v1.places.*']),
            'splits' => $module(__('Split costs'), '🧮', ['splits.*', 'api.v1.splits.*']),
            'review' => $module(__('Year in review'), '🌟', ['review.*', 'api.v1.review.*']),
            'emergency' => $module(__('Emergency folder'), '🛡', ['emergency.*', 'api.v1.emergency.*']),
            'provision' => $module(__('Provision check'), '☂️', ['provision.*', 'api.v1.provision.*']),
            'drives' => $module(__('Network drives'), '🗄', ['drives.*', 'api.v1.drives.*']),
        ];
    }

    public static function exists(string $module): bool
    {
        return array_key_exists($module, self::all());
    }

    /**
     * The module a link of the app belongs to, null for core pages and other sites.
     */
    public static function forUrl(string $url): ?string
    {
        $base = rtrim((string) config('app.url'), '/');

        if (! str_starts_with($url, '/') && ($base === '' || ! str_starts_with($url, $base))) {
            return null;
        }

        try {
            $route = app('router')->getRoutes()->match(Request::create($url));
        } catch (\Throwable) {
            return null;
        }

        return self::forRoute($route->getName());
    }

    /**
     * The module a named route belongs to, null for core pages.
     */
    public static function forRoute(?string $routeName): ?string
    {
        return $routeName ? self::match($routeName) : null;
    }

    /**
     * The module a Livewire component belongs to, null for core pages. Component names (e.g.
     * "health.index") happen to follow the same dotted shape as the route name patterns above,
     * so the same matching applies – used as a fallback when a Livewire update request does not
     * resolve the original page route (see RecordSlowRequests for how the name is read).
     */
    public static function forComponent(?string $componentName): ?string
    {
        return $componentName ? self::match($componentName) : null;
    }

    private static function match(string $name): ?string
    {
        foreach (self::all() as $key => $module) {
            foreach ($module['routes'] as $pattern) {
                if (Str::is($pattern, $name)) {
                    return $key;
                }
            }
        }

        return null;
    }
}
