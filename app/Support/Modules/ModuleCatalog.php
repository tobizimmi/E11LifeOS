<?php

namespace App\Support\Modules;

use App\Support\Addons;
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
            'brain' => $module(__('Ask your Second Brain'), '✨', ['brain.*']),
            'learnings' => $module(__('Lessons learned'), '🎓', ['learnings.*']),
            'journal' => $module(__('Routines & journal'), '🙂', ['journal.*']),
            'shopping' => $module(__('Shopping List'), '🛒', ['shopping-list.*']),
            'pantry' => $module(__('Pantry'), '🥫', ['pantry.*']),
            Addons::RECIPES => $module($addons[Addons::RECIPES]['label'], $addons[Addons::RECIPES]['icon'], ['recipes.*'], true),
            Addons::FINANCE => $module($addons[Addons::FINANCE]['label'], $addons[Addons::FINANCE]['icon'], ['finance.*'], true),
            Addons::PORTFOLIO => $module($addons[Addons::PORTFOLIO]['label'], $addons[Addons::PORTFOLIO]['icon'], ['portfolio.*'], true),
            Addons::BUSINESS => $module($addons[Addons::BUSINESS]['label'], $addons[Addons::BUSINESS]['icon'], ['business.*'], true),
            'net_worth' => $module(__('Net worth'), '🥧', ['net-worth.*']),
            'cashflow' => $module(__('Cashflow analysis'), '🔁', ['cashflow.*']),
            'contracts' => $module(__('Contract costs'), '📄', ['contracts.*', 'letters.*']),
            'quotes' => $module(__('Quote comparisons'), '⚖️', ['quotes.*']),
            'wishlist' => $module(__('Wishlist'), '🎁', ['wishlist.*']),
            'knowledge' => $module(__('Money knowledge'), '💡', ['knowledge.*']),
            'house' => $module(__('At home'), '🛠', ['house.*']),
            Addons::SMART_HOME => $module($addons[Addons::SMART_HOME]['label'], $addons[Addons::SMART_HOME]['icon'], ['smart-home.*'], true),
            'automations' => $module(__('Automations'), '🔄', ['automations.*']),
            Addons::REAL_ESTATE => $module($addons[Addons::REAL_ESTATE]['label'], $addons[Addons::REAL_ESTATE]['icon'], ['real-estate.*'], true),
            'vehicles' => $module(__('Vehicles'), '🚗', ['vehicles.*']),
            'health' => $module(__('Health'), '❤️', ['health.*']),
            'people' => $module(__('People & occasions'), '🎂', ['people.*']),
            'kids' => $module(__('Kids & pocket money'), '⭐', ['kids.*']),
            'work' => $module(__('Working time & leave'), '⏱', ['work.*']),
            'career' => $module(__('Career'), '💼', ['career.*']),
            'trips' => $module(__('Trips'), '✈️', ['trips.*']),
            'places' => $module(__('Places'), '📍', ['places.*']),
            'splits' => $module(__('Split costs'), '🧮', ['splits.*']),
            'review' => $module(__('Year in review'), '🌟', ['review.*']),
            'emergency' => $module(__('Emergency folder'), '🛡', ['emergency.*']),
            'provision' => $module(__('Provision check'), '☂️', ['provision.*']),
            'drives' => $module(__('Network drives'), '🗄', ['drives.*']),
        ];
    }

    public static function exists(string $module): bool
    {
        return array_key_exists($module, self::all());
    }

    /**
     * The module a named route belongs to, null for core pages.
     */
    public static function forRoute(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }

        foreach (self::all() as $key => $module) {
            foreach ($module['routes'] as $pattern) {
                if (Str::is($pattern, $routeName)) {
                    return $key;
                }
            }
        }

        return null;
    }
}
