<?php

namespace App\Support\Automation;

use App\Models\HouseholdMode;

/**
 * The building blocks of automations – triggers, conditions and actions – with their labels,
 * empty values for the editor, a readable description and ready-made templates.
 */
class AutomationCatalog
{
    public const OPERATORS = ['eq' => '=', 'ne' => '≠', 'gt' => '>', 'lt' => '<'];

    /**
     * @return array<string, array{label: string, icon: string, defaults: array<string, mixed>}>
     */
    public static function triggers(): array
    {
        return [
            'calendar' => ['label' => __('Calendar entry, trip or leave'), 'icon' => '🗓️', 'defaults' => ['keyword' => __('Holiday'), 'sources' => [CalendarMatcher::CALENDAR, CalendarMatcher::TRIPS, CalendarMatcher::LEAVE], 'edge' => 'start']],
            'mode' => ['label' => __('Mode switched'), 'icon' => '🏷️', 'defaults' => ['key' => 'vacation', 'edge' => 'on']],
            'time' => ['label' => __('Time of day'), 'icon' => '⏰', 'defaults' => ['at' => '07:00', 'days' => []]],
            'entity' => ['label' => __('Home Assistant value'), 'icon' => '🏠', 'defaults' => ['entity_id' => '', 'operator' => 'eq', 'value' => 'on']],
            'evcc' => ['label' => __('evcc value'), 'icon' => '🔌', 'defaults' => ['metric' => 'pv', 'operator' => 'gt', 'value' => '3000']],
            'manual' => ['label' => __('Only by hand'), 'icon' => '👆', 'defaults' => []],
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string, defaults: array<string, mixed>}>
     */
    public static function conditions(): array
    {
        return [
            'mode' => ['label' => __('Mode is on/off'), 'icon' => '🏷️', 'defaults' => ['key' => 'vacation', 'active' => true]],
            'time_window' => ['label' => __('Between times'), 'icon' => '🕒', 'defaults' => ['from' => '18:00', 'until' => '23:00']],
            'weekdays' => ['label' => __('On weekdays'), 'icon' => '📆', 'defaults' => ['days' => [1, 2, 3, 4, 5]]],
            'calendar' => ['label' => __('Calendar entry today'), 'icon' => '🗓️', 'defaults' => ['keyword' => __('Holiday'), 'sources' => [CalendarMatcher::CALENDAR], 'active' => true]],
            'entity' => ['label' => __('Home Assistant value'), 'icon' => '🏠', 'defaults' => ['entity_id' => '', 'operator' => 'eq', 'value' => 'on']],
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string, defaults: array<string, mixed>}>
     */
    public static function actions(): array
    {
        return [
            'ha_service' => ['label' => __('Home Assistant: call service'), 'icon' => '🏠', 'defaults' => ['service' => 'light.turn_on', 'entity_id' => '', 'data' => '']],
            'presence_start' => ['label' => __('Simulate presence (lights)'), 'icon' => '💡', 'defaults' => ['entities' => [], 'from' => '18:00', 'until' => '23:30']],
            'presence_stop' => ['label' => __('Stop presence simulation'), 'icon' => '⏹️', 'defaults' => []],
            'evcc_mode' => ['label' => __('evcc: charge mode'), 'icon' => '🔌', 'defaults' => ['loadpoint' => 1, 'mode' => 'pv']],
            'evcc_limit' => ['label' => __('evcc: charge limit'), 'icon' => '🔋', 'defaults' => ['loadpoint' => 1, 'soc' => 80]],
            'mode' => ['label' => __('Switch mode'), 'icon' => '🏷️', 'defaults' => ['key' => 'vacation', 'active' => true]],
            'push' => ['label' => __('Send notification'), 'icon' => '🔔', 'defaults' => ['to' => 'me', 'title' => '', 'body' => '', 'urgent' => false]],
            'task' => ['label' => __('Create task'), 'icon' => '✅', 'defaults' => ['title' => '', 'due' => 'today']],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function evccMetrics(): array
    {
        return [
            'pv' => __('PV power (W)'),
            'home' => __('Home consumption (W)'),
            'grid' => __('Grid power (W, negative = feed-in)'),
            'battery' => __('Home battery power (W)'),
            'batterySoc' => __('Home battery charge (%)'),
            'tariff' => __('Electricity price (€/kWh)'),
            'vehicleSoc' => __('Car charge (%)'),
            'connected' => __('Car plugged in (1/0)'),
            'charging' => __('Car charging (1/0)'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function evccModes(): array
    {
        return ['off' => __('Off'), 'now' => __('Fast'), 'minpv' => __('Min+PV'), 'pv' => __('PV only')];
    }

    /**
     * @return array<string, string>
     */
    public static function calendarSources(): array
    {
        return [
            CalendarMatcher::CALENDAR => __('Calendar entries with the word'),
            CalendarMatcher::TRIPS => __('Trips'),
            CalendarMatcher::LEAVE => __('Leave (working time)'),
        ];
    }

    /**
     * One line for lists and the run log.
     *
     * @param  array<string, mixed>  $block
     */
    public static function describe(array $block): string
    {
        $operator = self::OPERATORS[$block['operator'] ?? 'eq'] ?? '=';
        $modes = collect(HouseholdMode::defaults())->map(fn (array $mode) => $mode['name']);
        $mode = $modes[$block['key'] ?? ''] ?? (string) ($block['key'] ?? '');

        return match ($block['type'] ?? '') {
            'calendar' => collect((array) ($block['sources'] ?? []))->map(fn (string $source) => $source === CalendarMatcher::CALENDAR ? '„'.($block['keyword'] ?? '').'“' : self::calendarSources()[$source] ?? $source)->implode(' / ')
                .(isset($block['edge']) ? ' · '.($block['edge'] === 'end' ? __('ends') : __('begins')) : (isset($block['active']) && ! $block['active'] ? ' · '.__('not today') : '')),
            'mode' => $mode.(isset($block['edge']) ? ' · '.($block['edge'] === 'off' ? __('off') : __('on')) : ' · '.((bool) ($block['active'] ?? true) ? __('on') : __('off'))),
            'time' => __('at :time', ['time' => $block['at'] ?? '']),
            'time_window' => ($block['from'] ?? '').'–'.($block['until'] ?? ''),
            'weekdays' => collect((array) ($block['days'] ?? []))->map(fn ($day) => now()->startOfWeek()->addDays((int) $day - 1)->translatedFormat('D'))->implode(', '),
            'entity' => ($block['entity_id'] ?? '').' '.$operator.' '.($block['value'] ?? ''),
            'evcc' => (self::evccMetrics()[$block['metric'] ?? ''] ?? '').' '.$operator.' '.($block['value'] ?? ''),
            'manual' => __('Only by hand'),
            'ha_service' => ($block['service'] ?? '').(filled($block['entity_id'] ?? null) ? ' → '.$block['entity_id'] : ''),
            'presence_start' => __('Simulate presence with :count lights (:from–:until)', ['count' => count((array) ($block['entities'] ?? [])), 'from' => $block['from'] ?? '', 'until' => $block['until'] ?? '']),
            'presence_stop' => __('Stop presence simulation'),
            'evcc_mode' => __('Charge mode: :mode', ['mode' => self::evccModes()[$block['mode'] ?? ''] ?? '']),
            'evcc_limit' => __('Charge limit: :soc %', ['soc' => $block['soc'] ?? '']),
            'push' => __('Notification: :title', ['title' => $block['title'] ?? '']),
            'task' => __('Task: :title', ['title' => $block['title'] ?? '']),
            default => (string) ($block['type'] ?? ''),
        };
    }

    /**
     * Ready-made automations to start from.
     *
     * @return array<string, array{name: string, icon: string, description: string, trigger: array<string, mixed>, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>}>
     */
    public static function templates(): array
    {
        return [
            'vacation_on' => [
                'name' => __('Holiday begins → holiday mode'), 'icon' => '🏖️',
                'description' => __('When “Holiday”, a trip or leave is entered for today, holiday mode is switched on.'),
                'trigger' => ['type' => 'calendar', 'keyword' => __('Holiday'), 'sources' => [CalendarMatcher::CALENDAR, CalendarMatcher::TRIPS, CalendarMatcher::LEAVE], 'edge' => 'start'],
                'conditions' => [],
                'actions' => [['type' => 'mode', 'key' => 'vacation', 'active' => true]],
            ],
            'vacation_off' => [
                'name' => __('Holiday ends → holiday mode off'), 'icon' => '🏡',
                'description' => __('When nothing is entered any more, holiday mode is switched off again.'),
                'trigger' => ['type' => 'calendar', 'keyword' => __('Holiday'), 'sources' => [CalendarMatcher::CALENDAR, CalendarMatcher::TRIPS, CalendarMatcher::LEAVE], 'edge' => 'end'],
                'conditions' => [],
                'actions' => [['type' => 'mode', 'key' => 'vacation', 'active' => false]],
            ],
            'presence' => [
                'name' => __('Holiday mode → simulate presence'), 'icon' => '💡',
                'description' => __('In holiday mode, lights go on and off in the evening as if someone were at home.'),
                'trigger' => ['type' => 'mode', 'key' => 'vacation', 'edge' => 'on'],
                'conditions' => [],
                'actions' => [['type' => 'presence_start', 'entities' => [], 'from' => '18:00', 'until' => '23:30'], ['type' => 'push', 'to' => 'household', 'title' => __('Holiday mode is on'), 'body' => __('Presence is simulated in the evening.'), 'urgent' => false]],
            ],
            'presence_off' => [
                'name' => __('Holiday mode off → stop simulation'), 'icon' => '⏹️',
                'description' => __('Back home: the presence simulation stops and the lights go off.'),
                'trigger' => ['type' => 'mode', 'key' => 'vacation', 'edge' => 'off'],
                'conditions' => [],
                'actions' => [['type' => 'presence_stop']],
            ],
            'pv_surplus' => [
                'name' => __('PV surplus → notification'), 'icon' => '☀️',
                'description' => __('When the PV system delivers more than 4 kW, you get a tip to switch on the dishwasher or washing machine.'),
                'trigger' => ['type' => 'evcc', 'metric' => 'pv', 'operator' => 'gt', 'value' => '4000'],
                'conditions' => [['type' => 'time_window', 'from' => '09:00', 'until' => '17:00']],
                'actions' => [['type' => 'push', 'to' => 'me', 'title' => __('☀️ Plenty of sun power'), 'body' => __('Good time for the dishwasher or washing machine.'), 'urgent' => false]],
            ],
            'car_night' => [
                'name' => __('Car plugged in in the evening → charge to 80 %'), 'icon' => '🚗',
                'description' => __('When the car is plugged in in the evening, evcc charges it to 80 % with the fast mode.'),
                'trigger' => ['type' => 'evcc', 'metric' => 'connected', 'operator' => 'eq', 'value' => '1'],
                'conditions' => [['type' => 'time_window', 'from' => '18:00', 'until' => '23:59']],
                'actions' => [['type' => 'evcc_limit', 'loadpoint' => 1, 'soc' => 80], ['type' => 'evcc_mode', 'loadpoint' => 1, 'mode' => 'minpv']],
            ],
        ];
    }
}
